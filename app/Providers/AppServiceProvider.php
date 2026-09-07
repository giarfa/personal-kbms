<?php

namespace App\Providers;

use App\Doctor\Checks\IcsFeedCheck;
use App\Doctor\Checks\LauncherScriptCheck;
use App\Doctor\Checks\TimezoneCheck;
use App\Doctor\Checks\TranscriptsDirectoryCheck;
use App\Doctor\CheckSuite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CheckSuite::class, fn (): CheckSuite => new CheckSuite([
            // TASK-04 inserts the queue/schedule checks between LauncherScriptCheck and TimezoneCheck.
            new IcsFeedCheck,
            new TranscriptsDirectoryCheck,
            new LauncherScriptCheck,
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
