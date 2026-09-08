<?php

namespace App\Http\Controllers;

use App\Meetings\AgendaRow;
use App\Meetings\OutlookLinkBuilder;
use App\Models\CalendarEvent;
use Illuminate\Contracts\View\View;

class MeetingController extends Controller
{
    public function __invoke(CalendarEvent $occurrence, OutlookLinkBuilder $links): View
    {
        return view('pages.meeting', [
            'row' => new AgendaRow($occurrence),
            'outlookLink' => $links->for($occurrence),
            'teamsUrl' => $links->teamsUrl($occurrence),
        ]);
    }
}
