# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `php artisan kbms:doctor` — verifies the ICS feed, transcripts directory, launcher script, queue connection, scheduler registration and timezone, with a remediation hint per failure and a non-zero exit code.
- Application scaffold: Livewire + Flux starter kit, KBMS app shell with theme tokens and Workspace/Later navigation
- SQLite database, `database` queue driver, and a scheduled `kbms:sync-calendar` stub
- Full `KBMS_*` configuration surface with documented defaults
- Loopback-only access control with a configurable override
- Actionable error page for a missing or unreadable SQLite database file

### Removed

- Starter-kit authentication surface (Fortify, passkeys, 2FA, `User` model, login/register/settings views) — this product has no accounts by design
