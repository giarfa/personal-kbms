<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;

/**
 * One direction's outcome for the series navigation on the meeting detail
 * page (US-018): the adjacent occurrence, or the stated reason there is none.
 *
 * Deliberately the same three-way shape as `OutlookLink` — a link, or an
 * absence carrying its own reason, never a broken `href`. That is what lets
 * the view reuse the "Open in Outlook" disabled-CTA markup instead of
 * inventing a second vocabulary for "offered but inert".
 *
 * Labels and the accessible name are computed here rather than in Blade, the
 * same division `AgendaRow` already establishes: the view is a renderer.
 */
final readonly class SeriesNeighbour
{
    private function __construct(
        public SeriesDirection $direction,
        public ?CalendarEvent $event,
        private ?int $referenceYear,
    ) {}

    /**
     * The mirror holds an occurrence in this direction. `$referenceYear` is
     * the year of the occurrence being navigated *from*, so the label can stay
     * short in the usual case and disambiguate when a series crosses New Year.
     */
    public static function at(SeriesDirection $direction, CalendarEvent $event, int $referenceYear): self
    {
        return new self($direction, $event, $referenceYear);
    }

    /**
     * The mirror holds nothing in this direction. This is a statement about
     * the stored window, never about the series having begun or ended — the
     * two are indistinguishable from the data, and only one of them is true.
     */
    public static function none(SeriesDirection $direction): self
    {
        return new self($direction, null, null);
    }

    public function isAvailable(): bool
    {
        return $this->event !== null;
    }

    public function routeKey(): ?string
    {
        return $this->event?->occurrenceKey()->toRouteKey();
    }

    /**
     * The neighbour's own date and time — what makes the control worth
     * clicking, since "previous" alone says nothing about where it leads.
     */
    public function label(): string
    {
        if ($this->event === null) {
            return '';
        }

        $start = CarbonImmutable::instance($this->event->starts_at);

        $date = $start->year === $this->referenceYear
            ? $start->format('D j M')
            : $start->format('D j M Y');

        return $this->event->is_all_day
            ? $date.' · '.__('all day')
            : $date.' '.$start->format('H:i');
    }

    /**
     * Direction *and* destination, plus the new-tab announcement: a screen
     * reader hearing only "previous" would have to activate the link to find
     * out where it goes, and would then have lost the page it started from.
     */
    public function accessibleName(): string
    {
        $replacements = ['when' => $this->label()];

        return $this->direction === SeriesDirection::Previous
            ? __('Previous occurrence, :when (opens in a new tab)', $replacements)
            : __('Next occurrence, :when (opens in a new tab)', $replacements);
    }

    /**
     * Why the control is inert. Speaks about the mirror only.
     */
    public function reason(): ?string
    {
        if ($this->event !== null) {
            return null;
        }

        return $this->direction === SeriesDirection::Previous
            ? __('The mirror holds no earlier occurrence of this series.')
            : __('The mirror holds no later occurrence of this series.');
    }
}
