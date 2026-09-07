<?php

namespace App\Calendar;

final readonly class SyncResult
{
    public function __construct(
        public int $upserted,
        public int $cancelled,
    ) {}
}
