<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

final class TimezoneCheck implements EnvironmentCheck
{
    public function label(): string
    {
        return 'Timezone';
    }

    public function run(): CheckResult
    {
        $timezone = config('kbms.timezone');

        if (empty($timezone)) {
            return CheckResult::notConfigured(
                'no timezone configured',
                '`KBMS_TIMEZONE` is not set — configure an IANA timezone identifier (e.g. `Europe/Rome`)'
            );
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return CheckResult::failed(
                "configured timezone \"{$timezone}\" is not a recognized identifier",
                '`KBMS_TIMEZONE` is not a valid IANA timezone identifier (e.g. `Europe/Rome`)'
            );
        }

        return CheckResult::pass("resolved to \"{$timezone}\"");
    }
}
