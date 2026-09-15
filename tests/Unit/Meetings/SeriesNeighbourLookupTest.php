<?php

namespace Tests\Unit\Meetings;

use App\Meetings\SeriesDirection;
use App\Meetings\SeriesNeighbourLookup;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeriesNeighbourLookupTest extends TestCase
{
    use RefreshDatabase;

    private const SERIES_UID = 'weekly-standup-uid';

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

    private function lookup(): SeriesNeighbourLookup
    {
        return new SeriesNeighbourLookup;
    }

    /**
     * One occurrence of the weekly series, addressed by the date it falls on.
     */
    private function occurrence(string $date, string $time = '09:00', string $uid = self::SERIES_UID): CalendarEvent
    {
        return CalendarEvent::factory()
            ->occurrenceOf($uid, $date.'T'.$time.':00Z')
            ->at(CarbonImmutable::parse($date.' '.$time, 'Europe/Rome'), 30)
            ->create();
    }

    public function test_both_neighbours_are_the_nearest_occurrence_in_each_direction(): void
    {
        $this->occurrence('2026-08-25');
        $previous = $this->occurrence('2026-09-01');
        $current = $this->occurrence('2026-09-08');
        $next = $this->occurrence('2026-09-15');
        $this->occurrence('2026-09-22');

        $neighbours = $this->lookup()->forOccurrence($current);

        $this->assertNotNull($neighbours);
        $this->assertTrue($neighbours->previous->isAvailable());
        $this->assertTrue($neighbours->next->isAvailable());
        $this->assertSame($previous->id, $neighbours->previous->event?->id);
        $this->assertSame($next->id, $neighbours->next->event?->id);
    }

    public function test_the_first_stored_occurrence_has_no_previous_and_says_why(): void
    {
        $first = $this->occurrence('2026-09-01');
        $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($first);

        $this->assertNotNull($neighbours);
        $this->assertFalse($neighbours->previous->isAvailable());
        $this->assertTrue($neighbours->next->isAvailable());
        $this->assertSame('The mirror holds no earlier occurrence of this series.', $neighbours->previous->reason());
        $this->assertNull($neighbours->previous->routeKey());
    }

    public function test_the_last_stored_occurrence_has_no_next_and_says_why(): void
    {
        $this->occurrence('2026-09-01');
        $last = $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($last);

        $this->assertNotNull($neighbours);
        $this->assertTrue($neighbours->previous->isAvailable());
        $this->assertFalse($neighbours->next->isAvailable());
        $this->assertSame('The mirror holds no later occurrence of this series.', $neighbours->next->reason());
    }

    /**
     * The reason names the mirror, never the series. "There is no later
     * occurrence" and "the sync window ends here" are indistinguishable from
     * the data, and only the second one is something we can claim.
     */
    public function test_the_edge_reason_never_claims_the_series_begins_or_ends(): void
    {
        $only = $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($only);

        $this->assertNotNull($neighbours);

        foreach ([$neighbours->previous, $neighbours->next] as $neighbour) {
            $reason = (string) $neighbour->reason();

            $this->assertStringContainsString('mirror', $reason);
            $this->assertStringNotContainsString('series begins', $reason);
            $this->assertStringNotContainsString('series ends', $reason);
            $this->assertStringNotContainsString('first', $reason);
            $this->assertStringNotContainsString('last', $reason);
        }
    }

    public function test_a_cancelled_sibling_is_returned_rather_than_skipped(): void
    {
        $cancelled = CalendarEvent::factory()
            ->occurrenceOf(self::SERIES_UID, '2026-09-01T09:00:00Z')
            ->at(CarbonImmutable::parse('2026-09-01 09:00', 'Europe/Rome'), 30)
            ->cancelled()
            ->create();
        $current = $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($current);

        $this->assertNotNull($neighbours);
        $this->assertSame($cancelled->id, $neighbours->previous->event?->id);
    }

    /**
     * Recurrence is what the feed says, not what the mirror happens to hold:
     * a window containing one occurrence is still a series, and renders two
     * inert controls rather than none.
     */
    public function test_a_series_with_one_stored_occurrence_still_yields_a_pair(): void
    {
        $only = $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($only);

        $this->assertNotNull($neighbours);
        $this->assertFalse($neighbours->previous->isAvailable());
        $this->assertFalse($neighbours->next->isAvailable());
    }

    public function test_a_one_off_meeting_has_no_neighbours_at_all(): void
    {
        $oneOff = CalendarEvent::factory()
            ->at(CarbonImmutable::parse('2026-09-08 09:00', 'Europe/Rome'), 30)
            ->create(['recurrence_id' => '']);

        $this->assertNull($this->lookup()->forOccurrence($oneOff));
    }

    public function test_an_occurrence_of_another_series_is_never_a_neighbour(): void
    {
        $this->occurrence('2026-09-07', '09:00', 'other-series-uid');
        $this->occurrence('2026-09-09', '09:00', 'other-series-uid');
        $current = $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($current);

        $this->assertNotNull($neighbours);
        $this->assertFalse($neighbours->previous->isAvailable());
        $this->assertFalse($neighbours->next->isAvailable());
    }

    /**
     * A `RECURRENCE-ID` override moved onto a sibling's slot puts two rows on
     * the same `starts_at`. Ordering on start time alone would let each claim
     * the other as its neighbour in both directions — a two-row loop with no
     * way out. The composite cursor resolves them past each other instead.
     */
    public function test_a_tie_on_start_time_resolves_deterministically_and_never_onto_itself(): void
    {
        $early = CalendarEvent::factory()
            ->occurrenceOf(self::SERIES_UID, '2026-09-08T09:00:00Z')
            ->at(CarbonImmutable::parse('2026-09-08 09:00', 'Europe/Rome'), 30)
            ->create();
        $late = CalendarEvent::factory()
            ->occurrenceOf(self::SERIES_UID, '2026-09-15T09:00:00Z')
            ->at(CarbonImmutable::parse('2026-09-08 09:00', 'Europe/Rome'), 30)
            ->create();

        $fromEarly = $this->lookup()->forOccurrence($early);
        $fromLate = $this->lookup()->forOccurrence($late);

        $this->assertNotNull($fromEarly);
        $this->assertNotNull($fromLate);

        // Lower recurrence_id sorts first, so `early` sees `late` ahead of it
        // and `late` sees `early` behind it — one direction each, not both.
        $this->assertSame($late->id, $fromEarly->next->event?->id);
        $this->assertFalse($fromEarly->previous->isAvailable());
        $this->assertSame($early->id, $fromLate->previous->event?->id);
        $this->assertFalse($fromLate->next->isAvailable());
    }

    /**
     * A counted query is not a bounded one: loading the whole series and
     * picking the neighbour in PHP would also cost two queries and pass every
     * behavioural assertion above. The bound is the point — the detail page
     * of a standing meeting held weekly for three years must not read three
     * years of rows to find last week's.
     */
    public function test_each_direction_is_a_bounded_lookup_on_the_indexed_column(): void
    {
        foreach (range(1, 20) as $week) {
            $this->occurrence(CarbonImmutable::parse('2026-01-05')->addWeeks($week)->toDateString());
        }

        $current = CalendarEvent::query()->where('source_uid', self::SERIES_UID)->orderBy('starts_at')->skip(10)->first();
        $this->assertNotNull($current);

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->lookup()->forOccurrence($current);

        $this->assertCount(2, $statements);

        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/source_uid.{0,2} = \?/', $sql);
            $this->assertStringContainsStringIgnoringCase('limit 1', $sql);
        }
    }

    public function test_a_timed_neighbour_is_labelled_with_its_own_date_and_time(): void
    {
        $this->occurrence('2026-09-01', '14:30');
        $current = $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($current);

        $this->assertNotNull($neighbours);
        $this->assertSame('Tue 1 Sep 14:30', $neighbours->previous->label());
        $this->assertSame('Previous occurrence, Tue 1 Sep 14:30 (opens in a new tab)', $neighbours->previous->accessibleName());
    }

    public function test_an_all_day_neighbour_is_labelled_without_a_clock_time(): void
    {
        CalendarEvent::factory()
            ->occurrenceOf(self::SERIES_UID, '2026-09-15')
            ->create([
                'starts_at' => '2026-09-15 00:00:00',
                'ends_at' => '2026-09-16 00:00:00',
                'is_all_day' => true,
                'timezone' => null,
            ]);
        $current = $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($current);

        $this->assertNotNull($neighbours);
        $this->assertSame('Tue 15 Sep · all day', $neighbours->next->label());
        $this->assertSame('Next occurrence, Tue 15 Sep · all day (opens in a new tab)', $neighbours->next->accessibleName());
    }

    /**
     * A weekly series crossing New Year would otherwise offer two
     * indistinguishable "Mon 30 Dec" links from the same page.
     */
    public function test_a_neighbour_in_another_year_carries_the_year(): void
    {
        $current = $this->occurrence('2026-12-28');
        $this->occurrence('2027-01-04');

        $neighbours = $this->lookup()->forOccurrence($current);

        $this->assertNotNull($neighbours);
        $this->assertSame('Mon 4 Jan 2027 09:00', $neighbours->next->label());
        // …while the same-year side stays short.
        $this->assertSame('Mon 28 Dec 09:00', $this->lookup()->forOccurrence($this->occurrence('2026-12-21'))?->next->label());
    }

    public function test_an_absent_direction_exposes_no_label_or_accessible_destination(): void
    {
        $only = $this->occurrence('2026-09-08');

        $neighbours = $this->lookup()->forOccurrence($only);

        $this->assertNotNull($neighbours);
        $this->assertSame('', $neighbours->previous->label());
        $this->assertSame(SeriesDirection::Previous, $neighbours->previous->direction);
        $this->assertNull($neighbours->next->routeKey());

        // …and the accessible name degrades to the bare direction rather than
        // composing "Previous occurrence,  (opens in a new tab)".
        $this->assertSame('Previous occurrence', $neighbours->previous->accessibleName());
        $this->assertSame('Next occurrence', $neighbours->next->accessibleName());
    }
}
