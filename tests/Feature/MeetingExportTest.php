<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use App\Models\MeetingTranscript;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingExportTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00', 'Europe/Rome'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                @chmod($file, 0644);
                unlink($file);
            }

            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function configureDirectory(): string
    {
        $this->tempDir = realpath(sys_get_temp_dir()).'/kbms-export-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        config(['kbms.transcripts_path' => $this->tempDir]);

        return $this->tempDir;
    }

    private function seedFile(string $name, string $contents): string
    {
        $path = "{$this->tempDir}/{$name}";
        file_put_contents($path, $contents);

        return $path;
    }

    private function eventAt(string $startsAt, string $summary = 'Q4 roadmap review', bool $cancelled = false): CalendarEvent
    {
        return CalendarEvent::factory()
            ->when($cancelled, fn ($factory) => $factory->cancelled())
            ->create([
                'summary' => $summary,
                'starts_at' => $startsAt,
                'ends_at' => Carbon::parse($startsAt)->addMinutes(30),
            ]);
    }

    private function linkTranscript(CalendarEvent $event, string $path): MeetingTranscript
    {
        return MeetingTranscript::factory()->forOccurrence($event)->manual()->create(['path' => $path]);
    }

    // --- Note print ---------------------------------------------------

    public function test_note_print_renders_the_meeting_header_and_body(): void
    {
        $event = $this->eventAt('2026-09-09 09:30:00', 'Q4 roadmap review');
        MeetingNote::factory()->forOccurrence($event)->create(['body' => "# Hello\n\n**bold** content"]);

        $this->get(route('meetings.note.print', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('Q4 roadmap review')
            ->assertSee('9 Sep 2026', false)
            ->assertSee('<h1>Hello</h1>', false)
            ->assertSee('<strong>bold</strong>', false);
    }

    public function test_note_print_is_unavailable_with_no_row(): void
    {
        $event = $this->eventAt('2026-09-09 09:30:00');

        $this->get(route('meetings.note.print', $event->occurrenceKey()->toRouteKey()))
            ->assertNotFound()
            ->assertSee('This note is empty. There is nothing to share.');
    }

    public function test_note_print_is_unavailable_with_a_blank_body(): void
    {
        $event = $this->eventAt('2026-09-09 09:30:00');
        MeetingNote::factory()->forOccurrence($event)->create(['body' => '   ']);

        $this->get(route('meetings.note.print', $event->occurrenceKey()->toRouteKey()))
            ->assertNotFound()
            ->assertSee('This note is empty. There is nothing to share.');
    }

    public function test_note_print_never_shows_a_siblings_content(): void
    {
        $first = CalendarEvent::factory()->occurrenceOf('series-uid', 'r1')->at(Carbon::parse('2026-09-09 09:30:00'))->create();
        $second = CalendarEvent::factory()->occurrenceOf('series-uid', 'r2')->at(Carbon::parse('2026-09-16 09:30:00'))->create();
        MeetingNote::factory()->forOccurrence($first)->create(['body' => 'Only for the first occurrence']);

        $this->get(route('meetings.note.print', $second->occurrenceKey()->toRouteKey()))
            ->assertNotFound()
            ->assertDontSee('Only for the first occurrence');

        $this->get(route('meetings.note.print', $first->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('Only for the first occurrence');
    }

    public function test_a_cancelled_occurrence_still_exports_its_note(): void
    {
        $event = $this->eventAt('2026-09-09 09:30:00', cancelled: true);
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'Note on a cancelled occurrence']);

        $this->get(route('meetings.note.print', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('Note on a cancelled occurrence');
    }

    // --- Transcript print -----------------------------------------------

    public function test_transcript_print_renders_header_datetime_and_source_filename(): void
    {
        $this->configureDirectory();
        $path = $this->seedFile('protocol.md', '# Transcript body');
        $event = $this->eventAt('2026-09-09 09:30:00', 'Q4 roadmap review');
        $this->linkTranscript($event, $path);

        $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('Q4 roadmap review')
            ->assertSee('9 Sep 2026', false)
            ->assertSee('protocol.md')
            ->assertSee('<h1>Transcript body</h1>', false);
    }

    public function test_transcript_print_renders_txt_as_escaped_monospace(): void
    {
        $this->configureDirectory();
        $path = $this->seedFile('protocol.txt', '# Not a heading, **not bold**');
        $event = $this->eventAt('2026-09-09 09:30:00');
        $this->linkTranscript($event, $path);

        $response = $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertOk();

        $response->assertDontSee('<strong>', false);
        $response->assertSee('kb-print--mono', false);
        $response->assertSee('# Not a heading, **not bold**');
    }

    public function test_transcript_print_renders_a_file_past_the_preview_bound_complete(): void
    {
        config(['kbms.transcript_preview_bytes' => 10]);
        $this->configureDirectory();
        $tail = 'THE-VERY-END-OF-THE-TRANSCRIPT';
        $path = $this->seedFile('big.txt', str_repeat('a', 200).$tail);
        $event = $this->eventAt('2026-09-09 09:30:00');
        $this->linkTranscript($event, $path);

        $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee($tail);
    }

    public function test_transcript_print_is_unavailable_when_no_row_exists(): void
    {
        $this->configureDirectory();
        $event = $this->eventAt('2026-09-09 09:30:00');

        $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertNotFound()
            ->assertSee('No transcript is linked to this meeting.');
    }

    public function test_transcript_print_is_unavailable_for_the_manual_unlink_tombstone(): void
    {
        $this->configureDirectory();
        $event = $this->eventAt('2026-09-09 09:30:00');
        MeetingTranscript::factory()->forOccurrence($event)->suppressed()->create();

        $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertNotFound()
            ->assertSee('The transcript link was cleared for this meeting. There is nothing to export.');
    }

    public function test_transcript_print_reverifies_at_request_time_when_the_file_is_deleted_after_load(): void
    {
        $this->configureDirectory();
        $path = $this->seedFile('protocol.md', '# Body');
        $event = $this->eventAt('2026-09-09 09:30:00');
        $this->linkTranscript($event, $path);

        unlink($path);

        $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertNotFound()
            ->assertSee('The linked transcript file no longer exists.');
    }

    public function test_transcript_print_is_unavailable_for_an_unreadable_file(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable permissions running as root.');
        }

        $this->configureDirectory();
        $path = $this->seedFile('locked.md', 'secret');
        chmod($path, 0000);
        $event = $this->eventAt('2026-09-09 09:30:00');
        $this->linkTranscript($event, $path);

        $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertNotFound()
            ->assertSee('The linked transcript file exists but cannot be read.');
    }

    public function test_transcript_print_is_unavailable_for_a_path_escaping_the_base(): void
    {
        $this->configureDirectory();
        $outsideDir = realpath(sys_get_temp_dir()).'/kbms-export-outside-'.uniqid();
        mkdir($outsideDir, 0755, true);
        $outside = $outsideDir.'/outside.md';
        file_put_contents($outside, 'x');
        $event = $this->eventAt('2026-09-09 09:30:00');
        $this->linkTranscript($event, $outside);

        $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertNotFound()
            ->assertSee('The linked transcript path is outside the configured transcripts directory and is refused.');

        unlink($outside);
        rmdir($outsideDir);
    }

    public function test_transcript_print_never_shows_a_siblings_content(): void
    {
        $this->configureDirectory();
        $firstPath = $this->seedFile('first.md', 'First occurrence transcript');
        $secondPath = $this->seedFile('second.md', 'Second occurrence transcript');

        $first = CalendarEvent::factory()->occurrenceOf('series-uid', 'r1')->at(Carbon::parse('2026-09-09 09:30:00'))->create();
        $second = CalendarEvent::factory()->occurrenceOf('series-uid', 'r2')->at(Carbon::parse('2026-09-16 09:30:00'))->create();
        $this->linkTranscript($first, $firstPath);
        $this->linkTranscript($second, $secondPath);

        $this->get(route('meetings.transcript.print', $first->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('First occurrence transcript')
            ->assertDontSee('Second occurrence transcript');

        $this->get(route('meetings.transcript.print', $second->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('Second occurrence transcript')
            ->assertDontSee('First occurrence transcript');
    }

    public function test_a_cancelled_occurrence_still_exports_its_transcript(): void
    {
        $this->configureDirectory();
        $path = $this->seedFile('protocol.md', 'Transcript on a cancelled occurrence');
        $event = $this->eventAt('2026-09-09 09:30:00', cancelled: true);
        $this->linkTranscript($event, $path);

        $this->get(route('meetings.transcript.print', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('Transcript on a cancelled occurrence');
    }

    // --- Raw source route (clipboard) ------------------------------------

    public function test_source_route_is_byte_for_byte_identical_with_the_plain_text_content_type(): void
    {
        $this->configureDirectory();
        $body = "Line one\nLine two with **markdown-looking** syntax\n";
        $path = $this->seedFile('protocol.md', $body);
        $event = $this->eventAt('2026-09-09 09:30:00');
        $this->linkTranscript($event, $path);

        $response = $this->get(route('meetings.transcript.source', $event->occurrenceKey()->toRouteKey()))
            ->assertOk();

        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertSame($body, $response->streamedContent());
    }

    public function test_source_route_serves_a_file_past_the_preview_bound_complete(): void
    {
        config(['kbms.transcript_preview_bytes' => 10]);
        $this->configureDirectory();
        $body = str_repeat('a', 5000);
        $path = $this->seedFile('big.md', $body);
        $event = $this->eventAt('2026-09-09 09:30:00');
        $this->linkTranscript($event, $path);

        $response = $this->get(route('meetings.transcript.source', $event->occurrenceKey()->toRouteKey()))
            ->assertOk();

        $this->assertSame($body, $response->streamedContent());
    }

    public function test_source_route_refuses_with_its_own_reason_for_each_state(): void
    {
        $this->configureDirectory();
        $event = $this->eventAt('2026-09-09 09:30:00');

        $missing = $this->get(route('meetings.transcript.source', $event->occurrenceKey()->toRouteKey()));
        $missing->assertNotFound();
        $missing->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertSame('No transcript is linked to this meeting.', $missing->getContent());

        MeetingTranscript::factory()->forOccurrence($event)->suppressed()->create();
        $suppressed = $this->get(route('meetings.transcript.source', $event->occurrenceKey()->toRouteKey()));
        $suppressed->assertNotFound();
        $this->assertSame(
            'The transcript link was cleared for this meeting. There is nothing to export.',
            $suppressed->getContent()
        );
    }

    public function test_source_route_reverifies_at_request_time_when_the_file_is_gone(): void
    {
        $this->configureDirectory();
        $path = $this->seedFile('protocol.md', 'x');
        $event = $this->eventAt('2026-09-09 09:30:00');
        $this->linkTranscript($event, $path);

        unlink($path);

        $response = $this->get(route('meetings.transcript.source', $event->occurrenceKey()->toRouteKey()));
        $response->assertNotFound();
        $this->assertSame('The linked transcript file no longer exists.', $response->getContent());
    }
}
