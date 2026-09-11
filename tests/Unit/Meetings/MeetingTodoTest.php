<?php

namespace Tests\Unit\Meetings;

use App\Meetings\MeetingTodo;
use App\Meetings\TodoState;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US-013. Pins the marker vocabulary and the overdue comparison before any
 * surface renders them.
 */
class MeetingTodoTest extends TestCase
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
    private function event(string $summary, array $attributes = []): CalendarEvent
    {
        return CalendarEvent::factory()
            ->at(CarbonImmutable::parse('2026-09-08 14:00', 'Europe/Rome'), 30)
            ->make(array_merge(['summary' => $summary, 'location' => 'Room 1'], $attributes));
    }

    /**
     * Assert-and-unwrap, rather than `?->` in every assertion: a test that
     * silently short-circuits on `null` is a test that stops testing.
     */
    private function todoFor(CalendarEvent $event): MeetingTodo
    {
        $todo = MeetingTodo::for($event);

        $this->assertNotNull($todo, 'Expected ['.$event->summary.'] to parse as a todo.');

        return $todo;
    }

    public function test_every_accepted_marker_form_parses(): void
    {
        $open = ['[] Call the vendor', '[ ] Call the vendor', '[   ] Call the vendor', '  [ ] Call the vendor', '[]Call the vendor'];
        $done = ['[x] Call the vendor', '[X] Call the vendor', '[x]Call the vendor', '  [X]  Call the vendor'];

        foreach ($open as $summary) {
            $todo = MeetingTodo::for($this->event($summary));

            $this->assertNotNull($todo, "Expected [{$summary}] to parse.");
            $this->assertSame(TodoState::Open, $todo->state, "Expected [{$summary}] to be open.");
            $this->assertSame('Call the vendor', $todo->displayTitle);
        }

        foreach ($done as $summary) {
            $todo = MeetingTodo::for($this->event($summary));

            $this->assertNotNull($todo, "Expected [{$summary}] to parse.");
            $this->assertSame(TodoState::Done, $todo->state, "Expected [{$summary}] to be done.");
            $this->assertSame('Call the vendor', $todo->displayTitle);
        }
    }

    public function test_a_marker_that_is_not_at_the_very_start_is_ordinary_text(): void
    {
        // The whole point of anchoring: a title that happens to contain
        // brackets is a meeting, not a task.
        foreach (['Review [x] doc', 'Sprint [] planning', 'Call the vendor []'] as $summary) {
            $this->assertNull(MeetingTodo::for($this->event($summary)), "Expected [{$summary}] not to parse.");
        }
    }

    public function test_a_marker_outside_the_accepted_set_is_ordinary_text(): void
    {
        // `[ x ]` is the interesting one: the alternation must consume
        // everything up to `]`, so a padded x is not a done marker.
        foreach (['[ x ] Call', '[y] Call', '[xx] Call', '[ Call', '[X ] Call'] as $summary) {
            $this->assertNull(MeetingTodo::for($this->event($summary)), "Expected [{$summary}] not to parse.");
        }
    }

    public function test_the_raw_summary_is_never_mutated_by_parsing(): void
    {
        $event = $this->event('[] Call the vendor');

        MeetingTodo::for($event);

        $this->assertSame('[] Call the vendor', $event->summary);
    }

    public function test_a_marker_only_summary_falls_back_to_untitled(): void
    {
        foreach (['[]', '[x]', '[ ] ', '  [X]'] as $summary) {
            $todo = MeetingTodo::for($this->event($summary));

            $this->assertNotNull($todo, "Expected [{$summary}] to parse.");
            $this->assertSame('Untitled', $todo->displayTitle, "Expected [{$summary}] to fall back rather than render blank.");
        }
    }

    public function test_a_null_summary_parses_to_not_a_todo_and_raises_nothing(): void
    {
        $this->assertNull(MeetingTodo::for($this->event('placeholder', ['summary' => null])));
    }

    public function test_an_open_item_is_not_overdue_until_its_end_time_is_strictly_past(): void
    {
        $event = $this->event('[] Call the vendor', [
            'starts_at' => '2026-09-08 09:00:00',
            'ends_at' => '2026-09-08 09:30:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-08 09:30:00', 'Europe/Rome'));
        $this->assertFalse($this->todoFor($event)->isOverdue, 'Exactly on its end time is not yet overdue.');

        Carbon::setTestNow(Carbon::parse('2026-09-08 09:30:01', 'Europe/Rome'));
        $this->assertTrue($this->todoFor($event)->isOverdue, 'One second past its end time is overdue.');
    }

    public function test_a_done_item_is_never_overdue_however_old(): void
    {
        $event = $this->event('[x] Call the vendor', [
            'starts_at' => '2026-08-29 09:00:00',
            'ends_at' => '2026-08-29 09:30:00',
        ]);

        $todo = MeetingTodo::for($event);

        $this->assertNotNull($todo);
        $this->assertSame(TodoState::Done, $todo->state);
        $this->assertFalse($todo->isOverdue);
    }

    public function test_an_all_day_item_is_overdue_only_once_its_day_has_ended(): void
    {
        // ends_at holds the exclusive midnight boundary, so this falls out of
        // the same comparison — an all-day item must not read as late all day.
        $event = $this->event('[] Submit the expenses', [
            'starts_at' => '2026-09-08 00:00:00',
            'ends_at' => '2026-09-09 00:00:00',
            'is_all_day' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-08 23:59:00', 'Europe/Rome'));
        $this->assertFalse($this->todoFor($event)->isOverdue, 'Still its own day.');

        Carbon::setTestNow(Carbon::parse('2026-09-09 00:00:01', 'Europe/Rome'));
        $this->assertTrue($this->todoFor($event)->isOverdue, 'The day has ended.');
    }

    public function test_a_cancelled_occurrence_is_never_overdue(): void
    {
        // Cancelled wins over late, guarded in the parser rather than in CSS.
        $event = $this->event('[] Call the vendor', [
            'starts_at' => '2026-08-29 09:00:00',
            'ends_at' => '2026-08-29 09:30:00',
            'cancelled_at' => Carbon::parse('2026-08-28 10:00:00'),
        ]);

        $todo = MeetingTodo::for($event);

        $this->assertNotNull($todo);
        $this->assertSame(TodoState::Open, $todo->state);
        $this->assertFalse($todo->isOverdue);
    }

    public function test_now_is_re_derived_on_every_call_rather_than_captured(): void
    {
        $event = $this->event('[] Call the vendor', [
            'starts_at' => '2026-09-08 14:00:00',
            'ends_at' => '2026-09-08 14:30:00',
        ]);

        $this->assertFalse($this->todoFor($event)->isOverdue);

        // Same event object, no new mount, no cached timestamp — this is the
        // US-011 self-refresh seam: the clock moves and the status follows.
        Carbon::setTestNow(Carbon::parse('2026-09-08 15:00:00', 'Europe/Rome'));

        $this->assertTrue($this->todoFor($event)->isOverdue);
    }

    public function test_the_status_label_and_modifier_track_the_three_rendered_states(): void
    {
        $open = $this->event('[] Call', ['starts_at' => '2026-09-08 14:00:00', 'ends_at' => '2026-09-08 14:30:00']);
        $overdue = $this->event('[] Call', ['starts_at' => '2026-09-08 09:00:00', 'ends_at' => '2026-09-08 09:30:00']);
        $done = $this->event('[x] Call', ['starts_at' => '2026-09-08 09:00:00', 'ends_at' => '2026-09-08 09:30:00']);

        $this->assertSame('To do', $this->todoFor($open)->statusLabel());
        $this->assertSame('open', $this->todoFor($open)->modifier());

        $this->assertSame('To do, overdue', $this->todoFor($overdue)->statusLabel());
        $this->assertSame('overdue', $this->todoFor($overdue)->modifier());

        $this->assertSame('Done', $this->todoFor($done)->statusLabel());
        $this->assertSame('done', $this->todoFor($done)->modifier());
    }
}
