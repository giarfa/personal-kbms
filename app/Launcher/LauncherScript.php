<?php

namespace App\Launcher;

/**
 * Resolves and validates `KBMS_CLAUDE_LAUNCHER` — unset, no file, not
 * executable, or OK. Extracted out of `App\Doctor\Checks\LauncherScriptCheck`
 * so `kbms:doctor` and the launch panel answer the same question with the
 * same code and cannot drift.
 */
final class LauncherScript
{
    /**
     * Returns the resolved absolute path, or the specific reason it cannot
     * be used. Never throws.
     */
    public function resolve(): string|LaunchBlock
    {
        $path = config('kbms.claude_launcher');

        if (empty($path)) {
            return LaunchBlock::LauncherNotConfigured;
        }

        if (! is_file($path)) {
            return LaunchBlock::LauncherMissing;
        }

        if (! is_executable($path)) {
            return LaunchBlock::LauncherNotExecutable;
        }

        return $path;
    }
}
