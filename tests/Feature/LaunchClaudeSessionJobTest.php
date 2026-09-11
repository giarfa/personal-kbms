<?php

namespace Tests\Feature;

use App\Jobs\LaunchClaudeSession;
use App\Launcher\LaunchBlock;
use App\Launcher\LaunchBlockDetail;
use App\Launcher\LaunchPreflight;
use App\Launcher\PromptLaunchStatus;
use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Models\PromptLaunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;
use Tests\Unit\Launcher\AdversarialQuestions;

class LaunchClaudeSessionJobTest extends TestCase
{
    use AdversarialQuestions;
    use RefreshDatabase;

    private string $transcriptsDir;

    private string $launcherFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transcriptsDir = realpath(sys_get_temp_dir()).'/kbms-launch-job-'.uniqid();
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

    private function contextPath(): string
    {
        $path = "{$this->transcriptsDir}/transcript.md";
        file_put_contents($path, '# Transcript');
        file_put_contents(LaunchPreflight::txtSiblingPath($path), 'Transcript');

        return $path;
    }

    private function queuedLaunch(string $question, ?string $contextPath = null): PromptLaunch
    {
        $contextPath ??= $this->contextPath();
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create(['path' => $contextPath]);

        $txtPath = LaunchPreflight::txtSiblingPath($contextPath);

        return PromptLaunch::factory()->forOccurrence($event)->queued()->create([
            'context_path' => $txtPath,
            'question' => $question,
            'command' => [$this->launcherFile, $txtPath, $question],
        ]);
    }

    private function fakeTimeoutException(): ProcessTimedOutException
    {
        $symfonyProcess = new SymfonyProcess(['true']);
        $symfonyException = new SymfonyProcessTimedOutException($symfonyProcess, SymfonyProcessTimedOutException::TYPE_GENERAL);

        return ProcessTimedOutException::make($symfonyException, new ProcessResult($symfonyProcess));
    }

    #[DataProvider('adversarialQuestions')]
    public function test_every_adversarial_question_reaches_the_process_as_a_single_intact_argument(string $question): void
    {
        Process::fake();
        $launch = $this->queuedLaunch($question);
        $contextPath = $launch->context_path;

        LaunchClaudeSession::dispatchSync($launch->id);

        Process::assertRan(fn ($process) => $process->command === [$this->launcherFile, $contextPath, $question]);
        $this->assertSame(PromptLaunchStatus::Launched, $launch->fresh()->status);
    }

    public function test_exit_zero_is_recorded_as_launched(): void
    {
        Process::fake();
        $launch = $this->queuedLaunch('a question');

        LaunchClaudeSession::dispatchSync($launch->id);

        $fresh = $launch->fresh();
        $this->assertSame(PromptLaunchStatus::Launched, $fresh->status);
        $this->assertSame(0, $fresh->exit_code);
        $this->assertNotNull($fresh->launched_at);
    }

    public function test_exit_127_is_recorded_as_failed_with_a_stderr_excerpt(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'sh: claude: command not found', exitCode: 127)]);
        $launch = $this->queuedLaunch('a question');

        LaunchClaudeSession::dispatchSync($launch->id);

        $fresh = $launch->fresh();
        $this->assertSame(PromptLaunchStatus::Failed, $fresh->status);
        $this->assertSame(127, $fresh->exit_code);
        $this->assertStringContainsString('command not found', $fresh->error);
    }

    public function test_a_timeout_is_recorded_with_the_contract_violation_message(): void
    {
        Process::fake(fn () => $this->fakeTimeoutException());
        $launch = $this->queuedLaunch('a question');

        LaunchClaudeSession::dispatchSync($launch->id);

        $fresh = $launch->fresh();
        $this->assertSame(PromptLaunchStatus::TimedOut, $fresh->status);
        $this->assertStringContainsString('did not return within', $fresh->error);
    }

    public function test_a_second_pass_preflight_failure_blocks_and_runs_nothing(): void
    {
        $launch = $this->queuedLaunch('a question');
        // The .md transcript itself disappears between dispatch and execution.
        $mdPath = MeetingTranscript::query()->forOccurrence($launch->occurrenceKey())->first()->path;
        unlink($mdPath);

        Process::fake();

        LaunchClaudeSession::dispatchSync($launch->id);

        Process::assertNothingRan();
        $fresh = $launch->fresh();
        $this->assertSame(PromptLaunchStatus::Blocked, $fresh->status);
        $this->assertStringContainsString('no longer readable', $fresh->error);
        // The path detail must not be dropped — a blanked detail would still
        // contain "no longer readable" but with an empty quoted path.
        $this->assertStringContainsString($mdPath, $fresh->error);
    }

    public function test_a_second_pass_finds_the_txt_sibling_deleted_and_blocks_without_running(): void
    {
        $launch = $this->queuedLaunch('a question');
        // The .txt sibling disappears between dispatch and execution, while
        // the .md transcript itself stays intact.
        unlink($launch->context_path);

        Process::fake();

        LaunchClaudeSession::dispatchSync($launch->id);

        Process::assertNothingRan();
        $fresh = $launch->fresh();
        $this->assertSame(PromptLaunchStatus::Blocked, $fresh->status);
        $this->assertStringContainsString('missing or unreadable', $fresh->error);
    }

    public function test_launcher_deleted_after_dispatch_blocks_and_runs_nothing(): void
    {
        $launch = $this->queuedLaunch('a question');
        unlink($this->launcherFile);

        Process::fake();

        LaunchClaudeSession::dispatchSync($launch->id);

        Process::assertNothingRan();
        $fresh = $launch->fresh();
        $this->assertSame(PromptLaunchStatus::Blocked, $fresh->status);
        $this->assertStringContainsString($this->launcherFile, $fresh->error);
    }

    public function test_transcripts_path_repointed_after_dispatch_blocks_and_runs_nothing(): void
    {
        $launch = $this->queuedLaunch('a question');
        config(['kbms.transcripts_path' => sys_get_temp_dir().'/kbms-repointed-'.uniqid()]);

        Process::fake();

        LaunchClaudeSession::dispatchSync($launch->id);

        Process::assertNothingRan();
        $fresh = $launch->fresh();
        $this->assertSame(PromptLaunchStatus::Blocked, $fresh->status);
        $this->assertStringContainsString('KBMS_TRANSCRIPTS_PATH', $fresh->error);
    }

    /**
     * Robert's review flagged that dropping the detail for a second-pass
     * QuestionTooLong block would persist an EMPTY error string — this
     * cannot arise in practice (the question is already bounded before
     * dispatch), but the unit-level guarantee is asserted directly here.
     */
    public function test_second_pass_question_too_long_detail_is_never_dropped(): void
    {
        $detail = LaunchBlockDetail::for(LaunchBlock::QuestionTooLong, str_repeat('a', 8001), null);

        $this->assertNotSame('', LaunchBlock::QuestionTooLong->message($detail));
    }

    public function test_a_row_already_launched_is_skipped_without_running_a_process(): void
    {
        $launch = $this->queuedLaunch('a question');
        $launch->update(['status' => PromptLaunchStatus::Launched, 'exit_code' => 0, 'launched_at' => now()]);

        Process::fake();

        LaunchClaudeSession::dispatchSync($launch->id);

        Process::assertNothingRan();
    }

    public function test_tries_is_one(): void
    {
        $launch = $this->queuedLaunch('a question');

        $this->assertSame(1, (new LaunchClaudeSession($launch->id))->tries);
    }
}
