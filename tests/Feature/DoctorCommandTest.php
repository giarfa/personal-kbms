<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    private const VALID_ICS = <<<'ICS'
        BEGIN:VCALENDAR
        VERSION:2.0
        BEGIN:VEVENT
        SUMMARY:Standup
        END:VEVENT
        END:VCALENDAR
        ICS;

    private string $transcriptsDir;

    private string $launcherFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transcriptsDir = sys_get_temp_dir().'/kbms-transcripts-'.uniqid();
        mkdir($this->transcriptsDir, 0755);

        $this->launcherFile = sys_get_temp_dir().'/kbms-launcher-'.uniqid();
        file_put_contents($this->launcherFile, "#!/bin/sh\necho hi\n");
        chmod($this->launcherFile, 0755);

        config([
            'kbms.ics_url' => 'https://example.test/feed.ics',
            'kbms.transcripts_path' => $this->transcriptsDir,
            'kbms.claude_launcher' => $this->launcherFile,
            'kbms.timezone' => 'Europe/Rome',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->transcriptsDir)) {
            rmdir($this->transcriptsDir);
        }

        if (is_file($this->launcherFile)) {
            unlink($this->launcherFile);
        }

        parent::tearDown();
    }

    /**
     * Only one Http::fake('*' => ...) rule wins per test — the first registered
     * stub matches first, so tests that need a healthy ICS feed alongside another
     * broken check must call this instead of stacking a second Http::fake call.
     */
    private function fakeHealthyIcsFeed(): void
    {
        Http::fake(['*' => Http::response(self::VALID_ICS, 200)]);
    }

    /**
     * Runs the command via the real Artisan buffer rather than
     * PendingCommand::expectsOutputToContain(), which matches each
     * individual console write in isolation and can miss a substring
     * split across a wrapped line.
     *
     * @return array{0: int, 1: string}
     */
    private function runDoctor(): array
    {
        $exitCode = Artisan::call('kbms:doctor');

        return [$exitCode, Artisan::output()];
    }

    public function test_it_passes_every_check_and_exits_zero_when_the_environment_is_healthy(): void
    {
        $this->fakeHealthyIcsFeed();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(0, $exitCode);
        foreach (['ICS calendar feed', 'Transcripts directory', 'Claude launcher script', 'Queue connection', 'Calendar sync schedule', 'Timezone'] as $label) {
            $this->assertStringContainsString($label, $output);
        }
    }

    public function test_it_fails_when_the_ics_feed_is_unreachable(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('KBMS_ICS_URL', $output);
    }

    public function test_it_fails_when_the_ics_feed_returns_a_200_html_body(): void
    {
        Http::fake(['*' => Http::response('<html><body>Sign in</body></html>', 200)]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('re-copy the subscription URL', $output);
    }

    public function test_it_reports_not_configured_when_the_ics_url_is_unset(): void
    {
        config(['kbms.ics_url' => null]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('NOT CONFIGURED', $output);
    }

    public function test_it_fails_when_the_transcripts_directory_is_missing(): void
    {
        $this->fakeHealthyIcsFeed();
        config(['kbms.transcripts_path' => sys_get_temp_dir().'/kbms-missing-'.uniqid()]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('KBMS_TRANSCRIPTS_PATH', $output);
    }

    public function test_it_fails_when_the_launcher_lacks_the_executable_bit(): void
    {
        $this->fakeHealthyIcsFeed();
        chmod($this->launcherFile, 0644);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not executable', $output);
        $this->assertStringContainsString('KBMS_CLAUDE_LAUNCHER', $output);
    }

    public function test_it_fails_when_the_launcher_file_is_missing(): void
    {
        $this->fakeHealthyIcsFeed();
        config(['kbms.claude_launcher' => sys_get_temp_dir().'/kbms-missing-launcher-'.uniqid()]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('KBMS_CLAUDE_LAUNCHER', $output);
    }

    public function test_it_fails_when_the_timezone_is_invalid(): void
    {
        $this->fakeHealthyIcsFeed();
        config(['kbms.timezone' => 'Not/AZone']);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('KBMS_TIMEZONE', $output);
    }

    public function test_it_fails_when_the_calendar_sync_schedule_entry_is_missing(): void
    {
        $this->fakeHealthyIcsFeed();
        $this->app->instance(Schedule::class, new Schedule);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('routes/console.php', $output);
    }

    public function test_two_simultaneous_failures_are_both_reported(): void
    {
        $this->fakeHealthyIcsFeed();
        config(['kbms.timezone' => 'Not/AZone']);
        config(['kbms.transcripts_path' => sys_get_temp_dir().'/kbms-missing-'.uniqid()]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('KBMS_TIMEZONE', $output);
        $this->assertStringContainsString('KBMS_TRANSCRIPTS_PATH', $output);
    }

    public function test_not_configured_and_fail_render_distinctly(): void
    {
        config(['kbms.ics_url' => null]);
        config(['kbms.timezone' => 'Not/AZone']);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('NOT CONFIGURED', $output);
        $this->assertStringContainsString('FAIL', $output);
    }
}
