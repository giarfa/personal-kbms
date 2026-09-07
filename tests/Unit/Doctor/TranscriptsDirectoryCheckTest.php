<?php

namespace Tests\Unit\Doctor;

use App\Doctor\Checks\TranscriptsDirectoryCheck;
use App\Doctor\CheckStatus;
use Tests\TestCase;

class TranscriptsDirectoryCheckTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            chmod($this->tempDir, 0755);
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_it_reports_not_configured_when_the_path_is_unset(): void
    {
        config(['kbms.transcripts_path' => null]);

        $result = (new TranscriptsDirectoryCheck)->run();

        $this->assertSame(CheckStatus::NotConfigured, $result->status);
        $this->assertStringContainsString('KBMS_TRANSCRIPTS_PATH', $result->remediation);
    }

    public function test_it_fails_when_the_path_does_not_exist(): void
    {
        config(['kbms.transcripts_path' => sys_get_temp_dir().'/kbms-missing-'.uniqid()]);

        $result = (new TranscriptsDirectoryCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('KBMS_TRANSCRIPTS_PATH', $result->remediation);
    }

    public function test_it_fails_when_the_directory_is_not_readable(): void
    {
        $this->tempDir = sys_get_temp_dir().'/kbms-transcripts-'.uniqid();
        mkdir($this->tempDir, 0000);

        config(['kbms.transcripts_path' => $this->tempDir]);

        $result = (new TranscriptsDirectoryCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('KBMS_TRANSCRIPTS_PATH', $result->remediation);
    }

    public function test_it_passes_when_the_directory_exists_and_is_readable(): void
    {
        $this->tempDir = sys_get_temp_dir().'/kbms-transcripts-'.uniqid();
        mkdir($this->tempDir, 0755);

        config(['kbms.transcripts_path' => $this->tempDir]);

        $result = (new TranscriptsDirectoryCheck)->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString($this->tempDir, $result->detail);
    }
}
