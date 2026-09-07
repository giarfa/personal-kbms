<?php

namespace Tests\Unit\Doctor;

use App\Doctor\Checks\IcsFeedCheck;
use App\Doctor\CheckStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IcsFeedCheckTest extends TestCase
{
    private const VALID_ICS = <<<'ICS'
        BEGIN:VCALENDAR
        VERSION:2.0
        BEGIN:VEVENT
        SUMMARY:Standup
        END:VEVENT
        END:VCALENDAR
        ICS;

    public function test_it_reports_not_configured_when_the_url_is_unset(): void
    {
        config(['kbms.ics_url' => null]);

        $result = (new IcsFeedCheck)->run();

        $this->assertSame(CheckStatus::NotConfigured, $result->status);
        $this->assertStringContainsString('KBMS_ICS_URL', $result->remediation);
    }

    public function test_it_passes_for_a_valid_feed(): void
    {
        config(['kbms.ics_url' => 'https://example.test/feed.ics']);
        Http::fake(['*' => Http::response(self::VALID_ICS, 200)]);

        $result = (new IcsFeedCheck)->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString('1 event', $result->detail);
    }

    public function test_it_fails_when_the_response_is_a_404(): void
    {
        config(['kbms.ics_url' => 'https://example.test/feed.ics']);
        Http::fake(['*' => Http::response('not found', 404)]);

        $result = (new IcsFeedCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('404', $result->detail);
        $this->assertStringContainsString('KBMS_ICS_URL', $result->remediation);
    }

    public function test_it_fails_on_a_connection_timeout(): void
    {
        config(['kbms.ics_url' => 'https://example.test/feed.ics']);
        Http::fake(['*' => fn () => throw new ConnectionException('Connection timed out')]);

        $result = (new IcsFeedCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('5s', $result->remediation);
    }

    public function test_it_fails_when_a_200_response_is_not_icalendar(): void
    {
        config(['kbms.ics_url' => 'https://example.test/feed.ics']);
        Http::fake(['*' => Http::response('<html><body>Sign in</body></html>', 200)]);

        $result = (new IcsFeedCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('re-copy the subscription URL', $result->remediation);
    }

    public function test_it_fails_when_version_is_missing(): void
    {
        config(['kbms.ics_url' => 'https://example.test/feed.ics']);
        Http::fake(['*' => Http::response("BEGIN:VCALENDAR\nBEGIN:VEVENT\nEND:VEVENT\nEND:VCALENDAR", 200)]);

        $result = (new IcsFeedCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('VERSION', $result->remediation);
    }

    public function test_it_fails_when_the_calendar_has_zero_events(): void
    {
        config(['kbms.ics_url' => 'https://example.test/feed.ics']);
        Http::fake(['*' => Http::response("BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR", 200)]);

        $result = (new IcsFeedCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('no events', $result->remediation);
    }

    public function test_it_fails_when_the_body_is_truncated(): void
    {
        config(['kbms.ics_url' => 'https://example.test/feed.ics']);
        Http::fake(['*' => Http::response("BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nEND:VEVENT", 200)]);

        $result = (new IcsFeedCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('truncated', $result->remediation);
    }
}
