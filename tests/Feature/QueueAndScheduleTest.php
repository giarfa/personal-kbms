<?php

namespace Tests\Feature;

use App\Jobs\QueueSelfTestJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueAndScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_queue_driver_is_the_declared_default(): void
    {
        // phpunit.xml deliberately forces QUEUE_CONNECTION=sync so queued jobs run
        // inline during tests — assert the production default from .env.example instead.
        $this->assertStringContainsString(
            'QUEUE_CONNECTION=database',
            file_get_contents(base_path('.env.example'))
        );
        $this->assertSame('database', config('queue.connections.database.driver'));
    }

    public function test_queue_test_command_dispatches_the_self_test_job(): void
    {
        Queue::fake();

        Artisan::call('kbms:queue-test');

        Queue::assertPushed(QueueSelfTestJob::class);
    }

    public function test_the_self_test_job_writes_the_expected_log_line(): void
    {
        $spy = Log::spy();

        (new QueueSelfTestJob)->handle();

        $spy->shouldHaveReceived('info')->with('kbms.queue.self-test', \Mockery::type('array'))->once();
    }

    public function test_the_calendar_sync_is_scheduled_with_the_configured_interval_and_no_overlap(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($event) => str_contains($event->command, 'kbms:sync-calendar'));

        $this->assertNotNull($event, 'kbms:sync-calendar is not registered on the schedule');
        $this->assertSame('*/'.config('kbms.sync_minutes').' * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
