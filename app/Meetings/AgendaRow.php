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

    public function __construct(public CalendarEvent $event)
    {
        $this->start = CarbonImmutable::instance($event->starts_at);
        $this->end = CarbonImmutable::instance($event->ends_at);
        $this->isAllDay = (bool) $event->is_all_day;
        $this->durationMinutes = $this->isAllDay ? 0 : $this->start->diffInMinutes($this->end);
        $this->isCancelled = $event->cancelled_at !== null;
        $this->routeKey = $event->occurrenceKey()->toRouteKey();
        $this->coverage = new MeetingCoverage;

        $this->spanLabel = $this->isAllDay && $this->end->subDay()->isAfter($this->start)
            ? $this->start->format('M j').' – '.$this->end->subDay()->format('M j')
            : null;
    }
}
