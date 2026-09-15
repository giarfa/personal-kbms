<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Transcripts\TranscriptExporter;
use App\Transcripts\TranscriptState;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a linked transcript's verbatim bytes for the clipboard path
 * (US-017) — complete, unbounded, re-verified on this request. Deliberately
 * `text/plain; charset=utf-8`, never `text/markdown` or `text/html`: a
 * transcript is untrusted third-party content and must not be interpretable
 * as markup by the browser. Streams via `response()->file()` rather than
 * reading into PHP memory — this path has no size bound by design, unlike
 * the bounded in-page preview.
 */
class MeetingTranscriptSourceController extends Controller
{
    public function __invoke(CalendarEvent $occurrence, TranscriptExporter $exporter): BinaryFileResponse|Response
    {
        $path = $exporter->pathFor($occurrence->occurrenceKey());

        if ($path instanceof TranscriptState) {
            return response($path->exportRefusalReason(), 404, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        return response()->file($path, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
