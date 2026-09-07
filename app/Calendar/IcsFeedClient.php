<?php

namespace App\Calendar;

use App\Calendar\Exceptions\FeedUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class IcsFeedClient
{
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 30;

    /**
     * Fetch the configured ICS feed, sending conditional-GET validators when available.
     */
    public function fetch(?string $etag, ?string $lastModified): FeedResponse
    {
        $url = config('kbms.ics_url');

        if (empty($url)) {
            throw FeedUnavailable::notConfigured('KBMS_ICS_URL');
        }

        $request = $this->request();

        $headers = [];

        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }

        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        try {
            $response = $request->get($url);
        } catch (ConnectionException $exception) {
            throw FeedUnavailable::connectionFailed($url, $exception->getMessage());
        }

        if ($response->status() === 304) {
            return FeedResponse::notModified(
                $this->header($response, 'ETag') ?? $etag,
                $this->header($response, 'Last-Modified') ?? $lastModified,
            );
        }

        if ($response->successful()) {
            return FeedResponse::ok(
                $response->status(),
                $response->body(),
                $this->header($response, 'ETag'),
                $this->header($response, 'Last-Modified'),
            );
        }

        throw FeedUnavailable::httpError($response->status());
    }

    private function request(): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT);
    }

    private function header(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        return $value === '' ? null : $value;
    }
}
