<?php

namespace App\Meetings;

final readonly class OutlookLink
{
    public function __construct(public ?string $url, public OutlookLinkSource $source, public ?string $reason = null) {}
}
