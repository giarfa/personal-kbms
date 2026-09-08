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
| `KBMS_SYNC_RUN_RETENTION_DAYS` | `30` | No | US-003 (`calendar_sync_runs` pruning) |
| `KBMS_SYNC_STALE_MULTIPLIER` | `3` | No | US-004 (sync intervals without a success before "stale") |
| `KBMS_SYNC_STUCK_AFTER_SECONDS` | `300` | No | US-004 (abandon a `Running` row whose worker died) |

`KBMS_OUTLOOK_URL_TEMPLATE` supports three placeholders, substituted in `KBMS_TIMEZONE`: `{date}` (`Y-m-d`), `{time}` (`H:i`), `{datetime}` (ISO 8601). A feed-carried `event_url` always wins over the template. Worked example against Outlook Web Access: `https://outlook.office.com/calendar/view/day/{date}`.

## Running the background processes

Two long-running processes besides the web server:

```bash
php artisan queue:work        # calendar sync (US-003) and the launch bridge (US-007) are queued jobs
php artisan schedule:work      # fires kbms:sync-calendar on the configured interval
```

`composer dev` runs the server, queue listener, log tailer, and Vite dev server together for local iteration.

## Calendar sync

`php artisan kbms:sync-calendar` fetches the feed at `KBMS_ICS_URL`, expands recurring series into individually addressable occurrences, and upserts them into `calendar_events` — never deleting a row, only marking `cancelled_at` when an occurrence disappears or is cancelled upstream. Every run writes a `calendar_sync_runs` row (status, HTTP status, counts, error); runs older than `KBMS_SYNC_RUN_RETENTION_DAYS` are pruned after each run.

By default the command **dispatches to the queue**, so `php artisan queue:work` must be running for it to actually process. The scheduler entry (`php artisan schedule:work`) is what drives the `KBMS_ICS_SYNC_MINUTES` cadence. Flags:

- `--sync` — run inline instead of queueing, and print the resulting run's status and counts.
- `--force` — ignore the stored `ETag`/`Last-Modified` validators and force a full fetch instead of a conditional GET.

### Sync health

The app shell shows a sync pill on every page: **Synced** (last success, relative and absolute time), **Stale** (no success for more than `KBMS_SYNC_STALE_MULTIPLIER` × `KBMS_ICS_SYNC_MINUTES`, even if nothing has explicitly failed), **Sync failed** (with the stored error and the `KBMS_ICS_URL` key named — no need to open `storage/logs`), or **Never synced** (no run recorded at all). These four states are distinct and never collapse into one another.

A manual resync from the pill will not queue a duplicate or overlapping run while one is already in progress. A run whose worker died mid-flight (killed `queue:work`, crashed process) is recorded as failed after `KBMS_SYNC_STUCK_AFTER_SECONDS` rather than left spinning forever.

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

1. `php artisan kbms:doctor` — checks the whole integration surface in one shot: the ICS feed is reachable and actually parses as iCalendar, the transcripts directory exists and is readable, the launcher script exists and is executable (see the launcher two-argument contract above — the check only verifies the file exists and is executable, not that it honors the contract), the queue connection is reachable, `kbms:sync-calendar` is registered on the scheduler, and `KBMS_TIMEZONE` is a valid identifier. Every failing check prints a remediation hint naming the `KBMS_*` key at fault, and the command exits non-zero when any check fails, so it can gate a shell script. `NOT CONFIGURED` means the key was never set; `FAIL` means it was set to something the machine rejects — that distinction is the point of the command.
2. `php artisan kbms:queue-test` then `php artisan queue:work --stop-when-empty` — proves the database queue worker processes a job.
3. `php artisan schedule:list` — confirms the calendar sync entry and its interval.
4. If `database/database.sqlite` is missing or unreadable, the shell shows an actionable message naming the exact remedy (`touch` + `migrate`, or a permissions fix) instead of a stack trace.
5. A stale calendar mirror: run `kbms:doctor` first to confirm the feed itself is reachable and parses, then check the latest `calendar_sync_runs` row for the actual failure reason.

## Access model

**No authentication, by design** — this is a single-operator, localhost-bound tool with no accounts. The application binds explicitly to `127.0.0.1` and rejects non-loopback origins (override: `KBMS_ALLOW_NON_LOOPBACK`). **Accepted risk, recorded:** anything able to reach the port reads every transcript. There is no `SECURITY.md` or `security.txt` for the same reason — both are scoped to public-facing apps, and this one is never deployed.

## Quality

```bash
php artisan larapilot:quality   # Pint + Larastan level 5
php artisan test
```
