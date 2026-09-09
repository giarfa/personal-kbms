<?php

namespace App\Transcripts;

/**
 * Canonical base path and one-shot file index for `KBMS_TRANSCRIPTS_PATH`.
 * Bound `scoped` (see AppServiceProvider) so an agenda render lists the
 * directory once, not once per row.
 *
 * `realpath()` alone cannot decide containment: it returns `false` for a
 * path that simply does not exist, which would make a deleted-but-legitimate
 * path indistinguishable from an escape attempt and collapse the `Broken`
 * state into `Rejected`. So containment is checked lexically first — reject
 * a separator/null byte/`..` in a bare filename, then canonicalize the
 * joined path as a string and require the base as a prefix — and only then
 * confirmed with `realpath()`, which exists solely to catch a symlink
 * resolving outside the base. A path that passes the lexical check but does
 * not exist is left for the caller (TranscriptReader) to report as `Broken`,
 * never folded into `Rejected` here.
 */
final class TranscriptsDirectory
{
    private ?string $base = null;

    private bool $baseResolved = false;

    /** @var array<string, int>|null */
    private ?array $files = null;

    public function configured(): bool
    {
        return $this->base() !== null;
    }

    /**
     * Realpath of the configured base. Null when unset, missing, not a
     * directory, or unreadable — a configuration prompt, never an exception.
     */
    public function base(): ?string
    {
        if ($this->baseResolved) {
            return $this->base;
        }

        $this->baseResolved = true;

        $configured = config('kbms.transcripts_path');

        if (empty($configured)) {
            return $this->base = null;
        }

        $real = realpath($configured);

        if ($real === false || ! is_dir($real) || ! is_readable($real)) {
            return $this->base = null;
        }

        return $this->base = $real;
    }

    /**
     * Single-level listing of the configured extensions, case-insensitive,
     * mapping absolute path to mtime. Memoized for this instance's lifetime.
     * Never recurses into subdirectories — a nested pipeline layout is a
     * pattern change, not a code change.
     *
     * @return array<string, int>
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $base = $this->base();

        if ($base === null) {
            return $this->files = [];
        }

        /** @var list<string> $configuredExtensions */
        $configuredExtensions = config('kbms.transcript_extensions');
        $extensions = array_map(strtolower(...), $configuredExtensions);

        $entries = @scandir($base);

        if ($entries === false) {
            return $this->files = [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $base.DIRECTORY_SEPARATOR.$entry;

            if (! is_file($path)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            if (! in_array($extension, $extensions, true)) {
                continue;
            }

            // A symlink pointing outside the base must never surface as a
            // candidate — reject it here rather than trusting every later
            // consumer of files() to revalidate.
            if (! $this->contains($path)) {
                continue;
            }

            $mtime = filemtime($path);

            if ($mtime !== false) {
                $files[$path] = $mtime;
            }
        }

        return $this->files = $files;
    }

    /**
     * Whether an absolute path lies inside the base. Used to revalidate a
     * persisted path on every read, because the base itself is a `.env`
     * value that can change under a stored absolute path. Returns `true`
     * for a path that is lexically inside the base but does not exist on
     * disk — existence is not this method's concern, only containment.
     */
    public function contains(string $path): bool
    {
        $base = $this->base();

        if ($base === null || str_contains($path, "\0")) {
            return false;
        }

        $canonical = self::canonicalize($path);

        if (! str_starts_with($canonical, $base.DIRECTORY_SEPARATOR)) {
            return false;
        }

        $real = realpath($path);

        return $real === false || str_starts_with($real, $base.DIRECTORY_SEPARATOR);
    }

    /**
     * Joins a bare filename to the base, applying the same containment
     * rule. Returns null when the name carries a separator, a null byte,
     * `..`, or otherwise resolves outside the base. Existence is not
     * checked here — a nonexistent-but-contained result is a valid,
     * non-rejected path.
     */
    public function resolve(string $filename): ?string
    {
        $base = $this->base();

        if ($base === null) {
            return null;
        }

        if (
            str_contains($filename, "\0")
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, '..')
        ) {
            return null;
        }

        $joined = $base.DIRECTORY_SEPARATOR.$filename;

        return $this->contains($joined) ? $joined : null;
    }

    /**
     * Resolves `.` and `..` segments as a string, without touching the
     * filesystem — deliberately distinct from `realpath()`, which cannot
     * tell "escapes the base" apart from "does not exist".
     */
    private static function canonicalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $stack = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($stack);

                continue;
            }

            $stack[] = $part;
        }

        return ($isAbsolute ? DIRECTORY_SEPARATOR : '').implode(DIRECTORY_SEPARATOR, $stack);
    }
}
