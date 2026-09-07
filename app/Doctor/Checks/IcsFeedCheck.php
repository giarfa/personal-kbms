<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

final class IcsFeedCheck implements EnvironmentCheck
{
    private const CONNECT_TIMEOUT = 3;

    private const TIMEOUT = 5;

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
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)->get($url);
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

        try {
            $document = Reader::read($response->body(), Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            $document = null;
        }

        if (! $document instanceof VCalendar) {
            return CheckResult::failed(
                'the response body is not a valid iCalendar document',
                'the feed responded 200 but the body did not parse as iCalendar — an HTML sign-in page is the usual cause; re-copy the subscription URL into `KBMS_ICS_URL`'
            );
        }

        $eventCount = count($document->select('VEVENT'));

        return CheckResult::pass("HTTP {$response->status()}, {$eventCount} event(s)");
    }
}
