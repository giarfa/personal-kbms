<?php

namespace App\Providers;

use App\Doctor\Checks\IcsFeedCheck;
use App\Doctor\Checks\LauncherScriptCheck;
use App\Doctor\Checks\QueueConnectionCheck;
use App\Doctor\Checks\QueueWorkerFreshnessCheck;
use App\Doctor\Checks\SchedulerRegistrationCheck;
use App\Doctor\Checks\TimezoneCheck;
use App\Doctor\Checks\TranscriptsDirectoryCheck;
use App\Doctor\CheckSuite;
use App\Doctor\Worker\PsQueueWorkerProbe;
use App\Doctor\Worker\QueueWorkerProbe;
use App\Meetings\OccurrenceKey;
use App\Models\CalendarEvent;
use App\Transcripts\TranscriptsDirectory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(QueueWorkerProbe::class, PsQueueWorkerProbe::class);

        $this->app->singleton(CheckSuite::class, fn (): CheckSuite => new CheckSuite([
            new IcsFeedCheck,
            new TranscriptsDirectoryCheck,
            new LauncherScriptCheck,
            new QueueConnectionCheck,
            new QueueWorkerFreshnessCheck($this->app->make(QueueWorkerProbe::class)),
            new SchedulerRegistrationCheck($this->app->make(Schedule::class)),
            new TimezoneCheck,
        ]));

        // Scoped, not singleton: an agenda render must list the directory
        // once per request, not once per row, but a queue worker or test
        // run processing multiple "requests" must not share a stale index.
        $this->app->scoped(TranscriptsDirectory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::bind('occurrence', fn (string $value): CalendarEvent => CalendarEvent::query()
            ->forOccurrence(OccurrenceKey::fromRouteKey($value) ?? abort(404))
            ->firstOrFail());
    }
}
