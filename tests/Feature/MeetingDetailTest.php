<?php

namespace Tests\Feature;

use App\Meetings\OccurrenceKey;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', 'Europe/Rome'));
        config(['kbms.outlook_url_template' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_mirrored_field_is_rendered(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 60)->create([
            'summary' => 'Q4 roadmap review',
            'location' => 'Milano, Sala Nord',
            'organizer' => 'Chiara Fontana <c.fontana@example.com>',
            'description' => "Quarterly checkpoint.\n\nAgenda item one",
            'attendees' => [
                ['name' => 'Chiara Fontana', 'email' => 'c.fontana@example.com'],
                ['name' => 'Marco Bianchi', 'email' => 'm.bianchi@example.com'],
            ],
        ]);

        $this->get(route('meetings.show', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('Q4 roadmap review')
            ->assertSee('Milano, Sala Nord')
            ->assertSee('Chiara Fontana &lt;c.fontana@example.com&gt;', false)
            ->assertSee('Quarterly checkpoint.', false)
            ->assertSee('Chiara Fontana')
            ->assertSee('Marco Bianchi')
            ->assertSee('1 hour');
    }

    public function test_the_occurrence_uid_and_recurrence_id_are_on_the_page(): void
    {
        $event = CalendarEvent::factory()->occurrenceOf('series-uid-123', '20260907T073000Z')->at(
            now('Europe/Rome')->setTime(9, 30),
            30
        )->create();

        $this->get(route('meetings.show', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('series-uid-123')
            ->assertSee('20260907T073000Z');
    }

    public function test_two_occurrences_of_the_same_series_resolve_to_different_pages(): void
    {
        $first = CalendarEvent::factory()->occurrenceOf('series-uid', '20260907T073000Z')->at(
            now('Europe/Rome')->setTime(9, 30),
            30
        )->create();

        $second = CalendarEvent::factory()->occurrenceOf('series-uid', '20260914T073000Z')->at(
            now('Europe/Rome')->addWeek()->setTime(9, 30),
            30
        )->create();

        $this->assertNotSame(
            route('meetings.show', $first->occurrenceKey()->toRouteKey()),
            route('meetings.show', $second->occurrenceKey()->toRouteKey())
        );

        $this->get(route('meetings.show', $first->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('20260907T073000Z')
            ->assertDontSee('20260914T073000Z');

        $this->get(route('meetings.show', $second->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('20260914T073000Z')
            ->assertDontSee('20260907T073000Z');
    }

    public function test_a_feed_carried_event_url_is_the_ctas_href(): void
    {
        $event = CalendarEvent::factory()->withEventUrl()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $this->get(route('meetings.show', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee($event->event_url, false);
    }

    public function test_template_only_renders_the_substituted_template_url(): void
    {
        config(['kbms.outlook_url_template' => 'https://outlook.office.com/calendar/view/day/{date}']);

        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create(['event_url' => null]);

        $this->get(route('meetings.show', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('https://outlook.office.com/calendar/view/day/'.now('Europe/Rome')->toDateString(), false);
    }

    public function test_neither_link_disables_the_control_with_a_reason(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create(['event_url' => null]);

        $response = $this->get(route('meetings.show', $event->occurrenceKey()->toRouteKey()));

        $response->assertOk();
        $response->assertSee('disabled', false);
        $response->assertSee('KBMS_OUTLOOK_URL_TEMPLATE');
    }

    public function test_a_teams_link_is_a_distinct_action_and_external_links_are_safe(): void
    {
        $event = CalendarEvent::factory()->withTeamsLink()->withEventUrl()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $response = $this->get(route('meetings.show', $event->occurrenceKey()->toRouteKey()));

        $response->assertOk();
        $response->assertSee('Join Teams meeting');
        $response->assertSee($event->join_url, false);
        $response->assertSee('target="_blank"', false);
        $response->assertSee('rel="noopener"', false);
    }

    public function test_a_cancelled_occurrence_renders_its_marker(): void
    {
        $event = CalendarEvent::factory()->cancelled()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $this->get(route('meetings.show', $event->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertSee('Cancelled upstream');
    }

    public function test_an_unknown_route_key_returns_404(): void
    {
        $unknownKey = (new OccurrenceKey('does-not-exist'))->toRouteKey();

        $this->get('/meetings/'.$unknownKey)->assertNotFound();
    }

    public function test_a_malformed_route_key_returns_404(): void
    {
        $this->get('/meetings/not-valid-base64-!!!')->assertNotFound();
    }
}
