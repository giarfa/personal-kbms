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
 *
 * `readUnbounded()` (US-017) shares the same containment/readability ladder
 * and the same renderer, and differs only in the limit passed to the shared
 * read: `null` reads the stream whole, for an export that must never be cut
 * at the preview bound.
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
        return $this->verify($absolutePath) ?? $this->readWithLimit(
            $absolutePath,
            max(0, (int) config('kbms.transcript_preview_bytes')),
        );
    }

    /**
     * The complete file, never truncated — for an export leaving the tool
     * rather than a bounded in-page preview. Same verification ladder, same
     * renderer/escaping contract as `read()`.
     */
    public function readUnbounded(string $absolutePath): TranscriptContent|TranscriptState
    {
        return $this->verify($absolutePath) ?? $this->readWithLimit($absolutePath, null);
    }

    /**
     * The containment/existence/readability ladder shared by `read()` and
     * `readUnbounded()`. Null means the path is usable; a `TranscriptState`
     * is the specific reason it is not — `Rejected` for a path outside the
     * base, `Broken` for one that is contained but absent, `Unreadable` for
     * one that exists but cannot be opened or stat'd.
     */
    public function verify(string $absolutePath): ?TranscriptState
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

        return null;
    }

    /**
     * `$limit` of `null` reads the stream whole (`readUnbounded()`); any
     * other value keeps the `fread($handle, $limit + 1)` truncation probe
     * and the `mb_strcut` UTF-8 boundary cut `read()` has always used.
     */
    private function readWithLimit(string $absolutePath, ?int $limit): TranscriptContent|TranscriptState
    {
        $totalBytes = filesize($absolutePath);
        $modifiedAt = filemtime($absolutePath);

        if ($totalBytes === false || $modifiedAt === false) {
            return TranscriptState::Unreadable;
        }

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            return TranscriptState::Unreadable;
        }

        if ($limit === null) {
            $chunk = stream_get_contents($handle);
            fclose($handle);

            if ($chunk === false) {
                return TranscriptState::Unreadable;
            }

            $truncated = false;
            $bounded = $chunk;
        } else {
            $chunk = fread($handle, $limit + 1);
            fclose($handle);

            if ($chunk === false) {
                return TranscriptState::Unreadable;
            }

            $truncated = strlen($chunk) > $limit;
            $bounded = $truncated ? mb_strcut($chunk, 0, $limit, 'UTF-8') : $chunk;
        }

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
