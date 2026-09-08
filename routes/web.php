<?php

use App\Http\Controllers\MeetingController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.agenda')->name('agenda');

Route::view('calendar', 'pages.calendar')->name('calendar');

Route::get('meetings/{occurrence}', MeetingController::class)->name('meetings.show');
