<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarPageTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_page_renders_the_initial_view_and_range_label_for_month(): void
    {
        $this->get('/calendar?view=month&date=2026-09-08')
            ->assertOk()
            ->assertSee('September 2026');
    }

    public function test_the_page_renders_the_initial_view_and_range_label_for_week(): void
    {
        $this->get('/calendar?view=week&date=2026-09-08')
            ->assertOk()
            ->assertSee('7 – 13 September 2026');
    }

    public function test_the_page_renders_the_initial_view_and_range_label_for_day(): void
    {
        $this->get('/calendar?view=day&date=2026-09-08')
            ->assertOk()
            ->assertSee('Tuesday 8 September 2026');
    }

    public function test_an_array_shaped_view_and_date_degrade_instead_of_500ing(): void
    {
        // ?view[]=x&date[]=y sends arrays where strings are expected — a
        // TypeError, not a parse failure, and must degrade the same way
        // (Lars review).
        $this->get('/calendar?view[]=x&date[]=y')
            ->assertOk()
            ->assertSee('September 2026');
    }

    public function test_an_unknown_view_falls_back_to_month_rather_than_500ing(): void
    {
        $this->get('/calendar?view=quarter&date=2026-09-08')
            ->assertOk()
            ->assertSee('September 2026');
    }

    public function test_a_missing_view_falls_back_to_month(): void
    {
        $this->get('/calendar?view=&date=2026-09-08')
            ->assertOk()
            ->assertSee('September 2026');
    }

    public function test_an_unparseable_date_falls_back_to_today_rather_than_500ing(): void
    {
        $this->get('/calendar?view=month&date=not-a-date')
            ->assertOk()
            ->assertSee('September 2026');
    }

    public function test_a_truncated_bookmark_degrades_to_today_and_month(): void
    {
        $this->get('/calendar?date=2026-02-31T99:99')
            ->assertOk()
            ->assertSee('September 2026');
    }

    public function test_no_query_string_at_all_defaults_to_todays_month(): void
    {
        $this->get('/calendar')
            ->assertOk()
            ->assertSee('September 2026');
    }

    public function test_the_coverage_legend_names_notes_transcript_both_and_cancelled_in_words(): void
    {
        $html = $this->get('/calendar')->assertOk()->getContent();

        $this->assertStringContainsString('notes', $html);
        $this->assertStringContainsString('transcript', $html);
        $this->assertStringContainsString('both', $html);
        $this->assertStringContainsString('cancelled', $html);
    }

    public function test_the_empty_range_copy_is_the_sync_health_aware_wording(): void
    {
        $html = $this->get('/calendar')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing in this range', $html);
        $this->assertStringContainsString('The mirror is current', $html);
        // No CalendarSyncRun rows exist in this test's fresh database, so the
        // sync-health-aware sentence is the "never synced" variant — the
        // point is that it is sync-health-aware at all, not a bare "no events".
        $this->assertStringContainsString('No successful sync has completed yet.', $html);
    }

    public function test_the_page_links_to_the_events_feed_route(): void
    {
        $this->get('/calendar')
            ->assertOk()
            ->assertSee(route('calendar.events'), false);
    }

    public function test_the_sidebar_calendar_item_is_marked_current_on_this_route(): void
    {
        $html = $this->get('/calendar')->assertOk()->getContent();

        $start = strpos($html, 'href="'.route('calendar').'"');
        $this->assertNotFalse($start);

        $anchorEnd = strpos($html, '</a>', $start);
        $anchor = substr($html, max(0, $start - 400), $anchorEnd - max(0, $start - 400) + 4);

        $this->assertStringContainsString('data-current', $anchor);
    }
}
