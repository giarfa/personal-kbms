<?php

namespace Tests\Unit\Doctor;

use App\Doctor\Checks\LauncherScriptCheck;
use App\Doctor\CheckStatus;
use Tests\TestCase;

class LauncherScriptCheckTest extends TestCase
{
    private ?string $tempFile = null;

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        parent::tearDown();
    }

    public function test_it_reports_not_configured_when_the_path_is_unset(): void
    {
        config(['kbms.claude_launcher' => null]);

        $result = (new LauncherScriptCheck)->run();

        $this->assertSame(CheckStatus::NotConfigured, $result->status);
        $this->assertStringContainsString('KBMS_CLAUDE_LAUNCHER', $result->remediation);
    }

    public function test_it_fails_when_no_file_exists_at_the_configured_path(): void
    {
        config(['kbms.claude_launcher' => sys_get_temp_dir().'/kbms-missing-'.uniqid()]);

        $result = (new LauncherScriptCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('KBMS_CLAUDE_LAUNCHER', $result->remediation);
    }

    public function test_it_fails_with_a_specific_reason_when_the_file_exists_but_is_not_executable(): void
    {
        $this->tempFile = sys_get_temp_dir().'/kbms-launcher-'.uniqid();
        file_put_contents($this->tempFile, "#!/bin/sh\necho hi\n");
        chmod($this->tempFile, 0644);

        config(['kbms.claude_launcher' => $this->tempFile]);

        $result = (new LauncherScriptCheck)->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('not executable', $result->remediation);
        $this->assertStringContainsString('chmod +x', $result->remediation);
    }

    public function test_it_passes_when_the_file_exists_and_is_executable(): void
    {
        $this->tempFile = sys_get_temp_dir().'/kbms-launcher-'.uniqid();
        file_put_contents($this->tempFile, "#!/bin/sh\necho hi\n");
        chmod($this->tempFile, 0755);

        config(['kbms.claude_launcher' => $this->tempFile]);

        $result = (new LauncherScriptCheck)->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString($this->tempFile, $result->detail);
    }
}
