<?php

use App\Http\Controllers\CalendarEventsController;
use App\Http\Controllers\MeetingController;
use App\Livewire\Agenda;
use Illuminate\Support\Facades\Route;

Route::get('/', Agenda::class)->name('agenda');

Route::get('calendar/events', CalendarEventsController::class)->name('calendar.events');

Route::view('calendar', 'pages.calendar')->name('calendar');

Route::get('meetings/{occurrence}', MeetingController::class)->name('meetings.show');
