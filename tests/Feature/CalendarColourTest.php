<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * US-012 on the calendar surface — the JSON feed FullCalendar consumes, plus
 * the page legend. Every fixture sets `location` explicitly, for the same
 * reason as `AgendaColourTest`.
 */
class CalendarColourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:00:00', 'Europe/Rome'));
        config(['kbms.event_colour_rules' => $this->defaultRules()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return list<array<string, string>>
     */
    private function defaultRules(): array
    {
        return [
            ['field' => 'summary', 'condition' => 'contains', 'value' => 'PING', 'colour' => 'yellow', 'label' => 'Ping'],
            ['field' => 'location', 'condition' => 'empty', 'colour' => 'purple', 'label' => 'No location'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function meeting(array $attributes, int $hour = 9): CalendarEvent
    {
        return CalendarEvent::factory()
            ->at(Carbon::parse('2026-09-08', 'Europe/Rome')->setTime($hour, 0)->toImmutable(), 30)
            ->create(array_merge(['location' => 'Room 1'], $attributes));
    }

    private function feed(): TestResponse
    {
        return $this->getJson('/calendar/events?from=2026-09-01&to=2026-09-30');
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(string $summary): array
    {
        /** @var list<array<string, mixed>> $events */
        $events = $this->feed()->assertOk()->json();

        foreach ($events as $event) {
            if ($event['title'] === $summary) {
                return $event;
            }
        }

        $this->fail("No calendar payload found for [{$summary}].");
    }

    public function test_a_ping_meeting_carries_its_colour_class_and_label_in_the_accessible_name(): void
    {
        $this->meeting(['summary' => 'PING weekly']);

        $payload = $this->payloadFor('PING weekly');

        $this->assertContains('kb-ev--colour-yellow', $payload['classNames']);
        $this->assertStringContainsString('Ping', $payload['extendedProps']['accessibleName']);
    }

    public function test_a_meeting_without_a_location_carries_the_purple_class(): void
    {
        $this->meeting(['summary' => 'Roadmap review', 'location' => null]);

        $payload = $this->payloadFor('Roadmap review');

        $this->assertContains('kb-ev--colour-purple', $payload['classNames']);
        $this->assertStringContainsString('No location', $payload['extendedProps']['accessibleName']);
    }

    public function test_a_meeting_matching_both_rules_renders_only_the_first(): void
    {
        $this->meeting(['summary' => 'PING weekly', 'location' => null]);

        $payload = $this->payloadFor('PING weekly');

        $this->assertContains('kb-ev--colour-yellow', $payload['classNames']);
        $this->assertNotContains('kb-ev--colour-purple', $payload['classNames']);
        $this->assertStringNotContainsString('No location', $payload['extendedProps']['accessibleName']);
    }

    public function test_an_unmatched_meeting_carries_no_colour_class(): void
    {
        $this->meeting(['summary' => 'Roadmap review']);

        $payload = $this->payloadFor('Roadmap review');

        foreach ($payload['classNames'] as $className) {
            $this->assertStringNotContainsString('kb-ev--colour-', $className);
        }
    }

    public function test_the_accessible_name_keeps_its_coverage_wording_and_only_gains_the_label(): void
    {
        $event = $this->meeting(['summary' => 'PING weekly']);
        MeetingNote::factory()->forOccurrence($event)->create();

        $name = $this->payloadFor('PING weekly')['extendedProps']['accessibleName'];

        // Coverage words first, then the rule label — the pre-existing wording
        // order is extended, not rewritten.
        $this->assertSame('09:00 PING weekly, has notes, Ping', $name);
    }

    public function test_a_cancelled_match_still_ends_with_cancelled(): void
    {
        CalendarEvent::factory()
            ->at(Carbon::parse('2026-09-08 09:00', 'Europe/Rome')->toImmutable(), 30)
            ->cancelled()
            ->create(['summary' => 'PING weekly', 'location' => 'Room 1']);

        $payload = $this->payloadFor('PING weekly');

        $this->assertContains('kb-ev--cancelled', $payload['classNames']);
        $this->assertContains('kb-ev--colour-yellow', $payload['classNames']);
        $this->assertStringEndsWith('cancelled', $payload['extendedProps']['accessibleName']);
    }

    public function test_coverage_marks_survive_a_colour_fill(): void
    {
        $event = $this->meeting(['summary' => 'PING weekly']);
        MeetingNote::factory()->forOccurrence($event)->create();

        $payload = $this->payloadFor('PING weekly');

        $this->assertContains('kb-ev--note', $payload['classNames']);
        $this->assertContains('kb-ev--colour-yellow', $payload['classNames']);
        $this->assertTrue($payload['extendedProps']['hasNotes']);
    }

    public function test_the_colour_renders_in_month_week_and_day_views(): void
    {
        $this->meeting(['summary' => 'PING weekly']);

        // The feed is view-agnostic — the same payload backs all three grids —
        // so asserting the three windows the views request is the real check.
        $windows = [
            'month' => ['2026-08-31', '2026-10-05'],
            'week' => ['2026-09-07', '2026-09-14'],
            'day' => ['2026-09-08', '2026-09-09'],
        ];

        foreach ($windows as $view => [$from, $to]) {
            /** @var list<array<string, mixed>> $events */
            $events = $this->getJson("/calendar/events?from={$from}&to={$to}")->assertOk()->json();
            $match = collect($events)->firstWhere('title', 'PING weekly');

            $this->assertNotNull($match, "No payload in the {$view} window.");
            $this->assertContains('kb-ev--colour-yellow', $match['classNames'], "Missing colour in the {$view} window.");
        }
    }

    public function test_the_legend_is_generated_from_config_and_matches_the_agendas(): void
    {
        $this->get('/calendar')
            ->assertOk()
            ->assertSee('kb-legend__swatch--yellow', false)
            ->assertSee('kb-legend__swatch--purple', false)
            ->assertSee('Ping')
            ->assertSee('No location');
    }

    public function test_a_third_rule_appears_in_the_calendar_legend_with_no_view_edit(): void
    {
        config(['kbms.event_colour_rules' => array_merge($this->defaultRules(), [
            ['field' => 'summary', 'condition' => 'contains', 'value' => 'retro', 'colour' => 'green', 'label' => 'Retro'],
        ])]);

        $this->get('/calendar')
            ->assertOk()
            ->assertSee('kb-legend__swatch--green', false)
            ->assertSee('Retro');
    }

    public function test_an_empty_rule_list_yields_no_legend_and_no_classes(): void
    {
        config(['kbms.event_colour_rules' => []]);
        $this->meeting(['summary' => 'PING weekly', 'location' => null]);

        $this->get('/calendar')
            ->assertOk()
            ->assertDontSee('kb-legend__swatch', false);

        $payload = $this->payloadFor('PING weekly');

        $this->assertSame([], $payload['classNames']);
    }

    public function test_a_malformed_rule_leaves_the_feed_and_the_page_rendering(): void
    {
        config(['kbms.event_colour_rules' => [
            ['field' => 'attendees', 'condition' => 'empty', 'colour' => 'yellow', 'label' => 'Solo'],
        ]]);

        $this->meeting(['summary' => 'PING weekly']);

        $this->get('/calendar')->assertOk()->assertDontSee('kb-legend__swatch', false);

        $payload = $this->payloadFor('PING weekly');

        $this->assertSame([], $payload['classNames']);
    }

    public function test_colouring_adds_no_queries_to_a_dense_month_window(): void
    {
        for ($i = 0; $i < 30; $i++) {
            CalendarEvent::factory()
                ->at(Carbon::parse('2026-09-01', 'Europe/Rome')->addDays($i)->setTime(9, 0)->toImmutable(), 30)
                ->create(['summary' => $i % 2 === 0 ? "PING sync {$i}" : "Review {$i}", 'location' => $i % 3 === 0 ? null : 'Room 1']);
        }

        config(['kbms.event_colour_rules' => []]);
        $without = $this->countQueriesFetchingTheFeed();

        config(['kbms.event_colour_rules' => $this->defaultRules()]);
        $with = $this->countQueriesFetchingTheFeed();

        $this->assertSame($without, $with);
    }

    private function countQueriesFetchingTheFeed(): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->feed()->assertOk();

        return $queries;
    }
}
