<?php

namespace App\Calendar\Exceptions;

use RuntimeException;

final class FeedUnparsable extends RuntimeException
{
    public static function notCalendar(): self
    {
        return new self('the response body is not a valid iCalendar VCALENDAR document');
    }

    public static function expansionFailed(string $reason): self
    {
        return new self("recurrence expansion failed: {$reason}");
    }
}
