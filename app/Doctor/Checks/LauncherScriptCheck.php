<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Launcher\LaunchBlock;
use App\Launcher\LauncherScript;
use LogicException;

final class LauncherScriptCheck implements EnvironmentCheck
{
    public function __construct(private readonly LauncherScript $launcherScript = new LauncherScript) {}

    public function label(): string
    {
        return 'Claude launcher script';
    }

    public function run(): CheckResult
    {
        $path = config('kbms.claude_launcher');
        $result = $this->launcherScript->resolve();

        if (! $result instanceof LaunchBlock) {
            return CheckResult::pass("resolved to \"{$result}\"");
        }

        return match ($result) {
            LaunchBlock::LauncherNotConfigured => CheckResult::notConfigured(
                'no launcher script configured',
                '`KBMS_CLAUDE_LAUNCHER` is not set — configure the absolute path to the launcher script'
            ),
            LaunchBlock::LauncherMissing => CheckResult::failed(
                "no file at \"{$path}\"",
                "`KBMS_CLAUDE_LAUNCHER` is set to a path with no file at \"{$path}\""
            ),
            LaunchBlock::LauncherNotExecutable => CheckResult::failed(
                "\"{$path}\" is not executable",
                "`KBMS_CLAUDE_LAUNCHER` the script exists but is not executable — run `chmod +x {$path}`"
            ),
            default => throw new LogicException("LauncherScript::resolve() returned an unexpected block: {$result->name}"),
        };
    }
}
