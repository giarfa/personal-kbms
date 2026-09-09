<?php

namespace App\Meetings;

/**
 * Whether a meeting carries operator notes or a linked transcript.
 * `hasNotes` reflects a non-blank `meeting_notes.body` (US-006) — a blank-bodied
 * note reads as not annotated. `hasTranscript` stays a hard `false`, marked for US-007.
 */
final readonly class MeetingCoverage
{
    public function __construct(public bool $hasNotes = false, public bool $hasTranscript = false) {}
}
