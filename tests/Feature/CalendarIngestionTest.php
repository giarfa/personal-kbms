<?php

namespace Tests\Feature;

use App\Jobs\SyncCalendarFeed;
use App\Models\CalendarEvent;
use App\Models\CalendarSyncRun;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/ics/{$name}"));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['kbms.ics_url' => 'https://example.test/calendar.ics']);

        // Fixtures live in Q1/Q2 2026; freeze "now" so the default
        // -90/+180 day sync window covers them.
        $this->travelTo(Carbon::parse('2026-03-01'));
    }

    public function test_a_recurring_series_lands_as_independently_addressable_rows(): void
    {
        Http::fake(['example.test/*' => Http::response($this->fixture('recurring-weekly.ics'), 200)]);

        SyncCalendarFeed::dispatchSync();

        $this->assertSame(8, CalendarEvent::query()->count());
        $this->assertSame(8, CalendarEvent::query()->distinct()->count('recurrence_id'));
    }

    public function test_rerunning_against_an_identical_feed_reports_zero_upserted(): void
    {
        $body = $this->fixture('recurring-weekly.ics');

        Http::fake(['example.test/*' => Http::sequence()
            ->push($body, 200, ['ETag' => '"v1"'])
            ->push($body, 200, ['ETag' => '"v1"'])]);

        SyncCalendarFeed::dispatchSync(force: true);
        SyncCalendarFeed::dispatchSync(force: true);

        $run = CalendarSyncRun::query()->latest('started_at')->first();

        $this->assertSame(0, $run->events_upserted);
        $this->assertSame(8, CalendarEvent::query()->count());
    }

    public function test_rerunning_with_one_occurrence_removed_cancels_only_that_occurrence(): void
    {
        $fullSeries = $this->fixture('recurring-weekly.ics');
        // Same UID/series as the first fetch, minus the March 16 occurrence —
        // simulates the operator deleting one occurrence from the feed.
        $seriesWithOneRemoved = str_replace(
            "RRULE:FREQ=WEEKLY;COUNT=8\n",
            "RRULE:FREQ=WEEKLY;COUNT=8\nEXDATE;TZID=Europe/Rome:20260316T090000\n",
            $fullSeries
        );

        Http::fake(['example.test/*' => Http::sequence()
            ->push($fullSeries, 200)
            ->push($seriesWithOneRemoved, 200)]);

        SyncCalendarFeed::dispatchSync(force: true);

        $this->travel(1)->minute();

        SyncCalendarFeed::dispatchSync(force: true);

        $this->assertSame(8, CalendarEvent::query()->count(), 'no row is ever deleted');

        $removed = CalendarEvent::query()->where('recurrence_id', '2026-03-16T08:00:00Z')->first();
        $this->assertNotNull($removed->cancelled_at);

        $siblings = CalendarEvent::query()->where('recurrence_id', '!=', '2026-03-16T08:00:00Z')->get();
        $this->assertTrue($siblings->every(fn (CalendarEvent $event) => $event->cancelled_at === null));
    }

    public function test_an_all_day_events_stored_date_matches_the_fixture_exactly(): void
    {
        Http::fake(['example.test/*' => Http::response($this->fixture('all-day.ics'), 200)]);

        SyncCalendarFeed::dispatchSync(force: true);

        $event = CalendarEvent::query()->first();

        $this->assertTrue($event->is_all_day);
        $this->assertSame('2026-03-10', $event->starts_at->format('Y-m-d'));
        $this->assertSame('2026-03-11', $event->ends_at->format('Y-m-d'));
    }

    public function test_a_dst_crossing_series_renders_at_the_same_local_time_on_both_sides(): void
    {
        Http::fake(['example.test/*' => Http::response($this->fixture('dst-transition.ics'), 200)]);

        SyncCalendarFeed::dispatchSync(force: true);

        $events = CalendarEvent::query()->orderBy('starts_at')->get();

        $this->assertCount(3, $events);

        foreach ($events as $event) {
            $this->assertSame('Europe/Rome', $event->timezone);
            $this->assertSame('09:00', $event->starts_at->setTimezone(config('kbms.timezone'))->format('H:i'));
        }
    }
}
