<?php

namespace App\Calendar;

enum SyncRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case NotModified = 'not_modified';
    case Failed = 'failed';
}
