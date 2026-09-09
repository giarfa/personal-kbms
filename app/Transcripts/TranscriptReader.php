<?php

namespace App\Transcripts;

use App\Meetings\MarkdownRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\HtmlString;

/**
 * Reads a linked transcript for display without ever loading it whole, and
 * without collapsing missing / unreadable / rejected into one failure.
 *
 * Never `file_get_contents()`: `fopen` + a single
 * `fread($handle, $limit + 1)` reads one byte past the cap, which is how
 * truncation is detected without reading the remainder of a large file.
 * Truncation lands on a UTF-8 boundary (`mb_strcut`) — a byte-count cut
 * mid-codepoint produces invalid UTF-8, which Blade renders as a
 * replacement character and can upset CommonMark.
 *
 * Reuses MarkdownRenderer rather than a second converter — a transcript is
 * more obviously untrusted third-party content than a note the operator
 * typed themselves.
 */
final class TranscriptReader
{
    public function __construct(
        private readonly TranscriptsDirectory $directory,
        private readonly MarkdownRenderer $renderer,
    ) {}

    /**
     * Revalidates containment on every call — `KBMS_TRANSCRIPTS_PATH` is a
     * `.env` value that can change under a stored absolute path, so a link
     * that was valid at link time is not assumed valid forever.
     */
    public function read(string $absolutePath): TranscriptContent|TranscriptState
    {
        if (! $this->directory->contains($absolutePath)) {
            return TranscriptState::Rejected;
        }

        if (! is_file($absolutePath)) {
            return TranscriptState::Broken;
        }

        if (! is_readable($absolutePath)) {
            return TranscriptState::Unreadable;
        }

        $totalBytes = filesize($absolutePath);
        $modifiedAt = filemtime($absolutePath);

        if ($totalBytes === false || $modifiedAt === false) {
            return TranscriptState::Unreadable;
        }

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            return TranscriptState::Unreadable;
        }

        $limit = max(0, (int) config('kbms.transcript_preview_bytes'));
        $chunk = fread($handle, $limit + 1);
        fclose($handle);

        if ($chunk === false) {
            return TranscriptState::Unreadable;
        }

        $truncated = strlen($chunk) > $limit;
        $bounded = $truncated ? mb_strcut($chunk, 0, $limit, 'UTF-8') : $chunk;

        $isMarkdown = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'md';

        return new TranscriptContent(
            rendered: $isMarkdown ? $this->renderer->render($bounded) : new HtmlString(e($bounded)),
            isMarkdown: $isMarkdown,
            truncated: $truncated,
            bytesRead: strlen($bounded),
            totalBytes: $totalBytes,
            modifiedAt: CarbonImmutable::createFromTimestamp($modifiedAt),
        );
    }
}
