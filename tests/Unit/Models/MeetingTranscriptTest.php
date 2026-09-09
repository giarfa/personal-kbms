<?php

namespace Tests\Unit\Models;

use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptLinkSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingTranscriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_occurrence_hits_the_composite_key_and_not_a_sibling(): void
    {
        $event = CalendarEvent::factory()->occurrenceOf('series', 'r1')->create();
        $sibling = CalendarEvent::factory()->occurrenceOf('series', 'r2')->create();

        MeetingTranscript::factory()->forOccurrence($event)->create();

        $found = MeetingTranscript::query()->forOccurrence($event->occurrenceKey())->first();
        $notFound = MeetingTranscript::query()->forOccurrence($sibling->occurrenceKey())->first();

        $this->assertNotNull($found);
        $this->assertSame($event->source_uid, $found->event_uid);
        $this->assertNull($notFound);
    }

    public function test_the_unique_constraint_rejects_a_second_row_per_occurrence(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->create();

        $this->expectException(QueryException::class);

        MeetingTranscript::factory()->forOccurrence($event)->create();
    }

    public function test_link_source_casts_to_the_enum(): void
    {
        $transcript = MeetingTranscript::factory()->manual()->create();

        $this->assertInstanceOf(TranscriptLinkSource::class, $transcript->link_source);
        $this->assertSame(TranscriptLinkSource::Manual, $transcript->fresh()->link_source);
    }

    public function test_a_null_path_manual_row_is_valid_and_reads_as_suppressed(): void
    {
        $transcript = MeetingTranscript::factory()->suppressed()->create();

        $this->assertNull($transcript->fresh()->path);
        $this->assertTrue($transcript->isSuppressed());
        $this->assertTrue($transcript->isManual());
    }

    public function test_a_convention_row_is_not_suppressed(): void
    {
        $transcript = MeetingTranscript::factory()->convention()->create();

        $this->assertFalse($transcript->isSuppressed());
        $this->assertFalse($transcript->isManual());
    }
}
