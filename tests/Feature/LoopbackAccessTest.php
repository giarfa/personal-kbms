<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopbackAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_loopback_ipv4_is_allowed(): void
    {
        $this->call('GET', '/', server: ['REMOTE_ADDR' => '127.0.0.1'])->assertOk();
    }

    public function test_loopback_ipv6_is_allowed(): void
    {
        $this->call('GET', '/', server: ['REMOTE_ADDR' => '::1'])->assertOk();
    }

    public function test_a_non_loopback_origin_is_rejected(): void
    {
        $this->call('GET', '/', server: ['REMOTE_ADDR' => '203.0.113.7'])->assertForbidden();
    }

    public function test_allow_non_loopback_config_lifts_the_block(): void
    {
        config(['kbms.allow_non_loopback' => true]);

        $this->call('GET', '/', server: ['REMOTE_ADDR' => '203.0.113.7'])->assertOk();
    }

    public function test_a_spoofed_forwarded_for_header_does_not_bypass_the_block(): void
    {
        $this->call('GET', '/', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
        ])->assertForbidden();
    }
}
