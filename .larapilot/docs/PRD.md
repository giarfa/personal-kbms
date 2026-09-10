# Product Requirements Document

**Author:** Larapilot
**Date:** 2026-09-07

## Elevator Pitch

A local-first personal knowledge cockpit that turns a read-only Outlook `.ics` feed into something you can actually work with. It mirrors your calendar on your own machine, lets you attach durable personal notes to any meeting, links each meeting to the transcript your existing record-and-transcribe pipeline already produced, and hands both — the transcript path and a question you type — straight to your local Claude Code shell session. No calendar API, no LLM API key, no data leaving the laptop.

## Vision

Work knowledge is scattered across calendar, chat, calls, and email, and none of those tools let you keep your own layer of meaning on top. This product builds that layer, starting where the signal is densest: **meetings**. Two sources only in this release — the calendar feed and the call transcripts — joined by a note surface that belongs to you and survives every resync.

The long-term ambition is a personal knowledge base spanning more sources (chat, email, tasks), with meetings as the organizing spine. The design constraint that makes that ambition tractable is deliberate humility about ownership: the tool **never** produces calendar entries or transcripts, and it **never** hosts the LLM. It ingests what other systems emit and makes it navigable, annotatable, and promptable.

## User Personas

### The Operator (sole user)

- **Role:** Solo knowledge worker running a full meeting load; owns the machine, the calendar subscription, the transcription pipeline, and a local Claude Code installation.
- **Goals:** See what's coming and what happened in one place; capture a thought against a specific meeting without hunting for where it belongs; get from "I need to think about that call" to an LLM session preloaded with the transcript in one click; jump to Outlook when a native calendar action is actually needed.
- **Pain Points:** Context fragmented across calendar, chat, and call recordings; transcripts pile up as loose `.md`/`.txt` files with no route back to the meeting they describe; no calendar API access, so nothing can be automated the usual way; commercial LLM API keys are an unjustifiable recurring cost for personal use.

## Functional Requirements

### FR-001: Scheduled ICS calendar ingestion

**MoSCoW:** Must

A scheduled task fetches the subscribed `.ics` URL and mirrors its events into local storage.

- Fetch interval every **15 minutes**, `withoutOverlapping()`, interval configurable via `.env`.
- Conditional GET using `ETag` / `If-Modified-Since`; a `304 Not Modified` short-circuits parsing.
- Parse with `sabre/vobject`; expand `RRULE` recurrences across a rolling window (default −90 to +180 days, configurable).
- Upsert keyed on `UID` + `RECURRENCE-ID` so a single occurrence of a recurring series is addressable independently of its siblings.
- Preserve `TZID` and render in the operator's local timezone; all-day events handled as date-only, not midnight-local.
- Events that disappear from the feed, or arrive with `STATUS:CANCELLED`, are marked cancelled rather than deleted — annotated history must never be destroyed by an upstream change.
- Each run is recorded (start, finish, outcome, events upserted, error) for FR-010.

### FR-002: Calendar views (month, week, day)

**MoSCoW:** Must

Month, week, and day views of the mirrored calendar. Meetings that carry notes or a linked transcript are visually distinguished from bare ones, so the calendar doubles as a coverage map. Clicking any event opens FR-004.

### FR-003: Agenda view

**MoSCoW:** Must

A chronological list view spanning recent past and upcoming meetings, defaulting to today, with date-range navigation. This is the primary triage surface: it must be usable with the keyboard and must expose note and transcript presence inline without a click.

### FR-004: Meeting detail page

**MoSCoW:** Must

A single canonical page per meeting occurrence, showing feed-derived fields read-only (title, time, duration, location, organizer, attendees, description) alongside the operator-owned surfaces: notes (FR-005), transcript (FR-006, FR-007), prompt box (FR-008), and the Outlook CTA (FR-009). Feed data and operator data are visually distinct — one is a mirror and can change under you, the other is yours.

### FR-005: Personal notes on a meeting

**MoSCoW:** Must

A Markdown note surface attached to a meeting occurrence, autosaved, keyed on `UID` + `RECURRENCE-ID`.

- Notes survive every resync, including title, time, or location changes upstream.
- Notes are addressable before the meeting (prep) and after it (outcomes) — there is no state gate.
- Rendered Markdown preview; plain-text-safe storage.
- Explicit delete path for a meeting's notes (see **Privacy & data handling**).

### FR-006: Transcript context-file resolution

**MoSCoW:** Must

Each meeting resolves to at most **one** context file — the single path handed to the launcher in FR-008.

- A configurable filename-convention resolver maps a meeting to a candidate path under the transcripts directory, using placeholders (date, time, slugified title).
- The resolved path is verified to exist and be readable before it is offered.
- A **manual override** lets the operator pick or clear the file for a meeting; a manual link always wins over the convention.
- Once linked, the path is persisted on the meeting, so convention changes don't silently break existing links.
- Supports `.md` and `.txt`.
- Default convention is `{date}_{time}_{slug}.md` with `{date}` as `Ymd` and an underscore-separated, special-character-stripped `{slug}`; ordering among same-drift candidates favors a file whose slug starts with the event's slug (`LIKE 'slug%'` semantics) over exact equality — a prefix bonus only, never a candidate filter.

**Note:** the exact pipeline convention is not yet recorded in this PRD. The resolver is deliberately configuration-driven plus manually overridable so the MVP is correct under any convention; the concrete pattern is a configuration value, not a code change.

### FR-007: Transcript preview in the meeting detail

**MoSCoW:** Must

The linked transcript is readable in place — rendered for Markdown, monospace for plain text — without leaving the meeting page or opening an external editor. Long transcripts are scrollable within a bounded region and expose the resolved absolute path for copying.

### FR-008: Claude Code session launch bridge

**MoSCoW:** Must

A text input on the meeting detail page becomes the initial question for an interactive local Claude Code session.

- The operator types a question and triggers the launch.
- The application invokes the **operator-owned** shell script with exactly two arguments, in order: **(1)** the resolved context file path from FR-006, **(2)** the question text.
- The script path is a configuration value; the script itself is **outside** this product's scope and opens its own terminal window.
- Invocation happens inside a **queued job**, not the web request: Symfony's `Process` destructor terminates its child, so a request-scoped launch could kill the script mid-flight. The script returns as soon as it has spawned its own window, and that window is owned by the operating system rather than by PHP.
- Arguments are passed as an **argument array**, never an interpolated shell string — question text is arbitrary operator input and must not be able to alter the command.
- Each launch is persisted (meeting, context path, question, resolved command, timestamp, dispatch outcome).
- Launch is blocked with a clear reason when the script is missing or not executable, or when no context file is linked.
- The exact invocation is available to copy, so the operator can run it manually when preferred.

**Explicit non-goal:** the session is interactive and owned by the terminal, so the application **does not** capture Claude's replies. The meeting record holds the question, not the answer. See FR-018.

### FR-009: "Open in Outlook" CTA

**MoSCoW:** Must

Every meeting exposes a call-to-action that opens the event in Outlook on the web.

- Prefer a link carried by the feed itself when present: the `VEVENT` `URL` property, or a Teams join URL from `X-MICROSOFT-SKYPETEAMSMEETINGURL` / the description body.
- Otherwise fall back to a **configurable OWA URL template** with placeholders for the event date and time.
- **Known constraint:** without Microsoft Graph access there is no Outlook item identifier, so a guaranteed "open exactly this event" deep link is not constructible. The reliable fallback targets the correct calendar **date/view**. The template is configuration so it can be sharpened if the feed turns out to carry a usable identifier.
- When a Teams join URL is found, it is offered as a distinct action from the calendar CTA.

### FR-010: Sync status and manual resync

**MoSCoW:** Must

The interface always shows when the calendar was last successfully synced and whether the last attempt failed, because a silently stale mirror is worse than an obviously broken one. A manual resync is available on demand, and the last error is legible without reading log files.

### FR-011: Environment doctor command

**MoSCoW:** Must

An Artisan command verifies the whole integration surface in one shot and reports pass/fail per check: ICS URL reachable and parseable; transcripts directory present and readable; launcher script present and executable; queue worker reachable; scheduler registered; configured timezone valid. Every one of the three integration points in this product is an assumption about the operator's machine, and this command is what turns a silent misconfiguration into a stated one.

### FR-012: Prompt launch history per meeting

**MoSCoW:** Should

A browsable history of prompts launched against a meeting, with question text and timestamp, so a line of thinking is recoverable and re-runnable. The underlying records are already written by FR-008; this requirement is the reading surface.

### FR-013: Unified full-text search

**MoSCoW:** Should

Full-text search across meeting titles, personal notes, and linked transcript contents via SQLite FTS5, with results linking back to the meeting occurrence. This is the requirement that turns the tool from a calendar overlay into an actual knowledge base.

### FR-014: Unmatched transcript inbox

**MoSCoW:** Should

A view listing transcript files in the pipeline directory that no meeting currently claims, with a one-click path to attach each to a meeting. This closes the loop where the convention resolver fails or the meeting never appeared on the calendar.

### FR-015: Reusable prompt templates

**MoSCoW:** Could

Named, reusable prompt skeletons ("summarize decisions and owners", "draft follow-up email") that pre-fill the FR-008 input, so recurring intents don't get retyped.

### FR-016: Action items extracted from notes

**MoSCoW:** Could

Checklist items captured within a meeting's notes, surfaced in a cross-meeting open-items view with completion state.

### FR-017: Meeting tagging and filtering

**MoSCoW:** Could

Operator-defined tags on meeting occurrences, with filtering in the agenda and calendar views.

### FR-018: Ingesting Claude session output back into the tool

**MoSCoW:** Could

If the launcher script writes its session output to a predictable location, the application ingests that output and attaches it to the meeting that triggered the prompt — closing the gap left open by FR-008. This depends entirely on a convention the launcher script does not currently guarantee, so it is deferred rather than assumed.

### FR-019: Additional sources — chat, email, tasks

**MoSCoW:** Won't

Slack, email, and task-system ingestion are the stated long-term vision but are explicitly excluded from this release. Two sources, done properly, first.

### FR-020: Recording and transcription pipeline

**MoSCoW:** Won't

The recording and transcription pipeline already exists and is owned by the operator. This product consumes its output and will never reimplement, wrap, or orchestrate it.

### FR-021: Calendar write-back and event creation

**MoSCoW:** Won't

Creating, editing, or deleting calendar events. The `.ics` feed is read-only by nature and there is no API access — attempting write-back would be architecturally dishonest. Outlook remains the only place calendar mutations happen, which is precisely what FR-009 exists to make convenient.

### FR-022: Multi-user accounts, sharing, hosted deployment

**MoSCoW:** Won't

Single operator, single machine, no accounts, no sharing, no remote hosting in this release.

## MVP Scope

**Project Kind:** Personal
**Project Origin:** Greenfield
**Delivery Target:** MVP
**Deadlines:** None — no fixed delivery date; milestone tracking off (Lucille logs effort only)

### In Scope

- FR-001 — Scheduled ICS ingestion with recurrence expansion, conditional GET, and cancellation-safe upserts
- FR-002 — Calendar views (month, week, day) with note/transcript presence indicators
- FR-003 — Agenda list view with date-range navigation
- FR-004 — Meeting detail page separating mirrored feed data from operator-owned data
- FR-005 — Markdown personal notes keyed to `UID` + `RECURRENCE-ID`, resync-durable
- FR-006 — Single-context-file resolution: convention-driven plus manual override
- FR-007 — In-place transcript preview
- FR-008 — Claude Code launch bridge: operator's `.sh` invoked with context path + question, from a queued job, argument-array only
- FR-009 — "Open in Outlook" CTA with feed-carried link preferred and configurable OWA template fallback
- FR-010 — Sync status, last-error visibility, and manual resync
- FR-011 — `doctor` command validating every integration assumption

### Out of Scope

- FR-019 — Chat, email, and task sources: deferred deliberately so the two-source loop can be proven first
- FR-020 — Recording and transcription: owned by the operator's existing pipeline, permanently outside this product
- FR-021 — Calendar write-back: impossible without API access and dishonest to fake; Outlook stays the mutation surface
- FR-022 — Accounts, sharing, hosted deployment: single-operator local tool by design
- Capturing Claude's replies in the MVP — a direct consequence of choosing an interactive terminal session over a headless call; revisit via FR-018
- Any transmission of calendar data, notes, or transcripts to a third-party service

### Future Phases

- FR-012 — Prompt launch history surface
- FR-013 — Unified FTS5 search across titles, notes, and transcripts
- FR-014 — Unmatched-transcript inbox
- FR-015 — Reusable prompt templates
- FR-016 — Action items from notes with a cross-meeting open-items view
- FR-017 — Meeting tagging and filtering
- FR-018 — Ingesting launcher session output back onto the meeting
- Maintenance and support (Sophia): single-operator project — bugs are triaged through `/larapilot-bug` into the Maintenance epic; no SLA, no external intake channel
- Red-team assessment (Oliver): deferred to ship, and proportionate to a localhost-bound tool with no authentication surface and no network exposure

## Technical Architecture

**Budget Sensitivity:** Relaxed
**Frontend Topology:** Laravel-coupled
**Frontend stack (in-repo):** Laravel Starter Kit (Livewire + Flux), Livewire 3, Alpine, Tailwind 4, FullCalendar (MIT)
**External frontend repo:** N/A
**Admin panel:** Starter Kit (livewire) — used as application scaffold; authentication removed (see **Security & access**)

### Stack

- **Laravel 13.30.1** on **PHP 8.4.23** (verified via `composer show --direct`), Vite 8, Tailwind 4 — already present in the repository skeleton.
- **Frontend:** the official **Livewire + Flux starter kit** is installed as the application scaffold, supplying the app shell, layout, settings pages, dark/light theming, and Vite wiring. Livewire 3 drives the note editor, prompt box, and transcript panel; **FullCalendar** (MIT core plugins only — no premium plugins required) wrapped in a thin Alpine component provides month/week/day views.
- **Security & access:** localhost-only, **no authentication** (operator's explicit choice). The starter kit's auth routes, views, and `User` scaffolding are **removed** rather than left dormant, so there is no half-wired login surface to reason about. Consequently: no `User` model, no `Password::defaults()`, no Argon2id hashing, no 2FA, no Socialite — the standard Larapilot security baseline is intentionally **not applicable** to an app with no accounts. Compensating controls: bind explicitly to `127.0.0.1` (never `0.0.0.0`), treat the transcripts directory as read-only to the application, and keep the ICS URL in `.env` and out of version control. **Accepted risk, recorded:** anything able to reach the port reads every transcript. Notes have no owner column — single operator by construction.
- **Data store (Mike):** **SQLite**, single file, zero-ops — correct for a single-user local tool with no concurrent writers. **Hierarchy:** N/A — the domain is flat (occurrences, notes, transcripts, launches); no tree pattern is warranted and none should be invented. **Search:** SQLite **FTS5** when FR-013 lands; no external search engine at any point in this product's roadmap.
- **CLI tooling (Sarah):** **Artisan commands only** — `kbms:sync-calendar`, `kbms:scan-transcripts`, `kbms:doctor`. No standalone Bash or Go CLI: the only external script is the operator's own launcher, which this product treats strictly as a configured dependency it invokes and never edits. Run by the operator (`doctor`, manual sync) and by the scheduler (sync).
- **Queue & scheduler:** `database` queue driver on SQLite; `php artisan queue:work` and `php artisan schedule:work` run locally under Herd. The launch bridge (FR-008) and calendar sync (FR-001) both run as queued jobs so no operator-facing request ever blocks on I/O or a subprocess.
- **Local dev:** **Laravel Herd** (native macOS PHP/nginx). Herd is load-bearing rather than a preference: a Docker container could not see the host transcript directory or invoke the host launcher script without additional plumbing. Local URL `http://personal-kbms.test`.
- **Deploy / cloud / edge / observability:** **none** — the application runs only on the operator's machine. No deploy platform, no CDN, no WAF, no APM. Observability is `storage/logs` plus **Laravel Pail** (already installed) for live tailing, and FR-010 surfaces sync health in the interface itself.
- **Vendor policy:** per Larapilot vendor order, no Laravel first-party or Spatie package covers ICS parsing, so **`sabre/vobject ^5.0`** is the community choice — BSD-3-Clause, requires PHP `^8.2`, actively released, the de-facto standard for `RRULE` handling in PHP. `composer audit` after install.

### Integrations

Three integration points, all local to the operator's machine, none over an authenticated API:

1. **ICS calendar feed** — HTTPS `GET` of a subscribed `.ics` URL. No API, no OAuth, no write path. Configured as `KBMS_ICS_URL`; conditional GET with stored `ETag`/`Last-Modified`.
2. **Transcript directory** — a filesystem path emitted by the operator's record-and-transcribe pipeline, read-only. Configured as `KBMS_TRANSCRIPTS_PATH` plus a resolution pattern.
3. **Claude Code launcher script** — the operator's `.sh`, invoked with two positional arguments (context file path, question). Configured as `KBMS_CLAUDE_LAUNCHER`. The script opens its own terminal window; the product never parses its output in the MVP.

No third-party SaaS, no analytics, no error-reporting service, no newsletter, no object storage. Recurring cost: **zero**, including LLM usage — the interactive local Claude Code session is exactly what avoids an API key.

### Configuration surface

Every machine-specific assumption is a `.env` value, never a code constant:

- `KBMS_ICS_URL`, `KBMS_ICS_SYNC_MINUTES` (default `15`)
- `KBMS_ICS_WINDOW_PAST_DAYS` (default `90`), `KBMS_ICS_WINDOW_FUTURE_DAYS` (default `180`)
- `KBMS_TRANSCRIPTS_PATH`, `KBMS_TRANSCRIPT_PATTERN` — default `{date}_{time}_{slug}.md`; placeholders `{date}` (`Ymd`), `{time}` (`Hi`, **no colon** — deliberately unlike `KBMS_OUTLOOK_URL_TEMPLATE` below, since a filename cannot portably carry `:`), `{slug}` (`Str::slug($title, '_')` — underscore-separated, special characters stripped); the pattern must end with `{slug}`. The old hyphenated/`Y-m-d` convention is no longer recognized (hard cutover)
- `KBMS_TRANSCRIPT_TOLERANCE_MINUTES` (default `10`) — minutes of start-time drift the transcript resolver tolerates either side of a meeting's start
- `KBMS_TRANSCRIPT_PREVIEW_BYTES` (default `2097152`, 2 MiB) — bytes read from a transcript before the preview is truncated
- `KBMS_CLAUDE_LAUNCHER`
- `KBMS_LAUNCH_TIMEOUT_SECONDS` (default `30`) — seconds the queued launch job waits for the launcher script to return before recording a timeout
- `KBMS_QUESTION_MAX_CHARS` (default `8000`) — maximum character length for a launch question
- `KBMS_OUTLOOK_URL_TEMPLATE` — placeholders `{date}` (`Y-m-d`), `{time}` (`H:i`), `{datetime}` (ISO 8601), substituted in `KBMS_TIMEZONE`; a feed-carried `event_url` always takes precedence
- `KBMS_TIMEZONE`
- `KBMS_SYNC_RUN_RETENTION_DAYS` (default `30`) — days a finished `calendar_sync_runs` row is kept before pruning

### Privacy & data handling _(Violet)_

The application processes **third-party personal data**: transcripts contain colleagues' and clients' names, speech, and opinions, and calendar events carry attendee identities. Purely personal and household processing falls outside GDPR's material scope, which is why no consent or records-of-processing machinery is warranted here — but that exemption is conditional on the tool staying personal, and it lapses the moment output is shared in a work context or the instance becomes reachable by others.

Concrete obligations for this release:

1. **Local-only processing** — no calendar data, note, or transcript content is transmitted to any third party. The LLM runs on the operator's machine; this is a privacy property of the chosen architecture, not an incidental one.
2. **Deletion path** — the operator can delete a meeting's notes and clear its transcript link (FR-005). Transcript **files** remain owned by the pipeline; the tool never deletes them.
3. **No secondary copies** — transcript content is read from the pipeline directory on demand rather than duplicated into the database. FR-013's FTS5 index is a derived artifact and must be rebuildable and droppable.
4. **Reassess on scope change** — exposing the instance beyond localhost (FR-022) or sharing generated summaries externally moves this out of the household exemption and requires revisiting retention, transparency, and legal basis before shipping.

### UX & frontend _(Elise + Joe)_

- Flux design language with **light and dark** themes from the starter kit; no bespoke design system for a personal tool.
- **Desktop-first** — an explicit inversion of the usual mobile-first default, justified by a localhost-bound tool driven from the machine that runs it. Views stay responsive and must not break below `768px`, but no mobile-specific flows are designed.
- **WCAG 2.2 AA** as the baseline: keyboard-navigable agenda and calendar, visible focus states, AA contrast in both themes, correct heading structure, labelled form controls. The calendar grid needs deliberate keyboard and screen-reader handling — FullCalendar's defaults are not sufficient on their own.
- Density over decoration: the agenda is a scanning surface, so note and transcript presence must be legible without hovering or clicking.
- **Brand assets:** minimal — a simple `favicon.svg` and app title. No logo, OG image, or social assets: nothing here is public.

### Core Components

**Domain tables** (UUID primary keys where a stable external reference is useful; `calendar_events` additionally carries the natural key from the feed):

- `calendar_events` — `source_uid`, `recurrence_id`, `summary`, `description`, `location`, `organizer`, `attendees` (JSON), `starts_at`, `ends_at`, `is_all_day`, `timezone`, `join_url`, `event_url`, `content_hash`, `last_seen_at`, `cancelled_at`. Unique index on (`source_uid`, `recurrence_id`); index on `starts_at`.
- `meeting_notes` — `event_uid`, `event_recurrence_id`, `body` (Markdown), timestamps. Keyed to the occurrence, not to the event row's surrogate id, so a resync cannot orphan a note.
- `meeting_transcripts` — `event_uid`, `event_recurrence_id`, `path` (**nullable** — a `NULL` path with `link_source = manual` is the manual-unlink tombstone that stops the convention from re-linking a file the operator just rejected), `link_source` (`convention` \| `manual`), `file_size`, `file_mtime`, `linked_at`.
- `prompt_launches` — `event_uid`, `event_recurrence_id`, `context_path`, `question`, `command` (JSON argument array), `launched_at`, `status`, `exit_code`, `error`.
- `calendar_sync_runs` — `started_at`, `finished_at`, `status`, `http_status`, `etag`, `last_modified`, `events_upserted`, `events_cancelled`, `error`.

**Services:**

- `IcsFeedClient` — conditional HTTP GET, `ETag`/`Last-Modified` persistence, timeout and failure handling
- `IcsParser` — `sabre/vobject` wrapper; `RRULE` expansion over the configured window; `TZID` and all-day normalization
- `EventSynchronizer` — cancellation-safe upsert on (`UID`, `RECURRENCE-ID`); content hashing to skip unchanged rows
- `TranscriptResolver` — pattern-driven candidate path resolution, existence and readability checks, manual-override precedence
- `TranscriptScanner` — pipeline-directory scan feeding FR-014
- `ClaudeSessionLauncher` — argument-array construction, pre-flight validation, `Process` invocation from a queued job, launch persistence
- `OutlookLinkBuilder` — feed-carried link preference, configurable template fallback, Teams join URL extraction

**Console:** `kbms:sync-calendar`, `kbms:scan-transcripts`, `kbms:doctor`
**Jobs:** `SyncCalendarFeed`, `LaunchClaudeSession`

### Performance & Scalability

Deliberately trivial by design, and the design should stay honest about that: a single operator's calendar over a ~270-day window is a few thousand rows, which SQLite serves from memory. Conditional GET means the common sync path is a `304` with no parsing at all. The only real cost centre is `RRULE` expansion on feeds with long-running recurring series, bounded by the configurable window and skipped entirely when the feed's `ETag` is unchanged.

Correctness risks that matter more than throughput, and where the tests should concentrate:

- **Recurrence identity** — a note written on one occurrence of a weekly series must never appear on its siblings. This is the single highest-value test in the suite.
- **Timezone fidelity** — `TZID` events and all-day events must render correctly across DST boundaries.
- **Resync durability** — upstream retitling, rescheduling, or cancellation must leave notes and transcript links intact and reachable.
- **Argument safety** — question text containing quotes, newlines, backticks, or shell metacharacters must reach the launcher script as a single intact argument.

Infrastructure cost (Aurora): **€0 recurring** — no hosting, no SaaS, no API metering. Storage is a single SQLite file plus the pipeline's existing transcript directory.

### Development & delivery

- **Git:** `GITFLOW` without automatic push (`settings.git_mode`) — `feature/US-XXX-*` branches off `develop`, one atomic Conventional Commit per task, internal PR description prepared toward `develop`; nothing pushed unless explicitly requested.
- **Testing (Anne):** `settings.testing: NORMAL` — PHPUnit feature and unit coverage for sync, recurrence identity, timezone handling, resolver precedence, launcher argument construction, and the Livewire note/prompt components. HTTP fakes for the ICS feed, `Process::fake()` for the launcher, and fixture `.ics` files covering recurring series, cancellations, all-day events, and DST transitions. No Playwright, Dusk, or viewport matrix at this bar.
- **Factories and seeders:** factories for every domain model; a seeder producing a realistic fixture calendar plus sample transcript files, so the interface can be developed without a live feed.
- **Quality gate:** `php artisan larapilot:quality` (Pint + Larastan level 5+) before each task commit; `composer audit` after any `composer require`.
- **CI/CD:** none configured — no remote forge is enabled (`settings.github: NO`). The local quality gate is the gate. Revisit if the repository gains a remote.

### Documentation _(Albert)_

Baseline set only, matching a personal project: a `README.md` covering Herd setup, the full `.env` configuration surface, how to start the queue worker and scheduler, the **two-argument contract** the launcher script must satisfy, and `kbms:doctor` as the first troubleshooting step. No OpenAPI (no public API), no diagrams, no client manuals.

## PRD Revision History

| Date | Trigger | Summary |
| --- | --- | --- |
| 2026-09-07 | larapilot-inception | Initial PRD — Personal / MVP: ICS mirror, agenda + calendar, meeting notes, transcript linking, local Claude Code launch bridge, Outlook CTA |
| 2026-09-07 | larapilot-plan US-003 | Added `KBMS_SYNC_RUN_RETENTION_DAYS` to the configuration surface; added `last_modified` to the `calendar_sync_runs` column list (required to serve `If-Modified-Since` on the next conditional GET) |
| 2026-09-08 | larapilot-plan US-005 | Recorded the `KBMS_OUTLOOK_URL_TEMPLATE` placeholder vocabulary |
| 2026-09-09 | larapilot-plan US-007 | Added `KBMS_TRANSCRIPT_TOLERANCE_MINUTES` (default `10`) and `KBMS_TRANSCRIPT_PREVIEW_BYTES` (default `2097152`) to the configuration surface; documented the `KBMS_TRANSCRIPT_PATTERN` `{time}` = `Hi` grammar (deliberately unlike `KBMS_OUTLOOK_URL_TEMPLATE`'s `H:i`); marked `meeting_transcripts.path` nullable, carrying the manual-unlink tombstone |
| 2026-09-10 | larapilot-plan US-009 | Added `KBMS_LAUNCH_TIMEOUT_SECONDS` (`30`) and `KBMS_QUESTION_MAX_CHARS` (`8000`) to the configuration surface; added `exit_code` to `prompt_launches`; recorded that the queued job waits for the launcher's exit code under a bounded timeout, superseding the inception "Process::start" detail |
| 2026-09-10 | larapilot-feature US-010 | Changed default `KBMS_TRANSCRIPT_PATTERN` to `{date}_{time}_{slug}.md` with `{date}` as `Ymd` and underscore-separated, special-character-stripped `{slug}`; ordering now favors a file-slug-starts-with-event-slug prefix match over exact equality; hard cutover — the old hyphenated/`Y-m-d` convention is no longer recognized, superseding the 2026-09-07 convention decision |
