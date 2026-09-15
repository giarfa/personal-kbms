<?php

namespace App\Http\Controllers;

use App\Meetings\AgendaRow;
use App\Meetings\MeetingCoverageLookup;
use App\Meetings\OutlookLinkBuilder;
use App\Meetings\SeriesNeighbourLookup;
use App\Models\CalendarEvent;
use Illuminate\Contracts\View\View;

class MeetingController extends Controller
{
    public function __invoke(
        CalendarEvent $occurrence,
        OutlookLinkBuilder $links,
        MeetingCoverageLookup $lookup,
        SeriesNeighbourLookup $neighbours,
    ): View {
        return view('pages.meeting', [
            'row' => new AgendaRow($occurrence, $lookup->forOccurrence($occurrence)),
            'outlookLink' => $links->for($occurrence),
            'teamsUrl' => $links->teamsUrl($occurrence),
            'neighbours' => $neighbours->forOccurrence($occurrence),
        ]);
    }
}
