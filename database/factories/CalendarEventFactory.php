<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    /**
     * @var list<string>
     */
    private static array $meetingSummaries = [
        'Weekly sync', 'Roadmap review', 'Client kickoff', '1:1 check-in',
        'Sprint planning', 'Design review', 'Budget review', 'All-hands',
        'Architecture discussion', 'Retro',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('-30 days', '+45 days');
        $endsAt = (clone $startsAt)->modify('+30 minutes');

        return [
            'source_uid' => (string) Str::uuid(),
            'recurrence_id' => '',
            'summary' => fake()->randomElement(self::$meetingSummaries),
            'description' => fake()->sentence(12),
            'location' => fake()->boolean(50) ? fake()->city() : null,
            'organizer' => fake()->name().' <'.fake()->companyEmail().'>',
            'attendees' => [
                ['name' => fake()->name(), 'email' => fake()->safeEmail()],
                ['name' => fake()->name(), 'email' => fake()->safeEmail()],
            ],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_all_day' => false,
            'timezone' => 'Europe/Rome',
            'join_url' => null,
            'event_url' => null,
            'content_hash' => hash('sha256', Str::random(32)),
            'last_seen_at' => now(),
            'cancelled_at' => null,
        ];
    }

    /**
     * An all-day occurrence stored as a naive date, never timezone-converted.
     * `recurrence_id` stays whatever the base definition or `occurrenceOf()`
     * set — only a recurring all-day occurrence uses the Y-m-d form; a
     * standalone all-day event's recurrence_id is always ''.
     */
    public function allDay(): static
    {
        return $this->state(function (array $attributes) {
            $date = fake()->dateTimeBetween('-30 days', '+45 days')->format('Y-m-d');

            return [
                'starts_at' => $date.' 00:00:00',
                'ends_at' => $date.' 00:00:00',
                'is_all_day' => true,
                'timezone' => null,
            ];
        });
    }

    /**
     * A cancelled occurrence — the row still exists, only cancelled_at is set.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelled_at' => now(),
        ]);
    }

    /**
     * One occurrence of a recurring series sharing a source_uid.
     */
    public function occurrenceOf(string $sourceUid, string $recurrenceId): static
    {
        return $this->state(fn (array $attributes) => [
            'source_uid' => $sourceUid,
            'recurrence_id' => $recurrenceId,
        ]);
    }

    /**
     * A meeting carrying a Teams join link.
     */
    public function withTeamsLink(): static
    {
        return $this->state(fn (array $attributes) => [
            'join_url' => 'https://teams.microsoft.com/l/meetup-join/'.Str::random(20),
        ]);
    }

    /**
     * An occurrence with a stale last_seen_at, for cancellation-sweep tests.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_seen_at' => now()->subDays(2),
        ]);
    }

    /**
     * A meeting carrying a feed-provided Outlook Web Access link.
     */
    public function withEventUrl(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_url' => 'https://outlook.office.com/calendar/item/'.Str::random(20),
        ]);
    }

    /**
     * An all-day event spanning `$days` days, for the multi-day clamp path.
     */
    public function spanning(int $days): static
    {
        return $this->state(function (array $attributes) use ($days) {
            $date = fake()->dateTimeBetween('-30 days', '+45 days')->format('Y-m-d');

            return [
                'starts_at' => $date.' 00:00:00',
                'ends_at' => date('Y-m-d', strtotime($date.' +'.$days.' days')).' 00:00:00',
                'is_all_day' => true,
                'timezone' => null,
            ];
        });
    }

    /**
     * A timed meeting placed at a deterministic start time — random dates make agenda tests flaky.
     */
    public function at(CarbonInterface $start, int $minutes = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $start,
            'ends_at' => $start->clone()->addMinutes($minutes),
            'is_all_day' => false,
        ]);
    }
}
