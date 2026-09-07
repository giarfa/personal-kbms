<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Registered stub only — US-003 owns the ICS sync implementation.
 */
#[Signature('kbms:sync-calendar')]
#[Description('Sync the ICS calendar feed (not implemented — US-003)')]
class SyncCalendarCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->error('kbms:sync-calendar is not implemented — US-003');

        return self::FAILURE;
    }
}
