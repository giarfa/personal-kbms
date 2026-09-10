<?php

namespace Tests\Feature;

use App\Calendar\EventSynchronizer;
use App\Calendar\ParsedOccurrence;
use App\Livewire\TranscriptPanel;
use App\Meetings\OccurrenceKey;
use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptLinkSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TranscriptDurabilityTest extends TestCase
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

    public function test_a_link_survives_a_retitle_reschedule_and_relocation_and_stays_at_the_same_route(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        MeetingTranscript::factory()->forOccurrence($event)->convention()->create();
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

        // The persisted path wins over re-derivation — the retitle changes
        // the slug the convention would now compute, but the stored link is
        // untouched: link_source is exactly what stops a slug mismatch from
        // mattering.
        $transcript = MeetingTranscript::query()->forOccurrence(new OccurrenceKey('durability-uid', ''))->first();
        $this->assertNotNull($transcript);
        $this->assertSame(TranscriptLinkSource::Convention, $transcript->link_source);
    }

    public function test_a_link_survives_upstream_cancellation(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        MeetingTranscript::factory()->forOccurrence($event)->convention()->create();

        $synchronizer->synchronize([
            $this->occurrence(['isCancelled' => true]),
        ], $this->runStartedAt->addMinute(), $this->windowStart, $this->windowEnd);

        $cancelledEvent = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        $this->assertNotNull($cancelledEvent->cancelled_at);

        $transcript = MeetingTranscript::query()->forOccurrence(new OccurrenceKey('durability-uid', ''))->first();
        $this->assertNotNull($transcript);
    }

    public function test_a_link_survives_outright_deletion_of_the_calendar_events_row_and_reattaches(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        MeetingTranscript::factory()->forOccurrence($event)->convention()->create();

        // Nothing in EventSynchronizer ever deletes a row — this simulates
        // the edge case directly, proving the transcript link has no
        // foreign-key dependency on calendar_events.id.
        CalendarEvent::query()->where('source_uid', 'durability-uid')->delete();
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('meeting_transcripts', 1);

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt->addMinute(),
            $this->windowStart,
            $this->windowEnd
        );

        $reattachedEvent = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();
        $this->assertNotNull($reattachedEvent);
        $this->assertNotSame($event->id, $reattachedEvent->id);

        $transcript = MeetingTranscript::query()->forOccurrence($reattachedEvent->occurrenceKey())->first();
        $this->assertNotNull($transcript);
    }

    public function test_changing_the_transcript_pattern_does_not_break_an_existing_persisted_link(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();

        $dir = realpath(sys_get_temp_dir()).'/kbms-durability-'.uniqid();
        mkdir($dir, 0755, true);
        config(['kbms.transcripts_path' => $dir]);
        // A filename matching the now-RETIRED hyphenated convention — no
        // pattern parses it anymore, yet the point is that resolution never
        // re-derives from the current pattern once a row exists
        // (readerViewData() reads the persisted path directly).
        $path = "{$dir}/2026-06-02-1200-weekly-sync.md";
        file_put_contents($path, '# Persisted link content');
        MeetingTranscript::factory()->forOccurrence($event)->convention()->create(['path' => $path]);

        // A pattern that would not even parse the file above.
        config(['kbms.transcript_pattern' => 'totally-different-{date}_{time}_{slug}-scheme']);

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('Linked by convention')
            ->assertSee('Persisted link content');

        unlink($path);
        rmdir($dir);
    }

    public function test_an_old_convention_persisted_link_still_resolves_as_linked_under_the_default_pattern(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();

        $dir = realpath(sys_get_temp_dir()).'/kbms-durability-'.uniqid();
        mkdir($dir, 0755, true);
        config(['kbms.transcripts_path' => $dir]);

        // A retired hyphenated filename, stored back when that was the
        // active convention. The *current default* pattern (Ymd/underscore)
        // cannot parse it either — the row's stored path is what carries it.
        $path = "{$dir}/2026-06-02-1200-weekly-sync.md";
        file_put_contents($path, '# Persisted link content');
        MeetingTranscript::factory()->forOccurrence($event)->convention()->create(['path' => $path]);

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('Linked by convention')
            ->assertSee('Persisted link content');

        unlink($path);
        rmdir($dir);
    }

    public function test_a_persisted_txt_link_still_resolves_as_linked_and_renders_as_plain_text_under_md_only_config(): void
    {
        $synchronizer = new EventSynchronizer;

        $synchronizer->synchronize(
            [$this->occurrence()],
            $this->runStartedAt,
            $this->windowStart,
            $this->windowEnd
        );

        $event = CalendarEvent::query()->where('source_uid', 'durability-uid')->first();

        $dir = realpath(sys_get_temp_dir()).'/kbms-durability-'.uniqid();
        mkdir($dir, 0755, true);
        config(['kbms.transcripts_path' => $dir]);

        // Convention resolution is md-only, but an already-linked .txt row
        // must keep working — the stored path is read directly, never
        // re-derived from the (now-restricted) directory index.
        $path = "{$dir}/2026-06-02-1200-weekly-sync.txt";
        file_put_contents($path, 'plain text body');
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create(['path' => $path]);

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('.txt rendered as plain text');

        unlink($path);
        rmdir($dir);
    }
}
