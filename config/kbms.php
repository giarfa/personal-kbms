<?php

/**
 * @return array{
 *     ics_url: string|null,
 *     sync_minutes: int,
 *     window_past_days: int,
 *     window_future_days: int,
 *     transcripts_path: string|null,
 *     transcript_pattern: string,
 *     transcript_tolerance_minutes: int,
 *     transcript_preview_bytes: int,
 *     transcript_extensions: list<string>,
 *     claude_launcher: string|null,
 *     outlook_url_template: string|null,
 *     timezone: string,
 *     allow_non_loopback: bool,
 *     sync_run_retention_days: int,
 *     sync_stale_multiplier: int,
 *     sync_stuck_after_seconds: int,
 *     launch_timeout_seconds: int,
 *     question_max_chars: int,
 * }
 */
return [
    'ics_url' => env('KBMS_ICS_URL') ?: null,

    'sync_minutes' => (int) env('KBMS_ICS_SYNC_MINUTES', 15),

    'window_past_days' => (int) env('KBMS_ICS_WINDOW_PAST_DAYS', 90),

    'window_future_days' => (int) env('KBMS_ICS_WINDOW_FUTURE_DAYS', 180),

    'transcripts_path' => env('KBMS_TRANSCRIPTS_PATH') ?: null,

    // Ymd date + Hi time + underscore slug; the resolver keys off the meeting start datetime (decision journal: "transcript filename convention", superseded by da55147854b4096f).
    'transcript_pattern' => env('KBMS_TRANSCRIPT_PATTERN', '{date}_{time}_{slug}'),

    // Minutes of start-time drift the resolver tolerates either side of the occurrence's start (US-007).
    'transcript_tolerance_minutes' => (int) env('KBMS_TRANSCRIPT_TOLERANCE_MINUTES', 10),

    // Bytes read from a transcript before truncating the preview (US-007). Default: 2 MiB.
    'transcript_preview_bytes' => (int) env('KBMS_TRANSCRIPT_PREVIEW_BYTES', 2097152),

    // md-only: the operator's pipeline emits a .md + .txt pair per recording, so indexing
    // both made every meeting Ambiguous (decision journal 14f91842c55d2d7f).
    'transcript_extensions' => ['md'],

    'claude_launcher' => env('KBMS_CLAUDE_LAUNCHER') ?: null,

    'outlook_url_template' => env('KBMS_OUTLOOK_URL_TEMPLATE') ?: null,

    'timezone' => env('KBMS_TIMEZONE', 'Europe/Rome'),

    'allow_non_loopback' => (bool) env('KBMS_ALLOW_NON_LOOPBACK', false),

    'sync_run_retention_days' => (int) env('KBMS_SYNC_RUN_RETENTION_DAYS', 30),

    'sync_stale_multiplier' => (int) env('KBMS_SYNC_STALE_MULTIPLIER', 3),

    'sync_stuck_after_seconds' => (int) env('KBMS_SYNC_STUCK_AFTER_SECONDS', 300),

    // Seconds the launch job waits for the launcher script to return (US-009). The
    // script is expected to spawn its own terminal window and exit promptly; a
    // script still running after this bound is violating that contract, and the
    // job records a timed-out outcome instead of leaving the worker hanging.
    'launch_timeout_seconds' => (int) env('KBMS_LAUNCH_TIMEOUT_SECONDS', 30),

    // Maximum character length for a launch question (US-009). A product bound,
    // not a system one — macOS ARG_MAX is two orders of magnitude clear of it.
    'question_max_chars' => (int) env('KBMS_QUESTION_MAX_CHARS', 8000),
];
