<?php

namespace Tests\Unit\Calendar;

use App\Calendar\SyncHealthReporter;
use App\Calendar\SyncHealthState;
use App\Models\CalendarSyncRun;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncHealthReporterTest extends TestCase
{
    use RefreshDatabase;

    private function reporter(): SyncHealthReporter
    {
        return app(SyncHealthReporter::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-03-01 12:00:00'));
    }

    public function test_no_run_at_all_is_never_synced(): void
    {
        $this->assertSame(SyncHealthState::Never, $this->reporter()->current()->state);
    }

    public function test_a_running_run_inside_the_stuck_threshold_is_syncing(): void
    {
        CalendarSyncRun::factory()->running()->create(['started_at' => now()->subMinute()]);

        $health = $this->reporter()->current();

        $this->assertSame(SyncHealthState::Syncing, $health->state);
    }

    public function test_a_recent_successful_run_is_ok_and_exposes_last_success_at(): void
    {
        $startedAt = now()->subMinutes(4);
        CalendarSyncRun::factory()->successful()->create(['started_at' => $startedAt]);

        $health = $this->reporter()->current();

        $this->assertSame(SyncHealthState::Ok, $health->state);
        $this->assertTrue($health->lastSuccessAt->equalTo($startedAt));
    }

    public function test_a_recent_not_modified_run_is_ok_not_stale(): void
    {
        // Regression guard: a 304 confirms the mirror is current and must not
        // read as stale one interval later, the same as a fresh 200 would.
        CalendarSyncRun::factory()->notModified()->create(['started_at' => now()->subMinutes(4)]);

        $this->assertSame(SyncHealthState::Ok, $this->reporter()->current()->state);
    }

    public function test_a_stale_success_with_no_failure_is_stale(): void
    {
        CalendarSyncRun::factory()->stale()->create();

        $this->assertSame(SyncHealthState::Stale, $this->reporter()->current()->state);
    }

    public function test_success_at_exactly_the_stale_boundary_is_not_stale(): void
    {
        config(['kbms.sync_minutes' => 15, 'kbms.sync_stale_multiplier' => 3]);
        CalendarSyncRun::factory()->successful()->create(['started_at' => now()->subMinutes(45)]);

        $this->assertSame(SyncHealthState::Ok, $this->reporter()->current()->state);
    }

    public function test_success_one_minute_past_the_stale_boundary_is_stale(): void
    {
        config(['kbms.sync_minutes' => 15, 'kbms.sync_stale_multiplier' => 3]);
        CalendarSyncRun::factory()->successful()->create(['started_at' => now()->subMinutes(46)]);

        $this->assertSame(SyncHealthState::Stale, $this->reporter()->current()->state);
    }

    public function test_latest_finished_failed_is_failed_and_exposes_error_and_http_status(): void
    {
        CalendarSyncRun::factory()->successful()->create(['started_at' => now()->subMinutes(30)]);
        CalendarSyncRun::factory()->failed()->create([
            'started_at' => now()->subMinutes(2),
            'http_status' => 503,
            'error' => 'feed responded with HTTP 503',
        ]);

        $health = $this->reporter()->current();

        $this->assertSame(SyncHealthState::Failed, $health->state);
        $this->assertSame('feed responded with HTTP 503', $health->lastError);
        $this->assertSame(503, $health->httpStatus);
    }

    public function test_failed_and_stale_resolves_to_failed_not_both(): void
    {
        CalendarSyncRun::factory()->stale()->create();
        CalendarSyncRun::factory()->failed()->create(['started_at' => now()->subMinute()]);

        $this->assertSame(SyncHealthState::Failed, $this->reporter()->current()->state);
    }

    public function test_a_stuck_running_run_is_abandoned_as_failed_and_no_longer_reads_as_syncing(): void
    {
        config(['kbms.sync_stuck_after_seconds' => 300]);
        CalendarSyncRun::factory()->successful()->create(['started_at' => now()->subMinutes(30)]);
        $stuck = CalendarSyncRun::factory()->running()->create(['started_at' => now()->subMinutes(10)]);

        $health = $this->reporter()->current();

        $this->assertNotSame(SyncHealthState::Syncing, $health->state);
        $stuck->refresh();
        $this->assertStringContainsString('queue:work', (string) $stuck->error);
        $this->assertNotNull($stuck->finished_at);
    }

    public function test_only_ever_failed_runs_with_no_success_reads_as_never_synced(): void
    {
        // Defensive branch: showing "Sync failed" implies a mirror to fall back
        // to. With no success ever recorded, "never synced" is the honest state.
        CalendarSyncRun::factory()->failed()->create();
        CalendarSyncRun::factory()->failed()->create(['started_at' => now()->subMinute()]);

        $this->assertSame(SyncHealthState::Never, $this->reporter()->current()->state);
    }
}
