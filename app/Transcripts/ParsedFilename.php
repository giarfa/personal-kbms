<?php

namespace App\Transcripts;

use Carbon\CarbonImmutable;

/**
 * A transcript filename parsed by TranscriptPattern into its identity: the
 * naive datetime carried by the name (timezone is applied by the resolver,
 * not here) and the trailing slug portion, kept only as an ordering hint —
 * never a filter and never a tiebreaker. The resolver compares this slug
 * against the event's summary slug by PREFIX (`slug LIKE 'event_slug%'`),
 * not exact equality.
 */
final readonly class ParsedFilename
{
    public function __construct(public CarbonImmutable $datetime, public string $slug) {}
}
