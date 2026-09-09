<?php

namespace Tests\Unit\Models;

use App\Meetings\OccurrenceKey;
use App\Models\MeetingNote;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_occurrence_returns_the_row_for_its_natural_key(): void
    {
        $note = MeetingNote::factory()->create([
            'event_uid' => 'series-uid',
            'event_recurrence_id' => '20260907T073000Z',
        ]);

        $found = MeetingNote::query()
            ->forOccurrence(new OccurrenceKey('series-uid', '20260907T073000Z'))
            ->first();

        $this->assertNotNull($found);
        $this->assertSame($note->id, $found->id);
    }

    public function test_for_occurrence_on_a_sibling_recurrence_id_returns_nothing(): void
    {
        MeetingNote::factory()->create([
            'event_uid' => 'series-uid',
            'event_recurrence_id' => '20260907T073000Z',
        ]);

        $found = MeetingNote::query()
            ->forOccurrence(new OccurrenceKey('series-uid', '20260914T073000Z'))
            ->first();

        $this->assertNull($found);
    }

    public function test_occurrence_key_round_trips_through_the_route_key(): void
    {
        $note = MeetingNote::factory()->create([
            'event_uid' => 'series-uid',
            'event_recurrence_id' => '20260907T073000Z',
        ]);

        $decoded = OccurrenceKey::fromRouteKey($note->occurrenceKey()->toRouteKey());

        $this->assertNotNull($decoded);
        $this->assertSame('series-uid', $decoded->sourceUid);
        $this->assertSame('20260907T073000Z', $decoded->recurrenceId);
    }

    public function test_a_second_note_on_the_same_occurrence_violates_the_unique_constraint(): void
    {
        MeetingNote::factory()->create(['event_uid' => 'series-uid', 'event_recurrence_id' => '']);

        $this->expectException(QueryException::class);

        MeetingNote::factory()->create(['event_uid' => 'series-uid', 'event_recurrence_id' => '']);
    }

    public function test_has_content_is_false_for_a_blank_note(): void
    {
        $note = MeetingNote::factory()->blank()->make();

        $this->assertFalse($note->hasContent());
    }

    public function test_has_content_is_false_for_a_whitespace_only_body(): void
    {
        $note = MeetingNote::factory()->make(['body' => "  \n\t "]);

        $this->assertFalse($note->hasContent());
    }

    public function test_has_content_is_true_for_a_real_body(): void
    {
        $note = MeetingNote::factory()->make();

        $this->assertTrue($note->hasContent());
    }
}
