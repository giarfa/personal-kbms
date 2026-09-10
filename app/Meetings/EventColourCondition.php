<?php

namespace App\Meetings;

use Illuminate\Support\Str;

/**
 * The two match conditions US-012 supports, and deliberately no others.
 */
enum EventColourCondition: string
{
    /**
     * Case-insensitive substring match — `PING`, `Ping` and `ping` all hit.
     */
    case Contains = 'contains';

    /**
     * The field is `null`, absent, or contains only whitespace. An ICS feed
     * emitting `LOCATION:` followed by a single space counts as empty.
     */
    case Empty = 'empty';

    public function matches(?string $fieldValue, ?string $needle): bool
    {
        return match ($this) {
            // A null or empty needle would match every occurrence, because
            // str_contains($x, '') is always true. EventColourRule rejects such
            // a rule outright; this guard is the second line of that defence.
            self::Contains => $needle !== null
                && $needle !== ''
                && str_contains(Str::lower((string) $fieldValue), Str::lower($needle)),
            self::Empty => trim((string) $fieldValue) === '',
        };
    }
}
