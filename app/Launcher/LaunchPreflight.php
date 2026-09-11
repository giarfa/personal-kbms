<?php

namespace App\Launcher;

use App\Models\CalendarEvent;
use App\Models\MeetingTranscript;
use App\Transcripts\TranscriptsDirectory;

/**
 * Occurrence + question -> a ready LaunchCommand or the specific reason it
 * cannot be dispatched. Called before dispatch (Livewire) and again inside
 * the job — the launcher script, the transcript file, and
 * `KBMS_TRANSCRIPTS_PATH` are all mutable between the two calls, so neither
 * pass is trusted to still hold at the other's time.
 */
final class LaunchPreflight
{
    public function __construct(
        private readonly LauncherScript $launcherScript,
        private readonly TranscriptsDirectory $transcriptsDirectory,
    ) {}

    /**
     * Never throws, never returns null.
     */
    public function for(CalendarEvent $occurrence, string $question): LaunchCommand|LaunchBlock
    {
        $script = $this->launcherScript->resolve();

        if ($script instanceof LaunchBlock) {
            return $script;
        }

        $transcript = MeetingTranscript::query()->forOccurrence($occurrence->occurrenceKey())->first();

        // A missing row and the manual-unlink tombstone (path = NULL) both
        // mean there is no context file to pass — neither is distinguished
        // from the other here.
        if ($transcript === null || $transcript->path === null) {
            return LaunchBlock::NoTranscript;
        }

        $contextPath = $transcript->path;

        if (! $this->transcriptsDirectory->contains($contextPath)) {
            return LaunchBlock::TranscriptRejected;
        }

        if (! is_readable($contextPath)) {
            return LaunchBlock::TranscriptUnreadable;
        }

        if (trim($question) === '') {
            return LaunchBlock::QuestionEmpty;
        }

        if (str_contains($question, "\0")) {
            return LaunchBlock::QuestionTooLong;
        }

        if (mb_strlen($question) > config('kbms.question_max_chars')) {
            return LaunchBlock::QuestionTooLong;
        }

        return new LaunchCommand($script, self::withTxtExtension($contextPath), $question);
    }

    /**
     * The launcher script's contract requires a `.txt` context file
     * regardless of the transcript's actual extension on disk (`.md`
     * today) — swapped only for the argument the script receives; every
     * check above still reads the real file.
     */
    private static function withTxtExtension(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $filename = pathinfo($path, PATHINFO_FILENAME);

        return $directory.'/'.$filename.'.txt';
    }
}
