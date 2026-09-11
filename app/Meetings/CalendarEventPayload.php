<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;

/**
 * The FullCalendar event object for one occurrence. `start`/`end` are naive
 * local ISO strings with no offset — see CalendarFeedQuery's docblock for why
 * that, not UTC, is the identity-preserving choice against wall-clock storage.
 */
final readonly class CalendarEventPayload
{
    /**
     * @param  list<string>  $classNames
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $start,
        public string $end,
        public bool $allDay,
        public string $url,
        public array $classNames,
        public bool $hasNotes,
        public bool $hasTranscript,
        public bool $cancelled,
        public string $accessibleName,
        public ?string $todoStatus = null,
    ) {}

    public static function from(CalendarEvent $event, MeetingCoverage $coverage, ?EventColourRule $colourRule = null): self
    {
        $start = CarbonImmutable::instance($event->starts_at);
        $end = CarbonImmutable::instance($event->ends_at);
        $isAllDay = (bool) $event->is_all_day;
        $isCancelled = $event->cancelled_at !== null;
        $todo = MeetingTodo::for($event);
        $displayTitle = MeetingTodo::displayTitleFor($event, $todo);

        return new self(
            id: $event->occurrenceKey()->toRouteKey(),
            // The marker is stripped from what the grid shows; the raw summary
            // is still rendered verbatim on the meeting detail page (US-013).
            title: $displayTitle,
            start: $isAllDay ? $start->format('Y-m-d') : $start->format('Y-m-d\TH:i:s'),
            end: $isAllDay ? $end->format('Y-m-d') : $end->format('Y-m-d\TH:i:s'),
            allDay: $isAllDay,
            url: route('meetings.show', $event->occurrenceKey()->toRouteKey()),
            classNames: self::classNames($coverage, $isAllDay, $isCancelled, $colourRule, $todo),
            hasNotes: $coverage->hasNotes,
            hasTranscript: $coverage->hasTranscript,
            cancelled: $isCancelled,
            accessibleName: self::accessibleName($displayTitle, $start, $isAllDay, $coverage, $isCancelled, $colourRule, $todo),
            todoStatus: $todo?->modifier(),
        );
    }

    /**
     * @return list<string>
     */
    private static function classNames(MeetingCoverage $coverage, bool $isAllDay, bool $isCancelled, ?EventColourRule $colourRule, ?MeetingTodo $todo): array
    {
        $classNames = match (true) {
            $coverage->hasNotes && $coverage->hasTranscript => ['kb-ev--both'],
            $coverage->hasNotes => ['kb-ev--note'],
            $coverage->hasTranscript => ['kb-ev--transcript'],
            default => [],
        };

        if ($isAllDay) {
            $classNames[] = 'kb-ev--allday';
        }

        if ($isCancelled) {
            $classNames[] = 'kb-ev--cancelled';
        }

        // The rule colour is a separate channel from coverage (US-012): the
        // stylesheet lets this class paint only the fill, so the coverage
        // classes above keep their edge borders on the same event.
        if ($colourRule !== null) {
            $classNames[] = 'kb-ev--colour-'.$colourRule->colour->value;
        }

        // A third independent channel (US-013): the stylesheet lets this class
        // paint only the glyph and the title, so the coverage borders above and
        // the rule fill both survive on the same event. One class picks one
        // glyph — overdue replaces open rather than stacking on it.
        if ($todo !== null) {
            $classNames[] = 'kb-ev--todo-'.$todo->modifier();
        }

        return $classNames;
    }

    private static function accessibleName(string $title, CarbonImmutable $start, bool $isAllDay, MeetingCoverage $coverage, bool $isCancelled, ?EventColourRule $colourRule, ?MeetingTodo $todo): string
    {
        $coverageWords = match (true) {
            $coverage->hasNotes && $coverage->hasTranscript => __('has notes and transcript'),
            $coverage->hasNotes => __('has notes'),
            $coverage->hasTranscript => __('has transcript'),
            default => __('not annotated'),
        };

        $prefix = $isAllDay
            ? __('All day').', '.$title
            : $start->format('H:i').' '.$title;

        $name = $prefix.', '.$coverageWords;

        // After the coverage words and before the cancelled suffix, so the
        // existing wording order is preserved and only extended (US-012).
        if ($colourRule !== null) {
            $name .= ', '.$colourRule->label;
        }

        // Status wording last before the cancelled suffix, so a reader gets
        // coverage, then classification, then status (US-013).
        if ($todo !== null) {
            $name .= ', '.$todo->statusLabel();
        }

        return $isCancelled ? $name.', '.__('cancelled') : $name;
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     start: string,
     *     end: string,
     *     allDay: bool,
     *     url: string,
     *     classNames: list<string>,
     *     extendedProps: array{hasNotes: bool, hasTranscript: bool, cancelled: bool, accessibleName: string, todoStatus: string|null},
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'start' => $this->start,
            'end' => $this->end,
            'allDay' => $this->allDay,
            'url' => $this->url,
            'classNames' => $this->classNames,
            'extendedProps' => [
                'hasNotes' => $this->hasNotes,
                'hasTranscript' => $this->hasTranscript,
                'cancelled' => $this->cancelled,
                'accessibleName' => $this->accessibleName,
                'todoStatus' => $this->todoStatus,
            ],
        ];
    }
}
