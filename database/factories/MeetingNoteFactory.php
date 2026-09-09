<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MeetingNote>
 */
class MeetingNoteFactory extends Factory
{
    protected $model = MeetingNote::class;

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
            'body' => <<<'MD'
                ## Prep

                - Ingestion slippage: two weeks, root cause is the recurrence expansion rewrite
                - Push back on adding Q1 scope before the mirror is trustworthy

                ## Outcomes

                - **Decision:** hold Q1 scope until sync health is green for two consecutive weeks
                - Chiara owns the hiring plan sign-off, due Fri
                - [ ] Follow up with Sara on the transcript naming convention
                MD,
        ];
    }

    /**
     * A note addressed to the given occurrence's natural key.
     */
    public function forOccurrence(CalendarEvent $event): static
    {
        return $this->state(fn (array $attributes) => [
            'event_uid' => $event->source_uid,
            'event_recurrence_id' => $event->recurrence_id,
        ]);
    }

    /**
     * A note row with an empty body — present but not "annotated".
     */
    public function blank(): static
    {
        return $this->state(fn (array $attributes) => [
            'body' => '',
        ]);
    }
}
