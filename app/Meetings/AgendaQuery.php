<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the day-grouped agenda for a range in a single query. Correctness,
 * not the Blade layer, lives here: overlap semantics, local-day grouping,
 * and the multi-day clamp.
 */
class AgendaQuery
{
    /**
     * @return Collection<int, AgendaDay>
     */
    public static function for(AgendaRange $range): Collection
    {
        $from = $range->anchor;
        $to = $range->endsAt();
        $fromDate = $from->toDateString();

        // One overlap predicate for both timed and all-day rows: EventSynchronizer
        // stores starts_at/ends_at as config('app.timezone')-local wall-clock digits
        // (never UTC — see its own docblock), so comparing against $from/$to built in
        // config('kbms.timezone') is only correct because the two configs are the same
        // value. Do not "fix" this by converting to UTC, and do not split all-day back
        // into a separate whereDate() branch — both would break under the real storage
        // convention.
        $events = CalendarEvent::query()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();

        // Two queries per agenda page, regardless of week density: this batched
        // lookup, plus the events query above.
        $coverageByOccurrence = (new MeetingCoverageLookup)->for($events);

        $rows = $events->map(function (CalendarEvent $event) use ($coverageByOccurrence): AgendaRow {
            $coverage = $coverageByOccurrence[$event->source_uid."\0".$event->recurrence_id] ?? new MeetingCoverage;

            return new AgendaRow($event, $coverage);
        });

        $byDate = $rows->groupBy(function (AgendaRow $row) use ($fromDate): string {
            $day = $row->start->toDateString();

            // A multi-day event — all-day or timed — is emitted once, clamped to
            // the range's first visible day when it began before the window. The
            // clamp is not optional: the overlap predicate above already fetched
            // the row, so grouping it under a date outside the range would drop
            // it from the agenda entirely.
            return $day < $fromDate ? $fromDate : $day;
        });

        $today = CarbonImmutable::now(config('kbms.timezone'))->toDateString();

        return collect($range->dates())->map(function (CarbonImmutable $date) use ($byDate, $today): AgendaDay {
            $key = $date->toDateString();

            /** @var Collection<int, AgendaRow> $rowsForDay */
            $rowsForDay = $byDate->get($key, collect())
                ->sortBy(function (AgendaRow $row) use ($key): string {
                    if ($row->isAllDay) {
                        return '0';
                    }

                    // A timed event clamped from an earlier day is already in
                    // progress when this day opens, so it sorts ahead of the
                    // meetings that actually start today rather than at its own
                    // (earlier, and here meaningless) wall-clock time.
                    $time = $row->start->toDateString() < $key
                        ? '00:00:00'
                        : $row->start->format('H:i:s');

                    return '1'.$time;
                })
                ->values();

            return new AgendaDay($date, $rowsForDay->all(), $key === $today);
        });
    }
}
