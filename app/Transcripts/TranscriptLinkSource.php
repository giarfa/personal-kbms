<?php

namespace App\Transcripts;

/**
 * How a `meeting_transcripts` row came to point at its file. A manual row —
 * including one whose `path` is NULL, the unlink tombstone — always wins
 * over a convention match.
 */
enum TranscriptLinkSource: string
{
    case Convention = 'convention';
    case Manual = 'manual';
}
