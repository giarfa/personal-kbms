<?php

namespace App\Http\Controllers;

use App\Meetings\AgendaRow;
use App\Models\CalendarEvent;
use App\Transcripts\TranscriptExporter;
use App\Transcripts\TranscriptState;
use Illuminate\Http\Response;

/**
 * The chrome-free print view for a meeting's transcript (US-017). Every
 * refusal state gets its own stated reason at 404 — never a blank or
 * partial document — and verification happens on this request, exactly as
 * the panel's preview does, so a file removed after the detail page loaded
 * is caught here too.
 */
class MeetingTranscriptPrintController extends Controller
{
    public function __invoke(CalendarEvent $occurrence, TranscriptExporter $exporter): Response
    {
        $export = $exporter->forOccurrence($occurrence->occurrenceKey());

        if ($export instanceof TranscriptState) {
            return response()->view('pages.print.unavailable', [
                'reason' => $export->exportRefusalReason(),
            ], 404);
        }

        return response()->view('pages.print.transcript', [
            'row' => new AgendaRow($occurrence),
            'export' => $export,
        ]);
    }
}
