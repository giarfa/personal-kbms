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
