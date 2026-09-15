<?php

namespace App\Transcripts;

/**
 * A resolved, fully-read transcript ready to leave the tool — the print
 * view and the clipboard source route both consume this rather than
 * re-resolving the occurrence themselves.
 */
final readonly class TranscriptExport
{
    public function __construct(
        public string $path,
        public TranscriptContent $content,
    ) {}

    public function filename(): string
    {
        return basename($this->path);
    }
}
