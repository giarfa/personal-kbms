<?php

namespace Tests\Unit\Meetings;

use App\Meetings\AgendaRange;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AgendaRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', 'Europe/Rome'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_default_anchor_is_today_in_the_configured_timezone(): void
    {
        $range = AgendaRange::today();

        $this->assertSame('2026-09-08', $range->anchor->toDateString());
        $this->assertSame(7, $range->days);
    }

    public function test_previous_and_next_step_by_exactly_the_window_length(): void
    {
        $range = AgendaRange::today();

        $this->assertSame('2026-09-01', $range->previous()->anchor->toDateString());
        $this->assertSame('2026-09-15', $range->next()->anchor->toDateString());
    }

    public function test_today_resets_the_anchor_regardless_of_current_position(): void
    {
        $range = AgendaRange::today()->next()->next();

        $this->assertSame('2026-09-08', AgendaRange::today()->anchor->toDateString());
        $this->assertNotSame('2026-09-08', $range->anchor->toDateString());
    }

    public function test_jump_to_re_anchors_while_preserving_the_window_length(): void
    {
        $range = new AgendaRange(CarbonImmutable::parse('2026-09-08'), 7);

        $jumped = $range->jumpTo(CarbonImmutable::parse('2026-12-24 18:00:00'));

        $this->assertSame('2026-12-24', $jumped->anchor->toDateString());
        $this->assertSame(7, $jumped->days);
    }

    public function test_label_matches_the_mockup_format_within_a_single_month(): void
    {
        $range = new AgendaRange(CarbonImmutable::parse('2026-09-07'), 7);

        $this->assertSame('Mon 7 – Sun 13 Sep 2026', $range->label());
    }

    public function test_label_spans_a_month_boundary(): void
    {
        $range = new AgendaRange(CarbonImmutable::parse('2026-08-31'), 7);

        $this->assertSame('Mon 31 Aug – Sun 6 Sep 2026', $range->label());
    }

    public function test_label_spans_a_year_boundary(): void
    {
        $range = new AgendaRange(CarbonImmutable::parse('2026-12-28'), 7);

        $this->assertSame('Mon 28 Dec 2026 – Sun 3 Jan 2027', $range->label());
    }
}
