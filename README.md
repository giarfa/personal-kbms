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
| `KBMS_TRANSCRIPTS_PATH` | — | Yes | US-007 (transcript resolution) |
| `KBMS_TRANSCRIPT_PATTERN` | `{date}_{time}_{slug}` | No | US-007, US-010 |
| `KBMS_TRANSCRIPT_TOLERANCE_MINUTES` | `10` | No | US-007 (start-time drift tolerance) |
| `KBMS_TRANSCRIPT_PREVIEW_BYTES` | `2097152` | No | US-007 (preview truncation cap, 2 MiB) |
| `KBMS_CLAUDE_LAUNCHER` | — | Yes | US-007, US-009 (launch bridge) |
| `KBMS_OUTLOOK_URL_TEMPLATE` | — | No | US-005 ("Open in Outlook" fallback) |
| `KBMS_TIMEZONE` | `Europe/Rome` | No | US-001 (also binds `app.timezone`) |
| `KBMS_ALLOW_NON_LOOPBACK` | `false` | No | US-001 (loopback-only override) |
| `KBMS_SYNC_RUN_RETENTION_DAYS` | `30` | No | US-003 (`calendar_sync_runs` pruning) |
| `KBMS_SYNC_STALE_MULTIPLIER` | `3` | No | US-004 (sync intervals without a success before "stale") |
| `KBMS_SYNC_STUCK_AFTER_SECONDS` | `300` | No | US-004 (abandon a `Running` row whose worker died) |
| `KBMS_LAUNCH_TIMEOUT_SECONDS` | `30` | No | US-009 (seconds the launch job waits for the launcher to return) |
| `KBMS_QUESTION_MAX_CHARS` | `8000` | No | US-009 (maximum launch question length) |

`KBMS_OUTLOOK_URL_TEMPLATE` supports three placeholders, substituted in `KBMS_TIMEZONE`: `{date}` (`Y-m-d`), `{time}` (`H:i`), `{datetime}` (ISO 8601). A feed-carried `event_url` always wins over the template. Worked example against Outlook Web Access: `https://outlook.office.com/calendar/view/day/{date}`.

## Running the background processes

Two long-running processes besides the web server:

```bash
php artisan queue:work        # calendar sync (US-003) and the launch bridge (US-009) are queued jobs
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

## Agenda and meeting detail

The agenda (`/`) lists mirrored meetings chronologically in a 7-day window defaulting to today, with keyboard-reachable rows, forward/back navigation, and a date jump — every date in the range renders, including empty ones. Each occurrence has a canonical detail route addressed by its feed natural key (`source_uid` + `recurrence_id`), never by a surrogate id a resync could change, so links stay valid across syncs.

The detail page shows every mirrored feed field read-only — title, start, end, duration, location, organizer, attendees, description, plus the raw occurrence key — in a surface (`--kb-mirror-bg`, dashed rule, "Mirrored from the feed" flag) visually distinct from the operator-owned column, so it is obvious which side can change under you on the next sync. Cancelled occurrences are marked, never hidden, in both the agenda and the detail page.

The "Open in Outlook" action prefers a feed-carried `event_url` and otherwise falls back to `KBMS_OUTLOOK_URL_TEMPLATE`, substituting `{date}` (`Y-m-d`), `{time}` (`H:i`) and `{datetime}` (ISO 8601) in `KBMS_TIMEZONE` — for example `https://outlook.office.com/calendar/view/day/{date}` against Outlook Web Access. Without Graph API access the template fallback can only open the correct calendar **date**, not the exact item; when neither a feed link nor a template is configured, the control is disabled with the reason shown in place. A Teams join link, when the feed carries one, is offered as a separate action from the Outlook CTA.

## Calendar views

`/calendar` renders the mirrored calendar as Month, Week and Day grids (`?view=month|week|day`), each anchored on a bookmarkable `?date=YYYY-MM-DD`. Every event chip marks its coverage — a filled circle for notes, a filled square for a linked transcript, both together when a meeting has both — legible without hovering, and every chip's accessible name states time, title and coverage in words (e.g. "09:30 Q4 roadmap review, has notes and transcript") so the marks are decoration, not the only signal. Clicking or activating an event opens the same US-005 detail route as the agenda. The grid only ever fetches the visible window from `GET /calendar/events` (clamped server-side at 62 days), never the whole mirror, and renders every timestamp in `KBMS_TIMEZONE` — a DST boundary shifts nothing, because no UTC conversion happens anywhere in the path (same wall-clock storage convention the agenda relies on).

The grid is built on [FullCalendar](https://fullcalendar.io/) **6.x, MIT-core packages only** — `@fullcalendar/core`, `@fullcalendar/daygrid`, `@fullcalendar/timegrid`. No premium plugin (`@fullcalendar/resource*`, `@fullcalendar/timeline`, `@fullcalendar/adaptive`) is installed or referenced, and no `schedulerLicenseKey` appears anywhere in the config — this product cannot write to the calendar at all, so `@fullcalendar/interaction` (drag/resize/`dateClick`) is deliberately not installed either.

**The frontend must be built** — `npm install && npm run build` (or `npm run dev` while developing) — before the grid renders anything. A calendar page that loads with a populated agenda but shows a blank grid is the symptom of a stale or missing Vite build, not a sync problem; `php artisan kbms:doctor` cannot see this failure mode because it never touches the frontend build.

## Event colour rules

Calendar events and agenda rows can be tinted by rules you define against the mirrored feed fields, so a category of meeting is recognisable before you read a single title. Rules live in **`config/kbms.php`** under `event_colour_rules` — **not** in `.env`. There is deliberately **no `KBMS_*` key** for this, no database table, and no management screen: the array is the whole control surface, because rules are product shape rather than machine shape.

```php
'event_colour_rules' => [
    ['field' => 'summary', 'condition' => 'contains', 'value' => 'PING', 'colour' => 'yellow', 'label' => 'Ping'],
    ['field' => 'location', 'condition' => 'empty', 'colour' => 'purple', 'label' => 'No location'],
],
```

Those two are what ships by default.

| Key | Allowed values |
| --- | --- |
| `field` | `summary`, `location`, `description`, `organizer` |
| `condition` | `contains`, `empty` |
| `value` | the needle — **required** for `contains`, ignored by `empty` |
| `colour` | `yellow`, `purple`, `green`, `blue`, `orange`, `grey` |
| `label` | short human name, shown in the legend and in the accessible name |

- **`contains`** is a **case-insensitive** substring match: `PING`, `Ping` and `ping` all hit, anywhere in the field.
- **`empty`** is true when the field is `null`, absent, or contains only whitespace — an ICS feed emitting `LOCATION:` followed by a space counts as empty.
- **Array order is the priority, and the first match wins.** Rules are evaluated top-down; the first hit paints the occurrence and every later rule is skipped. Reordering the array is the only way to reprioritise — there is no priority key, and an occurrence never gets a split or striped two-colour fill.
- **A rule naming anything outside those vocabularies is dropped.** An unknown field, condition, or colour — or a raw CSS value like `#ff0000` — means the rule does not exist: its events render uncoloured, it does not appear in the legend, and the page still renders. A colour is always a named token resolved in the stylesheet, so configuration can never inject CSS into markup.
- **An empty or absent list is valid** and is the supported "off" state: both views render exactly as they did before this feature, with no legend entries and no fills.

**Three independent channels.** Rule colour fills the event body; note/transcript coverage keeps its own edge borders, marks and agenda tags; cancelled keeps its dashed, struck, muted treatment and wins where it conflicts with a fill. A meeting can be a PING meeting *and* an annotated one *and* cancelled, and each of those reads on its own. Colour never carries the meaning alone — every rule's label appears in the page legend on both surfaces, generated from the configuration, and is appended to each matched event's accessible name.

The legends are generated from the array, so adding a rule makes it appear on both pages with no code change. The meeting detail page at `/meetings/{occurrence}` is deliberately **not** coloured: the two scanning surfaces are where the signal pays for itself.

## Calendar as todo

An event whose title **starts** with a checkbox marker is rendered as a todo item on the agenda, the calendar and the meeting detail page.

| Marker | Meaning |
| --- | --- |
| `[]`, `[ ]` (one or more spaces) | open |
| `[x]`, `[X]` | done |

- The marker is recognised **only at the very start** of the title, with leading whitespace forgiven. `[] Call the vendor`, `  [ ] Call the vendor` and `[x]Call the vendor` are todos; `Review [x] doc` and `Sprint [] planning` are ordinary meetings, because a bracket in the middle of a title is just a bracket.
- `[ x ]` (padded) and `[y]` are **not** markers — the accepted set is exactly the four forms above.
- The marker is stripped from the displayed title and replaced by a checkbox glyph. A title that is *only* a marker renders as `Untitled` rather than as a blank row.
- The vocabulary is **hardcoded**. There is no `KBMS_*` key and no setting for it — the convention is stable and personal, so do not go looking for one.

**Overdue** means **open and past its end time**, re-derived on every render against `KBMS_TIMEZONE`. So an item goes late on its own while a page is left open, on the next self-refresh, with no reload.

- A **done** item is never overdue, however old.
- A **cancelled** occurrence is never overdue — cancelled wins over late.
- An **all-day** item turns overdue once its day has ended, not during it.

**It is read-only and derived.** Status is parsed from the mirrored summary at render time: there is **no column, no migration, and no write-back to the calendar**. The mirror stays a mirror. You tick items in Outlook, because this product cannot write to the calendar at all — and the meeting detail page keeps showing the raw feed summary verbatim, marker included, alongside the parsed status.

Todo status is a **third independent channel**, alongside note/transcript coverage and rule colour: an overdue, rule-coloured, annotated occurrence reads as all three at once, and none of them consumes another. The overdue treatment uses its own colour token, deliberately distinct from every rule colour. Status is never signalled by colour alone — the glyph and the accessible name carry it independently.

Deliberately absent, and not oversights: no way to tick a box from this application, no filtering or grouping by status, no overdue count badge, no pinned "overdue" strip, and no notification, digest or reminder of any kind. An overdue item is legible where it already sits.

## Self-refreshing views

The agenda (`/`) and the calendar (`/calendar`) bring themselves up to date roughly **once a minute** while their tab is visible, so a mirror refreshed by the background sync shows up without a reload. The refresh is deliberately invisible: no toast, no banner, no spinner, no announcement — rows and events simply become current, and scroll position, the agenda's date anchor and Jump-to field, and the calendar's view, anchor date and keyboard-focused day cell all survive it. Time-derived chrome re-derives with it, so on a page left open for hours the `Now` divider keeps moving and the sync pill's relative wording stays honest.

While the tab is hidden, refreshing **pauses** outright; returning to the tab fires one immediate catch-up rather than waiting out another minute. A refresh that fails — a stopped server, a restarted PHP process, an unreachable feed — leaves the last good render on screen and says nothing. Sync failures keep being reported only by the sync pill and the sync alert.

The cadence lives in exactly one place, `resources/js/self-refresh.js`, and is **hardcoded at 60 seconds** to match the sync indicator. **There is no `KBMS_*` key for it and no setting in the UI** — do not go looking for one.

The meeting detail page (`/meetings/{occurrence}`) deliberately does **not** self-refresh: it hosts the notes editor, and a timed re-render there would risk interrupting typing.

## Notes

Every meeting can carry a Markdown note, written straight into a Write/Preview panel on the detail page — no save button, just an autosave indicator (`Saving…` / `Saved H:i` / `Not saved` with a retry). A note belongs to the **occurrence**, keyed on (`event_uid`, `event_recurrence_id`), never to the `calendar_events` row — so nothing the feed does (retitle, reschedule, cancel, or even a resync that drops and re-creates the mirrored row) can move or destroy one, and a note on one occurrence of a recurring series never leaks onto its siblings.

Clearing the editor is an **edit**, not a deletion: the blank body is saved and the row is kept (the agenda badge just drops to "Not annotated"). The only thing that removes a note is the explicit **Delete notes…** confirmation dialog in the panel footer. Note bodies are stored as plain text and rendered through a hardened Markdown converter that escapes embedded HTML and neutralises unsafe links instead of executing them.

## Transcript linking

Every meeting resolves to **at most one** transcript file under `KBMS_TRANSCRIPTS_PATH`, driven by `KBMS_TRANSCRIPT_PATTERN` (default `{date}_{time}_{slug}`). Placeholders: `{date}` renders **`Ymd`** (e.g. `20260907`), `{time}` renders **`Hi`** (four digits, no colon — a filename cannot portably carry `:`), `{slug}` is the meeting title through `Str::slug($title, '_')` — lowercase, underscore-separated, special characters such as `|` and `#` stripped. Worked example: `20260907_1430_standup.md`. **The pattern must end with `{slug}`** — the trailing portion varies freely, and it is the date-and-time prefix that carries identity.

**This is a deliberate divergence from `KBMS_OUTLOOK_URL_TEMPLATE`**, where `{time}` is `H:i` — the two templates address different media (a filename vs. a URL) and must not be "simplified" to match each other.

Matching tolerates start-time drift within `KBMS_TRANSCRIPT_TOLERANCE_MINUTES` (default `10`), because a recording rarely starts on the exact calendar minute. When the tolerance window yields exactly one candidate, it is linked automatically the first time the meeting page is opened (never from the agenda, which never writes). When it yields **two or more**, the resolver surfaces every candidate with its drift and size and **does not guess** — the operator chooses. Among same-drift candidates, one whose file slug **starts with** the event's summary slug sorts ahead of one that does not — ordering only, it never removes a candidate from the list. A **manual override** (an in-app file picker, listing `.md` files only, no free-text path field) always wins over the convention, including a pattern change made afterwards; clearing a link writes an explicit tombstone rather than deleting the row, so the convention cannot silently re-link a file the operator just rejected.

The previous hyphenated convention (`{date}-{time}-{slug}`, `Y-m-d` dates) was retired in US-010 as a hard cutover: filenames in that shape are simply not indexed going forward. Transcripts already linked (an existing database row with a stored path) are unaffected, since the stored path is read directly rather than re-derived from the current pattern.

The preview reads at most `KBMS_TRANSCRIPT_PREVIEW_BYTES` (default `2097152`, 2 MiB) — a larger file shows its first bounded chunk with a truncation notice rather than loading the whole file. `.md` renders through the same hardened Markdown converter as notes; `.txt` is shown as escaped plain text. **Resolution is single-level and non-recursive** — a nested pipeline layout is a pattern change, not a code change. The application only ever **reads** `KBMS_TRANSCRIPTS_PATH`; it never writes, moves, or deletes a transcript file.

**Convention resolution and the manual picker consider `.md` files only** (the `transcript_extensions` config, not an env var) — a pipeline that emits a `.md` + `.txt` pair per recording would otherwise index both and turn every single-transcript meeting into an ambiguous one. A `.txt` file is reachable only through a link made before this restriction, and still renders as escaped plain text.

For local development, point `KBMS_TRANSCRIPTS_PATH` at `storage/app/transcripts` (already gitignored) — `MeetingTranscriptSeeder` writes real sample files there so every panel state (linked, ambiguous, `.txt` via a manual link, manual, broken) is visible after a plain `php artisan migrate:fresh --seed`.

## The launcher script contract

`KBMS_CLAUDE_LAUNCHER` points at an operator-owned shell script, invoked with **exactly two positional arguments, in order**:

1. The resolved context file path (the transcript).
2. The question text.

Arguments are passed as an argument array, never an interpolated shell string — the question is arbitrary operator input and must never be able to alter the command. The script opens its own terminal window; **the application never captures Claude's reply**.

The invocation runs inside a queued job (`LaunchClaudeSession`) rather than the web request, and **the script must return once it has spawned its own terminal window** — a script that runs Claude in the foreground instead of handing off to a window blocks the job. The job waits up to `KBMS_LAUNCH_TIMEOUT_SECONDS` (default `30`) for that return; a script still running past the bound is recorded as timed out rather than left to hang the worker indefinitely. The launcher's exit code is recorded on the launch row and shown on the meeting page. None of this runs without `php artisan queue:work` actually processing jobs — a reachable queue connection is not the same as a running worker.

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
6. Transcript panel states, each with a different fix — the wording is the contract, not decoration:
   - **Not configured** — `KBMS_TRANSCRIPTS_PATH` is unset; set it and re-run `kbms:doctor`.
   - **Missing** — no candidate file was found within the tolerance window; use the in-app picker to link one manually.
   - **Unreadable** — the file exists but permissions block it; `chmod`, not relink.
   - **Rejected** — the linked path resolves outside `KBMS_TRANSCRIPTS_PATH` (e.g. after the env value changed); relink to a file the server can index inside the base.

## Access model

**No authentication, by design** — this is a single-operator, localhost-bound tool with no accounts. The application binds explicitly to `127.0.0.1` and rejects non-loopback origins (override: `KBMS_ALLOW_NON_LOOPBACK`). **Accepted risk, recorded:** anything able to reach the port reads every transcript. There is no `SECURITY.md` or `security.txt` for the same reason — both are scoped to public-facing apps, and this one is never deployed.

## Quality

```bash
php artisan larapilot:quality   # Pint + Larastan level 5
php artisan test
```
