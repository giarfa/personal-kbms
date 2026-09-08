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
        $rows = CalendarEvent::query()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (CalendarEvent $event): AgendaRow => new AgendaRow($event));

        $byDate = $rows->groupBy(function (AgendaRow $row) use ($fromDate): string {
            $day = $row->start->toDateString();

            // A multi-day all-day event is emitted once, clamped to the range's
            // first visible day when it began before the window.
            return $row->isAllDay && $day < $fromDate ? $fromDate : $day;
        });

        $today = CarbonImmutable::now(config('kbms.timezone'))->toDateString();

        return collect($range->dates())->map(function (CarbonImmutable $date) use ($byDate, $today): AgendaDay {
            $key = $date->toDateString();

            /** @var Collection<int, AgendaRow> $rowsForDay */
            $rowsForDay = $byDate->get($key, collect())
                ->sortBy(fn (AgendaRow $row): string => ($row->isAllDay ? '0' : '1').$row->start->format('H:i:s'))
                ->values();

            return new AgendaDay($date, $rowsForDay->all(), $key === $today);
        });
    }
}
