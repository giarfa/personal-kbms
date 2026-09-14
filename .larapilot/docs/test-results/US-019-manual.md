# US-019 — Manual test handoff

**Spec:** Keyboard shortcut to launch a Claude Code session
**Testing bar:** `NORMAL` — no browser automation. Launch parity with the button, the blocked no-op, and the markup/accessibility contract are covered automatically in `tests/Feature/AskClaudePanelTest.php`; the chord label vocabulary is covered in `tests/Unit/Launcher/LaunchChordTest.php`. What remains is the true keystroke round-trip, which only reproduces in a real browser.

Run `npm run build` first. Open a meeting detail page at https://personal-kbms.test with a linked, readable transcript (a ready **Ask Claude Code** panel — button enabled, no block reason shown).

---

## 1. The chord launches with the full typed text, even fired immediately

1. Click into **Your question**, type a question, and press `Cmd`+`Enter` (macOS) or `Ctrl`+`Enter` (other platforms) **the instant** you finish typing — do not pause.
2. **Expect:** exactly one new row appears under **Dispatched**/**Queued**, and the invocation shown (or the eventual terminal window) carries the **full** text you typed — not a truncated or empty question.
3. **Failure mode to watch for:** this is the US-014 race restated for the keyboard path — `wire:model.live.debounce.400ms` can lag up to 400ms behind the last keystroke. If the chord ever launches with a stale or empty question, that debounce is being consulted somewhere it should not be.

## 2. Bare `Enter` and `Shift`+`Enter` never launch

1. In the question field, type some text, then press plain `Enter`.
2. **Expect:** a newline is inserted, cursor moves to the next line, nothing is dispatched.
3. Repeat with `Shift`+`Enter`.
4. **Expect:** same — newline only, no launch.

## 3. The chord is inert outside the question field

1. Focus the notes editor and press the chord.
2. **Expect:** nothing happens — no new row, no navigation, no console error.
3. Focus somewhere in the transcript panel (e.g. the scrollable region) and press the chord.
4. **Expect:** same — nothing happens.

## 4. Holding the chord, or repeating it mid-launch, never duplicates

1. Press and hold the chord down for two or three seconds (OS auto-repeat will re-fire the keydown event).
2. **Expect:** exactly **one** queued row, not one per repeat.
3. While a launch is still showing **Queued**, press the chord again with a valid question.
4. **Expect:** no second row is created while the first is in flight.

## 5. A blocked launcher stays silent on the chord too

1. Unset `KBMS_CLAUDE_LAUNCHER` (or otherwise put the panel into a blocked state) and reload the page.
2. Type a question and press the chord.
3. **Expect:** nothing happens — no new row, no new message, no validation error. The existing on-screen block reason is the only thing on screen, exactly as if the (disabled) button had been clicked.

## 6. The hint reads correctly for the platform

1. On macOS, look beside **Launch session**.
2. **Expect:** a small chip reading `⌘ Enter` followed by "to launch", visible only while the launcher is ready.
3. On Windows or Linux (or a browser reporting a non-Apple platform), the same chip should read `Ctrl Enter`.
4. With a screen reader, move to the question field.
5. **Expect:** its accessible description includes a sentence naming the chord (e.g. "Press Command-Enter to launch the session.") in addition to the existing help text — the chip itself should not be announced twice (it is `aria-hidden`).

## 7. Responsive check

At 375 / 768 / 1280 px, confirm the chord chip and "to launch" note sit alongside **Launch session** without crowding or overlapping the existing "Queued · opens its own terminal window …" note; `.kb-inline` should wrap normally below 768 px.
