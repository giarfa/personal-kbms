<?php

namespace Database\Factories;

use App\Launcher\LaunchCommand;
use App\Launcher\PromptLaunchStatus;
use App\Models\CalendarEvent;
use App\Models\PromptLaunch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromptLaunch>
 */
class PromptLaunchFactory extends Factory
{
    protected $model = PromptLaunch::class;

    /**
     * Define the model's default state.
     *
     * `command` is built by calling `LaunchCommand::toArray()` — the single
     * construction site of the argument array — so factory data cannot
     * drift from what actually gets executed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contextPath = storage_path('app/transcripts/'.fake()->slug().'.md');
        $question = fake()->randomElement([
            'Pull out every decision and its owner, then draft the follow-up email to Chiara.',
            'Summarize the blockers raised in this meeting and who is accountable for each.',
            'What commitments did we make on timeline, and are they realistic?',
            'Draft three bullet points I can paste into the sprint retro notes.',
        ]);
        $script = config('kbms.claude_launcher') ?: '/usr/local/bin/claude-launch.sh';

        return [
            'event_uid' => (string) Str::uuid(),
            'event_recurrence_id' => '',
            'context_path' => $contextPath,
            'question' => $question,
            'command' => (new LaunchCommand($script, $contextPath, $question))->toArray(),
            'status' => PromptLaunchStatus::Queued,
            'exit_code' => null,
            'error' => null,
            'launched_at' => null,
        ];
    }

    /**
     * A launch addressed to the given occurrence's natural key.
     */
    public function forOccurrence(CalendarEvent $event): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_uid' => $event->source_uid,
            'event_recurrence_id' => $event->recurrence_id,
        ]);
    }

    /**
     * Dispatched, awaiting the job — the default state, made explicit here.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PromptLaunchStatus::Queued,
            'exit_code' => null,
            'error' => null,
            'launched_at' => null,
        ]);
    }

    /**
     * The launcher ran and exited zero.
     */
    public function launched(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PromptLaunchStatus::Launched,
            'exit_code' => 0,
            'error' => null,
            'launched_at' => now(),
        ]);
    }

    /**
     * The launcher ran and exited non-zero.
     */
    public function failed(int $exitCode = 127): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PromptLaunchStatus::Failed,
            'exit_code' => $exitCode,
            'error' => 'sh: claude-launch.sh: command not found',
            'launched_at' => now(),
        ]);
    }

    /**
     * The launcher did not return within the configured bound.
     */
    public function timedOut(): static
    {
        $seconds = config('kbms.launch_timeout_seconds');

        return $this->state(fn (array $attributes): array => [
            'status' => PromptLaunchStatus::TimedOut,
            'exit_code' => null,
            'error' => "the launcher did not return within {$seconds}s — it must exit once it has spawned its own terminal window",
            'launched_at' => now(),
        ]);
    }

    /**
     * A precondition failed before any process ran — never launched.
     */
    public function blocked(?string $reason = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PromptLaunchStatus::Blocked,
            'exit_code' => null,
            'error' => $reason ?? 'No transcript linked — there would be no context file to pass as argument one.',
            'launched_at' => null,
        ]);
    }
}
