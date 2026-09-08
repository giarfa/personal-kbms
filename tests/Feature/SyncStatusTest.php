<?php

namespace Tests\Feature;

use App\Jobs\SyncCalendarFeed;
use App\Livewire\SyncAlert;
use App\Livewire\SyncStatus;
use App\Models\CalendarSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SyncStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_never_synced_shows_the_literal_string_and_no_relative_time(): void
    {
        Livewire::test(SyncStatus::class)
            ->assertSee('Never synced')
            ->assertDontSee('ago');
    }

    public function test_a_recent_success_shows_relative_and_absolute_time(): void
    {
        $startedAt = now()->subMinutes(4);
        CalendarSyncRun::factory()->successful()->create(['started_at' => $startedAt]);

        Livewire::test(SyncStatus::class)
            ->assertSee('4 minutes ago')
            ->assertSee($startedAt->setTimezone(config('kbms.timezone'))->format('H:i'));
    }

    public function test_stale_shows_stale_and_not_failed(): void
    {
        CalendarSyncRun::factory()->stale()->create();

        Livewire::test(SyncStatus::class)
            ->assertSee('stale')
            ->assertDontSee('failed');
    }

    public function test_failed_shows_the_stored_error_text(): void
    {
        CalendarSyncRun::factory()->successful()->create(['started_at' => now()->subMinutes(30)]);
        CalendarSyncRun::factory()->failed()->create([
            'started_at' => now()->subMinutes(2),
            'error' => 'feed responded with HTTP 503',
        ]);

        Livewire::test(SyncStatus::class)
            ->assertSee('feed responded with HTTP 503', false);
    }

    public function test_syncing_shows_syncing_and_has_no_resync_control(): void
    {
        CalendarSyncRun::factory()->running()->create(['started_at' => now()->subMinute()]);

        Livewire::test(SyncStatus::class)
            ->assertSee('Syncing')
            ->assertDontSee('Resync');
    }

    public function test_resync_dispatches_the_job_when_no_run_is_in_progress(): void
    {
        Queue::fake();

        Livewire::test(SyncStatus::class)->call('resync');

        Queue::assertPushed(SyncCalendarFeed::class, 1);
    }

    public function test_resync_pushes_nothing_while_a_run_is_already_in_progress(): void
    {
        Queue::fake();
        CalendarSyncRun::factory()->running()->create(['started_at' => now()->subMinute()]);

        Livewire::test(SyncStatus::class)->call('resync');

        Queue::assertNothingPushed();
    }

    public function test_sync_alert_renders_the_error_with_role_alert_when_failed(): void
    {
        CalendarSyncRun::factory()->successful()->create(['started_at' => now()->subMinutes(30)]);
        CalendarSyncRun::factory()->failed()->create([
            'started_at' => now()->subMinutes(2),
            'error' => 'connection timed out after 30s',
        ]);

        Livewire::test(SyncAlert::class)
            ->assertSee('role="alert"', false)
            ->assertSee('connection timed out after 30s', false)
            ->assertSee('KBMS_ICS_URL', false)
            ->assertSee('kbms:doctor', false);
    }

    public function test_sync_alert_renders_the_stale_variant(): void
    {
        CalendarSyncRun::factory()->stale()->create();

        Livewire::test(SyncAlert::class)
            ->assertSee('Mirror may be stale')
            ->assertSee('role="status"', false);
    }

    public function test_sync_alert_renders_nothing_when_healthy_syncing_or_never_synced(): void
    {
        Livewire::test(SyncAlert::class)->assertDontSee('kb-banner', false);

        CalendarSyncRun::factory()->successful()->create(['started_at' => now()->subMinutes(4)]);
        Livewire::test(SyncAlert::class)->assertDontSee('kb-banner', false);

        CalendarSyncRun::query()->delete();
        CalendarSyncRun::factory()->running()->create(['started_at' => now()->subMinute()]);
        Livewire::test(SyncAlert::class)->assertDontSee('kb-banner', false);
    }
}
