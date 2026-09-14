<?php

namespace App\Doctor\Worker;

/**
 * Answers "is a `queue:work` worker for this project running, and since
 * when?" against the live machine. Implementations must never throw, and
 * must never report `NotRunning` for a question they could not actually
 * answer — that is `Undetermined`'s job, since a false `NotRunning` (like a
 * false pass on the freshness check built on top of it) would hide the
 * exact defect this probe exists to surface.
 */
interface QueueWorkerProbe
{
    public function observe(): QueueWorkerObservation;
}
