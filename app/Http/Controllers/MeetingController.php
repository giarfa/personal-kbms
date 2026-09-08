<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Illuminate\Contracts\View\View;

class MeetingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CalendarEvent $occurrence): View
    {
        return view('pages.meeting', ['event' => $occurrence]);
    }
}
