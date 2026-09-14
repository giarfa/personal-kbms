<?php

namespace Tests\Unit\Launcher;

use App\Launcher\LaunchBlock;
use Tests\TestCase;

class LaunchBlockTest extends TestCase
{
    public function test_every_case_has_its_own_distinct_non_empty_message(): void
    {
        $messages = [
            LaunchBlock::LauncherNotConfigured->message(),
            LaunchBlock::LauncherMissing->message('/opt/launch.sh'),
            LaunchBlock::LauncherNotExecutable->message('/opt/launch.sh'),
            LaunchBlock::LauncherStaleWorker->message(),
            LaunchBlock::NoTranscript->message(),
            LaunchBlock::TranscriptRejected->message(),
            LaunchBlock::TranscriptUnreadable->message('/opt/transcript.md'),
            LaunchBlock::QuestionEmpty->message(),
            LaunchBlock::QuestionTooLong->message('Question is 8001 characters; the bound is 8000.'),
        ];

        foreach ($messages as $message) {
            $this->assertNotSame('', $message);
        }

        $this->assertCount(9, array_unique($messages));
    }

    public function test_launcher_not_configured_names_its_configuration_key(): void
    {
        $this->assertStringContainsString('KBMS_CLAUDE_LAUNCHER', LaunchBlock::LauncherNotConfigured->message());
    }

    public function test_launcher_missing_names_its_configuration_key_and_the_path(): void
    {
        $message = LaunchBlock::LauncherMissing->message('/opt/launch.sh');

        $this->assertStringContainsString('KBMS_CLAUDE_LAUNCHER', $message);
        $this->assertStringContainsString('/opt/launch.sh', $message);
    }

    public function test_launcher_not_executable_names_its_action_and_the_path(): void
    {
        $message = LaunchBlock::LauncherNotExecutable->message('/opt/launch.sh');

        $this->assertStringContainsString('chmod +x', $message);
        $this->assertStringContainsString('/opt/launch.sh', $message);
    }

    public function test_launcher_stale_worker_names_the_remedy_and_leaks_no_path_or_value(): void
    {
        config(['kbms.claude_launcher' => '/opt/launch.sh']);

        $message = LaunchBlock::LauncherStaleWorker->message();

        $this->assertStringContainsString('queue:restart', $message);
        $this->assertStringNotContainsString('is not configured', $message);
        $this->assertStringNotContainsString('/opt/launch.sh', $message);
    }

    public function test_transcript_rejected_names_its_configuration_key(): void
    {
        $this->assertStringContainsString('KBMS_TRANSCRIPTS_PATH', LaunchBlock::TranscriptRejected->message());
    }

    public function test_transcript_unreadable_names_the_path(): void
    {
        $this->assertStringContainsString('/opt/transcript.md', LaunchBlock::TranscriptUnreadable->message('/opt/transcript.md'));
    }

    public function test_question_too_long_names_the_character_bound(): void
    {
        $message = LaunchBlock::QuestionTooLong->message('Question is 8001 characters; the bound is 8000.');

        $this->assertStringContainsString('8000', $message);
    }
}
