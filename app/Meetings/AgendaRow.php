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

    /**
     * The todo this occurrence's summary declares, or `null` (US-013).
     *
     * Computed here rather than passed in — deliberately the mirror image of
     * `$colourRule` above. Colouring is injected so the detail page can opt out
     * by not passing it; todo status is wanted on all three surfaces, and
     * MeetingController builds an AgendaRow too, so deriving it in the
     * constructor is what makes the detail page get it for free.
     */
    public ?MeetingTodo $todo;

    /**
     * The title to render: the summary with any todo marker stripped, falling
     * back to the raw summary. Centralised here so no view has to remember to
     * strip it, and so the raw `summary` stays available for the mirror panel.
     */
    public string $displayTitle;

    /**
     * The first colour rule this occurrence satisfies, or `null` (US-012).
     *
     * The default is load-bearing, not convenience: it is what keeps
     * MeetingController's detail-page row uncoloured without a second code
     * path. "No colouring on /meetings/{occurrence}" is enforced here, at the
     * only place that builds an uncoloured row, rather than by a Blade
     * omission that a later edit could undo.
     */
    public function __construct(
        public CalendarEvent $event,
        MeetingCoverage $coverage = new MeetingCoverage,
        public ?EventColourRule $colourRule = null,
    ) {
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
        $this->todo = MeetingTodo::for($event);
        $this->displayTitle = MeetingTodo::displayTitleFor($event, $this->todo);

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
