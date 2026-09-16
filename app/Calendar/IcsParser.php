<?php

namespace App\Calendar;

use App\Calendar\Exceptions\FeedUnparsable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property\ICalendar\DateTime as ICalendarDateTime;
use Sabre\VObject\Reader;
use Throwable;

final class IcsParser
{
    private const TEAMS_LINK_PATTERN = '#https://teams\.microsoft\.com/l/meetup-join/[^\s<>"^`{}|\[\]\\\\]+#i';

    /**
     * Parse an iCalendar body into individually addressable occurrences,
     * bounded to the given window. Yields lazily — a wide window across
     * several long-running series can be thousands of VEVENTs.
     *
     * @return Generator<int, ParsedOccurrence>
     */
    public function parse(string $body, CarbonInterface $windowStart, CarbonInterface $windowEnd): Generator
    {
        $document = $this->read($body);

        $timezones = $this->harvestTimezones($document);
        $recurringUids = $this->harvestRecurringUids($document);

        try {
            $expanded = $document->expand($windowStart->clone()->utc(), $windowEnd->clone()->utc());
        } catch (Throwable $exception) {
            throw FeedUnparsable::expansionFailed($exception->getMessage());
        }

        /** @var VEvent $vevent */
        foreach ($expanded->select('VEVENT') as $vevent) {
            yield $this->buildOccurrence($vevent, $timezones, $recurringUids);
        }
    }

    private function read(string $body): VCalendar
    {
        try {
            $document = Reader::read($body, Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            throw FeedUnparsable::notCalendar();
        }

        if (! $document instanceof VCalendar) {
            throw FeedUnparsable::notCalendar();
        }

        return $document;
    }

    /**
     * Harvest the TZID of every master VEVENT's DTSTART, keyed by UID.
     * Expansion normalizes everything to UTC and discards VTIMEZONE, so this
     * map is the only surviving source for the timezone column.
     *
     * @return array<string, string>
     */
    private function harvestTimezones(VCalendar $document): array
    {
        $map = [];

        /** @var VEvent $vevent */
        foreach ($document->select('VEVENT') as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                continue;
            }

            $dtstart = $this->dateTimeProperty($vevent, 'DTSTART');
            $tzid = $dtstart?->offsetGet('TZID');

            if ($tzid instanceof Parameter) {
                $map[$this->uidOf($vevent)] = (string) $tzid;
            }
        }

        return $map;
    }

    /**
     * UIDs that carry an RRULE, RDATE, or RECURRENCE-ID anywhere in the
     * document — the same criterion sabre/vobject uses internally to decide
     * whether a UID is a recurring series. A non-recurring event's
     * recurrence_id is always ''; a recurring one's is derived below.
     *
     * @return array<string, true>
     */
    private function harvestRecurringUids(VCalendar $document): array
    {
        $uids = [];

        /** @var VEvent $vevent */
        foreach ($document->select('VEVENT') as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'}) || isset($vevent->RRULE) || isset($vevent->RDATE)) {
                $uids[$this->uidOf($vevent)] = true;
            }
        }

        return $uids;
    }

    /**
     * @param  array<string, string>  $timezones
     * @param  array<string, true>  $recurringUids
     */
    private function buildOccurrence(VEvent $vevent, array $timezones, array $recurringUids): ParsedOccurrence
    {
        $uid = $this->uidOf($vevent);
        $dtstart = $this->dateTimeProperty($vevent, 'DTSTART')
            ?? throw FeedUnparsable::expansionFailed("VEVENT {$uid} has no DTSTART");
        $isAllDay = ! $dtstart->hasTime();

        $startsAt = $this->readDateTime($dtstart, $isAllDay);
        $dtend = $this->dateTimeProperty($vevent, 'DTEND');
        $endsAt = $dtend !== null ? $this->readDateTime($dtend, $isAllDay) : $startsAt;

        $recurrenceId = $this->resolveRecurrenceId($vevent, $recurringUids, $isAllDay, $startsAt);

        return new ParsedOccurrence(
            sourceUid: $uid,
            recurrenceId: $recurrenceId,
            summary: isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : null,
            description: isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
            location: isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
            organizer: $this->readOrganizer($vevent),
            attendees: $this->readAttendees($vevent),
            startsAt: $startsAt,
            endsAt: $endsAt,
            isAllDay: $isAllDay,
            timezone: $isAllDay ? null : ($timezones[$uid] ?? null),
            joinUrl: $this->readJoinUrl($vevent),
            eventUrl: isset($vevent->URL) ? (string) $vevent->URL : null,
            isCancelled: isset($vevent->STATUS) && strtoupper((string) $vevent->STATUS) === 'CANCELLED',
        );
    }

    /**
     * @param  array<string, true>  $recurringUids
     */
    private function resolveRecurrenceId(VEvent $vevent, array $recurringUids, bool $isAllDay, CarbonImmutable $startsAt): string
    {
        if (! isset($recurringUids[$this->uidOf($vevent)])) {
            return '';
        }

        if (isset($vevent->{'RECURRENCE-ID'})) {
            return $this->formatRecurrenceId($this->readDateTime($vevent->{'RECURRENCE-ID'}, $isAllDay), $isAllDay);
        }

        return $this->formatRecurrenceId($startsAt, $isAllDay);
    }

    private function formatRecurrenceId(CarbonImmutable $dateTime, bool $isAllDay): string
    {
        return $isAllDay ? $dateTime->format('Y-m-d') : $dateTime->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * All-day (VALUE=DATE) values are read from the raw serialized text and
     * built as naive dates — never routed through timezone conversion, since
     * that would shift the date under any positive UTC offset.
     */
    private function readDateTime(mixed $property, bool $isAllDay): CarbonImmutable
    {
        if ($isAllDay) {
            $raw = (string) $property;

            return CarbonImmutable::createFromFormat('Ymd', $raw, 'UTC')->startOfDay();
        }

        return CarbonImmutable::instance($property->getDateTime());
    }

    private function readOrganizer(VEvent $vevent): ?string
    {
        if (! isset($vevent->ORGANIZER)) {
            return null;
        }

        $name = $vevent->ORGANIZER['CN'] ?? null;
        $email = $this->stripMailto((string) $vevent->ORGANIZER);

        return match (true) {
            $name !== null && $email !== null => "{$name} <{$email}>",
            $email !== null => $email,
            $name !== null => (string) $name,
            default => null,
        };
    }

    /**
     * @return array<int, array{name: ?string, email: ?string}>
     */
    private function readAttendees(VEvent $vevent): array
    {
        $attendees = [];

        foreach ($vevent->select('ATTENDEE') as $attendee) {
            $attendees[] = [
                'name' => isset($attendee['CN']) ? (string) $attendee['CN'] : null,
                'email' => $this->stripMailto((string) $attendee),
            ];
        }

        return $attendees;
    }

    private function readJoinUrl(VEvent $vevent): ?string
    {
        if (isset($vevent->{'X-MICROSOFT-SKYPETEAMSMEETINGURL'})) {
            return (string) $vevent->{'X-MICROSOFT-SKYPETEAMSMEETINGURL'};
        }

        $description = isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : '';

        if (preg_match(self::TEAMS_LINK_PATTERN, $description, $matches) === 1) {
            return $this->stripEscapedWrapper($matches[0]);
        }

        return null;
    }

    /**
     * Outlook's own HTML-escaped wrapper (`&gt;`) survives the character
     * class in TEAMS_LINK_PATTERN because `&`, `g`, `t`, `;` are all valid
     * URL characters. Trim it as a guarded trailing suffix, re-checking the
     * pattern so this never eats characters that are legitimately part of
     * the URL.
     */
    private function stripEscapedWrapper(string $url): string
    {
        $trimmed = preg_replace('/(?:&gt;)+$/i', '', $url);

        if ($trimmed === null || preg_match(self::TEAMS_LINK_PATTERN, $trimmed) !== 1) {
            return $url;
        }

        return $trimmed;
    }

    private function stripMailto(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return preg_replace('/^mailto:/i', '', $value) ?: null;
    }

    /**
     * `UID` is declared via `@property` on the vendor Component base class,
     * which Larastan does not resolve through magic property access —
     * select() is the array-typed API and sidesteps that gap.
     */
    private function uidOf(VEvent $vevent): string
    {
        $matches = $vevent->select('UID');

        return $matches === [] ? '' : (string) $matches[0];
    }

    private function dateTimeProperty(VEvent $vevent, string $name): ?ICalendarDateTime
    {
        $matches = $vevent->select($name);
        $match = $matches[0] ?? null;

        return $match instanceof ICalendarDateTime ? $match : null;
    }
}
