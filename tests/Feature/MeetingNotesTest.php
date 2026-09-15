<?php

namespace Tests\Feature;

use App\Livewire\MeetingNotes;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class MeetingNotesTest extends TestCase
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

    public function test_a_note_on_one_occurrence_of_a_series_never_appears_on_a_sibling(): void
    {
        $first = CalendarEvent::factory()->occurrenceOf('series-uid', '20260907T073000Z')->at(
            now('Europe/Rome')->setTime(9, 30),
            30
        )->create();

        $second = CalendarEvent::factory()->occurrenceOf('series-uid', '20260914T073000Z')->at(
            now('Europe/Rome')->addWeek()->setTime(9, 30),
            30
        )->create();

        Livewire::test(MeetingNotes::class, ['occurrence' => $first])
            ->set('body', 'Only for the first occurrence');

        Livewire::test(MeetingNotes::class, ['occurrence' => $second])
            ->assertSet('body', '');

        $this->get(route('meetings.show', $second->occurrenceKey()->toRouteKey()))
            ->assertOk()
            ->assertDontSee('Only for the first occurrence');
    }

    public function test_typing_persists_with_no_explicit_save_action(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->set('body', 'Autosaved content')
            ->assertSet('saveState', 'saved')
            ->assertSet('savedAt', now()->format('H:i'));

        $this->assertSame('Autosaved content', MeetingNote::query()->first()->body);
    }

    public function test_a_failed_write_keeps_typed_content_and_retry_persists_it(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        MeetingNote::saving(function (): void {
            throw new RuntimeException('simulated write failure');
        });

        $component = Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->set('body', 'typed but not yet saved')
            ->assertSet('saveState', 'error')
            ->assertSet('body', 'typed but not yet saved')
            ->assertSee('Not saved')
            ->assertSee('Retry');

        MeetingNote::flushEventListeners();

        $component->call('retry')->assertSet('saveState', 'saved');

        $this->assertSame('typed but not yet saved', MeetingNote::query()->first()->body);
    }

    public function test_preview_renders_an_html_bearing_note_escaped(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $response = Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->set('body', '<script>alert(1)</script>')
            ->call('switchMode', 'preview');

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_preview_renders_markdown_formatting_through_the_hardened_renderer(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $response = Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->set('body', '**bold**')
            ->call('switchMode', 'preview');

        $response->assertSee('<strong>bold</strong>', false);
    }

    public function test_the_component_behaves_identically_before_and_after_the_meeting_and_when_cancelled(): void
    {
        $past = CalendarEvent::factory()->at(now('Europe/Rome')->subDays(2), 30)->create();
        $future = CalendarEvent::factory()->at(now('Europe/Rome')->addDays(2), 30)->create();
        $cancelled = CalendarEvent::factory()->cancelled()->at(now('Europe/Rome')->addHour(), 30)->create();

        foreach ([$past, $future, $cancelled] as $event) {
            Livewire::test(MeetingNotes::class, ['occurrence' => $event])
                ->set('body', 'note for '.$event->id)
                ->assertSet('saveState', 'saved');
        }

        $this->assertSame(3, MeetingNote::query()->count());
    }

    public function test_clearing_the_editor_persists_a_blank_body_and_keeps_the_row(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'something']);

        Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->set('body', '')
            ->assertSet('saveState', 'saved');

        $this->assertDatabaseCount('meeting_notes', 1);
        $this->assertSame('', MeetingNote::query()->first()->body);
    }

    public function test_the_delete_path_removes_the_row_renders_both_required_strings_and_empties_the_editor(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create([
            'summary' => 'Q4 roadmap review',
        ]);
        MeetingNote::factory()->forOccurrence($event)->create(['body' => 'something to delete']);

        Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->assertSee('Only this occurrence is affected')
            ->assertSee('The linked transcript file is not touched. The pipeline owns it.')
            ->call('deleteNote')
            ->assertSet('body', '')
            ->assertSet('hasNote', false)
            ->assertSet('saveState', 'idle');

        $this->assertDatabaseCount('meeting_notes', 0);
    }

    public function test_the_copy_and_print_actions_are_absent_with_a_blank_body(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->assertDontSee('Copy as Markdown')
            ->assertDontSee('Print / Save as PDF');
    }

    public function test_the_copy_and_print_actions_appear_once_the_body_has_content(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->set('body', 'Something to share')
            ->assertSee('Copy as Markdown')
            ->assertSee('Print / Save as PDF');
    }

    public function test_the_print_link_targets_a_new_tab_with_noopener(): void
    {
        $event = CalendarEvent::factory()->at(now('Europe/Rome')->setTime(9, 30), 30)->create();

        $html = Livewire::test(MeetingNotes::class, ['occurrence' => $event])
            ->set('body', 'Something to share')
            ->html();

        $this->assertStringContainsString(route('meetings.note.print', $event->occurrenceKey()->toRouteKey()), $html);
        $this->assertMatchesRegularExpression('/target="_blank"\s+rel="noopener"/', $html);
    }
}
