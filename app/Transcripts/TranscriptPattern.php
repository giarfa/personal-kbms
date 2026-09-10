<?php

namespace App\Transcripts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Compiles `KBMS_TRANSCRIPT_PATTERN` into a regex once per instance, instead
 * of rendering the pattern at every minute in the tolerance window and
 * testing every filename against every rendering (see TranscriptResolver,
 * which builds a minute-keyed index from this class instead).
 *
 * `{time}` renders as `Hi` — no colon. A filename cannot portably carry `:`
 * (it is the legacy path separator on macOS), and the recorded convention's
 * own example is `20260907_1430_standup.md`. This is a DELIBERATE divergence
 * from `KBMS_OUTLOOK_URL_TEMPLATE`, where `{time}` is `H:i` — do not
 * "simplify" the two templates to match.
 *
 * `{date}` renders/parses as `Ymd` and `{slug}` is underscore-separated —
 * this replaced the hyphenated `Y-m-d` convention as a hard cutover.
 *
 * `parse()`/`render()` operate on the filename STEM (no extension) — the
 * extension is the reader's/directory's concern, not the naming convention's.
 */
final class TranscriptPattern
{
    public const DEFAULT_PATTERN = '{date}_{time}_{slug}';

    private function __construct(
        private readonly string $pattern,
        private readonly string $regex,
    ) {}

    /**
     * Compiles from `kbms.transcript_pattern`. A pattern missing `{date}` or
     * not ending in `{slug}` would either match nothing or let `{slug}`'s
     * `(.*)` swallow the date — validated here, not discovered at
     * match-time. Falls back to the packaged default with a logged warning
     * rather than compiling a regex that silently matches nothing.
     */
    public static function compile(): self
    {
        $pattern = (string) config('kbms.transcript_pattern');

        if (! self::isValid($pattern)) {
            Log::warning('kbms.transcript_pattern is invalid (must contain {date} and end with {slug}) — falling back to the default.', [
                'pattern' => $pattern,
            ]);

            $pattern = self::DEFAULT_PATTERN;
        }

        return new self($pattern, self::toRegex($pattern));
    }

    /**
     * Parses a filename stem (no extension) into its datetime and slug
     * part. Null when the name does not match this pattern — an
     * unparseable name is not an error, it is a file this convention does
     * not claim.
     */
    public function parse(string $stem): ?ParsedFilename
    {
        if (! preg_match($this->regex, $stem, $matches)) {
            return null;
        }

        $date = $matches['date'] ?? null;
        $time = $matches['time'] ?? '0000';
        $slug = $matches['slug'] ?? '';

        if ($date === null) {
            return null;
        }

        $datetime = CarbonImmutable::createFromFormat('Ymd Hi', "{$date} {$time}");

        if ($datetime === null) {
            return null;
        }

        if ($datetime->format('Ymd Hi') !== "{$date} {$time}") {
            return null;
        }

        return new ParsedFilename($datetime, $slug);
    }

    /**
     * Renders a filename stem for the given datetime and slug — the same
     * compiler the resolver matches against, so seeded demo data cannot
     * drift from the matcher.
     */
    public function render(CarbonInterface $at, string $slug): string
    {
        return strtr($this->pattern, [
            '{date}' => $at->format('Ymd'),
            '{time}' => $at->format('Hi'),
            '{slug}' => Str::slug($slug, '_'),
        ]);
    }

    private static function isValid(string $pattern): bool
    {
        return $pattern !== '' && str_contains($pattern, '{date}') && str_ends_with($pattern, '{slug}');
    }

    private static function toRegex(string $pattern): string
    {
        $placeholders = [
            '{date}' => '(?P<date>\d{8})',
            '{time}' => '(?P<time>\d{4})',
            '{slug}' => '(?P<slug>.*)',
        ];

        // Split on the placeholders, escape every literal chunk in between,
        // then splice the placeholder regexes back in — so a regex
        // metacharacter in a custom pattern's literal portion (e.g. a
        // literal ".") is escaped rather than interpreted.
        $chunks = preg_split('/(\{date\}|\{time\}|\{slug\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $regex = '';

        foreach ($chunks as $chunk) {
            $regex .= $placeholders[$chunk] ?? preg_quote($chunk, '#');
        }

        return '#^'.$regex.'$#u';
    }
}
