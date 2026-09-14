<?php

namespace App\Launcher;

/**
 * Every reason a launch can be refused, each with its own distinct
 * sentence — `states.html`: "Every error and blocked state names the
 * configuration key or the action that fixes it." Never collapsed into one
 * generic "blocked" message.
 */
enum LaunchBlock
{
    case LauncherNotConfigured;
    case LauncherMissing;
    case LauncherNotExecutable;
    case LauncherStaleWorker;
    case NoTranscript;
    case TranscriptRejected;
    case TranscriptUnreadable;
    case TranscriptSiblingUnreadable;
    case QuestionEmpty;
    case QuestionTooLong;

    /**
     * `$detail` carries case-specific, caller-supplied interpolation:
     * the configured launcher path for `LauncherMissing` /
     * `LauncherNotExecutable`, the persisted transcript path for
     * `TranscriptUnreadable`, the expected `.txt` sibling path for
     * `TranscriptSiblingUnreadable`, and the fully composed sentence for
     * `QuestionTooLong` (it has two distinct wordings — over-length vs.
     * null byte — decided by the caller, not by this enum).
     */
    public function message(?string $detail = null): string
    {
        return match ($this) {
            self::LauncherNotConfigured => '`KBMS_CLAUDE_LAUNCHER` is not configured.',
            self::LauncherMissing => "No launcher script at \"{$detail}\" — check `KBMS_CLAUDE_LAUNCHER`.",
            self::LauncherNotExecutable => "Script found but not executable: `chmod +x {$detail}`",
            self::LauncherStaleWorker => 'This launch was offered with a launcher script configured, but the queue worker that ran it resolved a different configuration — it is running the `.env` it booted with. Run `php artisan queue:restart`.',
            self::NoTranscript => 'No transcript linked — there would be no context file to pass as argument one.',
            self::TranscriptRejected => 'The linked path escapes `KBMS_TRANSCRIPTS_PATH` and is refused outright.',
            self::TranscriptUnreadable => "The linked transcript is no longer readable at \"{$detail}\".",
            self::TranscriptSiblingUnreadable => "The launcher's required `.txt` context file is missing or unreadable at \"{$detail}\" — the recording pipeline must write it alongside the linked transcript before this meeting can launch.",
            self::QuestionEmpty => 'Type a question first — whitespace only is rejected before anything is dispatched.',
            self::QuestionTooLong => (string) $detail,
        };
    }
}
