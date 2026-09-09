<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;

/**
 * A single agenda entry: the calendar event plus everything the agenda/detail
 * views render, pre-computed so the Blade layer stays a pure renderer.
 */
final readonly class AgendaRow
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public int $durationMinutes;

    public bool $isAllDay;

    public ?string $spanLabel;

    public bool $isCancelled;

    public string $routeKey;

    public MeetingCoverage $coverage;

    public function __construct(public CalendarEvent $event, MeetingCoverage $coverage = new MeetingCoverage)
    {
        $this->start = CarbonImmutable::instance($event->starts_at);
        $this->end = CarbonImmutable::instance($event->ends_at);
        $this->isAllDay = (bool) $event->is_all_day;
        // Carbon 3 returns a float here, so a feed event carrying seconds (a
        // 90-second occurrence is 1.5) would otherwise be implicitly truncated
        // to int — a deprecation notice, and the wrong minute.
        $this->durationMinutes = $this->isAllDay
            ? 0
            : (int) round($this->start->diffInMinutes($this->end));
        $this->isCancelled = $event->cancelled_at !== null;
        $this->routeKey = $event->occurrenceKey()->toRouteKey();
        $this->coverage = $coverage;

        // Both kinds store an exclusive end, so an end landing exactly on midnight
        // belongs to the previous day. Timed occurrences span days too (an
        // overnight bridge, a long workshop block) — labelling only all-day ones
        // leaves a carried-over row looking like it starts today.
        $lastDay = $this->end->equalTo($this->end->startOfDay())
            ? $this->end->subDay()
            : $this->end;

        $this->spanLabel = $lastDay->toDateString() > $this->start->toDateString()
            ? $this->start->format('M j').' – '.$lastDay->format('M j')
            : null;
    }
}
