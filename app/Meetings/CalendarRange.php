<?php

namespace App\Meetings;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;

/**
 * The visible local-date window for one of the three calendar views,
 * anchored on a local calendar date. Mirrors AgendaRange's shape: a pure
 * value type with no database access.
 */
final readonly class CalendarRange
{
    public CarbonImmutable $anchor;

    public function __construct(public CalendarView $view, CarbonImmutable $anchor)
    {
        // Same structural reason as AgendaRange: every consumer (and the
        // window math below) treats the anchor as a calendar day.
        $this->anchor = $anchor->startOfDay();
    }

    public static function today(CalendarView $view): self
    {
        return new self($view, CarbonImmutable::now(config('kbms.timezone')));
    }

    /**
     * `?view=` and `?date=` are hand-editable, bookmarkable query-string
     * values: either can be missing, unknown, malformed, or even a non-string
     * shape (`?date[]=x`), and this must degrade to today's month rather than
     * throw (Agenda::anchor() precedent).
     */
    public static function fromRequest(mixed $view, mixed $date): self
    {
        $resolvedView = CalendarView::fromRequest($view);

        if (! is_string($date)) {
            return self::today($resolvedView);
        }

        try {
            return new self($resolvedView, CarbonImmutable::parse($date)->startOfDay());
        } catch (Exception) {
            return self::today($resolvedView);
        }
    }

    /**
     * The first visible local date (inclusive).
     */
    public function visibleStart(): CarbonImmutable
    {
        return match ($this->view) {
            CalendarView::Month => $this->anchor->startOfMonth()->startOfWeek(CarbonInterface::MONDAY),
            CalendarView::Week => $this->anchor->startOfWeek(CarbonInterface::MONDAY),
            CalendarView::Day => $this->anchor,
        };
    }

    /**
     * The first local date no longer visible (exclusive).
     */
    public function visibleEnd(): CarbonImmutable
    {
        return match ($this->view) {
            // A whole number of weeks: FullCalendar paints the leading and
            // trailing days of the neighbouring months to fill the grid.
            // endOfWeek($day) treats $day as the end-of-week day itself (not
            // the start), so endOfWeek(MONDAY) already lands on the Monday
            // that is the grid's exclusive end — no further +1 day needed.
            CalendarView::Month => $this->anchor->endOfMonth()->endOfWeek(CarbonInterface::MONDAY)->startOfDay(),
            CalendarView::Week => $this->anchor->startOfWeek(CarbonInterface::MONDAY)->addDays(7),
            CalendarView::Day => $this->anchor->addDay(),
        };
    }

    public function previous(): self
    {
        return new self($this->view, match ($this->view) {
            CalendarView::Month => $this->anchor->startOfMonth()->subMonth(),
            CalendarView::Week => $this->anchor->subDays(7),
            CalendarView::Day => $this->anchor->subDays(1),
        });
    }

    public function next(): self
    {
        return new self($this->view, match ($this->view) {
            CalendarView::Month => $this->anchor->startOfMonth()->addMonth(),
            CalendarView::Week => $this->anchor->addDays(7),
            CalendarView::Day => $this->anchor->addDays(1),
        });
    }

    public function label(): string
    {
        return match ($this->view) {
            CalendarView::Month => $this->anchor->format('F Y'),
            CalendarView::Week => $this->weekLabel(),
            CalendarView::Day => $this->anchor->format('l j F Y'),
        };
    }

    private function weekLabel(): string
    {
        $start = $this->visibleStart();
        $end = $this->visibleEnd()->subDay();

        return $start->isSameMonth($end)
            ? $start->format('j').' – '.$end->format('j F Y')
            : $start->format('j M').' – '.$end->format('j M Y');
    }
}
