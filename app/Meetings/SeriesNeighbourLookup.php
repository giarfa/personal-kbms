<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only place in the product with an opinion about series order (US-018).
 *
 * Adjacency is the feed's: occurrences sharing a `source_uid`, ordered by
 * start time. Cancelled occurrences stay in the chain — excluding them would
 * make a note attached to a cancelled occurrence unreachable by this route,
 * which contradicts keeping the row rather than hiding it (FR-004).
 *
 * Each direction is one `LIMIT 1` query filtered on `source_uid`, the leading
 * column of the existing `(source_uid, recurrence_id)` unique index, and one
 * model is hydrated per direction however long the series is.
 *
 * Be precise about what that does and does not buy: the index serves the
 * `source_uid` equality only, so `EXPLAIN QUERY PLAN` shows a `TEMP B-TREE FOR
 * ORDER BY` — SQLite sorts the matched series rows to satisfy the ordering
 * before applying the limit. The page therefore costs two `LIMIT 1` queries
 * rather than anything proportional to the series *in PHP*, but the sort
 * inside SQLite is proportional to the series. A `(source_uid, starts_at,
 * recurrence_id)` composite index would make this a true keyset seek; it is
 * deliberately not added here, because US-018 scopes the lookup to the
 * existing index and the sync window already bounds a series to the low
 * hundreds of rows.
 */
final class SeriesNeighbourLookup
{
    /**
     * Both directions out of this occurrence, or `null` when it is a one-off.
     *
     * Recurrence is decided by the feed-carried `recurrence_id`, never by
     * counting stored rows: a series whose sync window holds exactly one
     * occurrence is still a series, and says so with two inert controls. The
     * window's edge and the series' extent are different facts.
     */
    public function forOccurrence(CalendarEvent $occurrence): ?SeriesNeighbours
    {
        if ($occurrence->recurrence_id === '') {
            return null;
        }

        $referenceYear = CarbonImmutable::instance($occurrence->starts_at)->year;

        return new SeriesNeighbours(
            previous: $this->neighbour($occurrence, SeriesDirection::Previous, $referenceYear),
            next: $this->neighbour($occurrence, SeriesDirection::Next, $referenceYear),
        );
    }

    private function neighbour(CalendarEvent $occurrence, SeriesDirection $direction, int $referenceYear): SeriesNeighbour
    {
        $event = $this->query($occurrence, $direction)->first();

        return $event === null
            ? SeriesNeighbour::none($direction)
            : SeriesNeighbour::at($direction, $event, $referenceYear);
    }

    /**
     * Keyset, not offset: the cursor is the composite `(starts_at,
     * recurrence_id)`, which makes the ordering total rather than merely
     * sorted. A strict comparison on that pair excludes the occurrence from
     * its own result set, so it can never be its own neighbour, and two rows
     * sharing a `starts_at` — the shape a `RECURRENCE-ID` override onto a
     * sibling's slot produces — resolve past each other in a fixed direction
     * instead of pointing at each other.
     *
     * @return Builder<CalendarEvent>
     */
    private function query(CalendarEvent $occurrence, SeriesDirection $direction): Builder
    {
        $isPrevious = $direction === SeriesDirection::Previous;
        $comparison = $isPrevious ? '<' : '>';
        $order = $isPrevious ? 'desc' : 'asc';

        return CalendarEvent::query()
            ->where('source_uid', $occurrence->source_uid)
            ->where(function (Builder $query) use ($occurrence, $comparison): void {
                $query
                    ->where('starts_at', $comparison, $occurrence->starts_at)
                    ->orWhere(function (Builder $query) use ($occurrence, $comparison): void {
                        $query
                            ->where('starts_at', '=', $occurrence->starts_at)
                            ->where('recurrence_id', $comparison, $occurrence->recurrence_id);
                    });
            })
            ->orderBy('starts_at', $order)
            ->orderBy('recurrence_id', $order);
    }
}
