<?php

namespace Tests\Unit\Meetings;

use App\Meetings\MeetingCoverageLookup;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeetingCoverageLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_blank_note_flags_only_its_own_occurrence(): void
    {
        $annotated = CalendarEvent::factory()->occurrenceOf('series', 'r1')->create();
        $sibling = CalendarEvent::factory()->occurrenceOf('series', 'r2')->create();
        MeetingNote::factory()->forOccurrence($annotated)->create(['body' => 'notes']);

        $coverage = (new MeetingCoverageLookup)->for(collect([$annotated, $sibling]));

        $this->assertTrue($coverage[$annotated->source_uid."\0".$annotated->recurrence_id]->hasNotes);
        $this->assertArrayNotHasKey($sibling->source_uid."\0".$sibling->recurrence_id, $coverage);
    }

    public function test_a_blank_bodied_note_does_not_flag(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingNote::factory()->forOccurrence($event)->blank()->create();

        $coverage = (new MeetingCoverageLookup)->for(collect([$event]));

        $this->assertArrayNotHasKey($event->source_uid."\0".$event->recurrence_id, $coverage);
    }

    public function test_an_occurrence_with_no_row_does_not_flag(): void
    {
        $event = CalendarEvent::factory()->create();

        $coverage = (new MeetingCoverageLookup)->for(collect([$event]));

        $this->assertSame([], $coverage);
    }

    public function test_for_occurrence_reads_the_single_row_case(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'notes']);

        $this->assertTrue((new MeetingCoverageLookup)->forOccurrence($event)->hasNotes);
    }

    public function test_the_batch_lookup_issues_exactly_one_query_regardless_of_event_count(): void
    {
        $events = CalendarEvent::factory()->count(10)->create();
        MeetingNote::factory()->forOccurrence($events->first())->create(['body' => 'notes']);

        DB::enableQueryLog();
        (new MeetingCoverageLookup)->for($events);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }
}
