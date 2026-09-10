<?php

namespace Tests\Feature;

use App\Calendar\EventSynchronizer;
use App\Calendar\ParsedOccurrence;
use App\Meetings\OccurrenceKey;
use App\Models\CalendarEvent;
use App\Models\PromptLaunch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptLaunchDurabilityTest extends TestCase
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
            'sourceUid' => 'durability-uid',
            'recurrenceId' => '',
            'summary' => 'Weekly sync',
            'description' => null,
            'location' => 'Room A',
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

    public function test_a_launch_survives_a_retitle_reschedule_and_relocation_and_stays_at_the_same_route(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        PromptLaunch::factory()->forOccurrence($event)->launched()->create(['question' => 'preserved question']);
        $routeKey = $event->occurrenceKey()->toRouteKey();

        $synchronizer->synchronize([
            $this->occurrence([
                'summary' => 'Weekly sync (retitled)',
                'location' => 'Room B',
                'startsAt' => $this->runStartedAt->addDays(2),
                'endsAt' => $this->runStartedAt->addDays(2)->addMinutes(30),
            ]),
        ], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $resyncedEvent = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();

        $this->assertSame('Weekly sync (retitled)', $resyncedEvent->summary);
        $this->assertSame($routeKey, $resyncedEvent->occurrenceKey()->toRouteKey());

        $launch = PromptLaunch::query()->forOccurrence(new OccurrenceKey('durability-uid', ''))->first();
        $this->assertNotNull($launch);
        $this->assertSame('preserved question', $launch->question);
    }

    public function test_a_launch_survives_upstream_cancellation(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        PromptLaunch::factory()->forOccurrence($event)->launched()->create();

        $synchronizer->synchronize([
            $this->occurrence(['isCancelled' => true]),
        ], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $cancelledEvent = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        $this->assertNotNull($cancelledEvent->cancelled_at);

        $launch = PromptLaunch::query()->forOccurrence(new OccurrenceKey('durability-uid', ''))->first();
        $this->assertNotNull($launch);
    }

    public function test_a_launch_survives_outright_deletion_of_the_calendar_events_row_and_reattaches(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        PromptLaunch::factory()->forOccurrence($event)->launched()->create();

        // Nothing in EventSynchronizer ever deletes a row — this simulates
        // the edge case directly, proving the launch has no foreign-key
        // dependency on calendar_events.id.
        CalendarEvent::query()->where('source_uid', 'durability-uid')->delete();
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('prompt_launches', 1);

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt->addMinute(),
            $this->windowStart,
            $this->windowEnd
        );

        $reattachedEvent = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        $this->assertNotNull($reattachedEvent);
        $this->assertNotSame($event->id, $reattachedEvent->id);

        $launch = PromptLaunch::query()->forOccurrence($reattachedEvent->occurrenceKey())->first();
        $this->assertNotNull($launch);
    }
}
