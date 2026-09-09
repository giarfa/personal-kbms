<?php

namespace Tests\Unit\Meetings;

use App\Meetings\CalendarRange;
use App\Meetings\CalendarView;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CalendarRangeTest extends TestCase
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

    public function test_the_month_window_includes_the_leading_and_trailing_days(): void
    {
        $range = new CalendarRange(CalendarView::Month, CarbonImmutable::parse('2026-09-08'));

        $this->assertSame('2026-08-31', $range->visibleStart()->toDateString());
        $this->assertSame('2026-10-05', $range->visibleEnd()->toDateString());

        // A whole number of weeks either side.
        $this->assertSame(0, (int) round($range->visibleStart()->diffInDays($range->visibleEnd())) % 7);
    }

    public function test_the_week_window_starts_monday(): void
    {
        $range = new CalendarRange(CalendarView::Week, CarbonImmutable::parse('2026-09-08'));

        $this->assertSame('2026-09-07', $range->visibleStart()->toDateString());
        $this->assertSame('2026-09-14', $range->visibleEnd()->toDateString());
    }

    public function test_the_day_window_is_a_single_day(): void
    {
        $range = new CalendarRange(CalendarView::Day, CarbonImmutable::parse('2026-09-08'));

        $this->assertSame('2026-09-08', $range->visibleStart()->toDateString());
        $this->assertSame('2026-09-09', $range->visibleEnd()->toDateString());
    }

    public function test_previous_and_next_step_by_the_views_unit(): void
    {
        $month = new CalendarRange(CalendarView::Month, CarbonImmutable::parse('2026-09-08'));
        $this->assertSame('2026-08-01', $month->previous()->anchor->toDateString());
        $this->assertSame('2026-10-01', $month->next()->anchor->toDateString());

        $week = new CalendarRange(CalendarView::Week, CarbonImmutable::parse('2026-09-08'));
        $this->assertSame('2026-09-01', $week->previous()->anchor->toDateString());
        $this->assertSame('2026-09-15', $week->next()->anchor->toDateString());

        $day = new CalendarRange(CalendarView::Day, CarbonImmutable::parse('2026-09-08'));
        $this->assertSame('2026-09-07', $day->previous()->anchor->toDateString());
        $this->assertSame('2026-09-09', $day->next()->anchor->toDateString());
    }

    public function test_stepping_a_month_never_overflows_a_short_target_month(): void
    {
        // 31 January stepping forward must land in February, not March.
        $range = new CalendarRange(CalendarView::Month, CarbonImmutable::parse('2026-01-31'));

        $this->assertSame('2026-02-01', $range->next()->anchor->toDateString());
    }

    public function test_label_per_view(): void
    {
        $this->assertSame('September 2026', (new CalendarRange(CalendarView::Month, CarbonImmutable::parse('2026-09-08')))->label());
        $this->assertSame('7 – 13 September 2026', (new CalendarRange(CalendarView::Week, CarbonImmutable::parse('2026-09-08')))->label());
        $this->assertSame('Tuesday 8 September 2026', (new CalendarRange(CalendarView::Day, CarbonImmutable::parse('2026-09-08')))->label());
    }

    public function test_from_request_falls_back_to_month_and_today_for_bad_input(): void
    {
        foreach ([null, '', 'quarter', 'not-a-date'] as $view) {
            $range = CalendarRange::fromRequest($view, null);

            $this->assertSame(CalendarView::Month, $range->view);
        }

        $range = CalendarRange::fromRequest(null, 'not-a-date');
        $this->assertSame('2026-09-08', $range->anchor->toDateString());

        $range = CalendarRange::fromRequest('week', '2026-09-08');
        $this->assertSame(CalendarView::Week, $range->view);
        $this->assertSame('2026-09-08', $range->anchor->toDateString());
    }

    public function test_a_week_straddling_the_dst_boundary_still_holds_exactly_seven_days(): void
    {
        // Europe/Rome DST ends 25 October 2026.
        $range = new CalendarRange(CalendarView::Week, CarbonImmutable::parse('2026-10-25'));

        $this->assertSame(7, (int) round($range->visibleStart()->diffInDays($range->visibleEnd())));
        $this->assertSame('2026-10-19', $range->visibleStart()->toDateString());
        $this->assertSame('2026-10-26', $range->visibleEnd()->toDateString());
    }
}
