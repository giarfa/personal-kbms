<?php

namespace App\Transcripts;

use App\Meetings\OccurrenceKey;
use App\Models\MeetingTranscript;

/**
 * Resolves an occurrence's transcript export target directly from the
 * persisted `meeting_transcripts` row — deliberately no candidate/convention
 * fallback. Export must reflect exactly what the panel currently shows as
 * Linked, never a guess the operator has not accepted by linking it.
 */
final class TranscriptExporter
{
    public function __construct(
        private readonly TranscriptReader $reader,
    ) {}

    /**
     * `Missing` when no row exists, `Suppressed` for the manual-unlink
     * tombstone (a non-null row with a null `path`), otherwise the stored
     * path re-verified at call time, or the `TranscriptState` explaining
     * why it is not usable.
     */
    public function pathFor(OccurrenceKey $key): string|TranscriptState
    {
        $row = MeetingTranscript::query()->forOccurrence($key)->first();

        if ($row === null) {
            return TranscriptState::Missing;
        }

        if ($row->path === null) {
            return TranscriptState::Suppressed;
        }

        return $this->reader->verify($row->path) ?? $row->path;
    }

    /**
     * Layers an unbounded read on top of pathFor() — the same distinct
     * failure states, plus the complete file content on success.
     */
    public function forOccurrence(OccurrenceKey $key): TranscriptExport|TranscriptState
    {
        $path = $this->pathFor($key);

        if ($path instanceof TranscriptState) {
            return $path;
        }

        $content = $this->reader->readUnbounded($path);

        if ($content instanceof TranscriptState) {
            return $content;
        }

        return new TranscriptExport($path, $content);
    }
}
