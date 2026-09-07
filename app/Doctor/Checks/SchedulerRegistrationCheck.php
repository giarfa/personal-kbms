<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Console\Scheduling\Schedule;

final class SchedulerRegistrationCheck implements EnvironmentCheck
{
    public function __construct(private Schedule $schedule) {}

    public function label(): string
    {
        return 'Calendar sync schedule';
    }

    public function run(): CheckResult
    {
        $event = collect($this->schedule->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'kbms:sync-calendar'));

        if ($event === null) {
            return CheckResult::failed(
                'kbms:sync-calendar is not registered on the schedule',
                '`kbms:sync-calendar` is not registered — check `routes/console.php`'
            );
        }

        return CheckResult::pass("registered with cron expression \"{$event->expression}\"");
    }
}
