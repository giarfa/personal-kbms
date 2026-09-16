# Support Intake Log

## BUG-20260911-launch-button-not-clickable

- **Reported:** 2026-09-11
- **Severity:** High
- **Environment:** Local
- **Summary:** The "Launch session" button on the Ask Claude Code form stays non-clickable even after a question is typed into the textarea.
- **Steps to reproduce:**
  1. Open a meeting detail page with a linked transcript.
  2. Type any text into the "Your question" textarea.
  3. Observe the "Launch session" button remains disabled/non-clickable.
- **Expected / Actual:**
  - Expected: once non-whitespace question text is present, the button becomes clickable.
  - Actual: button stays disabled; the helper text below still reads "Type a question first — whitespace only is rejected before anything is dispatched."
- **Root cause (confirmed via code read):** `resources/views/livewire/ask-claude.blade.php:53` disables the button with a native HTML `disabled` attribute driven by the server-computed `$command` (`app/Livewire/AskClaude.php:89-98`, backed by `LaunchPreflight::for()`). The textarea binds with plain `wire:model="question"` (no `.live`/`.blur`) at `ask-claude.blade.php:27`, which is deferred in Livewire 3 — typing never triggers a server round-trip, so `$command` is never recomputed and the native `disabled` attribute (which blocks `wire:click` from firing at all) never clears.
- **Affected spec:** US-009 (DONE — regression)
- **Routed to:** spec-add US-014 (US-009 was DONE, so `spec-request-changes` was not available; a new fix spec was created instead, referencing US-009)
- **Security:** No — Lars/Oliver not tagged

## BUG-20260911-context-txt-sibling-unverified

- **Reported:** 2026-09-11
- **Severity:** High
- **Environment:** Local
- **Summary:** `LaunchPreflight` verifies the resolved `.md` transcript (containment + readability) and then hands the launcher script a *different* file — the `.txt` sibling — with no existence or readability check and no blocked reason for its absence. Surfaced during the US-014 review, where 14 tests on `develop` were found failing against the newly merged `.txt` swap.
- **Steps to reproduce:**
  1. Link a meeting to a transcript whose `.md` file exists under `KBMS_TRANSCRIPTS_PATH` but which has **no** `.txt` sibling (delete the sibling, or pick an `.md` the pipeline produced alone).
  2. Open the meeting detail page and type a valid question.
  3. Observe the "Launch session" button is enabled and the exact-invocation preview shows a `.txt` path that does not exist on disk.
  4. Launch. The job dispatches and the script is invoked with a path to nothing — no block reason is ever shown.
- **Expected / Actual:**
  - Expected: the path handed to the launcher carries the same verify-before-offer guarantee as the resolved path (FR-006); a missing or unreadable sibling is refused with its own named reason, like every other blocked state.
  - Actual: the swap is blind. `LaunchPreflight::withTxtExtension()` rewrites the extension after all checks have run, so every guarantee applies to a file the script never opens.
- **Corrected finding:** the initial US-014 review note claimed nothing creates the `.txt` on disk. That is wrong — `config/kbms.php:47` records that the operator's pipeline emits a `.md` + `.txt` pair, and `transcript_extensions` was narrowed to `['md']` to stop every meeting reading Ambiguous (decision journal `14f91842c55d2d7f`, 2026-09-10). Verified against `/Users/excellence_innovation/Recordings/protocols`: 68 `.md`, 70 `.txt`, **zero** `.md` lacking a `.txt` sibling. The runtime path is correct today; the defect is the missing guarantee, plus a red suite.
- **Test evidence:** `develop` at `fca3fd4` — 518 passed / 14 failed. `LaunchClaudeSessionJobTest::test_every_adversarial_question_reaches_the_process_as_a_single_intact_argument` (13 datasets) and `AskClaudePanelTest::test_the_rendered_invocation_matches_launch_command_display_for_an_adversarial_question` still assert the `.md` path. Pint and Larastan pass.
- **Affected spec:** US-009 (DONE — FR-008 launch bridge); introduced by commit `d6d8ea5`, merged alongside US-014
- **Routed to:** spec-add US-015 (new fix spec; US-009 and US-014 are both DONE, so `spec-request-changes` was unavailable)
- **PRD:** requirement gap confirmed — FR-006 and FR-008 clarified to record the sibling contract and its verify-before-offer guarantee; PRD Revision History row added 2026-09-11
- **Security:** No — Lars consulted (the sibling stays inside `KBMS_TRANSCRIPTS_PATH`, so containment is not bypassed; the gap is silent failure, not escape)

## BUG-20260914-queue-worker-stale-config

- **Reported:** 2026-09-14
- **Severity:** High
- **Environment:** Local (the only environment this product has)
- **Summary:** Every Claude Code launch is refused with `` `KBMS_CLAUDE_LAUNCHER` is not configured. `` while the same page's panel badge reads **Launcher ready** and renders the full invocation with the correct script path. The two surfaces disagree because they run in two different processes: the panel resolves config in the web request, the job resolves it inside a long-lived queue worker that booted before the value existed.
- **Steps to reproduce:**
  1. Start the queue worker (LaunchAgent `com.personal-kbms.queue-worker`, `KeepAlive: true`).
  2. Add or change `KBMS_CLAUDE_LAUNCHER` in `.env` **without** running `php artisan queue:restart`.
  3. Open any meeting with a linked transcript, e.g. `/meetings/MDQwMDAwMDA4MjAwRTAwMDc0QzVCNzEwMUE4MkUwMDgwMDAwMDAwMEQyRDEyOTI2QUIzQUREMDEwMDAwMDAwMDAwMDAwMDAwMTAwMDAwMDA4MjZFRUNFRDIyNzgzMjQ3OTA3RjBFMzYxQTQzMUQzRA.MjAyNi0wOS0xNFQwNzoyMTowMFo`.
  4. Type a question and press **Launch session**.
  5. The panel still shows **Launcher ready** and the exact invocation; the launch row lands `blocked` with `` `KBMS_CLAUDE_LAUNCHER` is not configured. ``
- **Expected / Actual:**
  - Expected: the launch runs; or, if it genuinely cannot, the refusal names the real cause and the panel does not claim readiness it cannot deliver.
  - Actual: a launch that every operator-visible signal said was ready is refused with a message that contradicts `.env`, `kbms:doctor`, and the panel's own invocation preview. The operator has no way to reach the true cause from the interface.
- **Root cause (confirmed in-session):** `LaunchClaudeSession` deliberately re-runs `LaunchPreflight` inside the worker (`app/Jobs/LaunchClaudeSession.php:52`) — correct by design, since the script and transcript are mutable between dispatch and execution. But `queue:work` is a long-lived process that resolves `config('kbms.claude_launcher')` from the `.env` read **at boot**. Worker PID 92058 started **Thu 2026-09-10 09:50**; `.env` was last written **2026-09-11 09:54** (`storage/app/launcher/claude-launcher.sh` was itself created 09:31 that day). No `queue:restart` was ever broadcast. So the job saw `null` where the web request saw the path.
- **Evidence:** `.env:74` set and the script `-rwxr-xr-x`; `php artisan kbms:doctor` reports `Claude launcher script .. PASS` (it boots a fresh process, so it is structurally blind to this failure); `prompt_launches` shows every real launch since 2026-09-11 14:30 `blocked` with this message — the `launched`/`failed`/`queued` rows at 2026-09-11 12:28:46 are `PromptLaunchSeeder` fixtures, not real runs.
- **Immediate unblock (applied 2026-09-14):** `php artisan queue:restart` — launchd restarted the worker as PID 94319 with the current `.env`. Launching works again. This is a workaround, not the fix.
- **Affected spec:** US-009 (DONE — FR-008 launch bridge) and US-002 (DONE — FR-011 doctor); neither is a code regression, both are a missing guarantee
- **Routed to:** spec-add US-016 (US-002 and US-009 are both DONE, so `spec-request-changes` was unavailable)
- **PRD:** requirement gap confirmed — FR-008 and FR-011 clarified to record that the queued job must resolve the same configuration the panel offered, and that the doctor must verify the worker's **live** configuration rather than mere reachability; PRD Revision History row added 2026-09-14
- **Security:** No — Lars consulted. Nothing escapes a boundary and no input is trusted differently; the defect is a silent, misattributed failure across a process boundary.
- **Housekeeping:** `app/Launcher/LauncherScript.php` carries two uncommitted duplicate `\Log::debug()` lines from the operator's own debugging, flooding `laravel.log`. Not part of the defect — remove before the fix branch.

## BUG-20260916-teams-join-url-trailing-delimiter

- **Reported:** 2026-09-16
- **Severity:** High
- **Environment:** Local (the only environment this product has)
- **Summary:** The **Join Teams meeting** button points at a URL with one trailing character too many. The stored `join_url` ends `…%7d>` and the browser percent-encodes that `>` into the href as `%3E`, so the link resolves to a Teams URL Microsoft does not recognise. Every meeting whose join link was harvested from the `DESCRIPTION` free text is affected — **940 of 942** rows carrying a `join_url` (155 distinct URLs, occurrences spanning `2026-06-15` → `2027-03-15`).
- **Steps to reproduce:**
  1. Open any meeting whose Teams link came from the description, e.g. `/meetings/MDQwMDAwMDA4MjAwRTAwMDc0QzVCNzEwMUE4MkUwMDgwMDAwMDAwMDdBNjAxQ0Q4RkQzQUREMDEwMDAwMDAwMDAwMDAwMDAwMTAwMDAwMDAzNjg0QzAyRUNEMzFFNzQ3QUU2RjY3NzcxRENGM0YyRA.MjAyNi0wOS0xNlQwODowMDowMFo`.
  2. Inspect the **Join Teams meeting** anchor (`resources/views/components/meeting/external-links.blade.php:16`) or press it.
  3. The href ends `…%22%7d%3E` where the working Teams link ends `…%22%7d`.
- **Expected / Actual:**
  - Expected: `https://teams.microsoft.com/l/meetup-join/19%3ameeting_NjhkMjAyZWYtYTRkYy00MjcxLTgzNTAtZDgzMTdhMzYzNmI4%40thread.v2/0?context=%7b%22Tid%22%3a%22…%22%2c%22Oid%22%3a%22…%22%7d`
  - Actual: the same URL with a trailing `%3E` appended.
- **Root cause (confirmed in-session):** `app/Calendar/IcsParser.php:18` — `TEAMS_LINK_PATTERN = '#https://teams\.microsoft\.com/l/meetup-join/\S+#i'`. `\S+` is greedy over *every* non-whitespace character, so when Outlook wraps the link in its customary angle brackets (`<https://teams.microsoft.com/…>`) inside `DESCRIPTION`, the closing `>` is captured as part of the match. `readJoinUrl()` returns `$matches[0]` verbatim with no delimiter trimming. The `X-MICROSOFT-SKYPETEAMSMEETINGURL` branch above it is unaffected — it reads a structured property, which is why 2 rows are clean.
- **Evidence:** `DB::table('calendar_events')->where('join_url','like','%>%')->count()` → **940**; `whereNotNull('join_url')->count()` → **942**. `OutlookLinkBuilder::joinUrl()` (`app/Meetings/OutlookLinkBuilder.php:34`) passes the value through `isSafeUrl()` unchanged — scheme and host are valid, so the guard has nothing to object to; it is not the defect and not a security hole.
- **Affected spec:** US-003 (DONE — FR-001 ICS ingestion; the `join_url` capture AC). US-005 (DONE) renders it but does not produce it.
- **Routed to:** spec-add US-020 (US-003 and US-005 are both DONE, so `spec-request-changes` was unavailable)
- **Backfill:** **re-sync only** — operator's explicit choice, decision journal `f2afd78b2c6575f1`. `join_url` is part of the occurrence content hash (`app/Calendar/ParsedOccurrence.php:39`), so the **915** affected occurrences inside the sync window self-heal on the next `kbms:sync-calendar` once the parser is fixed. The **25** occurrences already older than `KBMS_ICS_WINDOW_PAST_DAYS` (`starts_at < 2026-06-18`) are never re-parsed and keep their malformed link permanently — accepted. No data migration, no read-time normalisation.
- **PRD:** no gap — implementation fix only, decision journal `acfb777dc90ee720`. US-003 already requires `join_url` capture from `URL` / `X-MICROSOFT-SKYPETEAMSMEETINGURL` / a Teams link in the description; extracting it *correctly* is implied, not a new requirement.
- **Security:** No — Lars consulted. The malformed value never escapes the Teams host, `isSafeUrl()` still constrains scheme and host, and the extra character is inert in an `href`. The defect is a broken primary action, not an injection or escape. Oliver not engaged.
