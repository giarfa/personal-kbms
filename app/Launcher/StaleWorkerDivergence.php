<?php

namespace App\Launcher;

use App\Models\PromptLaunch;

/**
 * The one place the dispatching web process's answer and the queue
 * worker's own answer are compared. `queue:work` is long-lived and resolves
 * `config('kbms.claude_launcher')` from the `.env` it booted with, so a
 * worker that has not been restarted since a launcher change can disagree
 * with the request that dispatched the launch. That divergence is a stale
 * worker, not an unset key — see US-016.
 */
final class StaleWorkerDivergence
{
    /**
     * Substitutes `LauncherStaleWorker` for `$block` when the worker's own
     * answer (this process's `config('kbms.claude_launcher')`) differs from
     * what the dispatching request recorded on `$launch->command[0]`.
     * Returns `$block` unchanged otherwise — including when `$launch`
     * carries no recorded command, or the two values agree, which leaves a
     * launcher key genuinely unset in both processes (or deleted after
     * dispatch with the same path both sides) on its existing message.
     */
    public static function resolve(LaunchBlock $block, PromptLaunch $launch): LaunchBlock
    {
        if (! in_array($block, [LaunchBlock::LauncherNotConfigured, LaunchBlock::LauncherMissing, LaunchBlock::LauncherNotExecutable], true)) {
            return $block;
        }

        $dispatchedPath = $launch->command[0] ?? null;

        if (! is_string($dispatchedPath) || $dispatchedPath === '') {
            return $block;
        }

        if ((string) config('kbms.claude_launcher') === $dispatchedPath) {
            return $block;
        }

        return LaunchBlock::LauncherStaleWorker;
    }
}
