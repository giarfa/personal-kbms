<?php

namespace Tests\Feature;

use App\Livewire\Agenda;
use App\Models\CalendarEvent;
use App\Models\CalendarSyncRun;
use App\Models\MeetingNote;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * US-011. The browser half of this spec — the 60 second cadence, the
 * hidden-tab pause, the catch-up on return, and the calendar's refetch —
 * has no server seam and is covered by the manual handoff in
 * `.larapilot/docs/test-results/US-011-manual.md`. What *is* testable here
 * is the half that matters most: that a `$refresh` (exactly what the tick
 * calls) produces a current render without disturbing view state.
 */
class AgendaSelfRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:00:00', 'Europe/Rome'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_agenda_root_subscribes_to_the_shared_refresh_cadence(): void
    {
        // The wiring is a single attribute, which makes it exactly the kind of
        // thing a later edit drops without noticing. Assert it on the rendered
        // page so the whole spec cannot silently stop working.
        $this->get('/')
            ->assertOk()
            ->assertSee('x-data="kbSelfRefresh"', false);
    }

    public function test_a_meeting_synced_after_first_paint_appears_on_the_next_refresh(): void
    {
        $component = Livewire::test(Agenda::class)
            ->assertDontSee('Vendor demo');

        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(15, 0), 45)->create([
            'summary' => 'Vendor demo',
        ]);

        $component->call('$refresh')
            ->assertSee('Vendor demo');
    }

    public function test_a_meeting_retitled_upstream_shows_its_new_title_on_refresh(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Roadmap review',
        ]);

        $component = Livewire::test(Agenda::class)
            ->assertSee('Roadmap review');

        $event->update(['summary' => 'Roadmap review (moved)']);

        $component->call('$refresh')
            ->assertSee('Roadmap review (moved)');
    }

    public function test_a_note_added_after_first_paint_flips_the_coverage_tag_on_refresh(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Client kickoff',
        ]);

        // "Not annotated" is also a legend entry, so count the badge class
        // rather than the words: legend + row before, legend only after.
        $component = Livewire::test(Agenda::class);
        $this->assertSame(2, substr_count($component->html(), 'kb-tag--bare'));

        MeetingNote::factory()->forOccurrence($event)->create();

        $refreshed = $component->call('$refresh');
        $refreshed->assertSee('Notes');
        $this->assertSame(1, substr_count($refreshed->html(), 'kb-tag--bare'));
    }

    public function test_a_refresh_keeps_a_past_range_anchored_instead_of_snapping_to_today(): void
    {
        CalendarEvent::factory()->at(Carbon::parse('2026-09-02 10:00:00', 'Europe/Rome')->toImmutable(), 30)->create([
            'summary' => 'Last week retro',
        ]);

        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(11, 0), 30)->create([
            'summary' => 'This week standup',
        ]);

        // mount() runs once; a refresh must not re-derive the anchor, or a
        // page left open on a past week snaps forward under the operator.
        Livewire::test(Agenda::class, ['date' => '2026-09-01'])
            ->assertSee('Last week retro')
            ->call('$refresh')
            ->assertSet('date', '2026-09-01')
            ->assertSee('Last week retro')
            ->assertDontSee('This week standup');
    }

    public function test_the_now_divider_re_derives_against_the_current_clock_on_each_refresh(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Morning sync',
        ]);

        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(14, 0), 30)->create([
            'summary' => 'Afternoon review',
        ]);

        $component = Livewire::test(Agenda::class)
            ->assertSee('08:00');

        Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00', 'Europe/Rome'));

        // The divider is time-derived chrome: it has to move as meetings pass,
        // not freeze where it landed at first paint.
        $component->call('$refresh')
            ->assertSee('10:00')
            ->assertDontSee('08:00');
    }

    public function test_the_relative_last_sync_wording_re_derives_on_refresh(): void
    {
        CalendarSyncRun::factory()->successful()->create([
            'started_at' => now()->subMinutes(2),
        ]);

        $component = Livewire::test(Agenda::class)
            ->assertSee('2 minutes ago');

        Carbon::setTestNow(Carbon::parse('2026-09-08 09:02:00', 'Europe/Rome'));

        $component->call('$refresh')
            ->assertSee('1 hour ago')
            ->assertDontSee('2 minutes ago');
    }

    public function test_a_refresh_stays_silent(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Client kickoff',
        ]);

        $html = Livewire::test(Agenda::class)
            ->call('$refresh')
            ->html();

        // No banner, no toast, and no announcement: the operator is meant to
        // notice only that the content is current. (Flux's own `wire:loading`
        // spinners on the Today button and the Jump-to input are left alone —
        // they carry a `wire:target`, so a targetless $refresh never trips
        // them, and they are not a refresh affordance.)
        $this->assertStringNotContainsString('role="alert"', $html);
        $this->assertStringNotContainsString('role="status"', $html);

        // Exactly one live region, and it is the pre-existing range label whose
        // content does not change across a refresh — so nothing is announced.
        $this->assertSame(1, substr_count($html, 'aria-live'));
        $this->assertStringContainsString('kb-toolbar__range', $html);
    }
}
