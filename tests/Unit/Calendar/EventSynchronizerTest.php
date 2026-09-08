<?php

namespace Tests\Unit\Calendar;

use App\Calendar\EventSynchronizer;
use App\Calendar\ParsedOccurrence;
use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EventSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $windowStart;

    private CarbonImmutable $windowEnd;

    private CarbonImmutable $runStartedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runStartedAt = CarbonImmutable::parse('2026-06-01 12:00:00');
        $this->windowStart = $this->runStartedAt->subDays(90);
        $this->windowEnd = $this->runStartedAt->addDays(180);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function occurrence(array $overrides = []): ParsedOccurrence
    {
        $defaults = [
            'sourceUid' => 'uid-1',
            'recurrenceId' => '',
            'summary' => 'Weekly sync',
            'description' => null,
            'location' => null,
            'organizer' => null,
            'attendees' => [],
            'startsAt' => $this->runStartedAt->addDay(),
            'endsAt' => $this->runStartedAt->addDay()->addMinutes(30),
            'isAllDay' => false,
            'timezone' => 'Europe/Rome',
            'joinUrl' => null,
            'eventUrl' => null,
            'isCancelled' => false,
        ];

        $args = [...$defaults, ...$overrides];

        return new ParsedOccurrence(...$args);
    }

    public function test_two_occurrences_of_one_series_are_independently_addressable(): void
    {
        $synchronizer = new EventSynchronizer;

        $occurrences = [
            $this->occurrence(['sourceUid' => 'series-1', 'recurrenceId' => 'r1', 'summary' => 'First']),
            $this->occurrence(['sourceUid' => 'series-1', 'recurrenceId' => 'r2', 'summary' => 'Second']),
        ];

        $synchronizer->synchronize($occurrences, $this->runStartedAt, $this->windowStart, $this->windowEnd);

        $this->assertDatabaseCount('calendar_events', 2);

        $synchronizer->synchronize([
            $this->occurrence(['sourceUid' => 'series-1', 'recurrenceId' => 'r1', 'summary' => 'First (edited)']),
            $this->occurrence(['sourceUid' => 'series-1', 'recurrenceId' => 'r2', 'summary' => 'Second']),
        ], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $this->assertDatabaseCount('calendar_events', 2);
        $this->assertSame('First (edited)', CalendarEvent::query()->where('recurrence_id', 'r1')->first()->summary);
        $this->assertSame('Second', CalendarEvent::query()->where('recurrence_id', 'r2')->first()->summary);
    }

    public function test_a_non_recurring_event_round_trips_without_duplicating(): void
    {
        $synchronizer = new EventSynchronizer;

        $occurrence = $this->occurrence(['sourceUid' => 'standalone-1', 'recurrenceId' => '']);

        $synchronizer->synchronize([$occurrence], $this->runStartedAt, $this->windowStart, $this->windowEnd);
        $synchronizer->synchronize([$occurrence], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_an_unchanged_second_run_reports_zero_upserted_and_only_bumps_last_seen_at(): void
    {
        $synchronizer = new EventSynchronizer;
        $occurrence = $this->occurrence(['sourceUid' => 'unchanged-1']);

        $synchronizer->synchronize([$occurrence], $this->runStartedAt, $this->windowStart, $this->windowEnd);

        $result = $synchronizer->synchronize([$occurrence], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $this->assertSame(0, $result->upserted);

        $event = CalendarEvent::query()->where('source_uid', 'unchanged-1')->first();

        $this->assertSame(
            $this->runStartedAt->addMinute()->toDateTimeString(),
            CarbonImmutable::parse($event->last_seen_at)->toDateTimeString()
        );
    }

    public function test_a_changed_summary_reports_one_upserted_and_rewrites_the_row(): void
    {
        $synchronizer = new EventSynchronizer;
        $synchronizer->synchronize([
            $this->occurrence(['sourceUid' => 'changed-1', 'summary' => 'Original']),
        ], $this->runStartedAt, $this->windowStart, $this->windowEnd);

        $result = $synchronizer->synchronize([
            $this->occurrence(['sourceUid' => 'changed-1', 'summary' => 'Updated']),
        ], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $this->assertSame(1, $result->upserted);
        $this->assertSame('Updated', CalendarEvent::query()->where('source_uid', 'changed-1')->first()->summary);
    }

    public function test_an_occurrence_that_stops_appearing_is_cancelled_but_still_exists(): void
    {
        CalendarEvent::factory()->create([
            'source_uid' => 'disappearing-1',
            'recurrence_id' => '',
            'starts_at' => $this->windowStart->addDay(),
            'ends_at' => $this->windowStart->addDay()->addHour(),
            'last_seen_at' => $this->runStartedAt->subDay(),
        ]);

        $synchronizer = new EventSynchronizer;
        $synchronizer->synchronize([], $this->runStartedAt, $this->windowStart, $this->windowEnd);

        $this->assertDatabaseCount('calendar_events', 1);
        $event = CalendarEvent::query()->where('source_uid', 'disappearing-1')->first();
        $this->assertNotNull($event->cancelled_at);
    }

    public function test_an_event_outside_the_window_with_a_stale_last_seen_at_is_not_swept(): void
    {
        CalendarEvent::factory()->create([
            'source_uid' => 'outside-window-1',
            'recurrence_id' => '',
            'starts_at' => $this->windowStart->subDays(10),
            'ends_at' => $this->windowStart->subDays(10)->addHour(),
            'last_seen_at' => $this->runStartedAt->subDay(),
        ]);

        $synchronizer = new EventSynchronizer;
        $synchronizer->synchronize([], $this->runStartedAt, $this->windowStart, $this->windowEnd);

        $event = CalendarEvent::query()->where('source_uid', 'outside-window-1')->first();
        $this->assertNull($event->cancelled_at);
    }

    public function test_status_cancelled_marks_cancelled_at_on_the_upsert_path(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize([
            $this->occurrence(['sourceUid' => 'cancelled-on-arrival-1', 'isCancelled' => true]),
        ], $this->runStartedAt, $this->windowStart, $this->windowEnd);

        $event = CalendarEvent::query()->where('source_uid', 'cancelled-on-arrival-1')->first();
        $this->assertNotNull($event->cancelled_at);
    }

    public function test_a_cancelled_occurrence_reappearing_active_is_un_cancelled(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize([
            $this->occurrence(['sourceUid' => 'flapping-1', 'isCancelled' => true]),
        ], $this->runStartedAt, $this->windowStart, $this->windowEnd);

        $this->assertNotNull(CalendarEvent::query()->where('source_uid', 'flapping-1')->first()->cancelled_at);

        $synchronizer->synchronize([
            $this->occurrence(['sourceUid' => 'flapping-1', 'isCancelled' => false]),
        ], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $this->assertNull(CalendarEvent::query()->where('source_uid', 'flapping-1')->first()->cancelled_at);
    }

    public function test_a_mid_stream_throwable_rolls_back_the_whole_transaction(): void
    {
        CalendarEvent::factory()->create(['source_uid' => 'pre-existing-1', 'recurrence_id' => '', 'summary' => 'Untouched']);

        $synchronizer = new EventSynchronizer;

        $failingOccurrences = (function () {
            yield $this->occurrence(['sourceUid' => 'should-not-persist-1']);

            throw new RuntimeException('boom');
        })();

        try {
            $synchronizer->synchronize($failingOccurrences, $this->runStartedAt, $this->windowStart, $this->windowEnd);
            $this->fail('Expected the transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertDatabaseMissing('calendar_events', ['source_uid' => 'should-not-persist-1']);
        $this->assertSame('Untouched', CalendarEvent::query()->where('source_uid', 'pre-existing-1')->first()->summary);
    }
}
