<?php

namespace Tests\Unit\Meetings;

use App\Meetings\AgendaDay;
use App\Meetings\AgendaQuery;
use App\Meetings\AgendaRange;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgendaQueryTest extends TestCase
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

    /**
     * @param  Collection<int, AgendaDay>  $days
     */
    private function dayFor(Collection $days, string $date): AgendaDay
    {
        $day = $days->first(fn (AgendaDay $day): bool => $day->date->toDateString() === $date);

        $this->assertNotNull($day, "No agenda day found for {$date}");

        return $day;
    }

    public function test_a_late_timed_event_lands_on_its_local_day_not_the_utc_one(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(23, 30), 30)->create([
            'summary' => 'Late night check',
        ]);

        $days = AgendaQuery::for(AgendaRange::today());

        $today = $this->dayFor($days, '2026-09-08');
        $this->assertSame(1, $today->meetingCount);
        $this->assertSame('Late night check', $today->rows[0]->event->summary);
    }

    public function test_an_event_spanning_a_dst_transition_keeps_its_local_start_time(): void
    {
        // Europe/Rome DST ends the last Sunday of October — 2026-10-25.
        $start = CarbonImmutable::parse('2026-10-26 09:00:00', 'Europe/Rome');

        $event = CalendarEvent::factory()->at($start, 30)->create([
            'summary' => 'Post-DST sync',
        ]);

        $days = AgendaQuery::for(new AgendaRange(CarbonImmutable::parse('2026-10-26'), 1));

        $day = $days->first();
        $this->assertSame('09:00', $day->rows[0]->start->format('H:i'));
        $this->assertSame($event->id, $day->rows[0]->event->id);
    }

    public function test_an_all_day_event_is_not_shifted_by_the_utc_offset(): void
    {
        CalendarEvent::factory()->allDay()->create([
            'summary' => 'Company holiday',
            'starts_at' => '2026-09-09 00:00:00',
            'ends_at' => '2026-09-10 00:00:00',
        ]);

        $days = AgendaQuery::for(AgendaRange::today());

        $day = $this->dayFor($days, '2026-09-09');
        $this->assertSame(1, $day->meetingCount);
        $this->assertTrue($day->rows[0]->isAllDay);
    }

    public function test_a_multiday_event_appears_once_in_its_start_day(): void
    {
        CalendarEvent::factory()->spanning(2)->create([
            'summary' => 'Offsite',
            'starts_at' => '2026-09-10 00:00:00',
            'ends_at' => '2026-09-12 00:00:00',
        ]);

        $days = AgendaQuery::for(AgendaRange::today());

        $this->assertSame(1, $this->dayFor($days, '2026-09-10')->meetingCount);
        $this->assertSame(0, $this->dayFor($days, '2026-09-11')->meetingCount);
        $this->assertSame('Sep 10 – Sep 11', $this->dayFor($days, '2026-09-10')->rows[0]->spanLabel);
    }

    public function test_a_multiday_event_that_began_before_the_window_is_clamped_to_the_range_start(): void
    {
        CalendarEvent::factory()->spanning(4)->create([
            'summary' => 'Pre-window offsite',
            'starts_at' => '2026-09-06 00:00:00',
            'ends_at' => '2026-09-10 00:00:00',
        ]);

        $days = AgendaQuery::for(AgendaRange::today());

        $this->assertSame(1, $this->dayFor($days, '2026-09-08')->meetingCount);
        $this->assertSame('Pre-window offsite', $this->dayFor($days, '2026-09-08')->rows[0]->event->summary);
    }

    public function test_overlapping_timed_events_both_appear_ordered_with_all_day_first(): void
    {
        CalendarEvent::factory()->allDay()->create([
            'summary' => 'Offsite',
            'starts_at' => '2026-09-08 00:00:00',
            'ends_at' => '2026-09-09 00:00:00',
        ]);

        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(10, 0), 60)->create(['summary' => 'Design review']);
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 60)->create(['summary' => 'Client kickoff']);

        $days = AgendaQuery::for(AgendaRange::today());
        $rows = $this->dayFor($days, '2026-09-08')->rows;

        $this->assertCount(3, $rows);
        $this->assertSame('Offsite', $rows[0]->event->summary);
        $this->assertSame('Client kickoff', $rows[1]->event->summary);
        $this->assertSame('Design review', $rows[2]->event->summary);
    }

    public function test_a_range_with_no_events_returns_every_date_with_zero_rows(): void
    {
        $days = AgendaQuery::for(AgendaRange::today());

        $this->assertCount(7, $days);
        foreach ($days as $day) {
            $this->assertSame(0, $day->meetingCount);
            $this->assertSame([], $day->rows);
        }
    }

    public function test_cancelled_occurrences_are_returned_not_filtered_out(): void
    {
        CalendarEvent::factory()->cancelled()->at(now('Europe/Rome')->setTime(14, 0), 30)->create([
            'summary' => 'Vendor demo',
        ]);

        $days = AgendaQuery::for(AgendaRange::today());
        $today = $this->dayFor($days, '2026-09-08');

        $this->assertSame(1, $today->meetingCount);
        $this->assertTrue($today->rows[0]->isCancelled);
    }

    public function test_the_agenda_is_built_with_a_single_query(): void
    {
        CalendarEvent::factory()->count(3)->create();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        AgendaQuery::for(AgendaRange::today());

        $this->assertSame(1, $queries);
    }
}
