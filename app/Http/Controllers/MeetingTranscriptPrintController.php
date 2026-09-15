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
                'reason' => $this->reasonFor($export),
            ], 404);
        }

        return response()->view('pages.print.transcript', [
            'row' => new AgendaRow($occurrence),
            'export' => $export,
        ]);
    }

    private function reasonFor(TranscriptState $state): string
    {
        return match ($state) {
            TranscriptState::Missing => __('No transcript is linked to this meeting.'),
            TranscriptState::Suppressed => __('The transcript link was cleared for this meeting. There is nothing to export.'),
            TranscriptState::Broken => __('The linked transcript file no longer exists.'),
            TranscriptState::Unreadable => __('The linked transcript file exists but cannot be read.'),
            TranscriptState::Rejected => __('The linked transcript path is outside the configured transcripts directory and is refused.'),
            // TranscriptExporter never returns these three — kept only so the
            // match stays exhaustive against the full enum.
            TranscriptState::NotConfigured, TranscriptState::Ambiguous, TranscriptState::Linked => __('This transcript cannot be exported right now.'),
        };
    }
}
