<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Support\Facades\Queue;

final class QueueConnectionCheck implements EnvironmentCheck
{
    public function label(): string
    {
        return 'Queue connection';
    }

    public function run(): CheckResult
    {
        $connectionName = config('queue.default');

        try {
            $size = Queue::connection($connectionName)->size();
        } catch (\Throwable $e) {
            $hint = "`QUEUE_CONNECTION` (\"{$connectionName}\") is not reachable: {$e->getMessage()}";

            if ($connectionName === 'database') {
                $hint .= ' — if the `jobs` table is missing, run `php artisan migrate`';
            }

            return CheckResult::failed(
                "connecting to the \"{$connectionName}\" queue failed",
                $hint
            );
        }

        return CheckResult::pass(
            "driver \"{$connectionName}\", {$size} pending job(s) — a reachable queue is not the same as a running worker (`php artisan queue:work`)"
        );
    }
}
