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
}
