<?php

namespace Tests\Feature;

use App\Jobs\LaunchClaudeSession;
use App\Launcher\LaunchChord;
use App\Launcher\LaunchCommand;
use App\Launcher\LaunchPreflight;
use App\Livewire\AskClaude;
use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Models\PromptLaunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Manual test handoff (US-014, US-019): the true keystroke round-trip —
 * typing in a real browser, and pressing `Cmd`/`Ctrl`+`Enter` — only
 * reproduces client-side. Verify manually at https://personal-kbms.test:
 * US-014 — the button enables without a blur/other action once a valid
 * question is typed; US-019 — `Cmd`+`Enter` (macOS) or `Ctrl`+`Enter`
 * (elsewhere) launches with the freshly typed text even when pressed
 * immediately after the last keystroke, bare `Enter`/`Shift`+`Enter` still
 * insert newlines, the chord is inert outside the question field, holding
 * it down or pressing it again mid-launch queues no duplicate, and the hint
 * reads `⌘ Enter` on macOS. Playwright/Dusk are out of scope under
 * `testing: NORMAL` — see `.larapilot/docs/test-results/US-019-manual.md`
 * for the full checklist.
 */
class AskClaudePanelTest extends TestCase
{
    use RefreshDatabase;

    private string $transcriptsDir;

    private string $launcherFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transcriptsDir = realpath(sys_get_temp_dir()).'/kbms-ask-claude-'.uniqid();
        mkdir($this->transcriptsDir, 0755);

        $this->launcherFile = sys_get_temp_dir().'/kbms-launcher-'.uniqid();
        file_put_contents($this->launcherFile, "#!/bin/sh\necho hi\n");
        chmod($this->launcherFile, 0755);

        config([
            'kbms.transcripts_path' => $this->transcriptsDir,
            'kbms.claude_launcher' => $this->launcherFile,
            'kbms.question_max_chars' => 8000,
            'kbms.launch_timeout_seconds' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->transcriptsDir)) {
            foreach (glob($this->transcriptsDir.'/*') ?: [] as $file) {
                @unlink($file);
            }

            rmdir($this->transcriptsDir);
        }

        if (is_file($this->launcherFile)) {
            unlink($this->launcherFile);
        }

        parent::tearDown();
    }

    private function readableTranscriptPath(): string
    {
        $path = "{$this->transcriptsDir}/transcript.md";
        file_put_contents($path, '# Transcript');
        file_put_contents(LaunchPreflight::txtSiblingPath($path), 'Transcript');

        return $path;
    }

    private function readyEvent(): CalendarEvent
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => $this->readableTranscriptPath(),
        ]);

        return $event;
    }

    public function test_blocked_when_launcher_is_not_configured(): void
    {
        config(['kbms.claude_launcher' => null]);
        $event = CalendarEvent::factory()->create();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'a valid question')
            ->call('launch')
            ->assertSee('`KBMS_CLAUDE_LAUNCHER` is not configured.');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prompt_launches', 0);
    }

    public function test_blocked_when_the_launcher_script_is_missing(): void
    {
        config(['kbms.claude_launcher' => sys_get_temp_dir().'/kbms-missing-'.uniqid()]);
        $event = CalendarEvent::factory()->create();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'a valid question')
            ->call('launch')
            ->assertSee('check `KBMS_CLAUDE_LAUNCHER`');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prompt_launches', 0);
    }

    public function test_blocked_when_the_launcher_script_is_not_executable(): void
    {
        chmod($this->launcherFile, 0644);
        $event = CalendarEvent::factory()->create();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'a valid question')
            ->call('launch')
            ->assertSee('chmod +x');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prompt_launches', 0);
    }

    public function test_blocked_when_there_is_no_transcript(): void
    {
        $event = CalendarEvent::factory()->create();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'a valid question')
            ->call('launch')
            ->assertSee('No transcript linked');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prompt_launches', 0);
    }

    public function test_blocked_when_the_transcript_is_tombstoned(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->suppressed()->create();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'a valid question')
            ->call('launch')
            ->assertSee('No transcript linked');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prompt_launches', 0);
    }

    public function test_blocked_when_the_transcript_path_escapes_the_configured_directory(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => realpath(sys_get_temp_dir()).'/kbms-outside-'.uniqid().'.md',
        ]);

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'a valid question')
            ->call('launch')
            ->assertSee('KBMS_TRANSCRIPTS_PATH');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prompt_launches', 0);
    }

    public function test_blocked_when_the_transcript_file_is_unreadable(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => "{$this->transcriptsDir}/gone.md",
        ]);

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'a valid question')
            ->call('launch')
            ->assertSee('is no longer readable');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prompt_launches', 0);
    }

    public function test_blocked_when_the_txt_sibling_is_missing(): void
    {
        $path = "{$this->transcriptsDir}/transcript.md";
        file_put_contents($path, '# Transcript');
        // Deliberately no .txt sibling written.
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create(['path' => $path]);

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'a valid question')
            ->call('launch')
            ->assertSee('missing or unreadable');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prompt_launches', 0);
    }

    public function test_an_empty_question_is_rejected_before_dispatch(): void
    {
        $event = $this->readyEvent();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', '')
            ->call('launch')
            ->assertSee('Type a question first');

        Queue::assertNothingPushed();
    }

    public function test_a_whitespace_only_question_is_rejected_before_dispatch(): void
    {
        $event = $this->readyEvent();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', "   \n\t  ")
            ->call('launch')
            ->assertSee('Type a question first');

        Queue::assertNothingPushed();
    }

    public function test_an_over_length_question_is_rejected_with_the_character_bound(): void
    {
        $event = $this->readyEvent();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', str_repeat('a', 8001))
            ->call('launch')
            ->assertSee('8000');

        Queue::assertNothingPushed();
    }

    public function test_a_question_at_exactly_the_character_bound_is_accepted(): void
    {
        $event = $this->readyEvent();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', str_repeat('a', 8000))
            ->call('launch');

        Queue::assertPushed(LaunchClaudeSession::class, 1);
    }

    public function test_the_launch_button_is_disabled_when_the_question_is_empty_or_whitespace_only(): void
    {
        $event = $this->readyEvent();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', '')
            ->assertSeeHtml('disabled aria-describedby="ask-block-reason"');

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', "   \n\t  ")
            ->assertSeeHtml('disabled aria-describedby="ask-block-reason"');
    }

    public function test_the_launch_button_is_enabled_once_a_valid_question_is_present(): void
    {
        $event = $this->readyEvent();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'What did we decide?')
            ->assertDontSeeHtml('disabled aria-describedby="ask-block-reason"');
    }

    public function test_the_question_textarea_keeps_the_live_binding_modifier(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/ask-claude.blade.php'));

        $this->assertStringContainsString('wire:model.live', $blade);
    }

    public function test_a_valid_launch_writes_one_queued_row_and_pushes_exactly_one_job(): void
    {
        $event = $this->readyEvent();

        Queue::fake();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'What did we decide?')
            ->call('launch');

        $this->assertDatabaseCount('prompt_launches', 1);
        $launch = PromptLaunch::query()->forOccurrence($event->occurrenceKey())->first();
        $this->assertNotNull($launch);
        $this->assertSame('queued', $launch->status->value);
        $this->assertCount(3, $launch->command);

        Queue::assertPushed(LaunchClaudeSession::class, fn (LaunchClaudeSession $job): bool => $job->launchId === $launch->id);
    }

    public function test_the_rendered_invocation_matches_launch_command_display_for_an_adversarial_question(): void
    {
        $path = $this->readableTranscriptPath();
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create(['path' => $path]);

        $question = "What did $(whoami) decide; also `rm -rf ~`, and \"why\"?\nInclude the second line too.";
        $command = new LaunchCommand($this->launcherFile, LaunchPreflight::txtSiblingPath($path), $question);
        [$scriptLine, $contextLine, $questionLine] = explode("\n", $command->display());

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', $question)
            ->assertSee($scriptLine)
            ->assertSee($contextLine)
            ->assertSee($questionLine);
    }

    public function test_a_failed_latest_launch_surfaces_its_exit_code(): void
    {
        $event = $this->readyEvent();
        PromptLaunch::factory()->forOccurrence($event)->failed(127)->create();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->assertSee('Launcher exited 127');
    }

    public function test_a_timed_out_latest_launch_surfaces_the_contract_violation_message(): void
    {
        $event = $this->readyEvent();
        PromptLaunch::factory()->forOccurrence($event)->timedOut()->create();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->assertSee('did not return within');
    }

    /**
     * The `Cmd`/`Ctrl`+`Enter` chord (US-019) calls this same `launch()`
     * action via `$wire.launch()` after a deferred `$wire.set('question', …,
     * false)` — see `resources/js/launch-shortcut.js`. There is no separate
     * server-side entry point for the keyboard path, so parity with the
     * button is structural: this is the one action both paths call, driven
     * here exactly as the button drives it.
     */
    public function test_launch_via_the_chord_writes_the_same_row_and_job_as_the_button(): void
    {
        $event = $this->readyEvent();
        $occurrence = CalendarEvent::query()->forOccurrence($event->occurrenceKey())->firstOrFail();

        Queue::fake();

        $expected = app(LaunchPreflight::class)->for($occurrence, 'What did we decide?');
        $this->assertInstanceOf(LaunchCommand::class, $expected);

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'What did we decide?')
            ->call('launch');

        $this->assertDatabaseCount('prompt_launches', 1);
        $launch = PromptLaunch::query()->forOccurrence($event->occurrenceKey())->first();
        $this->assertNotNull($launch);
        $this->assertSame($expected->contextPath, $launch->context_path);
        $this->assertSame($expected->question, $launch->question);
        $this->assertSame($expected->toArray(), $launch->command);

        Queue::assertPushed(LaunchClaudeSession::class, fn (LaunchClaudeSession $job): bool => $job->launchId === $launch->id);
    }

    public function test_the_shortcut_bindings_and_accessible_description_are_in_the_markup_when_ready(): void
    {
        $event = $this->readyEvent();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'What did we decide?')
            ->assertSeeHtml('x-on:keydown.cmd.enter.prevent="submit($event)"')
            ->assertSeeHtml('x-on:keydown.ctrl.enter.prevent="submit($event)"')
            ->assertSeeHtml('kbLaunchShortcut(')
            ->assertSeeHtml('question-shortcut')
            ->assertSee(LaunchChord::Command->label())
            ->assertSee(LaunchChord::Control->label());
    }

    public function test_the_accessible_description_lists_the_help_and_shortcut_ids_when_ready(): void
    {
        $event = $this->readyEvent();

        $html = Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'What did we decide?')
            ->html();

        // Blade's conditional literals can double up whitespace between
        // tokens (harmless — aria-describedby splits on any whitespace run),
        // so match the id order rather than exact spacing.
        $this->assertMatchesRegularExpression(
            '/aria-describedby="question-help\s+question-shortcut\s*"/',
            $html,
        );
    }

    public function test_the_shortcut_hint_is_absent_when_the_launcher_is_blocked(): void
    {
        $event = CalendarEvent::factory()->create();

        Livewire::test(AskClaude::class, ['occurrence' => $event])
            ->set('question', 'What did we decide?')
            ->assertDontSeeHtml('id="question-shortcut"')
            ->assertDontSeeHtml('x-text="chordLabel"');
    }
}
