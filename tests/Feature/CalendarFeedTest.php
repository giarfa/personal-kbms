<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use App\Models\MeetingTranscript;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', 'Europe/Rome'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function transcriptsDir(): string
    {
        $dir = realpath(sys_get_temp_dir()).'/kbms-calendar-feed-transcripts-'.uniqid();
        mkdir($dir, 0755, true);
        config(['kbms.transcripts_path' => $dir]);

        return $dir;
    }

    public function test_only_occurrences_inside_the_requested_window_come_back(): void
    {
        CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 09:00', 'Europe/Rome'), 30)->create([
            'summary' => 'Inside window',
        ]);
        CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-20 09:00', 'Europe/Rome'), 30)->create([
            'summary' => 'Outside window',
        ]);

        $this->getJson('/calendar/events?from=2026-09-07&to=2026-09-14')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Inside window'])
            ->assertJsonMissing(['title' => 'Outside window']);
    }

    public function test_the_window_is_clamped_to_the_maximum_range(): void
    {
        CalendarEvent::factory()->at(CarbonImmutable::parse('2026-12-01 09:00', 'Europe/Rome'), 30)->create([
            'summary' => 'Far outside the clamp',
        ]);

        $this->getJson('/calendar/events?from=2026-09-01&to=2027-09-01')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Far outside the clamp']);
    }

    public function test_a_malformed_from_degrades_to_a_default_window_instead_of_500ing(): void
    {
        $this->getJson('/calendar/events?from=nonsense&to=')
            ->assertOk();
    }

    public function test_an_empty_window_returns_an_empty_array_with_200(): void
    {
        $response = $this->getJson('/calendar/events?from=2026-01-01&to=2026-01-01');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_notes_only_transcript_only_and_both_coverage_each_appear_on_the_right_occurrence(): void
    {
        $dir = $this->transcriptsDir();

        $notesOnly = CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 09:00', 'Europe/Rome'), 30)->create(['summary' => 'Notes only']);
        MeetingNote::factory()->forOccurrence($notesOnly)->create(['body' => 'notes']);

        $transcriptOnly = CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 10:00', 'Europe/Rome'), 30)->create(['summary' => 'Transcript only']);
        $path = "{$dir}/t.md";
        file_put_contents($path, 'content');
        MeetingTranscript::factory()->forOccurrence($transcriptOnly)->convention()->create(['path' => $path]);

        $both = CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 11:00', 'Europe/Rome'), 30)->create(['summary' => 'Both']);
        MeetingNote::factory()->forOccurrence($both)->create(['body' => 'notes']);
        $bothPath = "{$dir}/both.md";
        file_put_contents($bothPath, 'content');
        MeetingTranscript::factory()->forOccurrence($both)->convention()->create(['path' => $bothPath]);

        $json = $this->getJson('/calendar/events?from=2026-09-01&to=2026-09-30')->assertOk()->json();

        $byTitle = collect($json)->keyBy('title');

        $this->assertTrue($byTitle['Notes only']['extendedProps']['hasNotes']);
        $this->assertFalse($byTitle['Notes only']['extendedProps']['hasTranscript']);

        $this->assertFalse($byTitle['Transcript only']['extendedProps']['hasNotes']);
        $this->assertTrue($byTitle['Transcript only']['extendedProps']['hasTranscript']);

        $this->assertTrue($byTitle['Both']['extendedProps']['hasNotes']);
        $this->assertTrue($byTitle['Both']['extendedProps']['hasTranscript']);
        $this->assertStringContainsString('has notes and transcript', $byTitle['Both']['extendedProps']['accessibleName']);
    }

    public function test_a_cancelled_occurrence_is_flagged(): void
    {
        CalendarEvent::factory()->cancelled()->at(CarbonImmutable::parse('2026-09-08 11:00', 'Europe/Rome'), 30)->create([
            'summary' => 'Vendor demo',
        ]);

        $json = $this->getJson('/calendar/events?from=2026-09-01&to=2026-09-30')->assertOk()->json();

        $payload = collect($json)->firstWhere('title', 'Vendor demo');

        $this->assertTrue($payload['extendedProps']['cancelled']);
    }

    public function test_an_occurrence_straddling_the_dst_boundary_keeps_its_wall_clock_digits(): void
    {
        CalendarEvent::factory()->at(CarbonImmutable::parse('2026-10-25 02:30', 'Europe/Rome'), 30)->create([
            'summary' => 'Straddles DST',
        ]);

        $json = $this->getJson('/calendar/events?from=2026-10-19&to=2026-10-26')->assertOk()->json();

        $payload = collect($json)->firstWhere('title', 'Straddles DST');

        $this->assertSame('2026-10-25T02:30:00', $payload['start']);
    }

    public function test_the_endpoint_costs_a_bounded_number_of_queries_regardless_of_density(): void
    {
        CalendarEvent::factory()->count(20)->sequence(fn ($sequence) => [
            'starts_at' => CarbonImmutable::parse('2026-09-08 08:00', 'Europe/Rome')->addMinutes($sequence->index * 15),
            'ends_at' => CarbonImmutable::parse('2026-09-08 08:00', 'Europe/Rome')->addMinutes($sequence->index * 15 + 10),
        ])->create();

        DB::enableQueryLog();

        $this->getJson('/calendar/events?from=2026-09-01&to=2026-09-30')->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $queryCount);
    }
}
