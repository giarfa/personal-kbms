<?php

namespace App\Transcripts;

/**
 * The eight states a transcript panel can be in. `states.html`: "'Missing',
 * 'unreadable' and 'unconfigured' must not collapse into one message" — each
 * carries its own wording at the presentation layer, and tests assert the
 * string, not just the enum case.
 */
enum TranscriptState: string
{
    case NotConfigured = 'not_configured';
    case Missing = 'missing';
    case Ambiguous = 'ambiguous';
    case Linked = 'linked';
    case Broken = 'broken';
    case Unreadable = 'unreadable';
    case Rejected = 'rejected';
    case Suppressed = 'suppressed';

    /**
     * The distinct refusal wording shown when a state stops a transcript
     * export (US-017 print and clipboard-source routes) — shared so the two
     * routes cannot drift apart on what each state says. `TranscriptExporter`
     * only ever produces `Missing`, `Suppressed`, `Broken`, `Unreadable`, or
     * `Rejected`; the other three fall back to a generic sentence so the
     * match stays exhaustive against the full enum.
     */
    public function exportRefusalReason(): string
    {
        return match ($this) {
            self::Missing => __('No transcript is linked to this meeting.'),
            self::Suppressed => __('The transcript link was cleared for this meeting. There is nothing to export.'),
            self::Broken => __('The linked transcript file no longer exists.'),
            self::Unreadable => __('The linked transcript file exists but cannot be read.'),
            self::Rejected => __('The linked transcript path is outside the configured transcripts directory and is refused.'),
            self::NotConfigured, self::Ambiguous, self::Linked => __('This transcript cannot be exported right now.'),
        };
    }
}
