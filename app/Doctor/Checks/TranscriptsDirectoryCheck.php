<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

final class TranscriptsDirectoryCheck implements EnvironmentCheck
{
    public function label(): string
    {
        return 'Transcripts directory';
    }

    public function run(): CheckResult
    {
        $path = config('kbms.transcripts_path');

        if (empty($path)) {
            return CheckResult::notConfigured(
                'no transcripts directory configured',
                '`KBMS_TRANSCRIPTS_PATH` is not set — configure the absolute path to the transcripts directory'
            );
        }

        if (! is_dir($path)) {
            return CheckResult::failed(
                "\"{$path}\" does not exist",
                '`KBMS_TRANSCRIPTS_PATH` path does not exist or is not a directory'
            );
        }

        if (! is_readable($path)) {
            return CheckResult::failed(
                "\"{$path}\" is not readable",
                '`KBMS_TRANSCRIPTS_PATH` directory exists but is not readable by the web/CLI user — check permissions'
            );
        }

        return CheckResult::pass("resolved to \"{$path}\"");
    }
}
