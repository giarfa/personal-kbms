<?php

use App\Calendar\SyncHealthReporter;
use App\Http\Controllers\CalendarEventsController;
use App\Http\Controllers\MeetingController;
use App\Livewire\Agenda;
use App\Meetings\CalendarRange;
use App\Meetings\EventColourRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', Agenda::class)->name('agenda');

Route::get('calendar/events', CalendarEventsController::class)->name('calendar.events');

Route::get('calendar', function (Request $request) {
    return view('pages.calendar', [
        'range' => CalendarRange::fromRequest($request->query('view'), $request->query('date')),
        'today' => CarbonImmutable::now(config('kbms.timezone'))->toDateString(),
        'syncHealth' => app(SyncHealthReporter::class)->current(),
        // Same source as the agenda legend, so the two pages cannot disagree
        // about what a colour means (US-012).
        'colourRules' => EventColourRules::fromConfig()->all(),
    ]);
})->name('calendar');

Route::get('meetings/{occurrence}', MeetingController::class)->name('meetings.show');
