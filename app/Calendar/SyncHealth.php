<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;

final readonly class SyncHealth
{
    public function __construct(
        public SyncHealthState $state,
        public ?CarbonImmutable $lastSuccessAt = null,
        public ?CarbonImmutable $lastAttemptAt = null,
        public ?string $lastError = null,
        public ?CarbonImmutable $lastErrorAt = null,
        public ?int $httpStatus = null,
        public bool $awaitingWorker = false,
    ) {}

    public function hasEverSynced(): bool
    {
        return $this->lastSuccessAt !== null;
    }

    public function needsAttention(): bool
    {
        return in_array($this->state, [SyncHealthState::Failed, SyncHealthState::Stale], true);
    }
}
