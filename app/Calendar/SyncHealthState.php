<?php

namespace App\Calendar;

enum SyncHealthState: string
{
    case Never = 'never';
    case Syncing = 'syncing';
    case Ok = 'ok';
    case Stale = 'stale';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never synced',
            self::Syncing => 'Syncing now…',
            self::Ok => 'Synced',
            self::Stale => 'stale',
            self::Failed => 'Sync failed',
        };
    }
}
