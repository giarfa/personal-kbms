<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class QueueSelfTestJob implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('kbms.queue.self-test', [
            'dispatched_at' => now()->toIso8601String(),
            'connection' => config('queue.default'),
        ]);
    }
}
