<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Doctor\Worker\QueueWorkerProbe;
use App\Doctor\Worker\QueueWorkerState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * `kbms:doctor` was structurally blind to one failure mode: `queue:work` is
 * long-lived and answers from the `.env` it booted with, so a fresh doctor
 * process seeing a reachable queue (`QueueConnectionCheck`) proves nothing
 * about whether the *running* worker is current. This check compares the
 * worker's effective start — its own start time, or the last
 * `queue:restart` broadcast, whichever is later — against `.env`'s mtime.
 * An answer this check cannot establish is reported as a FAILURE, never a
 * PASS: a false PASS here is exactly the defect under repair (see
 * US-016).
 *
 * `ps` and `filemtime()` are both second-granular, so a same-second tie
 * between the worker's effective start and the `.env` write is reported
 * STALE (`>=`, not `>`): the named remedy (`php artisan queue:restart`)
 * writes a strictly later restart timestamp, which clears the tie on the
 * next run.
 */
final class QueueWorkerFreshnessCheck implements EnvironmentCheck
{
    private readonly string $envPath;

    public function __construct(
        private readonly QueueWorkerProbe $probe,
        ?string $envPath = null,
    ) {
        $this->envPath = $envPath ?? base_path('.env');
    }

    public function label(): string
    {
        return 'Queue worker configuration';
    }

    public function run(): CheckResult
    {
        $envMtime = is_readable($this->envPath) ? @filemtime($this->envPath) : false;

        if ($envMtime === false) {
            return CheckResult::failed(
                "could not determine — \"{$this->envPath}\" is not readable",
                "this check refuses to pass when it cannot read \".env\"'s modification time — an undeterminable answer is reported as a failure by design"
            );
        }

        $observation = $this->probe->observe();

        return match ($observation->state) {
            QueueWorkerState::Undetermined => CheckResult::failed(
                "could not determine — {$observation->reason}",
                'run `php artisan queue:restart` after any `.env` change — this check refuses to pass when it cannot prove the worker is current'
            ),
            // The existing QueueConnectionCheck already owns "a reachable
            // queue is not the same as a running worker" — not restated
            // here, since no worker at all is not this check's business.
            QueueWorkerState::NotRunning => CheckResult::pass(
                'no `queue:work` worker for this project is running — nothing to be stale'
            ),
            QueueWorkerState::Running => $this->evaluateRunning($observation->startedAt, $envMtime),
        };
    }

    private function evaluateRunning(CarbonImmutable $startedAt, int $envMtime): CheckResult
    {
        // createFromTimestamp() defaults to UTC; $startedAt already renders
        // in the app timezone (it comes from the probe's now()), so every
        // timestamp compared or shown alongside it must be converted to the
        // same zone — otherwise the FAIL detail can read as though `.env`
        // predates the worker when it does not.
        $timezone = config('app.timezone');

        $restartBroadcastAt = Cache::get('illuminate:queue:restart');

        $effective = $restartBroadcastAt !== null
            ? $startedAt->max(CarbonImmutable::createFromTimestamp((int) $restartBroadcastAt, $timezone))
            : $startedAt;

        $envWrittenAt = CarbonImmutable::createFromTimestamp($envMtime, $timezone);

        if ($envWrittenAt->greaterThanOrEqualTo($effective)) {
            return CheckResult::failed(
                "worker started {$startedAt->toDateTimeString()}, \".env\" last written {$envWrittenAt->toDateTimeString()}",
                'the running queue worker booted before the current `.env` was last written — run `php artisan queue:restart`'
            );
        }

        return CheckResult::pass("worker started {$startedAt->toDateTimeString()}, after the current \".env\"");
    }
}
