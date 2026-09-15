<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MeetingSeriesNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const SERIES_UID = 'weekly-standup-uid';

    private int $queries = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', 'Europe/Rome'));
        config(['kbms.outlook_url_template' => null]);

        // Registered once: a listener added per countQueries() call would keep
        // mutating earlier counters and inflate anything measured after it.
        DB::listen(function (): void {
            $this->queries++;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function occurrence(string $date, string $time = '09:00', string $uid = self::SERIES_UID): CalendarEvent
    {
        return CalendarEvent::factory()
            ->occurrenceOf($uid, $date.'T'.$time.':00Z')
            ->at(CarbonImmutable::parse($date.' '.$time, 'Europe/Rome'), 30)
            ->create();
    }

    private function visit(CalendarEvent $occurrence): TestResponse
    {
        return $this->get(route('meetings.show', $occurrence->occurrenceKey()->toRouteKey()));
    }

    public function test_both_controls_render_naming_their_own_destination(): void
    {
        $previous = $this->occurrence('2026-09-01', '14:30');
        $current = $this->occurrence('2026-09-08');
        $next = $this->occurrence('2026-09-15', '11:15');

        $this->visit($current)
            ->assertOk()
            ->assertSee('kb-series-nav', false)
            ->assertSee('Tue 1 Sep 14:30')
            ->assertSee('Tue 15 Sep 11:15')
            ->assertSee('href="'.route('meetings.show', $previous->occurrenceKey()->toRouteKey()).'"', false)
            ->assertSee('href="'.route('meetings.show', $next->occurrenceKey()->toRouteKey()).'"', false);
    }

    /**
     * A plain click opens the neighbour beside the occurrence you started
     * from; every other click the browser knows keeps its ordinary meaning,
     * which is only true while this stays an `<a href>`.
     */
    public function test_each_control_is_an_ordinary_link_opening_in_a_new_tab(): void
    {
        $this->occurrence('2026-09-01');
        $current = $this->occurrence('2026-09-08');

        $html = (string) $this->visit($current)->assertOk()->getContent();

        $nav = $this->seriesNav($html);

        $this->assertStringContainsString('<a href=', $nav);
        $this->assertStringContainsString('target="_blank"', $nav);
        $this->assertStringContainsString('rel="noopener"', $nav);
        $this->assertStringNotContainsString('window.open', $nav);
        $this->assertStringNotContainsString('wire:navigate', $nav);
        $this->assertStringNotContainsString('wire:click', $nav);
        $this->assertStringNotContainsString('onclick', $nav);
    }

    public function test_the_accessible_name_carries_direction_destination_and_the_new_tab(): void
    {
        $this->occurrence('2026-09-01');
        $current = $this->occurrence('2026-09-08');
        $this->occurrence('2026-09-15');

        $this->visit($current)
            ->assertOk()
            ->assertSee('aria-label="Previous occurrence, Tue 1 Sep 09:00 (opens in a new tab)"', false)
            ->assertSee('aria-label="Next occurrence, Tue 15 Sep 09:00 (opens in a new tab)"', false);
    }

    public function test_the_first_stored_occurrence_disables_previous_with_a_stated_reason(): void
    {
        $first = $this->occurrence('2026-09-08');
        $this->occurrence('2026-09-15');

        $this->visit($first)
            ->assertOk()
            ->assertSee('aria-describedby="series-previous-reason"', false)
            ->assertSee('id="series-previous-reason"', false)
            ->assertSee('The mirror holds no earlier occurrence of this series.')
            ->assertDontSee('aria-describedby="series-next-reason"', false);
    }

    public function test_the_last_stored_occurrence_disables_next_with_a_stated_reason(): void
    {
        $this->occurrence('2026-09-01');
        $last = $this->occurrence('2026-09-08');

        $this->visit($last)
            ->assertOk()
            ->assertSee('aria-describedby="series-next-reason"', false)
            ->assertSee('id="series-next-reason"', false)
            ->assertSee('The mirror holds no later occurrence of this series.')
            ->assertDontSee('aria-describedby="series-previous-reason"', false);
    }

    /**
     * The window's edge is not the series' edge, and the page must not claim
     * otherwise — the two are indistinguishable from the mirrored data.
     */
    public function test_the_edge_reason_speaks_about_the_mirror_not_the_series(): void
    {
        $only = $this->occurrence('2026-09-08');

        $html = (string) $this->visit($only)->assertOk()->getContent();

        $nav = $this->seriesNav($html);

        $this->assertStringContainsString('The mirror holds no earlier occurrence of this series.', $nav);
        $this->assertStringContainsString('The mirror holds no later occurrence of this series.', $nav);
        $this->assertStringNotContainsString('series begins', $nav);
        $this->assertStringNotContainsString('series ends', $nav);
        $this->assertStringNotContainsString('first occurrence', $nav);
        $this->assertStringNotContainsString('last occurrence', $nav);
    }

    /**
     * A cancelled occurrence stays in the chain: excluding it would strand a
     * note written on it. Saying so is the destination page's job — the
     * control leads there and stays quiet about it.
     */
    public function test_a_cancelled_occurrence_is_reachable_and_unmarked_by_the_control(): void
    {
        $cancelled = CalendarEvent::factory()
            ->occurrenceOf(self::SERIES_UID, '2026-09-01T09:00:00Z')
            ->at(CarbonImmutable::parse('2026-09-01 09:00', 'Europe/Rome'), 30)
            ->cancelled()
            ->create();
        $current = $this->occurrence('2026-09-08');

        $html = (string) $this->visit($current)->assertOk()->getContent();

        $nav = $this->seriesNav($html);

        $this->assertStringContainsString(route('meetings.show', $cancelled->occurrenceKey()->toRouteKey()), $nav);
        $this->assertStringNotContainsString('Cancelled', $nav);

        // …and the destination page is where the status is stated.
        $this->visit($cancelled)->assertOk()->assertSee('Cancelled upstream');
    }

    /**
     * Recurrence is the feed's `recurrence_id`, not a count of stored rows.
     */
    public function test_a_series_holding_one_occurrence_still_renders_both_controls(): void
    {
        $only = $this->occurrence('2026-09-08');

        $this->visit($only)
            ->assertOk()
            ->assertSee('kb-series-nav', false)
            ->assertSee('Previous occurrence')
            ->assertSee('Next occurrence')
            ->assertSee('aria-describedby="series-previous-reason"', false)
            ->assertSee('aria-describedby="series-next-reason"', false);
    }

    public function test_a_one_off_meeting_renders_neither_control(): void
    {
        $oneOff = CalendarEvent::factory()
            ->at(CarbonImmutable::parse('2026-09-08 09:00', 'Europe/Rome'), 30)
            ->create(['recurrence_id' => '']);

        $this->visit($oneOff)
            ->assertOk()
            ->assertDontSee('kb-series-nav', false)
            ->assertDontSee('Previous occurrence')
            ->assertDontSee('Next occurrence');
    }

    /**
     * The page must not gain a query per sibling. This pins the count only —
     * an implementation that fetched the whole series in one query and picked
     * the neighbour in PHP would pass here too. The bound itself is pinned
     * where it is decided, by the `limit 1` assertion in
     * `SeriesNeighbourLookupTest::test_each_direction_is_a_bounded_lookup_on_the_indexed_column`.
     */
    public function test_the_page_cost_does_not_grow_with_the_length_of_the_series(): void
    {
        $short = $this->occurrence('2026-09-08', '09:00', 'short-series-uid');
        $this->occurrence('2026-09-01', '09:00', 'short-series-uid');
        $this->occurrence('2026-09-15', '09:00', 'short-series-uid');

        foreach (range(1, 40) as $week) {
            $this->occurrence(CarbonImmutable::parse('2026-01-05')->addWeeks($week)->toDateString());
        }

        $long = CalendarEvent::query()
            ->where('source_uid', self::SERIES_UID)
            ->orderBy('starts_at')
            ->skip(20)
            ->first();

        $this->assertNotNull($long);

        $this->assertSame(
            $this->countQueries(fn () => $this->visit($short)->assertOk()),
            $this->countQueries(fn () => $this->visit($long)->assertOk()),
        );
    }

    /**
     * The rendered series navigation, failing loudly rather than returning an
     * empty string — every "does not contain" assertion below would otherwise
     * pass vacuously the day the markup shifts.
     */
    private function seriesNav(string $html): string
    {
        $this->assertSame(1, preg_match('#<nav class="kb-series-nav".*?</nav>#s', $html, $matches));

        return $matches[0];
    }

    private function countQueries(callable $render): int
    {
        $this->queries = 0;

        $render();

        return $this->queries;
    }
}
