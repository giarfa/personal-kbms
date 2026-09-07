<?php

namespace Tests\Unit\Doctor;

use App\Doctor\Checks\TimezoneCheck;
use App\Doctor\CheckStatus;
use Tests\TestCase;

class TimezoneCheckTest extends TestCase
{
    public function test_it_reports_not_configured_when_the_timezone_is_unset(): void
    {
        config(['kbms.timezone' => null]);

        $result = (new TimezoneCheck)->run();

        $this->assertSame(CheckStatus::NotConfigured, $result->status);
        $this->assertStringContainsString('KBMS_TIMEZONE', $result->remediation);
    }

    public function test_it_fails_when_the_timezone_is_not_a_valid_identifier(): void
    {
        config(['kbms.timezone' => 'Not/AZone']);

        $result = (new TimezoneCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('KBMS_TIMEZONE', $result->remediation);
    }

    public function test_it_passes_when_the_timezone_is_a_valid_identifier(): void
    {
        config(['kbms.timezone' => 'Europe/Rome']);

        $result = (new TimezoneCheck)->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString('Europe/Rome', $result->detail);
    }
}
