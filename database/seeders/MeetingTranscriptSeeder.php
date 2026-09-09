<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptLinkSource;
use App\Transcripts\TranscriptPattern;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Writes real sample files into storage/app/transcripts/ (gitignored;
 * dev-only) so every panel state is demonstrable after a plain
 * `migrate:fresh --seed`. Static fixture files cannot work here — the
 * seeded calendar is relative to today, so filenames matching the
 * convention have to be generated. Filenames are rendered through
 * TranscriptPattern — the same compiler the resolver uses — so demo data
 * cannot drift from the matcher.
 *
 * Most cases need no `meeting_transcripts` row at all: an unambiguous
 * convention match resolves dynamically (both on the agenda's coverage
 * lookup and on first visit to the meeting page, which persists the row).
 * A row is written directly only where the state cannot arise from files
 * alone — a manual link, and a broken link whose file the seeder
 * deliberately does not create.
 */
class MeetingTranscriptSeeder extends Seeder
{
    public function run(): void
    {
        $directory = storage_path('app/transcripts');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $pattern = TranscriptPattern::compile();

        $this->seedExactMatch($directory, $pattern);
        $this->seedWeeklySyncDrift($directory, $pattern);
        $this->seedAmbiguousDesignReview($directory, $pattern);
        $this->seedTextTranscript($directory, $pattern);
        $this->seedManualLink($directory);
        $this->seedBrokenLink();
    }

    /**
     * Client kickoff: a single file at the exact start minute — the plain
     * single-candidate auto-link path.
     */
    private function seedExactMatch(string $directory, TranscriptPattern $pattern): void
    {
        $event = CalendarEvent::query()->where('summary', 'Client kickoff')->first();

        if ($event === null) {
            return;
        }

        $stem = $pattern->render(Carbon::parse($event->starts_at), $event->summary);

        file_put_contents("{$directory}/{$stem}.md", <<<'MD'
            # Client kickoff — transcript

            **09:31 — Chiara:** Let's start with the ingestion workstream. Where are we against the plan?

            **09:32 — Marco:** Two weeks behind. The recurrence expansion turned out to be the hard part — a single occurrence of a weekly series has to be addressable on its own, and the first cut flattened them.

            **09:34 — Chiara:** Does that block the hiring plan discussion?

            **09:34 — Marco:** No. Independent.

            **09:36 — Sara:** One thing that bit us: transcripts land with a date-and-time prefix, but the recording rarely starts on the exact calendar minute. Anything matching on an exact timestamp will miss.

            **09:38 — Chiara:** Noted. So we need tolerance in the matcher, and a manual override when it's ambiguous.

            **09:47 — Chiara:** Hiring plan: I'll take sign-off by Friday.

            **10:26 — Chiara:** Recap: ingestion slips two weeks, Q1 scope held, hiring sign-off Friday. Thanks everyone.
            MD);
    }

    /**
     * Exactly one occurrence of the Weekly sync series gets a −3 minute
     * drift match, and only that one — sibling isolation must be visible
     * in the running app, not merely green in the suite.
     */
    private function seedWeeklySyncDrift(string $directory, TranscriptPattern $pattern): void
    {
        $occurrence = CalendarEvent::query()
            ->where('summary', 'like', 'Weekly sync%')
            ->get()
            ->sortBy(fn (CalendarEvent $event): int => (int) round(abs($event->starts_at->diffInSeconds(now(), true))))
            ->first();

        if ($occurrence === null) {
            return;
        }

        $driftedStart = Carbon::parse($occurrence->starts_at)->subMinutes(3);
        $stem = $pattern->render($driftedStart, $occurrence->summary);

        file_put_contents("{$directory}/{$stem}.md", <<<'MD'
            # Weekly sync — transcript

            **Started a few minutes early.** Marco was already dialled in.

            **Marco:** Sync health looks steady this week, three green runs in a row.

            **Chiara:** Good. Keep Q1 scope held until that's four.
            MD);
    }

    /**
     * Two files within tolerance for Design review, so the ambiguity
     * chooser is on screen without any manual setup.
     */
    private function seedAmbiguousDesignReview(string $directory, TranscriptPattern $pattern): void
    {
        $event = CalendarEvent::query()->where('summary', 'Design review')->first();

        if ($event === null) {
            return;
        }

        $start = Carbon::parse($event->starts_at);

        $exactStem = $pattern->render($start, $event->summary);
        file_put_contents("{$directory}/{$exactStem}.md", <<<'MD'
            # Design review — transcript (candidate A)

            **Luca:** Let's walk through the component states doc first.
            MD);

        $driftStem = $pattern->render($start->clone()->addMinutes(4), 'design sync recording');
        file_put_contents("{$directory}/{$driftStem}.md", <<<'MD'
            # Untitled recording (candidate B)

            **Unknown speaker:** ...still setting up screen share...
            MD);
    }

    /**
     * Sprint planning gets a .txt file, so the plain-text rendering mode
     * (kb-scroll--mono, escaped rather than converted) is demonstrable too.
     */
    private function seedTextTranscript(string $directory, TranscriptPattern $pattern): void
    {
        $event = CalendarEvent::query()->where('summary', 'Sprint planning')->first();

        if ($event === null) {
            return;
        }

        $stem = $pattern->render(Carbon::parse($event->starts_at), $event->summary);

        file_put_contents("{$directory}/{$stem}.txt", <<<'TXT'
            Sprint planning -- raw transcript export

            [11:00] Chiara: Capacity this sprint is lighter, two people out.
            [11:04] Marco: Let's pull the transcript linking spec to the top.
            [11:11] Chiara: Agreed. Everything else slides one sprint.
            TXT);
    }

    /**
     * A manual link on Architecture discussion, pointing at a file whose
     * name matches no pattern at all — proving manual precedence over the
     * convention rather than a lucky convention match.
     */
    private function seedManualLink(string $directory): void
    {
        $event = CalendarEvent::query()->where('summary', 'Architecture discussion')->first();

        if ($event === null) {
            return;
        }

        $path = "{$directory}/voice-memo-042-unsorted.md";

        file_put_contents($path, <<<'MD'
            # Architecture discussion — recovered from an unsorted voice memo

            No date-and-time prefix on this one; the operator found it manually.

            **Chiara:** Let's settle on the tenancy pattern before Friday.
            MD);

        MeetingTranscript::factory()->forOccurrence($event)->manual()->create([
            'path' => $path,
            'file_size' => filesize($path),
            'file_mtime' => now(),
            'linked_at' => now(),
        ]);
    }

    /**
     * A broken link on Retro — a persisted convention row whose file the
     * seeder deliberately never creates, so the link-is-kept behaviour is
     * visible without editing the database by hand.
     */
    private function seedBrokenLink(): void
    {
        $event = CalendarEvent::query()->where('summary', 'Retro')->first();

        if ($event === null) {
            return;
        }

        MeetingTranscript::factory()->forOccurrence($event)->broken()->create([
            'link_source' => TranscriptLinkSource::Convention,
            'file_size' => 4096,
            'file_mtime' => now()->subDays(3),
            'linked_at' => now()->subDays(3),
        ]);
    }
}
