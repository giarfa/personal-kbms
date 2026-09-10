<?php

namespace Tests\Unit\Meetings;

use App\Meetings\CalendarEventPayload;
use App\Meetings\CalendarFeedQuery;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use App\Models\MeetingTranscript;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CalendarFeedQueryTest extends TestCase
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

    /**
     * @param  Collection<int, CalendarEventPayload>  $payloads
     */
    private function payloadFor(Collection $payloads, string $summary): CalendarEventPayload
    {
        $payload = $payloads->first(fn (CalendarEventPayload $payload): bool => $payload->title === $summary);

        $this->assertNotNull($payload, "No payload found for [{$summary}].");

        return $payload;
    }

    private function transcriptsDir(): string
    {
        $dir = realpath(sys_get_temp_dir()).'/kbms-calendar-transcripts-'.uniqid();
        mkdir($dir, 0755, true);
        config(['kbms.transcripts_path' => $dir]);

        return $dir;
    }

    public function test_a_timed_occurrence_emits_naive_local_strings_with_no_offset(): void
    {
        CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 09:30', 'Europe/Rome'), 30)->create([
            'summary' => 'Q4 roadmap review',
        ]);

        $payloads = CalendarFeedQuery::for(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
        );

        $payload = $this->payloadFor($payloads, 'Q4 roadmap review');

        $this->assertSame('2026-09-08T09:30:00', $payload->start);
        $this->assertSame('2026-09-08T10:00:00', $payload->end);
        $this->assertStringNotContainsString('+', $payload->start);
        $this->assertStringNotContainsString('Z', $payload->start);
        $this->assertFalse($payload->allDay);
    }

    public function test_an_all_day_occurrence_passes_the_stored_exclusive_end_through_unchanged(): void
    {
        CalendarEvent::factory()->allDay()->create([
            'summary' => 'Public holiday',
            'starts_at' => '2026-09-09 00:00:00',
            'ends_at' => '2026-09-10 00:00:00',
        ]);

        $payloads = CalendarFeedQuery::for(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
        );

        $payload = $this->payloadFor($payloads, 'Public holiday');

        $this->assertTrue($payload->allDay);
        $this->assertSame('2026-09-09', $payload->start);
        $this->assertSame('2026-09-10', $payload->end);
    }

    public function test_a_multiday_occurrence_is_emitted_once_spanning_its_whole_range(): void
    {
        CalendarEvent::factory()->spanning(3)->create([
            'summary' => 'Industry conference',
            'starts_at' => '2026-09-15 00:00:00',
            'ends_at' => '2026-09-18 00:00:00',
        ]);

        $payloads = CalendarFeedQuery::for(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
        );

        $matches = $payloads->filter(fn (CalendarEventPayload $payload): bool => $payload->title === 'Industry conference');

        $this->assertCount(1, $matches);
        $payload = $matches->first();
        $this->assertSame('2026-09-15', $payload->start);
        $this->assertSame('2026-09-18', $payload->end);
    }

    public function test_a_cancelled_occurrence_carries_the_cancelled_flag_and_class(): void
    {
        CalendarEvent::factory()->cancelled()->at(CarbonImmutable::parse('2026-09-08 11:00', 'Europe/Rome'), 30)->create([
            'summary' => 'Vendor demo',
        ]);

        $payloads = CalendarFeedQuery::for(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
        );

        $payload = $this->payloadFor($payloads, 'Vendor demo');

        $this->assertTrue($payload->cancelled);
        $this->assertContains('kb-ev--cancelled', $payload->classNames);
        $this->assertStringContainsString('cancelled', $payload->accessibleName);
    }

    public function test_coverage_combinations_are_reflected_in_class_names_and_extended_props(): void
    {
        $dir = $this->transcriptsDir();

        // Every event here gets an explicit location. The factory only sets one
        // half the time, and the shipped "location is empty" colour rule (US-012)
        // would otherwise let a coin flip decide whether these exact-classNames
        // assertions pass. Leaving the default rules active rather than clearing
        // them also proves the colour channel stays out of coverage's way.

        $bare = CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 08:00', 'Europe/Rome'), 30)->create(['summary' => 'Bare meeting', 'location' => 'Room 1']);

        $notesOnly = CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 09:00', 'Europe/Rome'), 30)->create(['summary' => 'Notes only meeting', 'location' => 'Room 1']);
        MeetingNote::factory()->forOccurrence($notesOnly)->create(['body' => 'notes']);

        $transcriptOnly = CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 10:00', 'Europe/Rome'), 30)->create(['summary' => 'Transcript only meeting', 'location' => 'Room 1']);
        $transcriptPath = "{$dir}/transcript-only.md";
        file_put_contents($transcriptPath, 'content');
        MeetingTranscript::factory()->forOccurrence($transcriptOnly)->convention()->create(['path' => $transcriptPath]);

        $both = CalendarEvent::factory()->at(CarbonImmutable::parse('2026-09-08 11:00', 'Europe/Rome'), 30)->create(['summary' => 'Fully annotated meeting', 'location' => 'Room 1']);
        MeetingNote::factory()->forOccurrence($both)->create(['body' => 'notes']);
        $bothPath = "{$dir}/both.md";
        file_put_contents($bothPath, 'content');
        MeetingTranscript::factory()->forOccurrence($both)->convention()->create(['path' => $bothPath]);

        $payloads = CalendarFeedQuery::for(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
        );

        $barePayload = $this->payloadFor($payloads, 'Bare meeting');
        $this->assertSame([], $barePayload->classNames);
        $this->assertFalse($barePayload->hasNotes);
        $this->assertFalse($barePayload->hasTranscript);
        $this->assertStringContainsString('not annotated', $barePayload->accessibleName);

        $notesPayload = $this->payloadFor($payloads, 'Notes only meeting');
        $this->assertContains('kb-ev--note', $notesPayload->classNames);
        $this->assertTrue($notesPayload->hasNotes);
        $this->assertFalse($notesPayload->hasTranscript);

        $transcriptPayload = $this->payloadFor($payloads, 'Transcript only meeting');
        $this->assertContains('kb-ev--transcript', $transcriptPayload->classNames);
        $this->assertFalse($transcriptPayload->hasNotes);
        $this->assertTrue($transcriptPayload->hasTranscript);

        $bothPayload = $this->payloadFor($payloads, 'Fully annotated meeting');
        $this->assertContains('kb-ev--both', $bothPayload->classNames);
        $this->assertTrue($bothPayload->hasNotes);
        $this->assertTrue($bothPayload->hasTranscript);
        $this->assertStringContainsString('has notes and transcript', $bothPayload->accessibleName);
    }
}
