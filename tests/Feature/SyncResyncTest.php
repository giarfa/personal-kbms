<?php

namespace Tests\Feature;

use App\Calendar\ResyncDispatcher;
use App\Calendar\ResyncOutcome;
use App\Calendar\SyncHealthReporter;
use App\Calendar\SyncHealthState;
use App\Jobs\SyncCalendarFeed;
use App\Models\CalendarSyncRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncResyncTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(): ResyncDispatcher
    {
        return app(ResyncDispatcher::class);
    }

    public function test_dispatch_with_no_run_in_progress_dispatches_the_job(): void
    {
        Queue::fake();

        $outcome = $this->dispatcher()->dispatch();

        $this->assertSame(ResyncOutcome::Dispatched, $outcome);
        Queue::assertPushed(SyncCalendarFeed::class, 1);
    }

    public function test_dispatch_while_a_run_is_in_progress_pushes_nothing(): void
    {
        Queue::fake();
        CalendarSyncRun::factory()->running()->create(['started_at' => now()->subMinute()]);

        $outcome = $this->dispatcher()->dispatch();

        $this->assertSame(ResyncOutcome::AlreadyRunning, $outcome);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_with_an_abandoned_stuck_run_still_dispatches(): void
    {
        Queue::fake();
        CalendarSyncRun::factory()->abandoned()->create();

        $outcome = $this->dispatcher()->dispatch();

        $this->assertSame(ResyncOutcome::Dispatched, $outcome);
        Queue::assertPushed(SyncCalendarFeed::class, 1);
    }

    public function test_the_job_unique_for_matches_the_stuck_after_seconds_config_and_is_still_unique(): void
    {
        config(['kbms.sync_stuck_after_seconds' => 300]);

        $job = new SyncCalendarFeed;

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(300, $job->uniqueFor);
    }

    public function test_dispatch_writes_a_cache_marker_the_reporter_reads_as_syncing(): void
    {
        Queue::fake();

        $this->dispatcher()->dispatch();

        $this->assertNotNull(Cache::get(SyncHealthReporter::DISPATCH_MARKER_KEY));
        $this->assertSame(SyncHealthState::Syncing, app(SyncHealthReporter::class)->current()->state);
    }
}
