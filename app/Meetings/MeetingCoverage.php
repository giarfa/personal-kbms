<?php

namespace App\Meetings;

/**
 * Whether a meeting carries operator notes or a linked transcript.
 * Both are hard `false` in US-005 — US-006 fills `hasNotes`, US-007 fills `hasTranscript`.
 */
final readonly class MeetingCoverage
{
    public function __construct(public bool $hasNotes = false, public bool $hasTranscript = false) {}
}
