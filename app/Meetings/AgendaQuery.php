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
