<?php

namespace App\Meetings;

use App\Models\CalendarEvent;

/**
 * The mirrored feed fields a colour rule may inspect (US-012).
 *
 * Deliberately closed and deliberately small: every case is a scalar string
 * column `AgendaQuery` and `CalendarFeedQuery` already select, which is what
 * makes rule matching free of per-occurrence queries.
 */
enum EventColourField: string
{
    case Summary = 'summary';
    case Location = 'location';
    case Description = 'description';
    case Organizer = 'organizer';

    public function valueFor(CalendarEvent $event): ?string
    {
        /** @var string|null $value */
        $value = $event->getAttribute($this->value);

        return $value;
    }
}
