<?php

namespace Tests\Feature;

use App\Livewire\Agenda;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
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

    public function test_a_carried_over_timed_event_renders_on_the_agenda_with_its_span(): void
    {
        CalendarEvent::factory()->create([
            'summary' => 'Overnight incident bridge',
            'is_all_day' => false,
            'starts_at' => '2026-09-07 22:00:00',
            'ends_at' => '2026-09-08 06:00:00',
        ]);

        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(8, 45), 15)->create([
            'summary' => 'Daily standup',
        ]);

        $response = $this->get('/')
            ->assertOk()
            ->assertSee('Overnight incident bridge')
            ->assertSee('Sep 7 – Sep 8')
            ->assertSee('Since 22:00');

        // It is already running when the day opens, so it sits above the
        // meetings that actually start this morning.
        $body = $response->getContent();
        $this->assertLessThan(
            strpos($body, 'Daily standup'),
            strpos($body, 'Overnight incident bridge'),
        );
    }

    public function test_a_timed_event_starting_today_keeps_its_plain_wall_clock(): void
    {
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(8, 45), 15)->create([
            'summary' => 'Daily standup',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('08:45')
            ->assertDontSee('Since 08:45');
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
        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Bare row meeting',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Not annotated', $this->badgesFor($html, 'Bare row meeting'));
    }

    public function test_an_annotated_meeting_renders_the_notes_badge(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Annotated meeting',
        ]);
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'notes']);

        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(11, 0), 30)->create([
            'summary' => 'Bare meeting',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $annotatedBadges = $this->badgesFor($html, 'Annotated meeting');
        $this->assertStringContainsString('Notes', $annotatedBadges);
        $this->assertStringNotContainsString('Not annotated', $annotatedBadges);

        $bareBadges = $this->badgesFor($html, 'Bare meeting');
        $this->assertStringContainsString('Not annotated', $bareBadges);
        $this->assertStringNotContainsString('Notes', $bareBadges);
    }

    public function test_a_blank_bodied_note_reads_not_annotated(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Blank note meeting',
        ]);
        MeetingNote::factory()->forOccurrence($event)->blank()->create();

        $html = $this->get('/')->assertOk()->getContent();

        $badges = $this->badgesFor($html, 'Blank note meeting');
        $this->assertStringContainsString('Not annotated', $badges);
        $this->assertStringNotContainsString('Notes', $badges);
    }

    public function test_the_day_headers_annotated_count_reflects_reality(): void
    {
        $annotated = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();
        MeetingNote::factory()->forOccurrence($annotated)->create(['body' => 'notes']);

        CalendarEvent::factory()->at(now('Europe/Rome')->setTime(11, 0), 30)->create();

        $this->get('/')->assertOk()->assertSee('1 annotated');
    }

    public function test_one_occurrence_of_a_series_is_badged_without_its_siblings(): void
    {
        $annotated = CalendarEvent::factory()->occurrenceOf('series-uid', 'r1')->at(
            now('Europe/Rome')->setTime(9, 30),
            30
        )->create(['summary' => 'Weekly sync annotated']);
        MeetingNote::factory()->forOccurrence($annotated)->create(['body' => 'notes']);

        CalendarEvent::factory()->occurrenceOf('series-uid', 'r2')->at(
            now('Europe/Rome')->setTime(11, 0),
            30
        )->create(['summary' => 'Weekly sync sibling']);

        $html = $this->get('/')->assertOk()->getContent();

        $annotatedBadges = $this->badgesFor($html, 'Weekly sync annotated');
        $this->assertStringContainsString('Notes', $annotatedBadges);
        $this->assertStringNotContainsString('Not annotated', $annotatedBadges);

        $siblingBadges = $this->badgesFor($html, 'Weekly sync sibling');
        $this->assertStringContainsString('Not annotated', $siblingBadges);
        $this->assertStringNotContainsString('Notes', $siblingBadges);
    }

    private function badgesFor(string $html, string $summary): string
    {
        $start = strpos($html, 'kb-row__title">'.e($summary));
        $this->assertNotFalse($start, "No agenda row found for [{$summary}].");
        $end = strpos($html, '</a>', $start);

        return substr($html, $start, $end - $start);
    }
}
