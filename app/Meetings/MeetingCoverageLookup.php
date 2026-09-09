<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Illuminate\Support\Collection;

/**
 * Batches `meeting_notes` reads so a whole agenda week costs one query
 * regardless of density. The composite unique index on
 * (event_uid, event_recurrence_id) already serves the whereIn('event_uid', ...)
 * below by leftmost prefix, and the single-occurrence read outright — do not
 * add another index for this.
 */
final class MeetingCoverageLookup
{
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

        return MeetingNote::query()
            ->select('event_uid', 'event_recurrence_id', 'body')
            ->whereIn('event_uid', $uids)
            ->get()
            ->filter(fn (MeetingNote $note): bool => $note->hasContent())
            ->mapWithKeys(fn (MeetingNote $note): array => [
                $note->event_uid."\0".$note->event_recurrence_id => new MeetingCoverage(hasNotes: true),
            ])
            ->all();
    }

    public function forOccurrence(CalendarEvent $event): MeetingCoverage
    {
        $note = MeetingNote::query()->forOccurrence($event->occurrenceKey())->first();

        return new MeetingCoverage(hasNotes: $note !== null && $note->hasContent());
    }
}
