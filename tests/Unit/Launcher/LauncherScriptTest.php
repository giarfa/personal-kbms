<?php

namespace Tests\Unit\Launcher;

use App\Launcher\LaunchBlock;
use App\Launcher\LauncherScript;
use Tests\TestCase;

class LauncherScriptTest extends TestCase
{
    public function test_unset_is_not_configured(): void
    {
        config(['kbms.claude_launcher' => null]);

        $this->assertSame(LaunchBlock::LauncherNotConfigured, (new LauncherScript)->resolve());
    }

    public function test_missing_file_is_reported(): void
    {
        config(['kbms.claude_launcher' => sys_get_temp_dir().'/kbms-missing-'.uniqid()]);

        $this->assertSame(LaunchBlock::LauncherMissing, (new LauncherScript)->resolve());
    }

    public function test_non_executable_file_is_reported(): void
    {
        $path = sys_get_temp_dir().'/kbms-launcher-'.uniqid();
        file_put_contents($path, "#!/bin/sh\necho hi\n");
        chmod($path, 0644);
        config(['kbms.claude_launcher' => $path]);

        $this->assertSame(LaunchBlock::LauncherNotExecutable, (new LauncherScript)->resolve());

        unlink($path);
    }

    public function test_executable_file_resolves_to_its_path(): void
    {
        $path = sys_get_temp_dir().'/kbms-launcher-'.uniqid();
        file_put_contents($path, "#!/bin/sh\necho hi\n");
        chmod($path, 0755);
        config(['kbms.claude_launcher' => $path]);

        $this->assertSame($path, (new LauncherScript)->resolve());

        unlink($path);
    }
}
