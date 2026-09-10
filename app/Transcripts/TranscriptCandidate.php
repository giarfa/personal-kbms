<?php

namespace App\Transcripts;

/**
 * One indexed file inside the tolerance window for a given occurrence.
 * `slugMatches` is an ordering hint only — it never filters candidates out
 * and never breaks a tie on its own. It is a PREFIX match: true when the
 * file's slug starts with the event's summary slug, not only on exact
 * equality.
 */
final readonly class TranscriptCandidate
{
    public function __construct(
        public string $path,
        public string $filename,
        public int $driftMinutes,
        public int $bytes,
        public int $mtime,
        public bool $slugMatches,
    ) {}
}
