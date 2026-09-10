<?php

namespace App\Launcher;

final readonly class LaunchOutcome
{
    public function __construct(
        public PromptLaunchStatus $status,
        public ?int $exitCode = null,
        public ?string $error = null,
    ) {}

    public static function launched(int $exitCode): self
    {
        return new self(PromptLaunchStatus::Launched, $exitCode);
    }

    public static function failed(int $exitCode, string $error): self
    {
        return new self(PromptLaunchStatus::Failed, $exitCode, $error);
    }

    public static function timedOut(string $error): self
    {
        return new self(PromptLaunchStatus::TimedOut, null, $error);
    }
}
