<?php

namespace App\Launcher;

/**
 * The argument array, and the only place it is constructed. `toArray()` is
 * what `ClaudeSessionLauncher` passes to `Process` — an array command, never
 * a string, so there is nothing for a shell to parse or escape.
 */
final readonly class LaunchCommand
{
    public function __construct(
        public string $script,
        public string $contextPath,
        public string $question,
    ) {}

    /**
     * The single construction site of the argument array. Exactly three
     * elements, in order: script, context file path, question.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        return [$this->script, $this->contextPath, $this->question];
    }

    /**
     * Presentation only — renders the invocation for the operator to read
     * and copy, three lines with the two arguments single-quote wrapped
     * (embedded `'` escaped as `'\''`). This is never passed to a process;
     * `toArray()` is what actually runs.
     */
    public function display(): string
    {
        return $this->script."\n"
            .'  '.self::quote($this->contextPath)."\n"
            .'  '.self::quote($this->question);
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
