<?php

namespace App\Transcripts;

use Carbon\CarbonImmutable;
use Illuminate\Support\HtmlString;

/**
 * A bounded, already-rendered read of a transcript file. `rendered` is
 * always safe to echo raw in Blade: `.md` went through MarkdownRenderer,
 * `.txt` is HTML-escaped. Content is held only for the render that produced
 * it — nothing here is cached to disk or database.
 */
final readonly class TranscriptContent
{
    public function __construct(
        public HtmlString $rendered,
        public bool $isMarkdown,
        public bool $truncated,
        public int $bytesRead,
        public int $totalBytes,
        public CarbonImmutable $modifiedAt,
    ) {}
}
