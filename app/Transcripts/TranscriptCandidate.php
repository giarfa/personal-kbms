<?php

namespace App\Transcripts;

/**
 * One indexed file inside the tolerance window for a given occurrence.
 * `slugMatches` is an ordering hint only — it never filters candidates out
 * and never breaks a tie on its own.
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
