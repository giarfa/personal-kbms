<?php

namespace App\Meetings;

use Carbon\CarbonImmutable;

/**
 * One date's worth of agenda rows — always present for every date in the
 * range, even when `rows` is empty, so the per-day empty state is structural.
 */
final readonly class AgendaDay
{
    public int $meetingCount;

    public int $annotatedCount;

    /**
     * @param  list<AgendaRow>  $rows
     */
    public function __construct(public CarbonImmutable $date, public array $rows, public bool $isToday)
    {
        $this->meetingCount = count($rows);
        $this->annotatedCount = count(array_filter(
            $rows,
            fn (AgendaRow $row): bool => $row->coverage->hasNotes || $row->coverage->hasTranscript,
        ));
    }
}
