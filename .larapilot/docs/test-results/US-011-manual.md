# US-011 — Manual test handoff

**Spec:** Self-refreshing agenda and calendar views
**Testing bar:** `NORMAL` — no Playwright, Dusk, Pest browser, or viewport matrix. The automated half lives in `tests/Feature/AgendaSelfRefreshTest.php`; everything below is browser behaviour with no server seam and no JS test runner in this project (`package.json` ships Vite only).

Run `npm run build` (or `composer run dev`) first, and have a queue worker up so `kbms:sync-calendar` can actually land new rows.

---

## 1. Cadence — the agenda brings itself up to date

1. Open `http://personal-kbms.test/` and leave it alone.
2. In a terminal, insert or retitle an occurrence inside the visible week — either let a real sync run, or `php artisan tinker --execute 'App\Models\CalendarEvent::factory()->at(now("Europe/Rome")->addHours(2), 30)->create(["summary" => "Manual refresh probe"]);'`
3. **Expect:** the meeting appears in its day group within ~60 seconds, with no click and no page reload.
4. **Failure mode:** nothing appears — check the browser console for a JS error in `self-refresh.js`, and confirm the root `<div>` on `/` carries `x-data="kbSelfRefresh"`.

## 2. Cadence — the calendar refetches in all three views

Repeat step 1 on `http://personal-kbms.test/calendar`, once per view (Month, Week, Day).

- **Expect:** the new meeting appears in the grid within ~60 seconds; a note or transcript added to an existing meeting changes its coverage marks in place.
- **Expect:** the DevTools Network panel shows one `calendar/events?from=…&to=…` request per minute, for the **visible** window only — never a wider range.
- **Failure mode:** repeated requests faster than 60s means two subscriptions are live; check for a duplicated `onRefreshTick` registration.

## 3. The refresh is silent

On both pages, watch a refresh land.

- **Expect:** no toast, no banner, no flash message, no spinner, no full-page reload, and no screen-reader announcement.
- **Expect:** scroll position is unchanged. Scroll to the bottom of a dense agenda week and wait out a tick — the viewport must not jump.
- **Expect (week/day calendar):** the hour-grid scroll offset is unchanged across a refetch.

## 4. View state survives a refresh

**Agenda:**

1. Navigate back two weeks with `‹`, then click into the **Jump to** date field and leave the cursor in it.
2. Wait out a refresh.
3. **Expect:** still on the same past week (no snap back to today), the date field still holds its value, and focus is still inside it.

**Calendar:**

1. Switch to Week view, page to a different week, then tab into the grid and arrow-key onto a specific day cell.
2. Wait out a refetch.
3. **Expect:** still Week view, still the same week, the URL still carries the same `?view=` and `?date=`, and focus is still on that day cell — arrow keys keep working from where you left off.

## 5. Time-derived chrome keeps moving

1. Open `/` a few minutes before a meeting starts and leave the tab open across its start time.
2. **Expect:** the `Now · HH:mm` divider re-clocks itself and moves past the meeting once it has started — it must not stay frozen where it was at first paint.
3. **Expect:** on a page left open for an hour or more, the sync pill's relative wording ("Synced 4 minutes ago" → "an hour ago") keeps up.

## 6. Hidden-tab pause and catch-up

1. Open `/` (or `/calendar`) with DevTools Network open and filtered to XHR/fetch.
2. Switch to another tab (or another window that fully hides this one) for three or four minutes.
3. **Expect:** **no** refresh requests are issued while the tab is hidden. The sync indicator's own `wire:poll` may still trickle a request occasionally — that is Livewire's own background throttle and is out of scope here; what must be absent is the agenda `$refresh` / `calendar/events` traffic.
4. Switch back.
5. **Expect:** exactly **one** catch-up request fires immediately on return, and the view is current — not a wait of up to another 60 seconds.
6. Switch away and back again inside the same minute, before any tick was missed.
7. **Expect:** no catch-up request — returning to a tab that missed nothing costs nothing.

## 7. Quiet degradation

1. With `/` open, stop the web server (or `herd stop`), and wait out two or three ticks.
2. **Expect:** the agenda keeps its last good render. **No** Livewire full-screen HTML error modal, **no** "This page has expired" confirm, no blank page, no empty state.
3. Do the same on `/calendar`.
4. **Expect:** the grid keeps the events it last fetched. It must not blank, and the "Nothing in this range" block must not appear.
5. Bring the server back up.
6. **Expect:** the next tick quietly restores normal behaviour — no reload needed.
7. Now break the *feed* instead: point `KBMS_ICS_URL` at an unreachable host and force a resync.
8. **Expect:** the sync pill and the sync alert report the failure exactly as they did before this spec (FR-010). The agenda and calendar content itself does not change and shows no additional error.

## 8. The meeting detail page is deliberately excluded

1. Open a meeting at `/meetings/{occurrence}` and start typing in the notes editor.
2. Leave it for two or three minutes without submitting.
3. **Expect:** no timed re-render interrupts typing, and the draft is not disturbed. The only polling on this page is `AskClaude`'s existing short-lived poll while a launch is queued — unchanged by this spec.

## 9. Contrast / theme sanity

This spec adds no colour and no new visual element, so the only check is a regression one: the agenda and calendar still render correctly in both light and dark themes after a refresh, at 375 / 768 / 1280 px.
