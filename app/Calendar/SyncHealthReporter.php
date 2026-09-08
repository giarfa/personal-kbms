<?php

namespace App\Calendar;

use App\Models\CalendarSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class SyncHealthReporter
{
    /**
     * Cache key set by ResyncDispatcher right before dispatching, so a page
     * render between dispatch and the worker picking up the job still shows
     * "Syncing" instead of a stale pill.
     */
    public const string DISPATCH_MARKER_KEY = 'kbms.sync.dispatched_at';

    /**
     * How long the dispatch marker is trusted at face value before the pill
     * starts warning that no worker has picked the job up yet.
     */
    private const int AWAITING_WORKER_GRACE_SECONDS = 5;

    /**
     * Resolve the current sync health from the run history, abandoning any
     * stuck `Running` row first so the verdict is based on honest history.
     */
    public function current(): SyncHealth
    {
        $active = CalendarSyncRun::activeRun();

        if ($active !== null) {
            $active = $this->abandonIfStuck($active);
        }

        if ($active !== null) {
            return new SyncHealth(state: SyncHealthState::Syncing);
        }

        $dispatchedAt = Cache::get(self::DISPATCH_MARKER_KEY);

        if ($dispatchedAt !== null) {
            return new SyncHealth(
                state: SyncHealthState::Syncing,
                awaitingWorker: now()->diffInSeconds(CarbonImmutable::parse($dispatchedAt), true) > self::AWAITING_WORKER_GRACE_SECONDS,
            );
        }

        $lastSuccess = CalendarSyncRun::latestSuccess();

        if ($lastSuccess === null) {
            // Defensive: no run at all, or only ever-failed runs with no success
            // to fall back on — both read as "never synced" to the operator.
            return new SyncHealth(state: SyncHealthState::Never);
        }

        $lastSuccessAt = $lastSuccess->started_at->toImmutable();
        $finished = CalendarSyncRun::latestFinished();

        if ($finished !== null && $finished->status === SyncRunStatus::Failed) {
            return new SyncHealth(
                state: SyncHealthState::Failed,
                lastSuccessAt: $lastSuccessAt,
                lastAttemptAt: $finished->started_at->toImmutable(),
                lastError: $finished->error,
                lastErrorAt: $finished->finished_at?->toImmutable(),
                httpStatus: $finished->http_status,
            );
        }

        $staleAfterSeconds = (int) config('kbms.sync_minutes') * (int) config('kbms.sync_stale_multiplier') * 60;

        if (now()->diffInSeconds($lastSuccessAt, true) > $staleAfterSeconds) {
            return new SyncHealth(state: SyncHealthState::Stale, lastSuccessAt: $lastSuccessAt);
        }

        return new SyncHealth(state: SyncHealthState::Ok, lastSuccessAt: $lastSuccessAt);
    }

    /**
     * Abandon a `Running` row whose worker died, so it stops reading as
     * "Syncing" forever. Returns null when the row was abandoned.
     */
    private function abandonIfStuck(CalendarSyncRun $run): ?CalendarSyncRun
    {
        $stuckAfterSeconds = (int) config('kbms.sync_stuck_after_seconds');

        if (now()->diffInSeconds($run->started_at, true) < $stuckAfterSeconds) {
            return $run;
        }

        $run->abandon(
            "the sync worker did not finish this run within {$stuckAfterSeconds}s; check that `php artisan queue:work` is running"
        );

        return null;
    }
}
