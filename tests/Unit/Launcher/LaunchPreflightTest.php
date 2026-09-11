<?php

namespace Tests\Unit\Launcher;

use App\Launcher\LaunchBlock;
use App\Launcher\LaunchCommand;
use App\Launcher\LauncherScript;
use App\Launcher\LaunchPreflight;
use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptsDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaunchPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $transcriptsDir;

    private string $launcherFile;

    protected function setUp(): void
    {
        parent::setUp();

        // sys_get_temp_dir() may itself be a symlink (e.g. macOS /tmp ->
        // /private/tmp) — resolve it first so TranscriptsDirectory's own
        // realpath() of the configured base matches this exactly.
        $this->transcriptsDir = realpath(sys_get_temp_dir()).'/kbms-transcripts-'.uniqid();
        mkdir($this->transcriptsDir, 0755);

        $this->launcherFile = sys_get_temp_dir().'/kbms-launcher-'.uniqid();
        file_put_contents($this->launcherFile, "#!/bin/sh\necho hi\n");
        chmod($this->launcherFile, 0755);

        config([
            'kbms.transcripts_path' => $this->transcriptsDir,
            'kbms.claude_launcher' => $this->launcherFile,
            'kbms.question_max_chars' => 8000,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->transcriptsDir)) {
            foreach (glob($this->transcriptsDir.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->transcriptsDir);
        }

        if (is_file($this->launcherFile)) {
            unlink($this->launcherFile);
        }

        parent::tearDown();
    }

    private function preflight(): LaunchPreflight
    {
        return new LaunchPreflight(new LauncherScript, new TranscriptsDirectory);
    }

    private function readableTranscriptPath(): string
    {
        $path = "{$this->transcriptsDir}/transcript.md";
        file_put_contents($path, '# Transcript');

        return $path;
    }

    public function test_blocked_when_launcher_is_not_configured(): void
    {
        config(['kbms.claude_launcher' => null]);
        $event = CalendarEvent::factory()->create();

        $result = $this->preflight()->for($event, 'a question');

        $this->assertSame(LaunchBlock::LauncherNotConfigured, $result);
    }

    public function test_blocked_when_launcher_file_is_missing(): void
    {
        config(['kbms.claude_launcher' => sys_get_temp_dir().'/kbms-missing-'.uniqid()]);
        $event = CalendarEvent::factory()->create();

        $result = $this->preflight()->for($event, 'a question');

        $this->assertSame(LaunchBlock::LauncherMissing, $result);
    }

    public function test_blocked_when_launcher_is_not_executable(): void
    {
        chmod($this->launcherFile, 0644);
        $event = CalendarEvent::factory()->create();

        $result = $this->preflight()->for($event, 'a question');

        $this->assertSame(LaunchBlock::LauncherNotExecutable, $result);
    }

    public function test_blocked_when_there_is_no_transcript_row(): void
    {
        $event = CalendarEvent::factory()->create();

        $result = $this->preflight()->for($event, 'a question');

        $this->assertSame(LaunchBlock::NoTranscript, $result);
    }

    public function test_blocked_when_the_transcript_is_tombstoned(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->suppressed()->create();

        $result = $this->preflight()->for($event, 'a question');

        $this->assertSame(LaunchBlock::NoTranscript, $result);
    }

    public function test_blocked_when_the_transcript_path_escapes_the_configured_directory(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => realpath(sys_get_temp_dir()).'/kbms-outside-'.uniqid().'.md',
        ]);

        $result = $this->preflight()->for($event, 'a question');

        $this->assertSame(LaunchBlock::TranscriptRejected, $result);
    }

    public function test_blocked_when_the_transcript_file_is_gone(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => "{$this->transcriptsDir}/gone.md",
        ]);

        $result = $this->preflight()->for($event, 'a question');

        $this->assertSame(LaunchBlock::TranscriptUnreadable, $result);
    }

    public function test_blocked_when_the_question_is_whitespace_only(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => $this->readableTranscriptPath(),
        ]);

        $result = $this->preflight()->for($event, "   \n\t  ");

        $this->assertSame(LaunchBlock::QuestionEmpty, $result);
    }

    public function test_blocked_when_the_question_contains_a_null_byte(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => $this->readableTranscriptPath(),
        ]);

        $result = $this->preflight()->for($event, "a question\0with a null byte");

        $this->assertSame(LaunchBlock::QuestionTooLong, $result);
    }

    public function test_blocked_when_the_question_exceeds_the_character_bound(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => $this->readableTranscriptPath(),
        ]);

        $result = $this->preflight()->for($event, str_repeat('a', 8001));

        $this->assertSame(LaunchBlock::QuestionTooLong, $result);
    }

    public function test_accepts_a_question_at_exactly_the_character_bound(): void
    {
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => $this->readableTranscriptPath(),
        ]);

        $result = $this->preflight()->for($event, str_repeat('a', 8000));

        $this->assertInstanceOf(LaunchCommand::class, $result);
    }

    public function test_returns_a_launch_command_when_every_precondition_is_satisfied(): void
    {
        $path = $this->readableTranscriptPath();
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create(['path' => $path]);

        $result = $this->preflight()->for($event, 'What did we decide?');

        $this->assertInstanceOf(LaunchCommand::class, $result);
        $this->assertSame($this->launcherFile, $result->script);
        $this->assertSame("{$this->transcriptsDir}/transcript.txt", $result->contextPath);
        $this->assertSame('What did we decide?', $result->question);
    }

    /**
     * The launcher contract requires `.txt` regardless of the transcript's
     * real extension on disk — the checks above still read the `.md` file,
     * but the argument the script receives is forced to `.txt`.
     */
    public function test_forces_the_context_path_extension_to_txt(): void
    {
        $path = "{$this->transcriptsDir}/transcript.notes.md";
        file_put_contents($path, '# Transcript');
        $event = CalendarEvent::factory()->create();
        MeetingTranscript::factory()->forOccurrence($event)->manual()->create(['path' => $path]);

        $result = $this->preflight()->for($event, 'What did we decide?');

        $this->assertInstanceOf(LaunchCommand::class, $result);
        $this->assertSame("{$this->transcriptsDir}/transcript.notes.txt", $result->contextPath);
    }

    /**
     * Locks the documented precedence: launcher resolution is checked
     * before anything about the transcript or the question, even when
     * every later precondition would also fail.
     */
    public function test_launcher_failure_takes_precedence_over_every_later_precondition(): void
    {
        config(['kbms.claude_launcher' => null]);
        $event = CalendarEvent::factory()->create();
        // No transcript row either, and an empty question — both would
        // also fail, but the launcher check must win.

        $result = $this->preflight()->for($event, '');

        $this->assertSame(LaunchBlock::LauncherNotConfigured, $result);
    }

    /**
     * Locks the documented precedence: the transcript checks run before
     * the question checks.
     */
    public function test_missing_transcript_takes_precedence_over_an_invalid_question(): void
    {
        $event = CalendarEvent::factory()->create();
        // No transcript row, and the question is also empty — the
        // transcript check must win.

        $result = $this->preflight()->for($event, '');

        $this->assertSame(LaunchBlock::NoTranscript, $result);
    }
}
