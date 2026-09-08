<?php

namespace App\Calendar;

use App\Jobs\SyncCalendarFeed;
use Illuminate\Support\Facades\Cache;

class ResyncDispatcher
{
    public function __construct(private readonly SyncHealthReporter $reporter) {}

    /**
     * Dispatch a manual resync, refusing when a run is already in progress.
     */
    public function dispatch(): ResyncOutcome
    {
        if ($this->reporter->current()->state === SyncHealthState::Syncing) {
            return ResyncOutcome::AlreadyRunning;
        }

        Cache::put(
            SyncHealthReporter::DISPATCH_MARKER_KEY,
            now()->toIso8601String(),
            (int) config('kbms.sync_stuck_after_seconds'),
        );

        SyncCalendarFeed::dispatch();

        return ResyncOutcome::Dispatched;
    }
}
