<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.agenda')->name('agenda');

Route::view('calendar', 'pages.calendar')->name('calendar');
