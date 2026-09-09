<?php

namespace Tests\Unit\Meetings;

use App\Meetings\OutlookLinkBuilder;
use App\Meetings\OutlookLinkSource;
use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutlookLinkBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): OutlookLinkBuilder
    {
        return app(OutlookLinkBuilder::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['kbms.outlook_url_template' => 'https://outlook.office.com/calendar/view/day/{date}']);
    }

    public function test_feed_carried_event_url_wins_even_with_a_template_configured(): void
    {
        $event = CalendarEvent::factory()->withEventUrl()->make();

        $link = $this->builder()->for($event);

        $this->assertSame(OutlookLinkSource::Feed, $link->source);
        $this->assertSame($event->event_url, $link->url);
    }

    public function test_template_substitutes_date_time_and_datetime_in_the_local_timezone(): void
    {
        config(['kbms.outlook_url_template' => '{date} {time} {datetime}']);

        // Stored as local wall-clock digits per the sync convention (see EventSynchronizer).
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setDate(2026, 9, 8)->setTime(9, 30))->make();

        $link = $this->builder()->for($event);

        $this->assertSame(OutlookLinkSource::Template, $link->source);
        $this->assertSame('2026-09-08 09:30 2026-09-08T09:30:00+02:00', $link->url);
    }

    public function test_all_day_template_substitution_is_not_timezone_shifted(): void
    {
        config(['kbms.outlook_url_template' => '{date} {time} {datetime}']);

        $event = CalendarEvent::factory()->allDay()->make([
            'starts_at' => '2026-09-08 00:00:00',
            'ends_at' => '2026-09-08 00:00:00',
        ]);

        $link = $this->builder()->for($event);

        $this->assertSame(OutlookLinkSource::Template, $link->source);
        $this->assertSame('2026-09-08 00:00 2026-09-08T00:00:00', $link->url);
    }

    public function test_unavailable_when_neither_feed_link_nor_template_are_set(): void
    {
        config(['kbms.outlook_url_template' => null]);

        $event = CalendarEvent::factory()->make(['event_url' => null]);

        $link = $this->builder()->for($event);

        $this->assertSame(OutlookLinkSource::Unavailable, $link->source);
        $this->assertNull($link->url);
        $this->assertStringContainsString('KBMS_OUTLOOK_URL_TEMPLATE', $link->reason);
    }

    public function test_teams_url_is_resolved_independently_of_the_outlook_outcome(): void
    {
        $withTeams = CalendarEvent::factory()->withTeamsLink()->make();
        $withoutTeams = CalendarEvent::factory()->make(['join_url' => null]);

        $this->assertSame($withTeams->join_url, $this->builder()->teamsUrl($withTeams));
        $this->assertNull($this->builder()->teamsUrl($withoutTeams));

        // Teams presence never changes the Outlook outcome.
        $this->assertSame(OutlookLinkSource::Template, $this->builder()->for($withTeams)->source);
    }

    public function test_a_dangerous_event_url_scheme_is_rejected_and_falls_back_to_the_template(): void
    {
        $event = CalendarEvent::factory()->make(['event_url' => 'javascript:alert(1)']);

        $link = $this->builder()->for($event);

        $this->assertSame(OutlookLinkSource::Template, $link->source);
    }

    public function test_a_dangerous_join_url_scheme_is_rejected(): void
    {
        $event = CalendarEvent::factory()->make(['join_url' => 'javascript:alert(1)']);

        $this->assertNull($this->builder()->teamsUrl($event));
    }
}
