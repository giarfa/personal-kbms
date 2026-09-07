<?php

namespace App\Jobs;

use App\Calendar\EventSynchronizer;
use App\Calendar\Exceptions\FeedUnavailable;
use App\Calendar\Exceptions\FeedUnparsable;
use App\Calendar\IcsFeedClient;
use App\Calendar\IcsParser;
use App\Models\CalendarSyncRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncCalendarFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public bool $force = false) {}

    /**
     * Fetch, parse, and mirror the configured ICS feed, then prune old runs.
     */
    public function handle(IcsFeedClient $client, IcsParser $parser, EventSynchronizer $synchronizer): void
    {
        $run = CalendarSyncRun::start();

        $validators = $this->force ? null : CalendarSyncRun::latestValidators();

        try {
            $response = $client->fetch($validators?->etag, $validators?->last_modified);
        } catch (FeedUnavailable $exception) {
            $run->fail($exception->getMessage(), $exception->httpStatus);
            Log::warning('kbms.calendar.sync', [
                'run_id' => $run->id,
                'status' => $run->status->value,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($response->notModified) {
            $run->markNotModified($response->etag, $response->lastModified);
            $this->prune();
            $this->logResult($run);

            return;
        }

        $windowStart = now()->subDays(config('kbms.window_past_days'));
        $windowEnd = now()->addDays(config('kbms.window_future_days'));

        try {
            $result = $synchronizer->synchronize(
                $parser->parse($response->body, $windowStart, $windowEnd),
                now(),
                $windowStart,
                $windowEnd,
            );
        } catch (FeedUnparsable $exception) {
            $run->fail($exception->getMessage(), $response->httpStatus);
            Log::warning('kbms.calendar.sync', [
                'run_id' => $run->id,
                'status' => $run->status->value,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $run->succeed($response->httpStatus, $response->etag, $response->lastModified, $result->upserted, $result->cancelled);

        $this->prune();
        $this->logResult($run);
    }

    /**
     * Delete finished runs older than the configured retention window.
     */
    private function prune(): void
    {
        CalendarSyncRun::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subDays(config('kbms.sync_run_retention_days')))
            ->delete();
    }

    private function logResult(CalendarSyncRun $run): void
    {
        Log::info('kbms.calendar.sync', [
            'run_id' => $run->id,
            'status' => $run->status->value,
            'events_upserted' => $run->events_upserted,
            'events_cancelled' => $run->events_cancelled,
        ]);
    }
}
