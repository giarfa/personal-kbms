<?php

namespace App\Meetings;

/**
 * The three calendar reading surfaces. The only place they are named — every
 * other layer (route params, FullCalendar config, labels) maps through here.
 */
enum CalendarView: string
{
    case Month = 'month';
    case Week = 'week';
    case Day = 'day';

    /**
     * The FullCalendar MIT-core view id this case renders as.
     */
    public function fullCalendarView(): string
    {
        return match ($this) {
            self::Month => 'dayGridMonth',
            self::Week => 'timeGridWeek',
            self::Day => 'timeGridDay',
        };
    }

    /**
     * `?view=` is a hand-editable, bookmarkable surface: anything unrecognised
     * — including a non-string shape such as `?view[]=x` — degrades to Month
     * rather than throwing (US-005 review precedent).
     */
    public static function fromRequest(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Month) : self::Month;
    }
}
