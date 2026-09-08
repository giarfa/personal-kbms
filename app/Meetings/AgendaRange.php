<?php

namespace App\Meetings;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A date-range window over the agenda, anchored on a local calendar date.
 */
final readonly class AgendaRange
{
    public function __construct(public CarbonImmutable $anchor, public int $days = 7) {}

    public static function today(int $days = 7): self
    {
        return new self(CarbonImmutable::now(config('kbms.timezone'))->startOfDay(), $days);
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
        return new self(CarbonImmutable::parse($date)->startOfDay(), $this->days);
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
