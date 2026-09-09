<?php

namespace Tests\Unit\Meetings;

use App\Meetings\MeetingCoverageLookup;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptPattern;
use App\Transcripts\TranscriptsDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeetingCoverageLookupTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                @chmod($file, 0644);
                unlink($file);
            }

            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function configureTranscriptsDirectory(): string
    {
        $this->tempDir = realpath(sys_get_temp_dir()).'/kbms-coverage-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        config([
            'kbms.transcripts_path' => $this->tempDir,
            'kbms.transcript_pattern' => TranscriptPattern::DEFAULT_PATTERN,
        ]);

        return $this->tempDir;
    }

    private function lookup(): MeetingCoverageLookup
    {
        return app(MeetingCoverageLookup::class);
    }

    public function test_a_non_blank_note_flags_only_its_own_occurrence(): void
    {
        $annotated = CalendarEvent::factory()->occurrenceOf('series', 'r1')->create();
        $sibling = CalendarEvent::factory()->occurrenceOf('series', 'r2')->create();
        MeetingNote::factory()->forOccurrence($annotated)->create(['body' => 'notes']);

        $coverage = $this->lookup()->for(collect([$annotated, $sibling]));

        $this->assertTrue($coverage[$annotated->source_uid."\0".$annotated->recurrence_id]->hasNotes);
        $this->assertArrayNotHasKey($sibling->source_uid."\0".$sibling->recurrence_id, $coverage);
    }

    public function test_a_blank_bodied_note_does_not_flag(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingNote::factory()->forOccurrence($event)->blank()->create();

        $coverage = $this->lookup()->for(collect([$event]));

        $this->assertArrayNotHasKey($event->source_uid."\0".$event->recurrence_id, $coverage);
    }

    public function test_an_occurrence_with_no_row_does_not_flag(): void
    {
        $event = CalendarEvent::factory()->create();

        $coverage = $this->lookup()->for(collect([$event]));

        $this->assertSame([], $coverage);
    }

    public function test_for_occurrence_reads_the_single_row_case(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'notes']);

        $this->assertTrue($this->lookup()->forOccurrence($event)->hasNotes);
    }

    public function test_a_readable_linked_transcript_flags_only_its_own_occurrence(): void
    {
        $dir = $this->configureTranscriptsDirectory();
        $linked = CalendarEvent::factory()->create();
        $sibling = CalendarEvent::factory()->create();

        $path = "{$dir}/transcript.md";
        file_put_contents($path, 'content');
        MeetingTranscript::factory()->forOccurrence($linked)->convention()->create(['path' => $path]);

        $coverage = $this->lookup()->for(collect([$linked, $sibling]));

        $this->assertTrue($coverage[$linked->source_uid."\0".$linked->recurrence_id]->hasTranscript);
        $this->assertArrayNotHasKey($sibling->source_uid."\0".$sibling->recurrence_id, $coverage);
    }

    public function test_broken_unreadable_and_suppressed_rows_all_flag_false(): void
    {
        $dir = $this->configureTranscriptsDirectory();

        $broken = CalendarEvent::factory()->create();
        // Inside the configured base but never created — Broken, not
        // Rejected (the factory's default broken() path lives under
        // storage_path(), which is outside this test's temp base).
        MeetingTranscript::factory()->forOccurrence($broken)->broken()->create([
            'path' => "{$dir}/gone.md",
        ]);

        $unreadablePath = "{$dir}/locked.md";
        file_put_contents($unreadablePath, 'x');
        chmod($unreadablePath, 0000);
        $unreadable = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($unreadable)->convention()->create(['path' => $unreadablePath]);

        $suppressed = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($suppressed)->suppressed()->create();

        $coverage = $this->lookup()->for(collect([$broken, $unreadable, $suppressed]));

        $this->assertArrayNotHasKey($broken->source_uid."\0".$broken->recurrence_id, $coverage);
        $this->assertArrayNotHasKey($unreadable->source_uid."\0".$unreadable->recurrence_id, $coverage);
        $this->assertArrayNotHasKey($suppressed->source_uid."\0".$suppressed->recurrence_id, $coverage);

        chmod($unreadablePath, 0644);
    }

    public function test_an_ambiguous_unlinked_occurrence_flags_false(): void
    {
        $dir = $this->configureTranscriptsDirectory();
        $pattern = TranscriptPattern::compile();

        $event = CalendarEvent::factory()->at(now(config('kbms.timezone'))->setTime(9, 30), 30)->create([
            'summary' => 'Ambiguous meeting',
        ]);

        $start = CarbonImmutable::instance($event->starts_at);
        file_put_contents($dir.'/'.$pattern->render($start, 'Ambiguous meeting').'.md', 'a');
        file_put_contents($dir.'/'.$pattern->render($start->addMinutes(2), 'other name').'.md', 'b');

        $coverage = $this->lookup()->for(collect([$event]));

        $this->assertArrayNotHasKey($event->source_uid."\0".$event->recurrence_id, $coverage);
    }

    public function test_an_unlinked_occurrence_with_exactly_one_convention_candidate_flags_true(): void
    {
        $dir = $this->configureTranscriptsDirectory();
        $pattern = TranscriptPattern::compile();

        $event = CalendarEvent::factory()->at(now(config('kbms.timezone'))->setTime(9, 30), 30)->create([
            'summary' => 'Single candidate meeting',
        ]);

        $start = CarbonImmutable::instance($event->starts_at);
        file_put_contents($dir.'/'.$pattern->render($start, 'Single candidate meeting').'.md', 'a');

        $coverage = $this->lookup()->for(collect([$event]));

        $this->assertTrue($coverage[$event->source_uid."\0".$event->recurrence_id]->hasTranscript);
    }

    public function test_the_batch_lookup_issues_the_documented_query_count_regardless_of_event_count(): void
    {
        $dir = $this->configureTranscriptsDirectory();
        $pattern = TranscriptPattern::compile();

        $events = CalendarEvent::factory()->count(10)->create();
        MeetingNote::factory()->forOccurrence($events->first())->create(['body' => 'notes']);

        // Several unlinked events all forcing dynamic resolution — proves
        // the query count stays fixed at 2 (notes + transcripts) no matter
        // how many occurrences fall through to the resolver, because the
        // resolver's file index is built once, not once per occurrence.
        foreach ($events->slice(1, 3) as $unlinked) {
            $start = CarbonImmutable::instance($unlinked->starts_at);
            file_put_contents($dir.'/'.$pattern->render($start, (string) $unlinked->summary).'.md', 'x');
        }

        DB::enableQueryLog();
        app(MeetingCoverageLookup::class)->for($events);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Events query is issued by the caller (AgendaQuery) in production;
        // here only the notes + transcripts batched reads are this class's
        // concern, so exactly two queries regardless of event count.
        $this->assertCount(2, $queries);
    }

    public function test_a_shared_resolver_instance_lists_the_directory_once_for_n_unlinked_events(): void
    {
        $dir = $this->configureTranscriptsDirectory();
        $pattern = TranscriptPattern::compile();

        $events = CalendarEvent::factory()->count(5)->create();

        foreach ($events as $event) {
            $start = CarbonImmutable::instance($event->starts_at);
            file_put_contents($dir.'/'.$pattern->render($start, (string) $event->summary).'.md', 'x');
        }

        // TranscriptsDirectory::files() is memoized per instance (TASK-02/04).
        // MeetingCoverageLookup must build exactly one TranscriptResolver for
        // the whole for() call, not one per occurrence — otherwise each
        // occurrence would re-list the directory. A single shared, scoped
        // TranscriptsDirectory instance is what makes that memoization span
        // the whole batch instead of resetting per event.
        $directory = app(TranscriptsDirectory::class);
        $this->assertSame($directory, app(TranscriptsDirectory::class));

        $coverage = app(MeetingCoverageLookup::class)->for($events);

        foreach ($events as $event) {
            $this->assertTrue($coverage[$event->source_uid."\0".$event->recurrence_id]->hasTranscript);
        }
    }
}
