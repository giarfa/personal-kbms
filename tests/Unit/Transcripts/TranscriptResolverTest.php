<?php

namespace Tests\Unit\Transcripts;

use App\Models\CalendarEvent;
use App\Transcripts\TranscriptPattern;
use App\Transcripts\TranscriptResolver;
use App\Transcripts\TranscriptsDirectory;
use App\Transcripts\TranscriptState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TranscriptResolverTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = realpath(sys_get_temp_dir()).'/kbms-resolver-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        config([
            'kbms.transcripts_path' => $this->tempDir,
            'kbms.transcript_pattern' => TranscriptPattern::DEFAULT_PATTERN,
            'kbms.transcript_tolerance_minutes' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function file(string $stem, string $extension = 'md'): void
    {
        file_put_contents($this->tempDir."/{$stem}.{$extension}", 'content');
    }

    private function resolver(): TranscriptResolver
    {
        return new TranscriptResolver(new TranscriptsDirectory, TranscriptPattern::compile());
    }

    private function eventAt(string $startsAt, string $summary = 'Q4 roadmap review', bool $allDay = false): CalendarEvent
    {
        return CalendarEvent::factory()->create([
            'summary' => $summary,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt,
            'is_all_day' => $allDay,
        ]);
    }

    public function test_an_exact_minute_single_file_links_by_convention(): void
    {
        $this->file('20260907_0930_q4_roadmap_review');
        $event = $this->eventAt('2026-09-07 09:30:00');

        $candidates = $this->resolver()->candidatesFor($event);

        $this->assertCount(1, $candidates);
        $this->assertSame(0, $candidates[0]->driftMinutes);
    }

    public function test_a_file_two_minutes_early_is_inside_a_ten_minute_tolerance(): void
    {
        $this->file('20260907_0928_q4_roadmap_review');
        $event = $this->eventAt('2026-09-07 09:30:00');

        $candidates = $this->resolver()->candidatesFor($event);

        $this->assertCount(1, $candidates);
        $this->assertSame(-2, $candidates[0]->driftMinutes);
    }

    public function test_a_file_eleven_minutes_late_is_outside_a_ten_minute_tolerance(): void
    {
        $this->file('20260907_0941_q4_roadmap_review');
        $event = $this->eventAt('2026-09-07 09:30:00');

        $this->assertCount(0, $this->resolver()->candidatesFor($event));
    }

    public function test_three_in_window_files_return_three_ordered_candidates_and_link_nothing(): void
    {
        // Exact datetime AND slug match — still must not short-circuit to a link.
        $this->file('20260907_0930_q4_roadmap_review');
        $this->file('20260907_0928_roadmap');
        $this->file('20260907_0933_untitled_call', 'txt');

        $event = $this->eventAt('2026-09-07 09:30:00');

        $candidates = $this->resolver()->candidatesFor($event);

        $this->assertCount(3, $candidates);
        $this->assertSame('20260907_0930_q4_roadmap_review.md', $candidates[0]->filename);
        $this->assertTrue($candidates[0]->slugMatches);
        $this->assertSame('20260907_0928_roadmap.md', $candidates[1]->filename);
        $this->assertSame('20260907_0933_untitled_call.txt', $candidates[2]->filename);
    }

    public function test_ordering_is_drift_then_slug_match_then_name(): void
    {
        $this->file('20260907_0932_not_a_match');
        $this->file('20260907_0932_q4_roadmap_review');
        $event = $this->eventAt('2026-09-07 09:30:00');

        $candidates = $this->resolver()->candidatesFor($event);

        $this->assertSame('20260907_0932_q4_roadmap_review.md', $candidates[0]->filename);
        $this->assertSame('20260907_0932_not_a_match.md', $candidates[1]->filename);
    }

    public function test_a_slug_superset_prefix_match_sorts_ahead_of_a_non_matching_file(): void
    {
        $this->file('20260907_0930_standup_daily_team');
        $this->file('20260907_0930_weekly_ops');
        $event = $this->eventAt('2026-09-07 09:30:00', 'Standup');

        $candidates = $this->resolver()->candidatesFor($event);

        $this->assertSame('20260907_0930_standup_daily_team.md', $candidates[0]->filename);
        $this->assertTrue($candidates[0]->slugMatches);
        $this->assertSame('20260907_0930_weekly_ops.md', $candidates[1]->filename);
        $this->assertFalse($candidates[1]->slugMatches);
    }

    public function test_prefix_matching_orders_but_never_filters_and_the_state_stays_ambiguous(): void
    {
        $this->file('20260907_0930_standup_daily_team');
        $this->file('20260907_0930_weekly_ops');
        $event = $this->eventAt('2026-09-07 09:30:00', 'Standup');

        $this->assertCount(2, $this->resolver()->candidatesFor($event));
        $this->assertSame(TranscriptState::Ambiguous, $this->resolver()->stateFor($event));
    }

    public function test_a_blank_event_summary_flags_no_candidate_as_a_slug_match(): void
    {
        $this->file('20260907_0930_standup');
        $this->file('20260907_0930_weekly_ops');
        $event = $this->eventAt('2026-09-07 09:30:00', '');

        $candidates = $this->resolver()->candidatesFor($event);

        $this->assertFalse($candidates[0]->slugMatches);
        $this->assertFalse($candidates[1]->slugMatches);
    }

    public function test_a_retired_hyphenated_file_inside_the_window_produces_no_candidates(): void
    {
        $this->file('2026-09-07-0930-q4-roadmap-review');
        $event = $this->eventAt('2026-09-07 09:30:00');

        $this->assertCount(0, $this->resolver()->candidatesFor($event));
    }

    public function test_an_all_day_occurrence_matches_every_file_on_its_date(): void
    {
        $this->file('20260907_0800_morning_file');
        $this->file('20260907_1800_evening_file');
        $this->file('20260908_0800_different_day');

        $event = $this->eventAt('2026-09-07 00:00:00', allDay: true);

        $candidates = $this->resolver()->candidatesFor($event);

        $this->assertCount(2, $candidates);
    }

    public function test_state_for_reports_missing_ambiguous_and_linked(): void
    {
        // A fresh resolver per assertion: the file index is memoized per
        // instance by design (TASK-02/03), so reusing one across a
        // changing directory would read stale state — that is the point
        // of the memoization, not a bug to work around.
        $missing = $this->eventAt('2026-09-07 09:30:00', 'Nothing here');
        $this->assertSame(TranscriptState::Missing, $this->resolver()->stateFor($missing));

        $this->file('20260907_0930_q4_roadmap_review');
        $linked = $this->eventAt('2026-09-07 09:30:00');
        $this->assertSame(TranscriptState::Linked, $this->resolver()->stateFor($linked));

        $this->file('20260907_0928_roadmap');
        $ambiguous = $this->eventAt('2026-09-07 09:30:00');
        $this->assertSame(TranscriptState::Ambiguous, $this->resolver()->stateFor($ambiguous));
    }

    public function test_the_resolver_performs_no_database_writes(): void
    {
        $this->file('20260907_0930_q4_roadmap_review');
        $event = $this->eventAt('2026-09-07 09:30:00');

        DB::enableQueryLog();
        $this->resolver()->candidatesFor($event);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $writeQueries = array_filter(
            $queries,
            fn (array $query): bool => (bool) preg_match('/^(insert|update|delete)/i', $query['query'])
        );

        $this->assertSame([], $writeQueries);
    }

    public function test_a_single_compiled_pattern_and_directory_index_resolves_n_events_consistently(): void
    {
        $this->file('20260907_0930_q4_roadmap_review');

        // One TranscriptPattern and one TranscriptsDirectory constructed
        // outside the loop — candidatesFor() must never recompile the
        // pattern or re-list the directory per event.
        $resolver = new TranscriptResolver(new TranscriptsDirectory, TranscriptPattern::compile());

        $events = CalendarEvent::factory()->count(5)->create([
            'starts_at' => '2026-09-07 09:30:00',
            'ends_at' => '2026-09-07 09:30:00',
        ]);

        foreach ($events as $event) {
            $candidates = $resolver->candidatesFor($event);

            $this->assertCount(1, $candidates);
            $this->assertSame('20260907_0930_q4_roadmap_review.md', $candidates[0]->filename);
        }
    }
}
