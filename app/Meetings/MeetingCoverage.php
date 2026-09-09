<?php

namespace App\Meetings;

/**
 * Whether a meeting carries operator notes or a linked transcript.
 * `hasNotes` reflects a non-blank `meeting_notes.body` (US-006) — a blank-bodied
 * note reads as not annotated. `hasTranscript` is defined narrowly (US-007):
 * a transcript is linked AND currently readable. Broken, unreadable,
 * ambiguous and suppressed all read as `false` — the agenda's three-tag
 * vocabulary (Notes / Transcript / Not annotated) has no room for
 * "probably, pending a choice", and claiming coverage the meeting page
 * then contradicts is worse than under-claiming.
 */
final readonly class MeetingCoverage
{
    public function __construct(public bool $hasNotes = false, public bool $hasTranscript = false) {}
}
