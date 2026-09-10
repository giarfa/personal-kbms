<?php

namespace App\Launcher;

/**
 * Computes the caller-supplied interpolation a LaunchBlock::message() needs
 * — the configured launcher path, the persisted transcript path, or the
 * composed over-length/null-byte sentence. Shared by the Livewire panel and
 * the job's second-pass block so neither path can silently drop it (a
 * dropped QuestionTooLong detail would persist an empty error string).
 */
final class LaunchBlockDetail
{
    public static function for(LaunchBlock $block, string $question, ?string $transcriptPath): ?string
    {
        return match ($block) {
            LaunchBlock::LauncherMissing, LaunchBlock::LauncherNotExecutable => (string) config('kbms.claude_launcher'),
            LaunchBlock::TranscriptUnreadable => $transcriptPath,
            LaunchBlock::QuestionTooLong => str_contains($question, "\0")
                ? 'A question cannot contain a null byte.'
                : 'Question is '.mb_strlen($question).' characters; the bound is '.config('kbms.question_max_chars').'.',
            default => null,
        };
    }
}
