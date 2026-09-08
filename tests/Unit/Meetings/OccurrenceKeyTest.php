<?php

namespace Tests\Unit\Meetings;

use App\Meetings\OccurrenceKey;
use PHPUnit\Framework\TestCase;

class OccurrenceKeyTest extends TestCase
{
    public function test_round_trips_a_plain_hex_uid(): void
    {
        $key = new OccurrenceKey('040000008200E00074C5B7101A82E00800000000');

        $decoded = OccurrenceKey::fromRouteKey($key->toRouteKey());

        $this->assertNotNull($decoded);
        $this->assertSame($key->sourceUid, $decoded->sourceUid);
        $this->assertSame('', $decoded->recurrenceId);
    }

    public function test_round_trips_a_uid_containing_slash_plus_and_equals(): void
    {
        $key = new OccurrenceKey('weird/uid+with=chars', '20260907T073000Z');

        $decoded = OccurrenceKey::fromRouteKey($key->toRouteKey());

        $this->assertNotNull($decoded);
        $this->assertSame($key->sourceUid, $decoded->sourceUid);
        $this->assertSame($key->recurrenceId, $decoded->recurrenceId);
    }

    public function test_empty_recurrence_id_is_omitted_from_the_route_key(): void
    {
        $key = new OccurrenceKey('some-uid', '');

        $this->assertStringNotContainsString('.', $key->toRouteKey());
    }

    public function test_round_trips_an_all_day_recurrence_id(): void
    {
        $key = new OccurrenceKey('some-uid', '2026-09-07');

        $decoded = OccurrenceKey::fromRouteKey($key->toRouteKey());

        $this->assertNotNull($decoded);
        $this->assertSame('some-uid', $decoded->sourceUid);
        $this->assertSame('2026-09-07', $decoded->recurrenceId);
    }

    public function test_empty_route_key_returns_null(): void
    {
        $this->assertNull(OccurrenceKey::fromRouteKey(''));
    }

    public function test_malformed_route_key_returns_null(): void
    {
        $this->assertNull(OccurrenceKey::fromRouteKey('!!!not-base64!!!'));
    }

    public function test_malformed_recurrence_segment_returns_null(): void
    {
        $encodedUid = rtrim(strtr(base64_encode('some-uid'), '+/', '-_'), '=');

        $this->assertNull(OccurrenceKey::fromRouteKey($encodedUid.'.!!!'));
    }
}
