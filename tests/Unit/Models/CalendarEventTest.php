<?php

namespace Tests\Unit\Models;

use App\Models\CalendarEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalendarEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_duplicate_source_uid_and_empty_recurrence_id_is_rejected(): void
    {
        CalendarEvent::factory()->create(['source_uid' => 'abc-123', 'recurrence_id' => '']);

        $this->expectException(QueryException::class);

        CalendarEvent::factory()->create(['source_uid' => 'abc-123', 'recurrence_id' => '']);
    }

    public function test_casts_resolve(): void
    {
        $event = CalendarEvent::factory()->create([
            'attendees' => [['name' => 'Jane Doe', 'email' => 'jane@example.com']],
            'is_all_day' => true,
        ]);

        $event->refresh();

        $this->assertIsArray($event->attendees);
        $this->assertSame('Jane Doe', $event->attendees[0]['name']);
        $this->assertTrue($event->is_all_day);
        $this->assertInstanceOf(Carbon::class, $event->starts_at);
        $this->assertInstanceOf(Carbon::class, $event->last_seen_at);
    }

    public function test_scope_in_window_filters_by_starts_at(): void
    {
        $inside = CalendarEvent::factory()->create(['starts_at' => now(), 'ends_at' => now()->addHour()]);
        $outside = CalendarEvent::factory()->create(['starts_at' => now()->addYear(), 'ends_at' => now()->addYear()->addHour()]);

        $results = CalendarEvent::query()->inWindow(now()->subDay(), now()->addDay())->get();

        $this->assertTrue($results->contains($inside));
        $this->assertFalse($results->contains($outside));
    }
}
