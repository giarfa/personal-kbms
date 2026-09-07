# Personal KBMS

## What this is

A local-first meeting knowledge cockpit. It mirrors a read-only Outlook `.ics` feed onto this machine, lets you attach durable personal notes to any meeting, links each meeting to the transcript your existing record-and-transcribe pipeline already produced, and hands both — the transcript path and a question you type — to a local Claude Code shell session.

Two sources only: the calendar feed and call transcripts. This product **never** produces calendar entries or transcripts, and it **never** hosts the LLM — it ingests what other systems emit and makes it navigable, annotatable, and promptable.

## Requirements

- PHP 8.4
- [Laravel Herd](https://herd.laravel.com/)
- Node (for the Vite build)

**Herd is load-bearing, not a preference.** A container could not read the host transcript directory (`KBMS_TRANSCRIPTS_PATH`) or invoke the host launcher script (`KBMS_CLAUDE_LAUNCHER`) without additional plumbing this product deliberately doesn't build.

## Setup

```bash
git clone <repo>
cd personal-kbms
composer setup
```

`composer setup` installs PHP dependencies, copies `.env` from `.env.example`, generates the app key, touches `database/database.sqlite`, migrates, installs npm dependencies, and builds assets. No manual file creation is needed on a clean checkout.

Point the Herd site at the project root; it serves at `http://personal-kbms.test`.

## Configuration

Every machine-specific assumption is a `.env` value, never a code constant. All keys live under `config/kbms.php`.

| Key | Default | Required | Consumed by |
| --- | --- | --- | --- |
| `KBMS_ICS_URL` | — | Yes | US-003 (calendar sync) |
| `KBMS_ICS_SYNC_MINUTES` | `15` | No | US-001 (schedule), US-003 |
| `KBMS_ICS_WINDOW_PAST_DAYS` | `90` | No | US-003, US-005 (agenda window) |
| `KBMS_ICS_WINDOW_FUTURE_DAYS` | `180` | No | US-003, US-005 |
| `KBMS_TRANSCRIPTS_PATH` | — | Yes | US-006 (transcript resolution) |
| `KBMS_TRANSCRIPT_PATTERN` | `{date}-{time}-{slug}` | No | US-006 |
| `KBMS_CLAUDE_LAUNCHER` | — | Yes | US-007, US-009 (launch bridge) |
| `KBMS_OUTLOOK_URL_TEMPLATE` | — | No | US-005 ("Open in Outlook" fallback) |
| `KBMS_TIMEZONE` | `Europe/Rome` | No | US-001 (also binds `app.timezone`) |
| `KBMS_ALLOW_NON_LOOPBACK` | `false` | No | US-001 (loopback-only override) |

## Running the background processes

Two long-running processes besides the web server:

```bash
php artisan queue:work        # calendar sync (US-003) and the launch bridge (US-007) are queued jobs
php artisan schedule:work      # fires kbms:sync-calendar on the configured interval
```

`composer dev` runs the server, queue listener, log tailer, and Vite dev server together for local iteration.

## The launcher script contract

`KBMS_CLAUDE_LAUNCHER` points at an operator-owned shell script, invoked with **exactly two positional arguments, in order**:

1. The resolved context file path (the transcript).
2. The question text.

Arguments are passed as an argument array, never an interpolated shell string — the question is arbitrary operator input and must never be able to alter the command. The script opens its own terminal window; **the application never captures Claude's reply**.

Minimal example script:

```bash
#!/usr/bin/env bash
# $1 = context file path, $2 = question
osascript -e "tell application \"Terminal\" to do script \"claude '$2' --file '$1'\""
```

## Troubleshooting

1. `php artisan kbms:doctor` — checks the whole integration surface in one shot (arrives with US-002).
2. `php artisan kbms:queue-test` then `php artisan queue:work --stop-when-empty` — proves the database queue worker processes a job.
3. `php artisan schedule:list` — confirms the calendar sync entry and its interval.
4. If `database/database.sqlite` is missing or unreadable, the shell shows an actionable message naming the exact remedy (`touch` + `migrate`, or a permissions fix) instead of a stack trace.

## Access model

**No authentication, by design** — this is a single-operator, localhost-bound tool with no accounts. The application binds explicitly to `127.0.0.1` and rejects non-loopback origins (override: `KBMS_ALLOW_NON_LOOPBACK`). **Accepted risk, recorded:** anything able to reach the port reads every transcript. There is no `SECURITY.md` or `security.txt` for the same reason — both are scoped to public-facing apps, and this one is never deployed.

## Quality

```bash
php artisan larapilot:quality   # Pint + Larastan level 5
php artisan test
```
