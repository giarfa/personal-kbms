<?php

namespace App\Livewire;

use App\Jobs\LaunchClaudeSession;
use App\Launcher\LaunchBlock;
use App\Launcher\LaunchCommand;
use App\Launcher\LaunchPreflight;
use App\Launcher\PromptLaunchStatus;
use App\Meetings\OccurrenceKey;
use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Models\PromptLaunch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The meeting-detail launch bridge panel: shows launch readiness, the exact
 * invocation, and the latest launch's outcome.
 *
 * Stores only the occurrence's natural key, never the model — the same
 * discipline TranscriptPanel documents. render() performs no writes; the
 * only write path is launch().
 */
class AskClaude extends Component
{
    #[Locked]
    public string $eventUid = '';

    #[Locked]
    public string $eventRecurrenceId = '';

    public string $question = '';

    public function mount(CalendarEvent $occurrence): void
    {
        $this->eventUid = $occurrence->source_uid;
        $this->eventRecurrenceId = $occurrence->recurrence_id;
    }

    /**
     * Validates the question, re-runs preflight, writes the queued row,
     * and dispatches the job. Nothing is dispatched when either step
     * refuses.
     */
    public function launch(): void
    {
        $this->validate([
            'question' => ['required', 'max:'.config('kbms.question_max_chars')],
        ], [
            // Laravel's `required` already treats a whitespace-only string as
            // empty (trim($value) === '') — the mockup's exact wording for that.
            'question.required' => 'Type a question first — whitespace only is rejected before anything is dispatched.',
        ]);

        $occurrence = $this->currentEvent();

        if ($occurrence === null) {
            return;
        }

        $result = app(LaunchPreflight::class)->for($occurrence, $this->question);

        if ($result instanceof LaunchBlock) {
            return;
        }

        $launch = PromptLaunch::query()->create([
            'event_uid' => $this->eventUid,
            'event_recurrence_id' => $this->eventRecurrenceId,
            'context_path' => $result->contextPath,
            'question' => $result->question,
            'command' => $result->toArray(),
            'status' => PromptLaunchStatus::Queued,
        ]);

        LaunchClaudeSession::dispatch($launch->id);

        $this->question = '';
    }

    public function render(): View
    {
        $occurrence = $this->currentEvent();
        $transcript = MeetingTranscript::query()->forOccurrence($this->occurrenceKey())->first();

        $result = $occurrence !== null
            ? app(LaunchPreflight::class)->for($occurrence, $this->question)
            : LaunchBlock::NoTranscript;

        $latest = PromptLaunch::query()->forOccurrence($this->occurrenceKey())->latestFirst()->first();

        $isQuestionBlock = $result instanceof LaunchBlock
            && in_array($result, [LaunchBlock::QuestionEmpty, LaunchBlock::QuestionTooLong], true);

        $command = $result instanceof LaunchCommand ? $result : null;

        return view('livewire.ask-claude', [
            'command' => $command,
            // display()'s three lines, split so the view can wrap the two
            // arguments in colour spans without re-deriving the quoting —
            // the panel and the job stay derived from the same object.
            'commandLines' => $command !== null ? explode("\n", $command->display()) : null,
            'blockMessage' => $result instanceof LaunchBlock ? $result->message($this->blockDetail($result, $transcript)) : null,
            'isQuestionBlock' => $isQuestionBlock,
            'latest' => $latest,
            'stillQueued' => $latest !== null
                && $latest->status === PromptLaunchStatus::Queued
                && $latest->created_at !== null
                && $latest->created_at->lt(now()->subSeconds(2 * (int) config('kbms.launch_timeout_seconds'))),
        ]);
    }

    /**
     * The interpolation each blocked case needs to name its configuration
     * key, path, or bound — computed here rather than inside LaunchBlock,
     * which is stateless.
     */
    private function blockDetail(LaunchBlock $block, ?MeetingTranscript $transcript): ?string
    {
        return match ($block) {
            LaunchBlock::LauncherMissing, LaunchBlock::LauncherNotExecutable => (string) config('kbms.claude_launcher'),
            LaunchBlock::TranscriptUnreadable => $transcript?->path,
            LaunchBlock::QuestionTooLong => str_contains($this->question, "\0")
                ? 'A question cannot contain a null byte.'
                : 'Question is '.mb_strlen($this->question).' characters; the bound is '.config('kbms.question_max_chars').'.',
            default => null,
        };
    }

    private function currentEvent(): ?CalendarEvent
    {
        return CalendarEvent::query()->forOccurrence($this->occurrenceKey())->first();
    }

    private function occurrenceKey(): OccurrenceKey
    {
        return new OccurrenceKey($this->eventUid, $this->eventRecurrenceId);
    }
}
