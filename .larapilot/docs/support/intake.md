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
