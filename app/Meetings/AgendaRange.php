<?php

namespace App\Meetings;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A date-range window over the agenda, anchored on a local calendar date.
 */
final readonly class AgendaRange
{
    public CarbonImmutable $anchor;

    public function __construct(CarbonImmutable $anchor, public int $days = 7)
    {
        // Structural, not cosmetic: every consumer treats the anchor as a calendar
        // day, and AgendaQuery's no-drop guarantee depends on it. A time component
        // shifts the window's exclusive end off the day boundary, so a row the
        // overlap predicate already fetched gets grouped outside dates() and
        // disappears — the same defect the day-one clamp exists to prevent.
        $this->anchor = $anchor->startOfDay();
    }

    public static function today(int $days = 7): self
    {
        return new self(CarbonImmutable::now(config('kbms.timezone')), $days);
    }

    public function previous(): self
    {
        return new self($this->anchor->subDays($this->days), $this->days);
    }

    public function next(): self
    {
        return new self($this->anchor->addDays($this->days), $this->days);
    }

    public function jumpTo(CarbonInterface $date): self
    {
        return new self(CarbonImmutable::parse($date), $this->days);
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function dates(): array
    {
        return array_map(
            fn (int $offset): CarbonImmutable => $this->anchor->addDays($offset),
            range(0, $this->days - 1),
        );
    }

    /**
     * The exclusive end of the window (the first date no longer included).
     */
    public function endsAt(): CarbonImmutable
    {
        return $this->anchor->addDays($this->days);
    }

    public function label(): string
    {
        $end = $this->anchor->addDays($this->days - 1);

        return match (true) {
            $this->anchor->isSameMonth($end) => $this->anchor->format('D j').' – '.$end->format('D j M Y'),
            $this->anchor->isSameYear($end) => $this->anchor->format('D j M').' – '.$end->format('D j M Y'),
            default => $this->anchor->format('D j M Y').' – '.$end->format('D j M Y'),
        };
    }
}
