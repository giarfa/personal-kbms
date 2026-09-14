<?php

namespace App\Doctor\Worker;

/**
 * The three answers a `QueueWorkerProbe` can give to "is a worker for this
 * project running, and since when?" — `Undetermined` is a first-class
 * outcome, not an exception: a probe that cannot establish the answer must
 * say so rather than guess `NotRunning` (see US-016).
 */
enum QueueWorkerState
{
    case Running;
    case NotRunning;
    case Undetermined;
}
