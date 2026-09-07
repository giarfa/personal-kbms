<?php

namespace App\Support;

class DatabaseUnavailability
{
    public function __construct(
        public readonly string $path,
        public readonly bool $missing,
    ) {}
}
