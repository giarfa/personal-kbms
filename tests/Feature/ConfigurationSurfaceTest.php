<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigurationSurfaceTest extends TestCase
{
    public function test_every_kbms_config_key_resolves_with_its_documented_default(): void
    {
        $this->assertNull(config('kbms.ics_url'));
        $this->assertSame(15, config('kbms.sync_minutes'));
        $this->assertSame(90, config('kbms.window_past_days'));
        $this->assertSame(180, config('kbms.window_future_days'));
        $this->assertNull(config('kbms.transcripts_path'));
        $this->assertSame('{date}-{time}-{slug}', config('kbms.transcript_pattern'));
        $this->assertSame(10, config('kbms.transcript_tolerance_minutes'));
        $this->assertSame(2097152, config('kbms.transcript_preview_bytes'));
        $this->assertSame(['md', 'txt'], config('kbms.transcript_extensions'));
        $this->assertNull(config('kbms.claude_launcher'));
        $this->assertNull(config('kbms.outlook_url_template'));
        $this->assertSame('Europe/Rome', config('kbms.timezone'));
        $this->assertFalse(config('kbms.allow_non_loopback'));
        $this->assertSame(30, config('kbms.sync_run_retention_days'));
    }

    public function test_app_timezone_follows_kbms_timezone(): void
    {
        $this->assertSame(config('kbms.timezone'), config('app.timezone'));
    }

    public function test_env_example_documents_every_kbms_key(): void
    {
        $contents = file_get_contents(base_path('.env.example'));

        foreach ([
            'KBMS_ICS_URL',
            'KBMS_ICS_SYNC_MINUTES',
            'KBMS_ICS_WINDOW_PAST_DAYS',
            'KBMS_ICS_WINDOW_FUTURE_DAYS',
            'KBMS_TRANSCRIPTS_PATH',
            'KBMS_TRANSCRIPT_PATTERN',
            'KBMS_TRANSCRIPT_TOLERANCE_MINUTES',
            'KBMS_TRANSCRIPT_PREVIEW_BYTES',
            'KBMS_CLAUDE_LAUNCHER',
            'KBMS_OUTLOOK_URL_TEMPLATE',
            'KBMS_TIMEZONE',
            'KBMS_SYNC_RUN_RETENTION_DAYS',
        ] as $key) {
            $this->assertStringContainsString($key, $contents, "{$key} missing from .env.example");
        }
    }
}
