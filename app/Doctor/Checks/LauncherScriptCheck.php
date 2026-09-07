<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

final class LauncherScriptCheck implements EnvironmentCheck
{
    public function label(): string
    {
        return 'Claude launcher script';
    }

    public function run(): CheckResult
    {
        $path = config('kbms.claude_launcher');

        if (empty($path)) {
            return CheckResult::notConfigured(
                'no launcher script configured',
                '`KBMS_CLAUDE_LAUNCHER` is not set — configure the absolute path to the launcher script'
            );
        }

        if (! is_file($path)) {
            return CheckResult::failed(
                "no file at \"{$path}\"",
                "`KBMS_CLAUDE_LAUNCHER` is set to a path with no file at \"{$path}\""
            );
        }

        if (! is_executable($path)) {
            return CheckResult::failed(
                "\"{$path}\" is not executable",
                "`KBMS_CLAUDE_LAUNCHER` the script exists but is not executable — run `chmod +x {$path}`"
            );
        }

        return CheckResult::pass("resolved to \"{$path}\"");
    }
}
