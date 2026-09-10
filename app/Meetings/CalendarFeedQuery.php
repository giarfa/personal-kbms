<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the FullCalendar event feed for a bounded window in a small, fixed
 * number of queries. Repeats AgendaQuery's overlap predicate verbatim rather
 * than sharing it: the two surfaces disagree on multi-day rendering (agenda
 * clamps to day one, this emits the whole span), so a shared abstraction
 * would need to be parameterised until it said nothing (recorded decision
 * "agenda multi-day event rendering").
 */
final class CalendarFeedQuery
{
    /**
     * Hard ceiling on the requested window, enforced by the controller. A
     * class constant rather than a config key: this spec adds no `.env` key.
     */
    public const int MAX_RANGE_DAYS = 62;

    /**
     * @return Collection<int, CalendarEventPayload>
     */
    public static function for(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        // Same overlap predicate as AgendaQuery, against the same wall-clock
        // storage convention: starts_at/ends_at hold config('kbms.timezone')
        // local digits, never UTC. Do not convert either side here.
        $events = CalendarEvent::query()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();

        // One shared directory listing plus two batched queries, regardless
        // of window density — never resolve coverage per occurrence in a loop.
        $coverageByOccurrence = app(MeetingCoverageLookup::class)->for($events);

        // Built once, outside the map, and matched against columns already
        // hydrated above: colouring (US-012) adds no query, so a dense month
        // window costs exactly what it costs today.
        $colourRules = EventColourRules::fromConfig();

        return $events->map(function (CalendarEvent $event) use ($coverageByOccurrence, $colourRules): CalendarEventPayload {
            $coverage = $coverageByOccurrence[$event->source_uid."\0".$event->recurrence_id] ?? new MeetingCoverage;

            return CalendarEventPayload::from($event, $coverage, $colourRules->match($event));
        })->values();
    }
}
