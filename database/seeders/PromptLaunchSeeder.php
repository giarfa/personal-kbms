<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\PromptLaunch;
use App\Transcripts\TranscriptPattern;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Three launches across two meetings that MeetingTranscriptSeeder already
 * gave a real linked transcript file — an orphan launch (one pointing at a
 * meeting with no context file) is not a state the application can
 * produce, so the demo data must not invent one. Runs after
 * MeetingTranscriptSeeder so the files these context paths point at
 * already exist on disk.
 */
class PromptLaunchSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedClientKickoffLaunches();
        $this->seedArchitectureDiscussionLaunch();
    }

    /**
     * Client kickoff resolves by convention (MeetingTranscriptSeeder::seedExactMatch) —
     * the stem is recomputed the same way so this points at the same file.
     */
    private function seedClientKickoffLaunches(): void
    {
        $event = CalendarEvent::query()->where('summary', 'Client kickoff')->first();

        if ($event === null) {
            return;
        }

        $pattern = TranscriptPattern::compile();
        $stem = $pattern->render(Carbon::parse($event->starts_at), $event->summary);
        $contextPath = storage_path("app/transcripts/{$stem}.md");
        $script = config('kbms.claude_launcher') ?: '/usr/local/bin/claude-launch.sh';

        $question = 'Pull out every decision and its owner, then draft the follow-up email to Chiara.';
        PromptLaunch::factory()->forOccurrence($event)->launched()->create([
            'context_path' => $contextPath,
            'question' => $question,
            'command' => [$script, $contextPath, $question],
        ]);

        $question = 'What did we agree on the ingestion timeline, and who owns the recurrence expansion fix?';
        PromptLaunch::factory()->forOccurrence($event)->failed(127)->create([
            'context_path' => $contextPath,
            'question' => $question,
            'command' => [$script, $contextPath, $question],
        ]);
    }

    /**
     * Architecture discussion has a manual link
     * (MeetingTranscriptSeeder::seedManualLink) at a fixed, hardcoded path.
     */
    private function seedArchitectureDiscussionLaunch(): void
    {
        $event = CalendarEvent::query()->where('summary', 'Architecture discussion')->first();

        if ($event === null) {
            return;
        }

        $contextPath = storage_path('app/transcripts/voice-memo-042-unsorted.md');
        $script = config('kbms.claude_launcher') ?: '/usr/local/bin/claude-launch.sh';
        $question = 'Summarize the concerns raised about the tenancy pattern before Friday\'s decision.';

        PromptLaunch::factory()->forOccurrence($event)->queued()->create([
            'context_path' => $contextPath,
            'question' => $question,
            'command' => [$script, $contextPath, $question],
        ]);
    }
}
