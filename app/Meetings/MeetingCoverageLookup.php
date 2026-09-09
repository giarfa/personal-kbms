<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptPattern;
use App\Transcripts\TranscriptResolver;
use App\Transcripts\TranscriptsDirectory;
use Illuminate\Support\Collection;

/**
 * Batches `meeting_notes` and `meeting_transcripts` reads so a whole agenda
 * week costs three queries regardless of density, plus exactly one
 * directory listing (via the injected, request-scoped TranscriptsDirectory)
 * when at least one occurrence needs dynamic convention resolution. The
 * composite unique index on (event_uid, event_recurrence_id) already serves
 * both whereIn('event_uid', ...) queries below by leftmost prefix, and the
 * single-occurrence read outright — do not add another index for this.
 *
 * `hasTranscript` never writes: an unambiguous convention match is reported
 * true without persisting a `meeting_transcripts` row — auto-linking stays
 * on the meeting-detail page (TranscriptPanel), never a list render.
 */
final class MeetingCoverageLookup
{
    public function __construct(private readonly TranscriptsDirectory $directory) {}

    /**
     * @param  Collection<int, CalendarEvent>  $events
     * @return array<string, MeetingCoverage>
     */
    public function for(Collection $events): array
    {
        $uids = $events->pluck('source_uid')->unique()->values();

        if ($uids->isEmpty()) {
            return [];
        }

        $notesByOccurrence = MeetingNote::query()
            ->select('event_uid', 'event_recurrence_id', 'body')
            ->whereIn('event_uid', $uids)
            ->get()
            ->filter(fn (MeetingNote $note): bool => $note->hasContent())
            ->keyBy(fn (MeetingNote $note): string => $note->event_uid."\0".$note->event_recurrence_id);

        $transcriptsByOccurrence = MeetingTranscript::query()
            ->select('event_uid', 'event_recurrence_id', 'path')
            ->whereIn('event_uid', $uids)
            ->get()
            ->keyBy(fn (MeetingTranscript $transcript): string => $transcript->event_uid."\0".$transcript->event_recurrence_id);

        $resolver = new TranscriptResolver($this->directory, TranscriptPattern::compile());

        $coverage = [];

        foreach ($events as $event) {
            $key = $event->source_uid."\0".$event->recurrence_id;
            $hasNotes = $notesByOccurrence->has($key);
            $hasTranscript = $this->hasReadableTranscript($event, $transcriptsByOccurrence->get($key), $resolver);

            if ($hasNotes || $hasTranscript) {
                $coverage[$key] = new MeetingCoverage(hasNotes: $hasNotes, hasTranscript: $hasTranscript);
            }
        }

        return $coverage;
    }

    public function forOccurrence(CalendarEvent $event): MeetingCoverage
    {
        $note = MeetingNote::query()->forOccurrence($event->occurrenceKey())->first();
        $transcript = MeetingTranscript::query()->forOccurrence($event->occurrenceKey())->first();
        $resolver = new TranscriptResolver($this->directory, TranscriptPattern::compile());

        return new MeetingCoverage(
            hasNotes: $note !== null && $note->hasContent(),
            hasTranscript: $this->hasReadableTranscript($event, $transcript, $resolver),
        );
    }

    /**
     * A transcript is linked and currently readable. A `Suppressed` (manual,
     * null path) or `Broken`/`Unreadable`/`Rejected` row badges `false` — the
     * row is kept, but coverage is not claimed for it. `Ambiguous` (no row,
     * 2+ candidates) also badges `false`: the agenda has no room for
     * "probably, pending a choice".
     */
    private function hasReadableTranscript(CalendarEvent $event, ?MeetingTranscript $row, TranscriptResolver $resolver): bool
    {
        if ($row !== null) {
            $path = $row->path;

            return $path !== null
                && $this->directory->contains($path)
                && is_file($path)
                && is_readable($path);
        }

        return count($resolver->candidatesFor($event)) === 1;
    }
}
