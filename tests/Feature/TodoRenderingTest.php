<?php

namespace Tests\Feature;

use App\Livewire\Agenda;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * US-013 across all three surfaces, plus the two promises that only show up
 * across a seam: the three channels rendering together, and an item crossing
 * into overdue on a US-011 self-refresh.
 *
 * Every fixture sets `location` explicitly — the factory only sets one half the
 * time, and the shipped US-012 "location is empty" rule would otherwise let a
 * coin flip decide an unrelated assertion.
 */
class TodoRenderingTest extends TestCase
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
     * @param  array<string, mixed>  $attributes
     */
    private function meeting(string $summary, int $hour = 14, array $attributes = []): CalendarEvent
    {
        return CalendarEvent::factory()
            ->at(CarbonImmutable::parse('2026-09-08', 'Europe/Rome')->setTime($hour, 0), 30)
            ->create(array_merge(['summary' => $summary, 'location' => 'Room 1'], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(string $title): array
    {
        /** @var list<array<string, mixed>> $events */
        $events = $this->getJson('/calendar/events?from=2026-09-01&to=2026-09-30')->assertOk()->json();

        foreach ($events as $event) {
            if ($event['title'] === $title) {
                return $event;
            }
        }

        $this->fail("No calendar payload found for [{$title}].");
    }

    // --- Agenda ----------------------------------------------------------

    public function test_the_agenda_renders_an_open_todo_with_its_glyph_and_stripped_title(): void
    {
        $this->meeting('[] Call the vendor');

        Livewire::test(Agenda::class)
            ->assertSee('kb-row--todo-open', false)
            ->assertSee('☐', false)
            ->assertSee('Call the vendor')
            ->assertDontSee('[] Call the vendor');
    }

    public function test_the_agenda_renders_a_done_todo_muted_and_struck(): void
    {
        $this->meeting('[x] Call the vendor', 9);

        Livewire::test(Agenda::class)
            ->assertSee('kb-row--todo-done', false)
            ->assertSee('☑', false)
            ->assertSee('Call the vendor');
    }

    public function test_the_agenda_renders_an_open_item_past_its_end_time_as_overdue(): void
    {
        $this->meeting('[] Call the vendor', 9);

        Livewire::test(Agenda::class)
            ->assertSee('kb-row--todo-overdue', false)
            ->assertSee('To do, overdue');
    }

    public function test_a_non_todo_meeting_renders_with_no_glyph_and_no_todo_class(): void
    {
        $this->meeting('Review [x] doc');

        $html = Livewire::test(Agenda::class)->html();

        $this->assertStringNotContainsString('kb-row--todo-', $html);
        $this->assertStringNotContainsString('kb-todo kb-todo--', $html);
        // The marker stays where it is: a mid-title bracket is ordinary text.
        $this->assertStringContainsString('Review [x] doc', $html);
    }

    public function test_a_marker_only_summary_renders_untitled_rather_than_a_blank_row(): void
    {
        $this->meeting('[]');

        Livewire::test(Agenda::class)
            ->assertSee('kb-row--todo-open', false)
            ->assertSee('Untitled');
    }

    public function test_the_agenda_legend_carries_the_three_todo_states(): void
    {
        Livewire::test(Agenda::class)
            ->assertSee('to do')
            ->assertSee('done')
            ->assertSee('overdue — still open past its end time');
    }

    // --- Calendar feed ---------------------------------------------------

    public function test_the_calendar_payload_carries_the_stripped_title_and_the_state_class(): void
    {
        $this->meeting('[] Call the vendor');

        $payload = $this->payloadFor('Call the vendor');

        $this->assertContains('kb-ev--todo-open', $payload['classNames']);
        $this->assertSame('open', $payload['extendedProps']['todoStatus']);
        $this->assertStringContainsString('To do', $payload['extendedProps']['accessibleName']);
    }

    public function test_a_done_calendar_event_never_reads_as_overdue(): void
    {
        $this->meeting('[x] Call the vendor', 9);

        $payload = $this->payloadFor('Call the vendor');

        $this->assertContains('kb-ev--todo-done', $payload['classNames']);
        $this->assertNotContains('kb-ev--todo-overdue', $payload['classNames']);
        $this->assertStringContainsString('Done', $payload['extendedProps']['accessibleName']);
    }

    public function test_the_overdue_class_is_present_in_the_month_week_and_day_windows(): void
    {
        $this->meeting('[] Call the vendor', 9);

        $windows = [
            'month' => ['2026-08-31', '2026-10-05'],
            'week' => ['2026-09-07', '2026-09-14'],
            'day' => ['2026-09-08', '2026-09-09'],
        ];

        foreach ($windows as $view => [$from, $to]) {
            /** @var list<array<string, mixed>> $events */
            $events = $this->getJson("/calendar/events?from={$from}&to={$to}")->assertOk()->json();
            $match = collect($events)->firstWhere('title', 'Call the vendor');

            $this->assertNotNull($match, "No payload in the {$view} window.");
            $this->assertContains('kb-ev--todo-overdue', $match['classNames'], "Missing overdue class in the {$view} window.");
        }
    }

    public function test_the_calendar_legend_carries_the_three_todo_states(): void
    {
        $this->get('/calendar')
            ->assertOk()
            ->assertSee('to do')
            ->assertSee('overdue — still open past its end time');
    }

    // --- Meeting detail --------------------------------------------------

    public function test_the_detail_page_shows_the_status_and_still_shows_the_raw_summary(): void
    {
        $event = $this->meeting('[] Call the vendor', 9);
        $key = $event->occurrenceKey()->toRouteKey();

        $response = $this->get("/meetings/{$key}")->assertOk();

        // Heading: stripped title plus status.
        $response->assertSee('Call the vendor');
        $response->assertSee('To do, overdue');

        // Mirror panel: the feed's own summary, marker intact (FR-004).
        $response->assertSee('[] Call the vendor');
    }

    public function test_a_non_todo_detail_page_is_unchanged(): void
    {
        $event = $this->meeting('Roadmap review');
        $key = $event->occurrenceKey()->toRouteKey();

        $html = $this->get("/meetings/{$key}")->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringNotContainsString('kb-todo kb-todo--', $html);
    }

    // --- Channel independence --------------------------------------------

    public function test_an_overdue_rule_coloured_annotated_occurrence_renders_all_three_channels(): void
    {
        config(['kbms.event_colour_rules' => [
            ['field' => 'summary', 'condition' => 'contains', 'value' => 'PING', 'colour' => 'yellow', 'label' => 'Ping'],
        ]]);

        $event = $this->meeting('[] PING sync', 9, ['location' => null]);
        MeetingNote::factory()->forOccurrence($event)->create();

        // Agenda: todo class, colour class, coverage tag — all three at once.
        $html = Livewire::test(Agenda::class)->html();
        $this->assertStringContainsString('kb-row--todo-overdue', $html);
        $this->assertStringContainsString('kb-row--colour-yellow', $html);
        $this->assertStringContainsString('kb-tag--note', $html);

        // Calendar: same three, plus all three stated in the accessible name.
        $payload = $this->payloadFor('PING sync');
        $this->assertContains('kb-ev--todo-overdue', $payload['classNames']);
        $this->assertContains('kb-ev--colour-yellow', $payload['classNames']);
        $this->assertContains('kb-ev--note', $payload['classNames']);
        $this->assertSame('09:00 PING sync, has notes, Ping, To do, overdue', $payload['extendedProps']['accessibleName']);
    }

    public function test_cancelled_wins_over_late(): void
    {
        $this->meeting('[] Call the vendor', 9, ['cancelled_at' => Carbon::parse('2026-09-07 10:00:00')]);

        $payload = $this->payloadFor('Call the vendor');

        $this->assertContains('kb-ev--cancelled', $payload['classNames']);
        $this->assertContains('kb-ev--todo-open', $payload['classNames']);
        $this->assertNotContains('kb-ev--todo-overdue', $payload['classNames']);
        $this->assertStringEndsWith('cancelled', $payload['extendedProps']['accessibleName']);
    }

    public function test_an_all_day_item_is_not_overdue_during_its_own_day(): void
    {
        CalendarEvent::factory()->create([
            'summary' => '[] Submit the expenses',
            'location' => 'Room 1',
            'starts_at' => '2026-09-08 00:00:00',
            'ends_at' => '2026-09-09 00:00:00',
            'is_all_day' => true,
        ]);

        $payload = $this->payloadFor('Submit the expenses');

        $this->assertContains('kb-ev--todo-open', $payload['classNames']);
        $this->assertNotContains('kb-ev--todo-overdue', $payload['classNames']);

        // The day ends; the same occurrence is now late.
        Carbon::setTestNow(Carbon::parse('2026-09-09 00:00:01', 'Europe/Rome'));

        $this->assertContains('kb-ev--todo-overdue', $this->payloadFor('Submit the expenses')['classNames']);
    }

    // --- The US-011 seam --------------------------------------------------

    public function test_an_open_item_crosses_into_overdue_on_a_self_refresh(): void
    {
        $this->meeting('[] Call the vendor', 14);

        $component = Livewire::test(Agenda::class);
        $this->assertStringContainsString('kb-row--todo-open', $component->html());
        $this->assertStringNotContainsString('kb-row--todo-overdue', $component->html());

        // No reload, no new mount — exactly what the US-011 tick triggers.
        Carbon::setTestNow(Carbon::parse('2026-09-08 15:00:00', 'Europe/Rome'));

        $refreshed = $component->call('$refresh')->html();
        $this->assertStringContainsString('kb-row--todo-overdue', $refreshed);
        $this->assertStringNotContainsString('kb-row--todo-open', $refreshed);
    }

    // --- Performance ------------------------------------------------------

    public function test_todo_parsing_adds_no_queries_on_either_surface(): void
    {
        for ($i = 0; $i < 30; $i++) {
            CalendarEvent::factory()
                ->at(CarbonImmutable::parse('2026-09-08', 'Europe/Rome')->setTime(8, 0)->addHours($i % 10), 30)
                ->create(['summary' => "Review {$i}", 'location' => 'Room 1']);
        }

        $agendaWithout = $this->countQueries(fn () => Livewire::test(Agenda::class)->html());
        $feedWithout = $this->countQueries(fn () => $this->getJson('/calendar/events?from=2026-09-01&to=2026-09-30')->assertOk());

        CalendarEvent::query()->update(['summary' => DB::raw("'[] ' || summary")]);

        $agendaWith = $this->countQueries(fn () => Livewire::test(Agenda::class)->html());
        $feedWith = $this->countQueries(fn () => $this->getJson('/calendar/events?from=2026-09-01&to=2026-09-30')->assertOk());

        // Parsing reads summary and ends_at, both already hydrated, so a dense
        // window costs exactly what it cost before this spec.
        $this->assertSame($agendaWithout, $agendaWith);
        $this->assertSame($feedWithout, $feedWith);
    }

    private function countQueries(callable $render): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $render();

        return $queries;
    }
}
