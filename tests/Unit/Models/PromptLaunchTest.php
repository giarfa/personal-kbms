<?php

namespace Tests\Unit\Models;

use App\Launcher\PromptLaunchStatus;
use App\Models\CalendarEvent;
use App\Models\PromptLaunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PromptLaunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_occurrence_hits_the_composite_key_and_not_a_sibling(): void
    {
        $event = CalendarEvent::factory()->occurrenceOf('series', 'r1')->create();
        $sibling = CalendarEvent::factory()->occurrenceOf('series', 'r2')->create();

        PromptLaunch::factory()->forOccurrence($event)->create();

        $found = PromptLaunch::query()->forOccurrence($event->occurrenceKey())->first();
        $notFound = PromptLaunch::query()->forOccurrence($sibling->occurrenceKey())->first();

        $this->assertNotNull($found);
        $this->assertSame($event->source_uid, $found->event_uid);
        $this->assertNull($notFound);
    }

    public function test_casts_resolve(): void
    {
        $launch = PromptLaunch::factory()->launched()->create();
        $launch->refresh();

        $this->assertInstanceOf(PromptLaunchStatus::class, $launch->status);
        $this->assertSame(PromptLaunchStatus::Launched, $launch->status);
        $this->assertCount(3, $launch->command);
        $this->assertInstanceOf(Carbon::class, $launch->launched_at);
    }

    public function test_latest_first_orders_newest_launch_first(): void
    {
        $event = CalendarEvent::factory()->create();

        $older = PromptLaunch::factory()->forOccurrence($event)->create(['created_at' => now()->subMinutes(5)]);
        $newer = PromptLaunch::factory()->forOccurrence($event)->create(['created_at' => now()]);

        $results = PromptLaunch::query()->forOccurrence($event->occurrenceKey())->latestFirst()->get();

        $this->assertSame($newer->id, $results->first()->id);
        $this->assertSame($older->id, $results->last()->id);
    }

    public function test_queued_state_has_no_exit_code_or_launched_at(): void
    {
        $launch = PromptLaunch::factory()->queued()->create();

        $this->assertSame(PromptLaunchStatus::Queued, $launch->status);
        $this->assertNull($launch->exit_code);
        $this->assertNull($launch->launched_at);
    }

    public function test_failed_state_records_the_exit_code(): void
    {
        $launch = PromptLaunch::factory()->failed(127)->create();

        $this->assertSame(PromptLaunchStatus::Failed, $launch->status);
        $this->assertSame(127, $launch->exit_code);
        $this->assertNotNull($launch->error);
    }

    public function test_timed_out_state_records_the_contract_violation_message(): void
    {
        $launch = PromptLaunch::factory()->timedOut()->create();

        $this->assertSame(PromptLaunchStatus::TimedOut, $launch->status);
        $this->assertStringContainsString('did not return within', $launch->error);
    }

    public function test_blocked_state_never_launched(): void
    {
        $launch = PromptLaunch::factory()->blocked('No transcript linked.')->create();

        $this->assertSame(PromptLaunchStatus::Blocked, $launch->status);
        $this->assertNull($launch->launched_at);
        $this->assertSame('No transcript linked.', $launch->error);
    }
}
