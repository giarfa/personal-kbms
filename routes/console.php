<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Interval is a literal fallback until TASK-05 introduces config('kbms.sync_minutes').
Schedule::command('kbms:sync-calendar')
    ->cron('*/15 * * * *')
    ->withoutOverlapping();
