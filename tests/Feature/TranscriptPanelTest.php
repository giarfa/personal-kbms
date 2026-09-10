<?php

namespace Tests\Feature;

use App\Livewire\TranscriptPanel;
use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptLinkSource;
use App\Transcripts\TranscriptPattern;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TranscriptPanelTest extends TestCase
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
        $this->tempDir = realpath(sys_get_temp_dir()).'/kbms-panel-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        config([
            'kbms.transcripts_path' => $this->tempDir,
            'kbms.transcript_pattern' => TranscriptPattern::DEFAULT_PATTERN,
            'kbms.transcript_tolerance_minutes' => 10,
        ]);

        return $this->tempDir;
    }

    private function seedFile(string $stem, string $extension, string $contents): string
    {
        $path = "{$this->tempDir}/{$stem}.{$extension}";
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
                'ends_at' => $startsAt,
            ]);
    }

    public function test_not_configured_renders_its_distinguishing_string(): void
    {
        config(['kbms.transcripts_path' => null]);
        $event = $this->eventAt('2026-09-09 09:30:00');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('Transcripts directory not configured');
    }

    public function test_missing_renders_its_distinguishing_string(): void
    {
        $this->configureDirectory();
        $event = $this->eventAt('2026-09-09 09:30:00', 'Nothing recorded');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('No transcript found for this meeting.');
    }

    public function test_ambiguous_renders_its_distinguishing_string_and_writes_nothing(): void
    {
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', 'a');
        $this->seedFile('20260909_0928_roadmap', 'md', 'b');
        $event = $this->eventAt('2026-09-09 09:30:00');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('Choose one');

        $this->assertDatabaseCount('meeting_transcripts', 0);
    }

    public function test_linked_renders_its_distinguishing_string_and_persists_link_source_convention(): void
    {
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', '# Hello');
        $event = $this->eventAt('2026-09-09 09:30:00');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('Linked by convention');

        $row = MeetingTranscript::query()->forOccurrence($event->occurrenceKey())->first();
        $this->assertNotNull($row);
        $this->assertSame(TranscriptLinkSource::Convention, $row->link_source);
    }

    public function test_a_second_render_does_not_write_again(): void
    {
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', '# Hello');
        $event = $this->eventAt('2026-09-09 09:30:00');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event]);
        $firstRow = MeetingTranscript::query()->forOccurrence($event->occurrenceKey())->first();

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event]);
        $secondRow = MeetingTranscript::query()->forOccurrence($event->occurrenceKey())->first();

        $this->assertSame(1, MeetingTranscript::query()->count());
        $this->assertSame($firstRow->id, $secondRow->id);
        $this->assertTrue($firstRow->linked_at->equalTo($secondRow->linked_at));
    }

    public function test_broken_renders_its_distinguishing_string(): void
    {
        $this->configureDirectory();
        $event = $this->eventAt('2026-09-09 09:30:00');
        // Inside the configured base but never created — Broken, not
        // Rejected (which is what a path outside the base would be).
        MeetingTranscript::factory()->forOccurrence($event)->broken()->create([
            'path' => "{$this->tempDir}/20260909_0930_gone.md",
        ]);

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('Linked file is gone');
    }

    public function test_unreadable_renders_its_distinguishing_string(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable permissions running as root.');
        }

        $dir = $this->configureDirectory();
        $path = $this->seedFile('locked', 'md', 'secret');
        chmod($path, 0000);
        $event = $this->eventAt('2026-09-09 09:30:00');
        MeetingTranscript::factory()->forOccurrence($event)->convention()->create(['path' => $path]);

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('File exists but is not readable');

        chmod($path, 0644);
    }

    public function test_rejected_renders_its_distinguishing_string(): void
    {
        $this->configureDirectory();
        $event = $this->eventAt('2026-09-09 09:30:00');
        $outside = realpath(sys_get_temp_dir()).'/kbms-outside-'.uniqid().'.md';
        file_put_contents($outside, 'x');
        MeetingTranscript::factory()->forOccurrence($event)->convention()->create(['path' => $outside]);

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('Rejected');

        unlink($outside);
    }

    public function test_suppressed_renders_its_distinguishing_string(): void
    {
        $this->configureDirectory();
        $event = $this->eventAt('2026-09-09 09:30:00');
        MeetingTranscript::factory()->forOccurrence($event)->suppressed()->create();

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('The convention will not re-link this meeting.');
    }

    public function test_the_ambiguous_chooser_links_the_selected_candidate_as_manual(): void
    {
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', 'a');
        $this->seedFile('20260909_0928_roadmap', 'md', 'b');
        $event = $this->eventAt('2026-09-09 09:30:00');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->set('selectedCandidate', '20260909_0928_roadmap.md')
            ->call('linkSelected')
            ->assertSee('Linked manually');

        $row = MeetingTranscript::query()->forOccurrence($event->occurrenceKey())->first();
        $this->assertSame(TranscriptLinkSource::Manual, $row->link_source);
        $this->assertStringContainsString('20260909_0928_roadmap.md', (string) $row->path);
    }

    public function test_the_picker_lists_only_files_inside_the_base(): void
    {
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', 'a');
        $event = $this->eventAt('2026-09-09 09:30:00', 'Something else entirely');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('20260909_0930_q4_roadmap_review.md');
    }

    public function test_a_submitted_filename_outside_the_base_is_refused_at_the_action_boundary(): void
    {
        $this->configureDirectory();
        $event = $this->eventAt('2026-09-09 09:30:00', 'Nothing recorded');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->call('link', '../../../../../../etc/passwd')
            ->assertSee('No transcript found for this meeting.');

        $this->assertDatabaseCount('meeting_transcripts', 0);
    }

    public function test_clear_link_writes_the_tombstone_and_leaves_the_file_on_disk(): void
    {
        $this->configureDirectory();
        $path = $this->seedFile('20260909_0930_q4_roadmap_review', 'md', 'a');
        $event = $this->eventAt('2026-09-09 09:30:00');

        $test = Livewire::test(TranscriptPanel::class, ['occurrence' => $event]);
        $test->assertSee('Linked by convention');

        $test->call('clearLink')->assertSee('The convention will not re-link this meeting.');

        $this->assertFileExists($path);

        $row = MeetingTranscript::query()->forOccurrence($event->occurrenceKey())->first();
        $this->assertTrue($row->isSuppressed());

        // A subsequent render (a fresh mount, i.e. revisiting the page)
        // must not re-link the still-present, still-matching file.
        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('The convention will not re-link this meeting.');
    }

    public function test_use_the_convention_again_deletes_the_row_and_re_resolves(): void
    {
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', 'a');
        $event = $this->eventAt('2026-09-09 09:30:00');

        $test = Livewire::test(TranscriptPanel::class, ['occurrence' => $event]);
        $test->call('clearLink');

        $test->call('useConvention')->assertSee('Linked by convention');

        $row = MeetingTranscript::query()->forOccurrence($event->occurrenceKey())->first();
        $this->assertSame(TranscriptLinkSource::Convention, $row->link_source);
    }

    public function test_the_absolute_path_is_rendered_and_copyable(): void
    {
        $this->configureDirectory();
        $path = $this->seedFile('20260909_0930_q4_roadmap_review', 'md', 'a');
        $event = $this->eventAt('2026-09-09 09:30:00');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee($path, false)
            ->assertSee('Copy transcript path');
    }

    public function test_the_preview_region_is_keyboard_scrollable_with_an_accessible_name(): void
    {
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', '# Hello');
        $event = $this->eventAt('2026-09-09 09:30:00');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('role="region"', false)
            ->assertSee('tabindex="0"', false)
            ->assertSee('Transcript preview, scrollable');
    }

    public function test_the_panel_behaves_identically_on_a_cancelled_occurrence(): void
    {
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', '# Hello');
        $event = $this->eventAt('2026-09-09 09:30:00', 'Q4 roadmap review', cancelled: true);

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('Linked by convention');
    }

    public function test_a_manually_linked_txt_transcript_renders_as_plain_text_not_markdown(): void
    {
        $this->configureDirectory();
        $path = $this->seedFile('20260909_0930_q4_roadmap_review', 'txt', 'plain text body');
        $event = $this->eventAt('2026-09-09 09:30:00');
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create(['path' => $path]);

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('.txt rendered as plain text');
    }

    public function test_a_filename_with_a_quote_does_not_break_out_of_the_js_context(): void
    {
        $this->configureDirectory();
        // A filename a slug-derived convention could plausibly produce
        // (an externally-influenced meeting title) containing a single
        // quote — must not break out of the wire:click JS string literal.
        $this->seedFile("evil'); alert(1); ('unrelated", 'md', 'x');
        $event = $this->eventAt('2026-09-09 09:30:00', 'Nothing recorded');

        $html = Livewire::test(TranscriptPanel::class, ['occurrence' => $event])->html();

        $this->assertStringNotContainsString("link('evil'", $html);
        $this->assertStringContainsString('\\u0027', $html);
    }

    public function test_a_truncated_transcript_shows_the_truncation_notice(): void
    {
        config(['kbms.transcript_preview_bytes' => 5]);
        $this->configureDirectory();
        $this->seedFile('20260909_0930_q4_roadmap_review', 'md', str_repeat('a', 50));
        $event = $this->eventAt('2026-09-09 09:30:00');

        Livewire::test(TranscriptPanel::class, ['occurrence' => $event])
            ->assertSee('open the file for the rest');
    }
}
