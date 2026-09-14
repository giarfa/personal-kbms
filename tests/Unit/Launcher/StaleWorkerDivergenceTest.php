<?php

namespace Tests\Unit\Launcher;

use App\Launcher\LaunchBlock;
use App\Launcher\StaleWorkerDivergence;
use App\Models\PromptLaunch;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaleWorkerDivergenceTest extends TestCase
{
    /**
     * @return array<string, array{LaunchBlock}>
     */
    public static function launcherBlocks(): array
    {
        return [
            'not configured' => [LaunchBlock::LauncherNotConfigured],
            'missing' => [LaunchBlock::LauncherMissing],
            'not executable' => [LaunchBlock::LauncherNotExecutable],
        ];
    }

    #[DataProvider('launcherBlocks')]
    public function test_it_substitutes_the_stale_worker_block_when_the_worker_disagrees_with_the_dispatching_request(LaunchBlock $block): void
    {
        config(['kbms.claude_launcher' => null]);
        $launch = PromptLaunch::factory()->make(['command' => ['/opt/launch.sh', '/tmp/context.txt', 'a question']]);

        $this->assertSame(LaunchBlock::LauncherStaleWorker, StaleWorkerDivergence::resolve($block, $launch));
    }

    #[DataProvider('launcherBlocks')]
    public function test_it_leaves_the_block_untouched_when_the_worker_agrees_with_the_dispatching_request(LaunchBlock $block): void
    {
        config(['kbms.claude_launcher' => '/opt/launch.sh']);
        $launch = PromptLaunch::factory()->make(['command' => ['/opt/launch.sh', '/tmp/context.txt', 'a question']]);

        $this->assertSame($block, StaleWorkerDivergence::resolve($block, $launch));
    }

    public function test_it_leaves_a_non_launcher_block_untouched(): void
    {
        config(['kbms.claude_launcher' => null]);
        $launch = PromptLaunch::factory()->make(['command' => ['/opt/launch.sh', '/tmp/context.txt', 'a question']]);

        $this->assertSame(LaunchBlock::TranscriptUnreadable, StaleWorkerDivergence::resolve(LaunchBlock::TranscriptUnreadable, $launch));
        $this->assertSame(LaunchBlock::QuestionEmpty, StaleWorkerDivergence::resolve(LaunchBlock::QuestionEmpty, $launch));
    }

    public function test_it_leaves_the_block_untouched_when_the_launch_recorded_no_command(): void
    {
        // The dispatching request itself never had a launcher configured, so
        // its own resolved command never carried one either — this is the
        // genuinely-unset case, not a divergence.
        config(['kbms.claude_launcher' => null]);
        $launch = PromptLaunch::factory()->make(['command' => []]);

        $this->assertSame(LaunchBlock::LauncherNotConfigured, StaleWorkerDivergence::resolve(LaunchBlock::LauncherNotConfigured, $launch));
    }
}
