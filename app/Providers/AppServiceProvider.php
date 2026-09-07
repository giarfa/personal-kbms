<?php

namespace App\Providers;

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
            // TASK-02/TASK-03/TASK-04 append their checks here, in report order.
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
