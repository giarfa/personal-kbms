<?php

namespace App\Doctor\Worker;

use Carbon\CarbonImmutable;

/**
 * The outcome of one `QueueWorkerProbe::observe()` call. `$startedAt` is
 * populated only for `Running`; `$reason` only for `Undetermined`, and
 * always names the tool that failed to answer.
 */
final readonly class QueueWorkerObservation
{
    private function __construct(
        public QueueWorkerState $state,
        public ?CarbonImmutable $startedAt = null,
        public ?string $reason = null,
    ) {}

    public static function running(CarbonImmutable $startedAt): self
    {
        return new self(QueueWorkerState::Running, startedAt: $startedAt);
    }

    public static function notRunning(): self
    {
        return new self(QueueWorkerState::NotRunning);
    }

    public static function undetermined(string $reason): self
    {
        return new self(QueueWorkerState::Undetermined, reason: $reason);
    }
}
