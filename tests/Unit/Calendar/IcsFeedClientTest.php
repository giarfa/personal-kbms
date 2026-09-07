<?php

namespace Tests\Unit\Calendar;

use App\Calendar\Exceptions\FeedUnavailable;
use App\Calendar\IcsFeedClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IcsFeedClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['kbms.ics_url' => 'https://example.test/calendar.ics']);
    }

    public function test_a_200_response_captures_body_etag_and_last_modified(): void
    {
        Http::fake([
            'example.test/*' => Http::response('BEGIN:VCALENDAR', 200, [
                'ETag' => '"abc123"',
                'Last-Modified' => 'Wed, 21 Oct 2026 07:28:00 GMT',
            ]),
        ]);

        $response = (new IcsFeedClient)->fetch(null, null);

        $this->assertSame(200, $response->httpStatus);
        $this->assertSame('BEGIN:VCALENDAR', $response->body);
        $this->assertSame('"abc123"', $response->etag);
        $this->assertSame('Wed, 21 Oct 2026 07:28:00 GMT', $response->lastModified);
        $this->assertFalse($response->notModified);
    }

    public function test_a_304_response_returns_not_modified_with_a_null_body(): void
    {
        Http::fake([
            'example.test/*' => Http::response('', 304),
        ]);

        $response = (new IcsFeedClient)->fetch('"abc123"', 'Wed, 21 Oct 2026 07:28:00 GMT');

        $this->assertTrue($response->notModified);
        $this->assertNull($response->body);
        $this->assertSame('"abc123"', $response->etag);
        $this->assertSame('Wed, 21 Oct 2026 07:28:00 GMT', $response->lastModified);
    }

    public function test_validators_are_sent_when_supplied_and_omitted_when_null(): void
    {
        Http::fake(['example.test/*' => Http::response('BEGIN:VCALENDAR', 200)]);

        (new IcsFeedClient)->fetch('"abc123"', 'Wed, 21 Oct 2026 07:28:00 GMT');

        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"abc123"')
            && $request->hasHeader('If-Modified-Since', 'Wed, 21 Oct 2026 07:28:00 GMT'));

        (new IcsFeedClient)->fetch(null, null);

        Http::assertSent(fn ($request) => ! $request->hasHeader('If-None-Match') && ! $request->hasHeader('If-Modified-Since'));
    }

    public function test_the_connect_and_total_timeouts_are_bounded(): void
    {
        // Guzzle timeout/connect_timeout are transfer options, not part of the
        // PSR-7 request, so Http::assertSent cannot introspect them — assert
        // the bounded constants directly instead.
        $reflection = new \ReflectionClass(IcsFeedClient::class);

        $this->assertSame(5, $reflection->getConstant('CONNECT_TIMEOUT'));
        $this->assertSame(30, $reflection->getConstant('TIMEOUT'));
    }

    public function test_a_500_response_throws_feed_unavailable_carrying_the_http_status(): void
    {
        Http::fake(['example.test/*' => Http::response('', 500)]);

        try {
            (new IcsFeedClient)->fetch(null, null);
            $this->fail('Expected FeedUnavailable to be thrown.');
        } catch (FeedUnavailable $exception) {
            $this->assertSame(500, $exception->httpStatus);
        }
    }

    public function test_a_connection_exception_becomes_feed_unavailable_with_a_null_http_status(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        try {
            (new IcsFeedClient)->fetch(null, null);
            $this->fail('Expected FeedUnavailable to be thrown.');
        } catch (FeedUnavailable $exception) {
            $this->assertNull($exception->httpStatus);
        }
    }

    public function test_an_unset_url_throws_naming_the_key(): void
    {
        config(['kbms.ics_url' => null]);

        try {
            (new IcsFeedClient)->fetch(null, null);
            $this->fail('Expected FeedUnavailable to be thrown.');
        } catch (FeedUnavailable $exception) {
            $this->assertStringContainsString('KBMS_ICS_URL', $exception->getMessage());
        }
    }
}
