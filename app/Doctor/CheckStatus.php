<?php

namespace App\Doctor;

enum CheckStatus
{
    case Pass;
    case NotConfigured;
    case Failed;

    public function isPassing(): bool
    {
        return $this === self::Pass;
    }
}
