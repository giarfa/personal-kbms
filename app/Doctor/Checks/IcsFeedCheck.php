<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class IcsFeedCheck implements EnvironmentCheck
{
    public function label(): string
    {
        return 'ICS calendar feed';
    }

    public function run(): CheckResult
    {
        $url = config('kbms.ics_url');

        if (empty($url)) {
            return CheckResult::notConfigured(
                'no ICS feed URL configured',
                '`KBMS_ICS_URL` is not set — configure the calendar subscription URL'
            );
        }

        try {
            $response = Http::connectTimeout(3)->timeout(5)->get($url);
        } catch (ConnectionException) {
            return CheckResult::failed(
                'the request did not complete within the bounded timeout',
                'the feed at `KBMS_ICS_URL` did not respond within 5s — check the URL and network reachability'
            );
        }

        if (! $response->successful()) {
            return CheckResult::failed(
                "the feed responded with HTTP {$response->status()}",
                '`KBMS_ICS_URL` responded with an error status — a 401/403 usually means the subscription URL requires its secret token'
            );
        }

        $body = $response->body();

        if (! str_contains($body, 'BEGIN:VCALENDAR')) {
            return CheckResult::failed(
                'the response body is not iCalendar',
                'the feed responded 200 but the body is not iCalendar — an HTML sign-in page is the usual cause; re-copy the subscription URL into `KBMS_ICS_URL`'
            );
        }

        if (! str_contains($body, 'VERSION:')) {
            return CheckResult::failed(
                'the iCalendar envelope is missing VERSION',
                'the iCalendar envelope is malformed — `VERSION` is missing'
            );
        }

        $eventCount = substr_count($body, 'BEGIN:VEVENT');

        if ($eventCount === 0) {
            return CheckResult::failed(
                'the calendar contains no events',
                'the feed parsed as iCalendar but contains no events — verify `KBMS_ICS_URL` points at a calendar with entries'
            );
        }

        if (! str_contains($body, 'END:VCALENDAR')) {
            return CheckResult::failed(
                'the response body is truncated',
                'the feed body is truncated — `END:VCALENDAR` is missing'
            );
        }

        return CheckResult::pass("HTTP {$response->status()}, {$eventCount} event(s)");
    }
}
