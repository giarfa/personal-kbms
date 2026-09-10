<?php

namespace Tests\Feature;

use App\Livewire\Agenda;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * US-012 on the agenda surface.
 *
 * Every fixture sets `location` explicitly: the factory only sets one half the
 * time, and the shipped "location is empty" rule would otherwise let a coin
 * flip decide the outcome of a colour assertion.
 */
class AgendaColourTest extends TestCase
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
            ->at(now('Europe/Rome')->setTime($hour, 0), 30)
            ->create(array_merge(['location' => 'Room 1'], $attributes));
    }

    public function test_a_ping_meeting_carries_its_colour_class_and_its_screen_reader_label(): void
    {
        $this->meeting(['summary' => 'PING weekly']);

        Livewire::test(Agenda::class)
            ->assertSee('kb-row--colour-yellow', false)
            ->assertSee('<span class="kb-sronly">Ping</span>', false);
    }

    public function test_a_meeting_without_a_location_carries_the_purple_class(): void
    {
        $this->meeting(['summary' => 'Roadmap review', 'location' => null]);

        Livewire::test(Agenda::class)
            ->assertSee('kb-row--colour-purple', false)
            ->assertSee('<span class="kb-sronly">No location</span>', false);
    }

    public function test_a_meeting_matching_both_rules_renders_only_the_first(): void
    {
        // Array order is the priority: PING is listed first, so a locationless
        // PING meeting is yellow and the purple rule is never reached.
        $this->meeting(['summary' => 'PING weekly', 'location' => null]);

        Livewire::test(Agenda::class)
            ->assertSee('kb-row--colour-yellow', false)
            ->assertDontSee('kb-row--colour-purple', false)
            ->assertSee('<span class="kb-sronly">Ping</span>', false)
            ->assertDontSee('<span class="kb-sronly">No location</span>', false);
    }

    public function test_an_unmatched_meeting_carries_no_colour_class(): void
    {
        $this->meeting(['summary' => 'Roadmap review']);

        $html = Livewire::test(Agenda::class)->html();

        $this->assertStringNotContainsString('kb-row--colour-', $html);
    }

    public function test_a_cancelled_match_keeps_its_cancelled_treatment(): void
    {
        CalendarEvent::factory()
            ->at(now('Europe/Rome')->setTime(9, 0), 30)
            ->cancelled()
            ->create(['summary' => 'PING weekly', 'location' => 'Room 1']);

        // Cancelled wins where the two conflict, but the class still ships —
        // the stylesheet's :not(.kb-row--cancelled) guard is what resolves it.
        Livewire::test(Agenda::class)
            ->assertSee('kb-row--cancelled', false)
            ->assertSee('kb-row--colour-yellow', false)
            ->assertSee('Cancelled');
    }

    public function test_an_annotated_match_still_shows_its_coverage_tags(): void
    {
        $event = $this->meeting(['summary' => 'PING weekly']);
        MeetingNote::factory()->forOccurrence($event)->create();

        // Colour and coverage are independent channels: this row must read as
        // both a PING meeting and an annotated one.
        Livewire::test(Agenda::class)
            ->assertSee('kb-row--colour-yellow', false)
            ->assertSee('kb-tag--note', false)
            ->assertSee('Notes');
    }

    public function test_the_legend_lists_every_configured_rule(): void
    {
        Livewire::test(Agenda::class)
            ->assertSee('kb-legend__swatch--yellow', false)
            ->assertSee('kb-legend__swatch--purple', false)
            ->assertSee('Ping')
            ->assertSee('No location');
    }

    public function test_a_third_rule_appears_in_the_legend_with_no_view_edit(): void
    {
        config(['kbms.event_colour_rules' => array_merge($this->defaultRules(), [
            ['field' => 'summary', 'condition' => 'contains', 'value' => 'retro', 'colour' => 'green', 'label' => 'Retro'],
        ])]);

        Livewire::test(Agenda::class)
            ->assertSee('kb-legend__swatch--green', false)
            ->assertSee('Retro');
    }

    public function test_an_empty_rule_list_is_the_off_state(): void
    {
        config(['kbms.event_colour_rules' => []]);
        $this->meeting(['summary' => 'PING weekly', 'location' => null]);

        $html = Livewire::test(Agenda::class)->html();

        $this->assertStringNotContainsString('kb-row--colour-', $html);
        $this->assertStringNotContainsString('kb-legend__swatch', $html);
        $this->assertStringNotContainsString('Rule colours:', $html);
    }

    public function test_a_malformed_rule_leaves_the_page_rendering_and_the_event_uncoloured(): void
    {
        config(['kbms.event_colour_rules' => [
            ['field' => 'summary', 'condition' => 'contains', 'value' => 'PING', 'colour' => '#ff0000', 'label' => 'Ping'],
        ]]);

        $this->meeting(['summary' => 'PING weekly']);

        // A misconfigured colour must never reach the browser as markup, and
        // must never break the agenda.
        $this->get('/')
            ->assertOk()
            ->assertSee('PING weekly')
            ->assertDontSee('kb-row--colour-', false)
            ->assertDontSee('#ff0000', false);
    }

    public function test_colouring_adds_no_queries_to_a_dense_week(): void
    {
        for ($i = 0; $i < 30; $i++) {
            CalendarEvent::factory()
                ->at(now('Europe/Rome')->addDays($i % 7)->setTime(8 + ($i % 8), 0), 30)
                ->create(['summary' => $i % 2 === 0 ? "PING sync {$i}" : "Review {$i}", 'location' => $i % 3 === 0 ? null : 'Room 1']);
        }

        config(['kbms.event_colour_rules' => []]);
        $without = $this->countQueriesRenderingTheAgenda();

        config(['kbms.event_colour_rules' => $this->defaultRules()]);
        $with = $this->countQueriesRenderingTheAgenda();

        // Rules are matched against columns the query already hydrated, so a
        // dense week costs exactly what it cost before this spec.
        $this->assertSame($without, $with);
    }

    private function countQueriesRenderingTheAgenda(): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::test(Agenda::class)->html();

        return $queries;
    }
}
