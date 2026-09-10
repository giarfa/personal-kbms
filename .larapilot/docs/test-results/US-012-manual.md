# US-012 — Manual test handoff

**Spec:** Rule-based event colouring on the calendar and agenda
**Testing bar:** `NORMAL` — no browser automation. The rule engine, both surfaces' markup, the config-generated legends, and the query count are covered automatically in `tests/Unit/Meetings/EventColourRulesTest.php`, `tests/Feature/AgendaColourTest.php` and `tests/Feature/CalendarColourTest.php`. What remains is the part only eyes can settle: whether the colours actually read.

Run `npm run build` first. The shipped defaults in `config/kbms.php` (PING → yellow, no location → purple) are enough for every check below; seed a couple of meetings matching each.

---

## 1. Both fills are legible in both themes

1. Open `/` and `/calendar` in light mode, then switch to dark with the header theme toggle.
2. **Expect:** the yellow and purple fills are clearly visible against the page in both themes, and the text sitting on a coloured calendar chip is comfortably readable.
3. **Expect:** neither fill looks like an error or a warning state — compare against a cancelled event (dashed, struck, muted) and against the sync pill's stale/failed colours.
4. **Why this needs eyes:** contrast is inherited by construction — every `--kb-rule-*` token uses the same oklch lightness pair as the already AA-verified `--kb-note` / `--kb-transcript` tokens (`0.45` on `0.955` light, `0.80` on `0.28` dark), so the ratio is ≈4.8:1 and ≈5.9:1 respectively. That guarantees the *number*; it does not guarantee the fills look right beside each other.

## 2. Rule colours stay distinguishable from the coverage hues

1. Put four meetings side by side in a month view: a PING one, a locationless one, one with notes, one with a transcript.
2. **Expect:** the yellow and purple fills are not confusable with the note teal (hue 205) or the transcript indigo (hue 285). Purple is deliberately pushed to hue 320 for exactly this reason.
3. **Expect:** the same holds in dark mode, where the tints are darker and closer together.

## 3. The three channels render together

1. Create one meeting that is a PING **and** has notes **and** a linked transcript.
2. **Expect on `/calendar`:** yellow fill, note circle and transcript square marks still visible on top of it, and the left/right coverage borders still present. All three legible at once.
3. **Expect on `/`:** yellow row fill with the `Notes` and `Transcript` tags still readable against it.
4. Now cancel that meeting upstream and resync.
5. **Expect:** cancelled wins — dashed border, struck title, muted text on both surfaces. The colour fill must not survive it.

## 4. Legends match the rendering

1. Compare each legend swatch on `/` and on `/calendar` against the actual fill of a matching event.
2. **Expect:** same colour, both themes, both pages. The two legends list the same rules in the same order.
3. Add a third rule to `config/kbms.php` (e.g. `summary contains retro → green, label "Retro"`).
4. **Expect:** it appears in **both** legends with no code change, and a matching meeting picks up the green fill.
5. Remove every rule from the array.
6. **Expect:** both pages render exactly as they did before this spec — no legend line, no fills.

## 5. First match wins, visibly

1. Create a meeting titled `PING offsite` with **no** location, with the shipped default order.
2. **Expect:** yellow, not purple.
3. Swap the two rules in the array.
4. **Expect:** the same meeting turns purple. Array order is the only reprioritising mechanism.

## 6. A broken rule breaks nothing

1. Set a rule with a made-up colour (`'colour' => 'chartreuse'`) or a raw CSS value (`'#ff0000'`).
2. **Expect:** `/` and `/calendar` both still render, the affected events are simply uncoloured, and the rule does not appear in either legend. No raw colour value appears anywhere in the page source.

## 7. Screen-reader check (optional but cheap)

1. With VoiceOver (or any screen reader), move onto a coloured agenda row and a coloured calendar event.
2. **Expect:** the announcement includes the rule label alongside the coverage words — e.g. "09:00 PING weekly, has notes, Ping". Colour is never the only carrier.

## 8. The meeting detail page stays uncoloured

1. Open a PING meeting at `/meetings/{occurrence}`.
2. **Expect:** no rule colour anywhere on the page. This is deliberate and enforced by `AgendaRow`'s constructor default, not by a Blade omission.

## 9. Responsive regression

At 375 / 768 / 1280 px, confirm the legend wraps cleanly on both pages and that a coloured agenda row's three columns still stack correctly below 768 px.
