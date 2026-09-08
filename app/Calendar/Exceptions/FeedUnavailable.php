<?php

namespace App\Calendar\Exceptions;

use RuntimeException;

final class FeedUnavailable extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $httpStatus)
    {
        parent::__construct($message);
    }

    public static function connectionFailed(string $url, string $reason): self
    {
        return new self("could not reach the feed at {$url}: {$reason}", httpStatus: null);
    }

    public static function httpError(int $status): self
    {
        return new self("the feed responded with HTTP {$status}", httpStatus: $status);
    }

    public static function notConfigured(string $envKey): self
    {
        return new self("{$envKey} is not set", httpStatus: null);
    }
}
