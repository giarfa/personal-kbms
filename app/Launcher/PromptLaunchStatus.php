<?php

namespace App\Launcher;

enum PromptLaunchStatus: string
{
    case Queued = 'queued';
    case Launched = 'launched';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Blocked = 'blocked';
}
