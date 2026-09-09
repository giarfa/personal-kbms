<?php

namespace Tests\Feature;

use App\Calendar\EventSynchronizer;
use App\Calendar\ParsedOccurrence;
use App\Meetings\OccurrenceKey;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingNoteDurabilityTest extends TestCase
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

    public function test_a_note_survives_a_retitle_reschedule_and_relocation_and_stays_at_the_same_route(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'important prep notes']);
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

        $note = MeetingNote::query()->forOccurrence(new OccurrenceKey('durability-uid', ''))->first();
        $this->assertNotNull($note);
        $this->assertSame('important prep notes', $note->body);
    }

    public function test_a_note_survives_upstream_cancellation_and_stays_editable(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'notes on a cancelled meeting']);

        $synchronizer->synchronize([
            $this->occurrence(['isCancelled' => true]),
        ], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $cancelledEvent = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        $this->assertNotNull($cancelledEvent->cancelled_at);

        $note = MeetingNote::query()->forOccurrence(new OccurrenceKey('durability-uid', ''))->first();
        $this->assertNotNull($note);
        $this->assertSame('notes on a cancelled meeting', $note->body);
    }

    public function test_a_note_survives_the_disappearance_sweep(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'orphaned by disappearance']);

        // A run that no longer carries this occurrence at all — the sweep
        // cancels the row, it is never deleted.
        $synchronizer->synchronize([], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $sweptEvent = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        $this->assertNotNull($sweptEvent);
        $this->assertNotNull($sweptEvent->cancelled_at);

        $note = MeetingNote::query()->forOccurrence(new OccurrenceKey('durability-uid', ''))->first();
        $this->assertNotNull($note);
        $this->assertSame('orphaned by disappearance', $note->body);
    }

    public function test_a_note_survives_outright_deletion_of_the_calendar_events_row_and_reattaches(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'never depends on the surrogate id']);

        // Nothing in EventSynchronizer ever deletes a row — this simulates the
        // edge case directly, proving the note has no foreign-key dependency
        // on calendar_events.id.
        CalendarEvent::query()->where('source_uid', 'durability-uid')->delete();
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('meeting_notes', 1);

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt->addMinute(),
            $this->windowStart,
            $this->windowEnd
        );

        $reattachedEvent = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        $this->assertNotNull($reattachedEvent);
        $this->assertNotSame($event->id, $reattachedEvent->id);

        $note = MeetingNote::query()->forOccurrence($reattachedEvent->occurrenceKey())->first();
        $this->assertNotNull($note);
        $this->assertSame('never depends on the surrogate id', $note->body);
    }
}
