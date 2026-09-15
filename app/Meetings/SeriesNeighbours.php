<?php

namespace App\Meetings;

/**
 * Both directions out of one occurrence of a recurring series (US-018).
 *
 * The pair always exists for a recurring occurrence — a side with nothing in
 * it is a `SeriesNeighbour` carrying its reason, not a missing property. The
 * absence of this object altogether is what says "this is a one-off meeting".
 */
final readonly class SeriesNeighbours
{
    public function __construct(
        public SeriesNeighbour $previous,
        public SeriesNeighbour $next,
    ) {}
}
