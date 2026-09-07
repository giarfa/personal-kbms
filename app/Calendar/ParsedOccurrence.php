<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;

final readonly class ParsedOccurrence
{
    /**
     * @param  array<int, array{name: ?string, email: ?string}>  $attendees
     */
    public function __construct(
        public string $sourceUid,
        public string $recurrenceId,
        public ?string $summary,
        public ?string $description,
        public ?string $location,
        public ?string $organizer,
        public array $attendees,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $isAllDay,
        public ?string $timezone,
        public ?string $joinUrl,
        public ?string $eventUrl,
        public bool $isCancelled,
    ) {}

    /**
     * Sha-256 over the normalized user-visible fields. `last_seen_at` is
     * deliberately excluded and attendees are sorted, so the hash does not
     * flap between runs that carry no real change.
     */
    public function contentHash(): string
    {
        $attendees = $this->attendees;
        usort($attendees, fn (array $a, array $b) => [$a['email'], $a['name']] <=> [$b['email'], $b['name']]);

        return hash('sha256', json_encode([
            'summary' => $this->summary,
            'description' => $this->description,
            'location' => $this->location,
            'organizer' => $this->organizer,
            'attendees' => $attendees,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'is_all_day' => $this->isAllDay,
            'timezone' => $this->timezone,
            'join_url' => $this->joinUrl,
            'event_url' => $this->eventUrl,
            'is_cancelled' => $this->isCancelled,
        ]));
    }
}
