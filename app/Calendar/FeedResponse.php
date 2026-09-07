<?php

namespace App\Calendar;

final readonly class FeedResponse
{
    public function __construct(
        public int $httpStatus,
        public ?string $body,
        public ?string $etag,
        public ?string $lastModified,
        public bool $notModified,
    ) {}

    public static function ok(int $httpStatus, string $body, ?string $etag, ?string $lastModified): self
    {
        return new self($httpStatus, $body, $etag, $lastModified, notModified: false);
    }

    public static function notModified(?string $etag, ?string $lastModified): self
    {
        return new self(304, null, $etag, $lastModified, notModified: true);
    }
}
