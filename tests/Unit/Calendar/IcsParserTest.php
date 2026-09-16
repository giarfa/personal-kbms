<?php

namespace Tests\Unit\Calendar;

use App\Calendar\Exceptions\FeedUnparsable;
use App\Calendar\IcsParser;
use App\Calendar\ParsedOccurrence;
use Carbon\Carbon;
use Tests\TestCase;

class IcsParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/ics/{$name}"));
    }

    /**
     * @return list<ParsedOccurrence>
     */
    private function parse(string $fixture, string $windowStart, string $windowEnd): array
    {
        return iterator_to_array(
            (new IcsParser)->parse($this->fixture($fixture), Carbon::parse($windowStart), Carbon::parse($windowEnd)),
            preserve_keys: false,
        );
    }

    public function test_a_weekly_series_expands_with_distinct_recurrence_ids(): void
    {
        $occurrences = $this->parse('recurring-weekly.ics', '2026-01-01', '2026-12-31');

        $this->assertCount(8, $occurrences);
        $this->assertSame(8, count(array_unique(array_map(fn ($o) => $o->recurrenceId, $occurrences))));
        $this->assertSame('Europe/Rome', $occurrences[0]->timezone);
    }

    public function test_exdate_removes_the_excluded_occurrence(): void
    {
        $occurrences = $this->parse('exdate.ics', '2026-01-01', '2026-12-31');

        $this->assertCount(7, $occurrences);
        $this->assertNotContains('2026-03-16T08:00:00Z', array_map(fn ($o) => $o->recurrenceId, $occurrences));
    }

    public function test_a_recurrence_id_override_is_not_flattened_into_the_series(): void
    {
        $occurrences = $this->parse('recurrence-override.ics', '2026-01-01', '2026-12-31');

        $overridden = collect($occurrences)->firstWhere('recurrenceId', '2026-03-16T08:00:00Z');
        $sibling = collect($occurrences)->firstWhere('recurrenceId', '2026-03-02T08:00:00Z');

        $this->assertSame('Weekly sync (moved)', $overridden->summary);
        $this->assertSame('2026-03-16T13:00:00+00:00', $overridden->startsAt->toIso8601String());
        $this->assertSame('Weekly sync', $sibling->summary);
    }

    public function test_a_weekly_series_stays_at_the_same_local_time_across_the_dst_boundary(): void
    {
        $occurrences = $this->parse('dst-transition.ics', '2026-01-01', '2026-12-31');

        $this->assertCount(3, $occurrences);

        foreach ($occurrences as $occurrence) {
            $this->assertSame('Europe/Rome', $occurrence->timezone);
            $this->assertSame('09:00', $occurrence->startsAt->setTimezone('Europe/Rome')->format('H:i'));
        }

        $this->assertSame('08:00', $occurrences[0]->startsAt->format('H:i'), 'before DST, 09:00 Rome is 08:00 UTC');
        $this->assertSame('07:00', $occurrences[2]->startsAt->format('H:i'), 'after DST, 09:00 Rome is 07:00 UTC');
    }

    public function test_an_all_day_event_yields_the_exact_date_with_no_shift(): void
    {
        $occurrences = $this->parse('all-day.ics', '2026-01-01', '2026-12-31');

        $this->assertCount(1, $occurrences);
        $occurrence = $occurrences[0];

        $this->assertTrue($occurrence->isAllDay);
        $this->assertNull($occurrence->timezone);
        $this->assertSame('2026-03-10', $occurrence->startsAt->format('Y-m-d'));
        $this->assertSame('2026-03-11', $occurrence->endsAt->format('Y-m-d'));
        $this->assertSame('', $occurrence->recurrenceId);
    }

    public function test_status_cancelled_yields_is_cancelled(): void
    {
        $occurrences = $this->parse('cancelled.ics', '2026-01-01', '2026-12-31');

        $this->assertCount(1, $occurrences);
        $this->assertTrue($occurrences[0]->isCancelled);
    }

    public function test_an_unbounded_series_is_bounded_by_the_window(): void
    {
        $occurrences = $this->parse('unbounded-series.ics', '2026-01-01', '2026-02-01');

        $this->assertCount(5, $occurrences);
    }

    public function test_truncated_body_throws_feed_unparsable(): void
    {
        $this->expectException(FeedUnparsable::class);

        $this->parse('truncated.ics', '2026-01-01', '2026-12-31');
    }

    public function test_html_body_throws_feed_unparsable(): void
    {
        $this->expectException(FeedUnparsable::class);

        $this->parse('not-calendar.html', '2026-01-01', '2026-12-31');
    }

    public function test_url_teams_link_and_teams_link_in_description_populate_join_and_event_url(): void
    {
        $weekly = $this->parse('recurring-weekly.ics', '2026-01-01', '2026-12-31');

        $this->assertSame('https://example.com/meetings/weekly-sync', $weekly[0]->eventUrl);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc123', $weekly[0]->joinUrl);
        $this->assertCount(2, $weekly[0]->attendees);
        $this->assertSame('John Smith', $weekly[0]->attendees[0]['name']);
        $this->assertSame('john.smith@example.com', $weekly[0]->attendees[0]['email']);

        $cancelled = $this->parse('cancelled.ics', '2026-01-01', '2026-12-31');

        $this->assertSame('https://teams.microsoft.com/l/meetup-join/19%3ameeting_fallback456', $cancelled[0]->joinUrl);
    }

    public function test_a_wrapped_description_teams_link_drops_the_closing_angle_bracket(): void
    {
        $occurrences = $this->parse('teams-link-wrapped.ics', '2026-01-01', '2026-12-31');
        $wrapped = collect($occurrences)->firstWhere('sourceUid', 'uid-wrapped-001');

        $this->assertSame(
            'https://teams.microsoft.com/l/meetup-join/19%3ameeting_wrapped001%40thread.v2/0?context=%7b%22Tid%22%3a%2205001249-320e-4144-af6d-4bf9030aa9b6%22%7d',
            $wrapped->joinUrl,
        );
        $this->assertFalse(str_contains($wrapped->joinUrl, '>'));
    }

    public function test_content_hash_is_stable_and_changes_when_the_summary_changes(): void
    {
        $occurrences = $this->parse('recurring-weekly.ics', '2026-01-01', '2026-12-31');
        $occurrencesAgain = $this->parse('recurring-weekly.ics', '2026-01-01', '2026-12-31');

        $this->assertSame($occurrences[0]->contentHash(), $occurrencesAgain[0]->contentHash());

        $overrideOccurrences = $this->parse('recurrence-override.ics', '2026-01-01', '2026-12-31');
        $renamed = collect($overrideOccurrences)->firstWhere('recurrenceId', '2026-03-16T08:00:00Z');
        $unrenamedSibling = collect($overrideOccurrences)->firstWhere('recurrenceId', '2026-03-02T08:00:00Z');

        $this->assertNotSame($renamed->contentHash(), $unrenamedSibling->contentHash());
    }
}
