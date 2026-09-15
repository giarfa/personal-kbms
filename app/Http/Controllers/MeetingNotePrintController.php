<?php

namespace App\Http\Controllers;

use App\Meetings\AgendaRow;
use App\Meetings\MarkdownRenderer;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Illuminate\Http\Response;

/**
 * The chrome-free print view for a meeting's note (US-017). A missing row or
 * a blank body renders the stated-unavailable page rather than an empty
 * document — sharing never offers to print nothing.
 */
class MeetingNotePrintController extends Controller
{
    public function __invoke(CalendarEvent $occurrence, MarkdownRenderer $renderer): Response
    {
        $note = MeetingNote::query()->forOccurrence($occurrence->occurrenceKey())->first();

        if ($note === null || ! $note->hasContent()) {
            return response()->view('pages.print.unavailable', [
                'reason' => __('This note is empty. There is nothing to share.'),
            ], 404);
        }

        return response()->view('pages.print.note', [
            'row' => new AgendaRow($occurrence),
            'body' => $renderer->render($note->body),
        ]);
    }
}
