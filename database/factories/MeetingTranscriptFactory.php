<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptLinkSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MeetingTranscript>
 */
class MeetingTranscriptFactory extends Factory
{
    protected $model = MeetingTranscript::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_uid' => (string) Str::uuid(),
            'event_recurrence_id' => '',
            'path' => storage_path('app/transcripts/'.fake()->slug().'.md'),
            'link_source' => TranscriptLinkSource::Convention,
            'file_size' => fake()->numberBetween(500, 40000),
            'file_mtime' => now(),
            'linked_at' => now(),
        ];
    }

    /**
     * A transcript addressed to the given occurrence's natural key.
     */
    public function forOccurrence(CalendarEvent $event): static
    {
        return $this->state(fn (array $attributes) => [
            'event_uid' => $event->source_uid,
            'event_recurrence_id' => $event->recurrence_id,
        ]);
    }

    /**
     * Linked by the naming convention (the default `link_source`, made explicit here).
     */
    public function convention(): static
    {
        return $this->state(fn (array $attributes) => [
            'link_source' => TranscriptLinkSource::Convention,
        ]);
    }

    /**
     * The operator's own choice — always wins over the convention.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'link_source' => TranscriptLinkSource::Manual,
        ]);
    }

    /**
     * The manual-unlink tombstone: `path` is NULL, stopping the convention
     * from re-linking a file the operator just rejected.
     */
    public function suppressed(): static
    {
        return $this->state(fn (array $attributes) => [
            'link_source' => TranscriptLinkSource::Manual,
            'path' => null,
            'file_size' => null,
            'file_mtime' => null,
            'linked_at' => null,
        ]);
    }

    /**
     * A convention row whose file no longer exists — the row is kept
     * rather than silently re-resolved.
     */
    public function broken(): static
    {
        return $this->state(fn (array $attributes) => [
            'link_source' => TranscriptLinkSource::Convention,
            'path' => storage_path('app/transcripts/'.fake()->slug().'-gone.md'),
        ]);
    }
}
