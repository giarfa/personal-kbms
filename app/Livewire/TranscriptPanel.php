<?php

namespace App\Livewire;

use App\Meetings\OccurrenceKey;
use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptCandidate;
use App\Transcripts\TranscriptLinkSource;
use App\Transcripts\TranscriptPattern;
use App\Transcripts\TranscriptReader;
use App\Transcripts\TranscriptResolver;
use App\Transcripts\TranscriptsDirectory;
use App\Transcripts\TranscriptState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The meeting-detail transcript surface: resolve, auto-link, preview,
 * relink, clear, and every failure state as its own message.
 *
 * Stores only the occurrence's natural key, never the model — a
 * Livewire-hydrated model would reintroduce the surrogate id as identity.
 * Auto-linking happens only in mount() (once per full page load) and in
 * useConvention() (once per that action) — render() is read-only, so a
 * Livewire update never writes.
 */
class TranscriptPanel extends Component
{
    #[Locked]
    public string $eventUid = '';

    #[Locked]
    public string $eventRecurrenceId = '';

    public string $filter = '';

    public string $selectedCandidate = '';

    public function mount(CalendarEvent $occurrence): void
    {
        $this->eventUid = $occurrence->source_uid;
        $this->eventRecurrenceId = $occurrence->recurrence_id;

        if (MeetingTranscript::query()->forOccurrence($this->occurrenceKey())->doesntExist()) {
            $this->maybeAutoLinkFromConvention($occurrence);
        }
    }

    /**
     * A candidate chosen from the ambiguous chooser is always persisted as
     * `manual` — only the fully-unambiguous auto-link path uses
     * `convention`. `$filename` is client input (a Livewire action
     * parameter), so it is re-validated against the canonical base here,
     * never trusted because it happened to come from the server-rendered list.
     */
    public function link(string $filename): void
    {
        $directory = app(TranscriptsDirectory::class);
        $resolved = $directory->resolve($filename);

        if ($resolved === null) {
            return;
        }

        $mtime = $directory->files()[$resolved] ?? null;
        $bytes = is_file($resolved) ? filesize($resolved) : false;

        MeetingTranscript::query()->updateOrCreate(
            ['event_uid' => $this->eventUid, 'event_recurrence_id' => $this->eventRecurrenceId],
            [
                'path' => $resolved,
                'link_source' => TranscriptLinkSource::Manual,
                'file_size' => $bytes === false ? null : $bytes,
                'file_mtime' => $mtime !== null ? CarbonImmutable::createFromTimestamp($mtime) : null,
                'linked_at' => now(),
            ]
        );

        $this->selectedCandidate = '';
    }

    /**
     * Wraps link() for the ambiguous chooser's shared "Link selected"
     * button, bound to the radio group via $selectedCandidate.
     */
    public function linkSelected(): void
    {
        $this->link($this->selectedCandidate);
    }

    /**
     * Writes the manual-unlink tombstone. Never deletes the file — the
     * pipeline owns it. The convention will not re-link this occurrence
     * until useConvention() removes the row.
     */
    public function clearLink(): void
    {
        MeetingTranscript::query()->updateOrCreate(
            ['event_uid' => $this->eventUid, 'event_recurrence_id' => $this->eventRecurrenceId],
            [
                'path' => null,
                'link_source' => TranscriptLinkSource::Manual,
                'file_size' => null,
                'file_mtime' => null,
                'linked_at' => null,
            ]
        );
    }

    /**
     * Deletes the row and reopens the occurrence to convention resolution,
     * auto-linking immediately when the reopened match is unambiguous.
     */
    public function useConvention(): void
    {
        MeetingTranscript::query()->forOccurrence($this->occurrenceKey())->delete();

        $event = $this->currentEvent();

        if ($event !== null) {
            $this->maybeAutoLinkFromConvention($event);
        }
    }

    public function render(): View
    {
        $row = MeetingTranscript::query()->forOccurrence($this->occurrenceKey())->first();

        return view('livewire.transcript-panel', $this->buildViewData($row));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(?MeetingTranscript $row): array
    {
        $directory = app(TranscriptsDirectory::class);

        if (! $directory->configured()) {
            return ['state' => TranscriptState::NotConfigured, 'pickerFiles' => []];
        }

        $base = ['pickerFiles' => $this->pickerFiles($directory)];

        if ($row !== null) {
            $path = $row->path;

            if ($path === null) {
                return $base + ['state' => TranscriptState::Suppressed];
            }

            return $base + $this->readerViewData($path, $row->link_source);
        }

        $event = $this->currentEvent();
        $candidates = $event !== null
            ? (new TranscriptResolver($directory, TranscriptPattern::compile()))->candidatesFor($event)
            : [];

        return match (true) {
            // Only reachable if mount()'s auto-link somehow did not persist
            // (e.g. the directory only became configured mid-request) —
            // rendered exactly like a convention link, never written here.
            count($candidates) === 1 => $base + $this->readerViewData($candidates[0]->path, TranscriptLinkSource::Convention),
            count($candidates) > 1 => $base + ['state' => TranscriptState::Ambiguous, 'candidates' => $candidates],
            default => $base + ['state' => TranscriptState::Missing, 'candidates' => []],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function readerViewData(string $path, TranscriptLinkSource $source): array
    {
        $result = app(TranscriptReader::class)->read($path);

        if ($result instanceof TranscriptState) {
            return ['state' => $result, 'path' => $path, 'linkSource' => $source];
        }

        return [
            'state' => TranscriptState::Linked,
            'path' => $path,
            'linkSource' => $source,
            'content' => $result,
            // The footer shows the file's CURRENT stat, not the row's
            // linked-at snapshot — a file that changed under the link must
            // stay visible rather than described from a stale row.
            'sizeLabel' => $this->formatBytes($result->totalBytes),
            'modifiedLabel' => $this->formatModified($result->modifiedAt),
            'printUrl' => route('meetings.transcript.print', $this->occurrenceKey()->toRouteKey()),
            'sourceUrl' => route('meetings.transcript.source', $this->occurrenceKey()->toRouteKey()),
        ];
    }

    /**
     * Public so the Blade view can format the configured preview cap for
     * the truncation notice.
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $kb = $bytes / 1024;

        if ($kb < 1024) {
            return number_format($kb, 1).' KB';
        }

        return number_format($kb / 1024, 1).' MB';
    }

    private function formatModified(CarbonImmutable $at): string
    {
        return $at->isToday() ? 'today '.$at->format('H:i') : $at->format('j M H:i');
    }

    private function maybeAutoLinkFromConvention(CalendarEvent $event): void
    {
        $directory = app(TranscriptsDirectory::class);

        if (! $directory->configured()) {
            return;
        }

        $candidates = (new TranscriptResolver($directory, TranscriptPattern::compile()))->candidatesFor($event);

        if (count($candidates) !== 1) {
            return;
        }

        $this->persistConventionLink($candidates[0]);
    }

    private function persistConventionLink(TranscriptCandidate $candidate): void
    {
        MeetingTranscript::query()->updateOrCreate(
            ['event_uid' => $this->eventUid, 'event_recurrence_id' => $this->eventRecurrenceId],
            [
                'path' => $candidate->path,
                'link_source' => TranscriptLinkSource::Convention,
                'file_size' => $candidate->bytes,
                'file_mtime' => CarbonImmutable::createFromTimestamp($candidate->mtime),
                'linked_at' => now(),
            ]
        );
    }

    /**
     * Newest-first, filtered by $filter — the in-app picker (decision
     * journal: "transcript manual override mechanism"). No free-text path
     * field: the operator can only choose an entry the server itself
     * indexed inside the base.
     *
     * @return list<string>
     */
    private function pickerFiles(TranscriptsDirectory $directory): array
    {
        $needle = strtolower($this->filter);

        return collect($directory->files())
            ->sortByDesc(fn (int $mtime): int => $mtime)
            ->keys()
            ->map(fn (string $path): string => basename($path))
            ->filter(fn (string $name): bool => $needle === '' || str_contains(strtolower($name), $needle))
            ->values()
            ->all();
    }

    private function currentEvent(): ?CalendarEvent
    {
        return CalendarEvent::query()->forOccurrence($this->occurrenceKey())->first();
    }

    private function occurrenceKey(): OccurrenceKey
    {
        return new OccurrenceKey($this->eventUid, $this->eventRecurrenceId);
    }
}
