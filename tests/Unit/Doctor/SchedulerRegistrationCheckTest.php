<?php

namespace Tests\Unit\Doctor;

use App\Doctor\Checks\SchedulerRegistrationCheck;
use App\Doctor\CheckStatus;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerRegistrationCheckTest extends TestCase
{
    public function test_it_passes_when_kbms_sync_calendar_is_registered(): void
    {
        // routes/console.php registers kbms:sync-calendar on the real Schedule singleton.
        $schedule = $this->app->make(Schedule::class);

        $result = (new SchedulerRegistrationCheck($schedule))->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    public function test_it_fails_when_the_command_is_not_registered(): void
    {
        $schedule = new Schedule;

        $result = (new SchedulerRegistrationCheck($schedule))->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('routes/console.php', $result->remediation);
    }
}
