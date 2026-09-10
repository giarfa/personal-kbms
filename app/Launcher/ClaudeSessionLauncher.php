<?php

namespace App\Launcher;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Runs a LaunchCommand through the Process facade and waits for its exit
 * code, under a bounded timeout. No overload accepts a string — the only
 * way to invoke a process here is with the array Process::run() hands
 * straight to Symfony's proc_open, so there is nothing for a shell to
 * parse or escape.
 */
final class ClaudeSessionLauncher
{
    /**
     * Bytes kept from a failed process's stderr — a runaway script must not
     * write an unbounded blob into the launch row.
     */
    private const STDERR_EXCERPT_LENGTH = 2000;

    public function run(LaunchCommand $command): LaunchOutcome
    {
        try {
            $result = Process::timeout(config('kbms.launch_timeout_seconds'))->run($command->toArray());
        } catch (ProcessTimedOutException $exception) {
            $seconds = config('kbms.launch_timeout_seconds');

            return LaunchOutcome::timedOut(
                "the launcher did not return within {$seconds}s — it must exit once it has spawned its own terminal window"
            );
        }

        if ($result->successful()) {
            return LaunchOutcome::launched((int) $result->exitCode());
        }

        return LaunchOutcome::failed(
            (int) $result->exitCode(),
            Str::limit($result->errorOutput(), self::STDERR_EXCERPT_LENGTH, '')
        );
    }
}
