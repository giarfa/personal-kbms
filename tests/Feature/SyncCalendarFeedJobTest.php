<?php

namespace Tests\Feature;

use App\Calendar\SyncRunStatus;
use App\Jobs\SyncCalendarFeed;
use App\Models\CalendarEvent;
use App\Models\CalendarSyncRun;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCalendarFeedJobTest extends TestCase
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

        // Fixtures live in March-April 2026; freeze "now" so the default
        // -90/+180 day sync window actually covers them.
        $this->travelTo(Carbon::parse('2026-03-01'));
    }

    public function test_a_successful_run_writes_a_success_row_and_populates_calendar_events(): void
    {
        Http::fake([
            'example.test/*' => Http::response($this->fixture('recurring-weekly.ics'), 200, [
                'ETag' => '"v1"',
                'Last-Modified' => 'Wed, 21 Oct 2026 07:28:00 GMT',
            ]),
        ]);

        SyncCalendarFeed::dispatchSync();

        $run = CalendarSyncRun::query()->latest('started_at')->first();

        $this->assertSame(SyncRunStatus::Success, $run->status);
        $this->assertSame(200, $run->http_status);
        $this->assertSame('"v1"', $run->etag);
        $this->assertSame(8, $run->events_upserted);
        $this->assertSame(0, $run->events_cancelled);
        $this->assertSame(8, CalendarEvent::query()->count());
    }

    public function test_a_304_writes_a_not_modified_row_with_zero_writes(): void
    {
        Http::fake(['example.test/*' => Http::response('', 304)]);

        SyncCalendarFeed::dispatchSync();

        $run = CalendarSyncRun::query()->latest('started_at')->first();

        $this->assertSame(SyncRunStatus::NotModified, $run->status);
        $this->assertSame(0, CalendarEvent::query()->count());
    }

    public function test_validators_from_the_latest_successful_run_are_sent_on_the_next_fetch(): void
    {
        CalendarSyncRun::factory()->successful()->create([
            'etag' => '"stored-etag"',
            'last_modified' => 'Wed, 21 Oct 2026 07:28:00 GMT',
            'started_at' => now()->subHour(),
        ]);

        Http::fake(['example.test/*' => Http::response('', 304)]);

        SyncCalendarFeed::dispatchSync();

        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"stored-etag"'));
    }

    public function test_force_omits_the_stored_validators(): void
    {
        CalendarSyncRun::factory()->successful()->create([
            'etag' => '"stored-etag"',
            'started_at' => now()->subHour(),
        ]);

        Http::fake(['example.test/*' => Http::response('', 304)]);

        SyncCalendarFeed::dispatchSync(force: true);

        Http::assertSent(fn ($request) => ! $request->hasHeader('If-None-Match'));
    }

    public function test_an_unreachable_feed_writes_failed_and_leaves_the_mirror_intact(): void
    {
        CalendarEvent::factory()->create(['source_uid' => 'pre-existing-1', 'recurrence_id' => '', 'summary' => 'Untouched']);

        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        SyncCalendarFeed::dispatchSync();

        $run = CalendarSyncRun::query()->latest('started_at')->first();

        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNull($run->http_status);
        $this->assertSame(1, CalendarEvent::query()->count());
        $this->assertSame('Untouched', CalendarEvent::query()->first()->summary);
    }

    public function test_a_500_writes_failed_with_the_http_status_and_leaves_the_mirror_intact(): void
    {
        CalendarEvent::factory()->create(['source_uid' => 'pre-existing-2', 'recurrence_id' => '', 'summary' => 'Untouched']);

        Http::fake(['example.test/*' => Http::response('', 500)]);

        SyncCalendarFeed::dispatchSync();

        $run = CalendarSyncRun::query()->latest('started_at')->first();

        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame(500, $run->http_status);
        $this->assertSame(1, CalendarEvent::query()->count());
    }

    public function test_a_truncated_body_writes_failed_and_leaves_the_mirror_intact(): void
    {
        CalendarEvent::factory()->create(['source_uid' => 'pre-existing-3', 'recurrence_id' => '', 'summary' => 'Untouched']);

        Http::fake(['example.test/*' => Http::response($this->fixture('truncated.ics'), 200)]);

        SyncCalendarFeed::dispatchSync();

        $run = CalendarSyncRun::query()->latest('started_at')->first();

        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame(1, CalendarEvent::query()->count());
        $this->assertSame('Untouched', CalendarEvent::query()->first()->summary);
    }

    public function test_runs_older_than_the_retention_window_are_pruned(): void
    {
        config(['kbms.sync_run_retention_days' => 30]);

        $old = CalendarSyncRun::factory()->successful()->create([
            'started_at' => now()->subDays(40),
            'finished_at' => now()->subDays(40),
        ]);
        $recent = CalendarSyncRun::factory()->successful()->create([
            'started_at' => now()->subDays(5),
            'finished_at' => now()->subDays(5),
        ]);

        Http::fake(['example.test/*' => Http::response('', 304)]);

        SyncCalendarFeed::dispatchSync();

        $this->assertDatabaseMissing('calendar_sync_runs', ['id' => $old->id]);
        $this->assertDatabaseHas('calendar_sync_runs', ['id' => $recent->id]);
    }

    public function test_the_job_is_should_be_unique(): void
    {
        $this->assertInstanceOf(ShouldBeUnique::class, new SyncCalendarFeed);
    }
}
