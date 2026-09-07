<?php

namespace App\Doctor;

final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $detail,
        public ?string $remediation = null,
    ) {}

    public static function pass(string $detail): self
    {
        return new self(CheckStatus::Pass, $detail);
    }

    public static function notConfigured(string $detail, string $remediation): self
    {
        return new self(CheckStatus::NotConfigured, $detail, $remediation);
    }

    public static function failed(string $detail, string $remediation): self
    {
        return new self(CheckStatus::Failed, $detail, $remediation);
    }
}
