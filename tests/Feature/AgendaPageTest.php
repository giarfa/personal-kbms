<?php

namespace Tests\Feature;

use App\Livewire\Agenda;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgendaPageTest extends TestCase
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

    public function test_the_root_route_defaults_to_todays_range(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Client kickoff',
        ]);

        CalendarEvent::factory()->at(now('Europe/Rome')->addWeeks(3)->setTime(9, 30), 30)->create([
            'summary' => 'Far future planning',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Client kickoff')
            ->assertDontSee('Far future planning');
    }

    public function test_an_unparseable_anchor_date_falls_back_to_today_instead_of_throwing(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Client kickoff',
        ]);

        // `date` is a #[Url] property: a truncated or hand-edited bookmark must
        // not reach CarbonImmutable::parse() and 500 the application's index route.
        $this->get('/?date=not-a-date')
            ->assertOk()
            ->assertSee('Client kickoff');
    }

    public function test_a_calendar_impossible_anchor_date_falls_back_to_today(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Client kickoff',
        ]);

        $this->get('/?date=2026-02-31T99:99')
            ->assertOk()
            ->assertSee('Client kickoff');
    }

    public function test_a_datetime_valued_anchor_is_normalized_to_the_start_of_its_day(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Client kickoff',
        ]);

        Livewire::test(Agenda::class, ['date' => '2026-09-08 17:45:00'])
            ->assertSet('date', '2026-09-08')
            ->assertSee('Client kickoff');
    }

    public function test_an_invalid_anchor_is_rewritten_so_the_bookmarked_url_self_corrects(): void
    {
        Livewire::test(Agenda::class, ['date' => 'yesterday-ish'])
            ->assertSet('date', '2026-09-08');
    }

    public function test_next_and_previous_move_the_window(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->addDays(10)->setTime(9, 30), 30)->create([
            'summary' => 'Next window meeting',
        ]);

        Livewire::test(Agenda::class)
            ->assertDontSee('Next window meeting')
            ->call('next')
            ->assertSee('Next window meeting')
            ->call('previous')
            ->assertDontSee('Next window meeting');
    }

    public function test_today_resets_the_window(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Client kickoff',
        ]);

        Livewire::test(Agenda::class)
            ->call('next')
            ->assertDontSee('Client kickoff')
            ->call('today')
            ->assertSee('Client kickoff');
    }

    public function test_the_date_jump_re_anchors_the_window(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->addDays(20)->setTime(9, 30), 30)->create([
            'summary' => 'Distant meeting',
        ]);

        Livewire::test(Agenda::class)
            ->assertDontSee('Distant meeting')
            ->set('date', now('Europe/Rome')->addDays(20)->toDateString())
            ->assertSee('Distant meeting');
    }

    public function test_a_cancelled_occurrence_is_present_and_marked(): void
    {
        CalendarEvent::factory()->cancelled()->at(now('Europe/Rome')->setTime(14, 0), 30)->create([
            'summary' => 'Vendor demo',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Vendor demo')
            ->assertSee('Cancelled');
    }

    public function test_a_multiday_all_day_event_renders_its_span_label_once(): void
    {
        CalendarEvent::factory()->spanning(2)->create([
            'summary' => 'Offsite',
            'starts_at' => now('Europe/Rome')->format('Y-m-d').' 00:00:00',
            'ends_at' => now('Europe/Rome')->addDays(2)->format('Y-m-d').' 00:00:00',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeInOrder(['Offsite']);
        $this->assertSame(1, substr_count($response->getContent(), 'Offsite'));
    }

    public function test_a_day_with_no_meetings_renders_its_empty_state(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $this->get('/')->assertOk()->assertSee('Nothing on the calendar');
    }

    public function test_a_wholly_empty_range_renders_the_range_level_empty_state(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Nothing in this range')
            ->assertDontSee('Nothing on the calendar');
    }

    public function test_a_row_links_to_the_occurrence_route(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $this->get('/')->assertOk()->assertSee(
            route('meetings.show', $event->occurrenceKey()->toRouteKey())
        );
    }

    public function test_the_not_annotated_badge_appears_for_every_row(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $this->get('/')->assertOk()->assertSee('Not annotated');
    }
}
