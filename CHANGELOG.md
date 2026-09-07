# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

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
