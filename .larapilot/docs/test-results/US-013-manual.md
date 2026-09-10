# US-013 — Manual test handoff

**Spec:** Calendar-as-todo — checkbox title convention with time-aware status
**Testing bar:** `NORMAL` — no browser automation. Parsing, the overdue boundary, all three surfaces, channel independence, the self-refresh seam and the query count are covered automatically in `tests/Unit/Meetings/MeetingTodoTest.php` and `tests/Feature/TodoRenderingTest.php`. What remains is what only eyes can settle.

Run `npm run build` first. Create four events in Outlook (or via tinker) covering: an open item later today, an open item that has already ended, a `[x]` done item, and an ordinary meeting.

---

## 1. Glyphs are legible at both sizes, in both themes

1. Open `/` and `/calendar`, then switch themes with the header toggle.
2. **Expect:** `☐`, `☑` and `☐ !` are legible on an agenda row **and** inside a month-view chip, where the font is `0.75rem` and the chip may be narrow.
3. **Expect:** the glyph never wraps away from its title, and never pushes the title out of a month cell on its own.
4. **Failure mode to watch for:** on a system without the ballot-box glyphs, the browser will substitute — check the fallback still reads as a checkbox rather than as a tofu box.

## 2. Overdue is distinguishable from everything already red-ish

Put these side by side and compare:

1. An **overdue** todo (hue 40, bold, `☐ !`).
2. A **cancelled** meeting (dashed, struck, muted — and, on the agenda, a red `Cancelled` tag).
3. A meeting with **notes** (teal) and one with a **transcript** (indigo).
4. The sync pill in its **stale** (amber) and **failed** (red) states.

**Expect:** overdue reads as its own thing against all four, in both themes. It should say "late", not "broken" and not "cancelled".

**Note:** a cancelled occurrence is never overdue — that is guarded in the parser — so you will never see both treatments on the same row. If you ever do, that is a bug.

## 3. Done recedes without disappearing

1. Look at a `[x]` item among open ones.
2. **Expect:** muted and struck through, clearly secondary in the scan, but still readable — not so faint it becomes invisible against the card in either theme.

## 4. All three channels at once

1. Create `[] PING sync`, with no location, with notes on it, scheduled to have already ended.
2. **Expect on `/` and on `/calendar`:** the overdue todo treatment, the yellow rule fill (PING is listed before the no-location rule), **and** the note coverage mark/tag — all three legible simultaneously, none swallowing another.
3. **Expect:** the rule fill is still visible *under* the todo styling. If the fill has vanished, a todo CSS rule has grown a `background`, which the channel contract forbids.

## 5. No layout shift on ordinary meetings

1. Put an ordinary meeting directly above and below a todo on the agenda.
2. **Expect:** the ordinary rows have **no** glyph and **no** reserved gutter — their titles start exactly where they did before this feature. The todo's title is simply indented by its glyph.

## 6. The overdue flip, live

1. Open `/` a couple of minutes before an open todo's end time and leave the tab alone.
2. **Expect:** within about a minute of it passing, the row turns itself over to the overdue treatment — no reload, no click. This is US-011 and US-013 working together, and it is the single most satisfying thing to see confirmed.
3. Repeat on `/calendar`.

## 7. The detail page shows both truths

1. Open an overdue todo at `/meetings/{occurrence}`.
2. **Expect in the heading:** the glyph, the title **without** the marker, and a visible status tag.
3. **Expect in the mirror panel:** the summary exactly as the feed sent it — `[] Call the vendor`, marker intact.
4. **That difference is deliberate.** The heading is the product's reading of the title; the mirror panel is the feed's own text, which FR-004 requires be shown verbatim. Do not report it as an inconsistency.
5. **Expect:** the browser tab title and the breadcrumb both use the stripped title.

## 8. Screen-reader check (optional but cheap)

1. Move onto an overdue, rule-coloured, annotated occurrence.
2. **Expect:** the announcement carries coverage, then the rule label, then the status — e.g. "09:00 PING sync, has notes, Ping, To do, overdue". Neither the glyph nor the colour is the only carrier.

## 9. Outlook round trip

1. Tick an item in Outlook by editing its title from `[]` to `[x]`.
2. Wait for a sync (or force one from the pill).
3. **Expect:** the item turns done on the next self-refresh. Nothing in this application can change it — there is no toggle, by design, because the product cannot write to the calendar (FR-021).

## 10. Responsive regression

At 375 / 768 / 1280 px, confirm the three-entry todo legend wraps cleanly on both pages and that a todo row's columns still stack correctly below 768 px.
