<?php

namespace Tests\Unit\Models;

use App\Calendar\SyncRunStatus;
use App\Models\CalendarSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarSyncRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_a_running_run(): void
    {
        $run = CalendarSyncRun::start();

        $this->assertSame(SyncRunStatus::Running, $run->status);
        $this->assertNull($run->finished_at);
    }

    public function test_succeed_records_status_validators_and_counts(): void
    {
        $run = CalendarSyncRun::start();

        $run->succeed(200, 'W/"abc"', 'Wed, 21 Oct 2026 07:28:00 GMT', 5, 1);

        $this->assertSame(SyncRunStatus::Success, $run->fresh()->status);
        $this->assertSame(200, $run->fresh()->http_status);
        $this->assertSame('W/"abc"', $run->fresh()->etag);
        $this->assertSame(5, $run->fresh()->events_upserted);
        $this->assertSame(1, $run->fresh()->events_cancelled);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_mark_not_modified_carries_validators_forward(): void
    {
        $run = CalendarSyncRun::start();

        $run->markNotModified('W/"abc"', 'Wed, 21 Oct 2026 07:28:00 GMT');

        $this->assertSame(SyncRunStatus::NotModified, $run->fresh()->status);
        $this->assertSame(304, $run->fresh()->http_status);
        $this->assertSame('W/"abc"', $run->fresh()->etag);
    }

    public function test_fail_records_error_and_optional_http_status(): void
    {
        $run = CalendarSyncRun::start();

        $run->fail('connection timed out', 500);

        $this->assertSame(SyncRunStatus::Failed, $run->fresh()->status);
        $this->assertSame('connection timed out', $run->fresh()->error);
        $this->assertSame(500, $run->fresh()->http_status);
    }

    public function test_latest_validators_prefers_the_most_recent_success_or_not_modified(): void
    {
        CalendarSyncRun::factory()->successful()->create(['started_at' => now()->subHours(3)]);
        $notModified = CalendarSyncRun::factory()->notModified()->create(['started_at' => now()->subHour()]);
        CalendarSyncRun::factory()->failed()->create(['started_at' => now()->subMinutes(10)]);
        CalendarSyncRun::factory()->running()->create(['started_at' => now()->subMinute()]);

        $latest = CalendarSyncRun::latestValidators();

        $this->assertNotNull($latest);
        $this->assertSame($notModified->id, $latest->id);
    }
}
