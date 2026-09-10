<?php

namespace App\Jobs;

use App\Launcher\ClaudeSessionLauncher;
use App\Launcher\LaunchBlock;
use App\Launcher\LaunchBlockDetail;
use App\Launcher\LaunchPreflight;
use App\Launcher\PromptLaunchStatus;
use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Models\PromptLaunch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class LaunchClaudeSession implements ShouldQueue
{
    use Queueable;

    /**
     * Not negotiable: a retried job opens a second terminal window on the
     * operator's machine, and there is no key that could suppress the
     * duplicate.
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $launchId)
    {
        // Never fires before Process::timeout() inside ClaudeSessionLauncher,
        // so the worker's own timeout never steals the process timeout's
        // error message.
        $this->timeout = (int) config('kbms.launch_timeout_seconds') + 15;
    }

    public function handle(LaunchPreflight $preflight, ClaudeSessionLauncher $launcher): void
    {
        $launch = PromptLaunch::query()->find($this->launchId);

        if ($launch === null || $launch->status !== PromptLaunchStatus::Queued) {
            return;
        }

        $occurrence = CalendarEvent::query()->forOccurrence($launch->occurrenceKey())->first();

        // Re-run preflight: the script, the transcript file, and
        // KBMS_TRANSCRIPTS_PATH are all mutable between dispatch and
        // execution. A second-pass failure is an outcome the operator
        // needs to read, not an exception — nothing is run.
        $result = $occurrence !== null
            ? $preflight->for($occurrence, $launch->question)
            : LaunchBlock::NoTranscript;

        if ($result instanceof LaunchBlock) {
            $transcriptPath = MeetingTranscript::query()->forOccurrence($launch->occurrenceKey())->first()?->path;

            $launch->update([
                'status' => PromptLaunchStatus::Blocked,
                'error' => $result->message(LaunchBlockDetail::for($result, $launch->question, $transcriptPath)),
            ]);

            Log::info('kbms.launch', [
                'launch_id' => $launch->id,
                'status' => PromptLaunchStatus::Blocked->value,
                'exit_code' => null,
            ]);

            return;
        }

        $launch->update(['launched_at' => now()]);

        $outcome = $launcher->run($result);

        $launch->update([
            'status' => $outcome->status,
            'exit_code' => $outcome->exitCode,
            'error' => $outcome->error,
        ]);

        Log::info('kbms.launch', [
            'launch_id' => $launch->id,
            'status' => $outcome->status->value,
            'exit_code' => $outcome->exitCode,
        ]);
    }
}
