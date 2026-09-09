<?php

namespace App\Transcripts;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns an occurrence into an ordered candidate set and decides link vs.
 * ambiguous vs. nothing. Never guesses: two or more candidates always
 * surface for the operator, including when one is an exact datetime AND
 * slug match — the slug orders the list, it never filters and never breaks
 * a tie. Performs no database writes; persistence is the caller's job, and
 * only from the meeting-detail page, never the agenda.
 *
 * Every indexed filename is parsed once through TranscriptPattern into a
 * map keyed `Y-m-d H:i`, so resolving N occurrences costs N times a handful
 * of map lookups rather than N x window x files regex comparisons.
 */
final class TranscriptResolver
{
    /**
     * @var array<string, list<array{path: string, filename: string, slug: string, mtime: int, bytes: int}>>|null
     */
    private ?array $index = null;

    public function __construct(
        private readonly TranscriptsDirectory $directory,
        private readonly TranscriptPattern $pattern,
    ) {}

    /**
     * @return list<TranscriptCandidate>
     */
    public function candidatesFor(CalendarEvent $event): array
    {
        $start = CarbonImmutable::instance($event->starts_at);
        $summarySlug = Str::slug((string) $event->summary);

        $entries = $event->is_all_day
            ? $this->entriesForDate($start)
            : $this->entriesForWindow($start);

        $candidates = array_map(
            fn (array $entry): TranscriptCandidate => new TranscriptCandidate(
                path: $entry['path'],
                filename: $entry['filename'],
                driftMinutes: $entry['drift'],
                bytes: $entry['bytes'],
                mtime: $entry['mtime'],
                slugMatches: $entry['slug'] === $summarySlug,
            ),
            $entries
        );

        usort($candidates, function (TranscriptCandidate $a, TranscriptCandidate $b): int {
            $driftCompare = abs($a->driftMinutes) <=> abs($b->driftMinutes);

            if ($driftCompare !== 0) {
                return $driftCompare;
            }

            $slugCompare = (int) $b->slugMatches <=> (int) $a->slugMatches;

            if ($slugCompare !== 0) {
                return $slugCompare;
            }

            return $a->filename <=> $b->filename;
        });

        return $candidates;
    }

    /**
     * 0 candidates -> Missing, 1 -> Linked (by convention), >=2 -> Ambiguous.
     * `NotConfigured` is not this method's concern — the caller checks
     * `TranscriptsDirectory::configured()` before ever asking for candidates.
     */
    public function stateFor(CalendarEvent $event): TranscriptState
    {
        return match (count($this->candidatesFor($event))) {
            0 => TranscriptState::Missing,
            1 => TranscriptState::Linked,
            default => TranscriptState::Ambiguous,
        };
    }

    /**
     * @return list<array{path: string, filename: string, slug: string, mtime: int, bytes: int, drift: int}>
     */
    private function entriesForWindow(CarbonImmutable $start): array
    {
        $tolerance = (int) config('kbms.transcript_tolerance_minutes');
        $index = $this->index();
        $entries = [];

        for ($drift = -$tolerance; $drift <= $tolerance; $drift++) {
            $key = $start->addMinutes($drift)->format('Y-m-d H:i');

            foreach ($index[$key] ?? [] as $entry) {
                $entries[] = [...$entry, 'drift' => $drift];
            }
        }

        return $entries;
    }

    /**
     * All-day occurrences have no meaningful start minute, so they match
     * `{date}` alone across every indexed time on that date.
     *
     * @return list<array{path: string, filename: string, slug: string, mtime: int, bytes: int, drift: int}>
     */
    private function entriesForDate(CarbonImmutable $start): array
    {
        $date = $start->format('Y-m-d');
        $entries = [];

        foreach ($this->index() as $key => $group) {
            if (! str_starts_with($key, $date)) {
                continue;
            }

            foreach ($group as $entry) {
                $entries[] = [...$entry, 'drift' => 0];
            }
        }

        return $entries;
    }

    /**
     * @return array<string, list<array{path: string, filename: string, slug: string, mtime: int, bytes: int}>>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->directory->files() as $path => $mtime) {
            $filename = basename($path);
            $stem = pathinfo($filename, PATHINFO_FILENAME);
            $parsed = $this->pattern->parse($stem);

            if ($parsed === null) {
                continue;
            }

            $key = $parsed->datetime->format('Y-m-d H:i');
            $bytes = @filesize($path);

            $index[$key][] = [
                'path' => $path,
                'filename' => $filename,
                'slug' => $parsed->slug,
                'mtime' => $mtime,
                'bytes' => $bytes === false ? 0 : $bytes,
            ];
        }

        return $this->index = $index;
    }
}
