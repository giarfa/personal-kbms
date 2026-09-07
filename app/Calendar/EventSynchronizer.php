<?php

namespace App\Calendar;

use App\Models\CalendarEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class EventSynchronizer
{
    /**
     * Persist parsed occurrences without ever deleting a row, then sweep the
     * window for occurrences that stopped appearing in the feed. Everything
     * runs inside a single transaction, so a mid-stream failure leaves the
     * mirror exactly as it was.
     *
     * @param  iterable<ParsedOccurrence>  $occurrences
     */
    public function synchronize(iterable $occurrences, CarbonInterface $runStartedAt, CarbonInterface $windowStart, CarbonInterface $windowEnd): SyncResult
    {
        return DB::transaction(function () use ($occurrences, $runStartedAt, $windowStart, $windowEnd) {
            $upserted = 0;

            foreach ($occurrences as $occurrence) {
                if ($this->upsertOne($occurrence, $runStartedAt)) {
                    $upserted++;
                }
            }

            $cancelled = $this->sweep($runStartedAt, $windowStart, $windowEnd);

            return new SyncResult($upserted, $cancelled);
        });
    }

    /**
     * @return bool Whether the row's mirrored content changed (i.e. counts toward `events_upserted`).
     */
    private function upsertOne(ParsedOccurrence $occurrence, CarbonInterface $runStartedAt): bool
    {
        $existing = CalendarEvent::query()
            ->where('source_uid', $occurrence->sourceUid)
            ->where('recurrence_id', $occurrence->recurrenceId)
            ->first();

        $contentHash = $occurrence->contentHash();

        $mirroredFields = [
            'summary' => $occurrence->summary,
            'description' => $occurrence->description,
            'location' => $occurrence->location,
            'organizer' => $occurrence->organizer,
            'attendees' => $occurrence->attendees,
            'starts_at' => $occurrence->startsAt,
            'ends_at' => $occurrence->endsAt,
            'is_all_day' => $occurrence->isAllDay,
            'timezone' => $occurrence->timezone,
            'join_url' => $occurrence->joinUrl,
            'event_url' => $occurrence->eventUrl,
            'content_hash' => $contentHash,
        ];

        if ($existing === null) {
            CalendarEvent::query()->create([
                ...$mirroredFields,
                'source_uid' => $occurrence->sourceUid,
                'recurrence_id' => $occurrence->recurrenceId,
                'last_seen_at' => $runStartedAt,
                'cancelled_at' => $occurrence->isCancelled ? $runStartedAt : null,
            ]);

            return true;
        }

        $changed = $existing->content_hash !== $contentHash;

        $updates = $changed ? $mirroredFields : [];
        $updates['last_seen_at'] = $runStartedAt;

        if ($occurrence->isCancelled && $existing->cancelled_at === null) {
            $updates['cancelled_at'] = $runStartedAt;
        } elseif (! $occurrence->isCancelled && $existing->cancelled_at !== null) {
            $updates['cancelled_at'] = null;
        }

        $existing->update($updates);

        return $changed;
    }

    /**
     * Mark occurrences that stopped appearing in the feed as cancelled.
     * Scoped to the window: without it, the first run would mark every
     * event older than the past window as cancelled.
     */
    private function sweep(CarbonInterface $runStartedAt, CarbonInterface $windowStart, CarbonInterface $windowEnd): int
    {
        return CalendarEvent::query()
            ->whereNull('cancelled_at')
            ->inWindow($windowStart, $windowEnd)
            ->where('last_seen_at', '<', $runStartedAt)
            ->update(['cancelled_at' => $runStartedAt]);
    }
}
