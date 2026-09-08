# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Agenda: a chronological, keyboard-navigable triage view of mirrored meetings defaulting to today's 7-day window, with forward/back range navigation and a date jump. Day groups are always rendered, including empty ones, so a genuinely quiet day reads as trustworthy rather than unsynced. Multi-day events — all-day and timed alike — render once, clamped to their first visible day and labelled with the days they span; overlapping timed events both render, correctly ordered, with an event still in progress at midnight sorted ahead of the meetings that start that morning. A bookmarked range URL carrying an unreadable date falls back to today instead of erroring.
- Meeting detail page addressed by a canonical occurrence route (`source_uid` + `recurrence_id`, never a surrogate id a resync can change), showing every mirrored feed field read-only in a surface visually distinct from the operator-owned column.
- "Open in Outlook" call-to-action: prefers a feed-carried `event_url`, falls back to the configurable `KBMS_OUTLOOK_URL_TEMPLATE` (placeholders `{date}`, `{time}`, `{datetime}`), and disables itself with a visible reason when neither is available — never a broken link. A Teams join link, when present, is offered as a distinct action.
- Sync health in the app shell: a status pill on every page shows the last successful sync in relative and absolute form, flags a **stale** mirror after `KBMS_SYNC_STALE_MULTIPLIER` × `KBMS_ICS_SYNC_MINUTES` without a success even when nothing has failed, and reads **never synced** when no run exists. A failure banner prints the stored error and names `KBMS_ICS_URL`, so a broken feed never requires opening `storage/logs`.
- Manual resync from the sync pill — dispatches `SyncCalendarFeed` without a duplicate or overlapping run (`ShouldBeUnique` plus an in-flight guard), and abandons a run whose worker died as failed after `KBMS_SYNC_STUCK_AFTER_SECONDS` instead of leaving it spinning forever.
- `KBMS_SYNC_STALE_MULTIPLIER` (default `3`) and `KBMS_SYNC_STUCK_AFTER_SECONDS` (default `300`) configuration variables.
- Scheduled ICS calendar ingestion (`kbms:sync-calendar`): a queued job fetches the subscribed feed with conditional GET, expands recurring series into individually addressable occurrences (`calendar_events`), and upserts them without ever deleting a row — disappearances and `STATUS:CANCELLED` both mark `cancelled_at` instead. Every run is recorded in `calendar_sync_runs`, pruned after `KBMS_SYNC_RUN_RETENTION_DAYS`.
- `php artisan kbms:doctor` — verifies the ICS feed, transcripts directory, launcher script, queue connection, scheduler registration and timezone, with a remediation hint per failure and a non-zero exit code.
- Application scaffold: Livewire + Flux starter kit, KBMS app shell with theme tokens and Workspace/Later navigation
- SQLite database, `database` queue driver, and a scheduled `kbms:sync-calendar` stub
- Full `KBMS_*` configuration surface with documented defaults
- Loopback-only access control with a configurable override
- Actionable error page for a missing or unreadable SQLite database file

### Changed

- `kbms:doctor`'s ICS feed check now parses the body with `sabre/vobject` instead of string-matching the envelope. A feed that parses cleanly with zero events now **passes** — it previously failed, since string-matching had no way to distinguish an empty-but-valid calendar from a wrong subscription URL.

### Removed

- Starter-kit authentication surface (Fortify, passkeys, 2FA, `User` model, login/register/settings views) — this product has no accounts by design
