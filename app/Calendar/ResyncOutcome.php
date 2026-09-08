<?php

namespace App\Calendar;

enum ResyncOutcome: string
{
    case Dispatched = 'dispatched';
    case AlreadyRunning = 'already_running';
}
