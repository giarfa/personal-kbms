<?php

namespace Database\Factories;

use App\Calendar\SyncRunStatus;
use App\Models\CalendarSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarSyncRun>
 */
class CalendarSyncRunFactory extends Factory
{
    protected $model = CalendarSyncRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-7 days', 'now');

        return [
            'started_at' => $startedAt,
            'finished_at' => (clone $startedAt)->modify('+2 seconds'),
            'status' => SyncRunStatus::Success,
            'http_status' => 200,
            'etag' => '"'.Str::random(16).'"',
            'last_modified' => fake()->dateTimeBetween('-8 days', $startedAt)->format(\DateTimeInterface::RFC7231),
            'events_upserted' => fake()->numberBetween(0, 20),
            'events_cancelled' => fake()->numberBetween(0, 3),
            'error' => null,
        ];
    }

    /**
     * A successful run.
     */
    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Success,
            'http_status' => 200,
        ]);
    }

    /**
     * A failed run with a legible error message.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Failed,
            'http_status' => fake()->randomElement([null, 500, 503]),
            'etag' => null,
            'last_modified' => null,
            'events_upserted' => 0,
            'events_cancelled' => 0,
            'error' => fake()->randomElement([
                'connection timed out after 30s',
                'feed responded with HTTP 500',
                'the calendar body is truncated: END:VCALENDAR is missing',
            ]),
        ]);
    }

    /**
     * A 304 Not Modified run.
     */
    public function notModified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::NotModified,
            'http_status' => 304,
            'events_upserted' => 0,
            'events_cancelled' => 0,
            'error' => null,
        ]);
    }

    /**
     * A run still in progress.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Running,
            'finished_at' => null,
            'http_status' => null,
            'events_upserted' => 0,
            'events_cancelled' => 0,
            'error' => null,
        ]);
    }

    /**
     * A `Running` row whose worker died before finishing it.
     */
    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Running,
            'started_at' => now()->subMinutes(10),
            'finished_at' => null,
            'http_status' => null,
            'events_upserted' => 0,
            'events_cancelled' => 0,
            'error' => null,
        ]);
    }

    /**
     * A successful run old enough to trip the stale multiplier.
     */
    public function stale(): static
    {
        $staleMinutes = (int) config('kbms.sync_minutes') * (int) config('kbms.sync_stale_multiplier') + 1;

        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Success,
            'started_at' => now()->subMinutes($staleMinutes),
            'finished_at' => now()->subMinutes($staleMinutes)->addSeconds(2),
            'http_status' => 200,
        ]);
    }
}
