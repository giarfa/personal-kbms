<?php

namespace App\Providers;

use App\Doctor\Checks\IcsFeedCheck;
use App\Doctor\Checks\LauncherScriptCheck;
use App\Doctor\Checks\QueueConnectionCheck;
use App\Doctor\Checks\SchedulerRegistrationCheck;
use App\Doctor\Checks\TimezoneCheck;
use App\Doctor\Checks\TranscriptsDirectoryCheck;
use App\Doctor\CheckSuite;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CheckSuite::class, fn (): CheckSuite => new CheckSuite([
            new IcsFeedCheck,
            new TranscriptsDirectoryCheck,
            new LauncherScriptCheck,
            new QueueConnectionCheck,
            new SchedulerRegistrationCheck($this->app->make(Schedule::class)),
            new TimezoneCheck,
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
