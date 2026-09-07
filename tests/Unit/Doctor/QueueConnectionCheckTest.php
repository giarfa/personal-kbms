<?php

namespace Tests\Unit\Doctor;

use App\Doctor\Checks\QueueConnectionCheck;
use App\Doctor\CheckStatus;
use Tests\TestCase;

class QueueConnectionCheckTest extends TestCase
{
    public function test_it_passes_when_the_configured_connection_is_reachable(): void
    {
        // phpunit.xml forces QUEUE_CONNECTION=sync, which resolves without any external service.
        $result = (new QueueConnectionCheck)->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString('sync', $result->detail);
    }

    public function test_it_fails_with_a_remediation_hint_when_the_connection_cannot_be_resolved(): void
    {
        config(['queue.default' => 'kbms-unknown-connection']);

        $result = (new QueueConnectionCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('QUEUE_CONNECTION', $result->remediation);
    }
}
