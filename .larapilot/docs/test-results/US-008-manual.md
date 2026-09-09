# US-008 — Manual test handoff

Automated coverage (`php artisan test`, `NORMAL` bar) is in `tests/Unit/Meetings/CalendarRangeTest.php`, `tests/Unit/Meetings/CalendarFeedQueryTest.php`, `tests/Feature/CalendarFeedTest.php`, and `tests/Feature/CalendarPageTest.php`. No Playwright, Dusk, Pest browser, or viewport-matrix suite exists at this bar — FullCalendar's rendered DOM and keyboard behaviour need a human pass at `http://personal-kbms.test/calendar`.

## Keyboard-only pass (all three views)

For each of Month, Week and Day:

- `Tab` from the toolbar into the grid. Exactly one day cell (or column, in Week/Day) should receive focus, with a visible focus ring.
- Arrow keys move focus: left/right by one day; in Month view, up/down by one week.
- `Home` / `End` jump focus to the first / last day of the current row (the visible week in Month, the visible week in Week view).
- `PageUp` / `PageDown` step to the previous/next range (month, week, or day) and land focus on the corresponding day.
- Moving past the edge of the visible range (e.g. arrowing right past the last visible day) navigates the calendar forward and lands focus on the correct day in the newly rendered range.
- `Tab` from a focused day cell reaches its event chips in order; `Enter` on a chip opens the meeting detail page.
- On a day with a `+N more` link: activate it with `Enter`/`Space`, confirm focus moves into the popover, `Esc` closes it and returns focus to the `+N more` control.

## Screen reader pass

- The grid announces the current view and range on load and after every navigation (e.g. "September 2026, month view").
- Each event chip's accessible name states time (or "All day"), title, and coverage in words — e.g. "09:30 Q4 roadmap review, has notes and transcript" — never relying on the coloured/shaped marks alone.
- The view switcher (Month/Week/Day) exposes pressed state (`aria-pressed`) that updates as the view changes.
- Navigating (prev/next/today/view change) triggers a polite announcement of the new range label.

## Theme toggle

- Toggle light/dark and confirm every event state is legible against the grid background: bare, has-notes, has-transcript, has-both, all-day, cancelled, and today's cell highlight.
- Confirm the coverage marks (circle for notes, square for transcript) are visible in both themes, not just implied by colour.

## 375 px pass

- Month view is the only surface that scrolls horizontally; nothing is clipped and no control (toolbar buttons, segmented switcher) is clipped or falls below the 44 px minimum touch target.
- Week and Day views fit without horizontal scroll.

## DST week visual check

- Navigate Week view to the week containing **25 October 2026** (`Europe/Rome` DST end). Confirm the week still shows exactly seven days and any events that day keep their expected wall-clock time (no hour shift).

## Back-navigation state

- From the Agenda page, open "Calendar view", switch to Week and navigate a few weeks forward, then click into a meeting and press Back. Confirm the calendar returns to the same view and date rather than resetting to today's month.

## Empty state

- Navigate to a range with no mirrored meetings. Confirm the copy reads the sync-health-aware sentence ("The mirror is current — this range is genuinely empty rather than unsynced." or the appropriate never-synced/stale variant), not a bare "no events".
