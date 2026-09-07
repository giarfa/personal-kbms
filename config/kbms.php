<?php

/**
 * @return array{
 *     ics_url: string|null,
 *     sync_minutes: int,
 *     window_past_days: int,
 *     window_future_days: int,
 *     transcripts_path: string|null,
 *     transcript_pattern: string,
 *     claude_launcher: string|null,
 *     outlook_url_template: string|null,
 *     timezone: string,
 *     allow_non_loopback: bool,
 * }
 */
return [
    'ics_url' => env('KBMS_ICS_URL'),

    'sync_minutes' => (int) env('KBMS_ICS_SYNC_MINUTES', 15),

    'window_past_days' => (int) env('KBMS_ICS_WINDOW_PAST_DAYS', 90),

    'window_future_days' => (int) env('KBMS_ICS_WINDOW_FUTURE_DAYS', 180),

    'transcripts_path' => env('KBMS_TRANSCRIPTS_PATH'),

    // Date + time prefix; the resolver keys off the meeting start datetime (decision journal: "transcript filename convention").
    'transcript_pattern' => env('KBMS_TRANSCRIPT_PATTERN', '{date}-{time}-{slug}'),

    'claude_launcher' => env('KBMS_CLAUDE_LAUNCHER'),

    'outlook_url_template' => env('KBMS_OUTLOOK_URL_TEMPLATE'),

    'timezone' => env('KBMS_TIMEZONE', 'Europe/Rome'),

    'allow_non_loopback' => (bool) env('KBMS_ALLOW_NON_LOOPBACK', false),
];
