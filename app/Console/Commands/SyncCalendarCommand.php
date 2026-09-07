<?php

namespace App\Console\Commands;

use App\Calendar\SyncRunStatus;
use App\Jobs\SyncCalendarFeed;
use App\Models\CalendarSyncRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kbms:sync-calendar {--sync : Run inline instead of queueing} {--force : Ignore stored ETag/Last-Modified}')]
#[Description('Sync the ICS calendar feed')]
class SyncCalendarCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (! $this->option('sync')) {
            SyncCalendarFeed::dispatch($force);

            $this->components->info('Dispatched SyncCalendarFeed. Run `php artisan queue:work --stop-when-empty` to process it.');

            return self::SUCCESS;
        }

        SyncCalendarFeed::dispatchSync($force);

        $run = CalendarSyncRun::query()->latest('started_at')->first();

        if ($run === null) {
            $this->components->error('The sync ran but no run was recorded.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Sync %s: %d upserted, %d cancelled.',
            $run->status->value,
            $run->events_upserted,
            $run->events_cancelled,
        ));

        return $run->status === SyncRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
