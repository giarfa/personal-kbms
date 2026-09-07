<?php

namespace App\Console\Commands;

use App\Jobs\QueueSelfTestJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kbms:queue-test')]
#[Description('Dispatch a self-test job to prove the database queue worker processes jobs')]
class QueueSelfTestCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        QueueSelfTestJob::dispatch();

        $this->components->info(
            'Dispatched QueueSelfTestJob. Run `php artisan queue:work --stop-when-empty` '
            .'then check storage/logs for the "kbms.queue.self-test" entry.'
        );

        return self::SUCCESS;
    }
}
