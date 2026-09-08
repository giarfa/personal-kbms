<?php

namespace Tests\Feature;

use App\Jobs\SyncCalendarFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncCalendarCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kbms.ics_url' => 'https://example.test/calendar.ics']);
    }

    public function test_the_default_invocation_dispatches_the_job_to_the_queue(): void
    {
        Queue::fake();

        $this->artisan('kbms:sync-calendar')->assertExitCode(0);

        Queue::assertPushed(SyncCalendarFeed::class);
    }

    public function test_sync_runs_inline_and_reports_counts(): void
    {
        Http::fake(['example.test/*' => Http::response('', 304)]);

        $this->artisan('kbms:sync-calendar', ['--sync' => true])
            ->expectsOutputToContain('not_modified')
            ->assertExitCode(0);
    }

    public function test_a_failed_inline_run_exits_non_zero(): void
    {
        Http::fake(['example.test/*' => Http::response('', 500)]);

        $this->artisan('kbms:sync-calendar', ['--sync' => true])
            ->expectsOutputToContain('failed')
            ->assertExitCode(1);
    }
}
