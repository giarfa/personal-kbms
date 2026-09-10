<?php

namespace Tests\Unit\Launcher;

use App\Launcher\LaunchCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaunchCommandTest extends TestCase
{
    use AdversarialQuestions;

    #[DataProvider('adversarialQuestions')]
    public function test_to_array_preserves_the_question_byte_for_byte(string $question): void
    {
        $command = new LaunchCommand('/usr/local/bin/claude-launch.sh', '/path/to/context.md', $question);

        $array = $command->toArray();

        $this->assertCount(3, $array);
        $this->assertSame('/usr/local/bin/claude-launch.sh', $array[0]);
        $this->assertSame('/path/to/context.md', $array[1]);
        $this->assertSame($question, $array[2]);
    }

    public function test_display_renders_three_lines(): void
    {
        $command = new LaunchCommand('/usr/local/bin/claude-launch.sh', '/path/to/context.md', 'a question');

        $lines = explode("\n", $command->display());

        $this->assertCount(3, $lines);
        $this->assertSame('/usr/local/bin/claude-launch.sh', $lines[0]);
    }

    public function test_display_round_trips_embedded_single_quotes(): void
    {
        $question = "it's a 'test' value";
        $command = new LaunchCommand('/usr/local/bin/claude-launch.sh', '/path/to/context.md', $question);

        $lines = explode("\n", $command->display());
        $questionLine = trim($lines[2]);

        $inner = substr($questionLine, 1, -1);
        $recovered = str_replace("'\\''", "'", $inner);

        $this->assertSame($question, $recovered);
    }
}
