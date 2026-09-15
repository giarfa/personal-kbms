# Mockups — Personal KBMS (full app UI set)

Visual contract for the whole product interface: app shell, sync health, agenda, meeting detail, calendar views. One folder rather than one per spec, because these surfaces are a single continuous interface — a per-spec split would have produced five mutually inconsistent shells.

Browse at **`/mockups/kbms-app`** (dev/local only). Nothing here touches application code.

| File | Screen | Specs covered |
| --- | --- | --- |
| `index.html` | **Agenda** — light, primary reference | US-004 (sync pill), US-005 (agenda, coverage, empty day) |
| `dark.html` | Agenda — dark twin, for contrast comparison | same |
| `meeting.html` | **Meeting detail** — mirrored feed panel vs. operator-owned panels | US-005, US-006, US-007, US-009, FR-012 |
| `calendar.html` | **Calendar** — month + week + day, coverage indicators | US-004 (stale), US-008 |
| `states.html` | **Component states** — every loading / empty / stale / blocked / broken state the acceptance criteria name | US-004…US-009 |
| `mobile.html` | Narrow-viewport companion — real screens in 375 px frames + nav drawer | all |
| `favicon.svg` | Brand mark (the only brand asset this product gets) | US-001 |
| `starter-kit-tokens.css` | Copied verbatim from `.larapilot/design-systems/starter-kit/tokens.css` | — |
| `kbms.css` | Project layer — additive `--kb-*` tokens and components only | — |
| `theme.js` | Mockup-only light/dark toggle | — |

Open `states.html` and press the theme button in the header to check every error and warning surface in dark as well as light.

---

## Design system

**Laravel Starter Kit — `livewire` variant (Flux UI).** Recorded in the PRD `## Technical Architecture` and in the decision journal (`ui stack / admin panel`).

- Reference: [`.larapilot/design-systems/starter-kit/`](../../design-systems/starter-kit/) — `README.md`, `components.md`, `sources.md`, `html/`
- Upstream: [laravel/livewire-starter-kit](https://github.com/laravel/livewire-starter-kit) · [starter-kits docs](https://laravel.com/docs/starter-kits)
- Layout variant: **sidebar** (`sk-layout` + `sk-sidebar` + `sk-inset`), breadcrumb header strip
- Font: **Instrument Sans** (kit default), loaded from fonts.bunny.net
- No Filament, no Bootstrap, no Nordic-minimal marketing language — this app has no public pages at all

### One deliberate departure from the kit

**There is no user menu and no auth layout.** US-001 removes starter-kit authentication outright — no `User` model, no login views. The sidebar footer where the kit puts the avatar dropdown instead carries a short environment block (`127.0.0.1`, timezone, SQLite, "no accounts"). Alex must delete the kit's user-menu partial rather than stub it with a placeholder name, or a login surface will creep back in.

---

## Theme tokens

Starter Kit tokens are used **unmodified**. `kbms.css` only adds `--kb-*` values; it never redefines a kit token, so a kit upgrade cannot be silently overridden by this layer.

| Token | Light | Dark | Meaning |
| --- | --- | --- | --- |
| `--kb-mirror-bg` | `oklch(0.975 0.004 250)` | `oklch(0.185 0.006 250)` | Surface for **feed-derived, read-only** data |
| `--kb-mirror-border` | `oklch(0.90 0.008 250)` | `oklch(0.30 0.01 250)` | Dashed rule on the mirror panel |
| `--kb-note` | `oklch(0.45 0.085 205)` | `oklch(0.80 0.09 205)` | "Has notes" — teal |
| `--kb-transcript` | `oklch(0.44 0.095 285)` | `oklch(0.79 0.10 285)` | "Has transcript" — indigo |
| `--kb-ok` | `oklch(0.45 0.095 155)` | `oklch(0.80 0.11 155)` | Synced, linked, launched |
| `--kb-warn` | `oklch(0.46 0.10 70)` | `oklch(0.83 0.11 80)` | Stale, ambiguous, bounded |
| `--kb-danger` | `--destructive` | `oklch(0.75 0.14 25)` | Failed, missing, rejected |

Each has a matching `--kb-*-bg` tint. The `.dark` block **flips lightness rather than reusing light values** — this is why the coverage badges stay AA-legible on `--card` in both themes.

Alex maps these to Tailwind 4 `@theme` entries in `resources/css/app.css`. No brand palette overrides the kit's neutral primary: this is a personal tool with no brand.

Notable non-token choices, all in `kbms.css`:

- **Tabular numerals + monospace** for agenda times, hour gutters and paths (`--kb-mono`). Times are scanned in a column; proportional digits ruin that.
- `--kb-frame-max: 88rem` caps content width so a 1920 px window does not produce 200-character agenda rows.
- No shadows beyond the kit's `shadow-xs` feel; density is the aesthetic here, not depth.

---

## Screen-by-screen contract

### App shell

Sidebar: `Personal KBMS` + `favicon.svg` as the mark → **Workspace** (Agenda, Calendar) → **Reference** (mockup-only, drop at implementation) → **Later** (Search FR-013, Transcript inbox FR-014, shown disabled with the FR tag so the nav does not need restructuring when they land) → footer environment block.

Header strip: sidebar toggle · breadcrumbs · **sync pill** · theme toggle. The sync pill is in the shell, not on a page, because US-004 requires it everywhere.

### Agenda (`index.html`)

- Range toolbar: prev / next / **Today** / live range label / native `<input type="date">` jump. Defaults to today; navigates backwards as well as forwards.
- Day groups with **sticky headers** (`h2`) carrying a count and an annotated count — the coverage map at day granularity.
- Rows are `<a>` elements in a `<ul>`: three columns (time · title+meta · badges) collapsing to a stack below 768 px.
- Coverage badges state `Notes` / `Transcript` / `Not annotated` / `Cancelled` **in words** plus colour plus icon. Legible without hover, per the PRD density requirement.
- `Now` divider is decorative (`aria-hidden`); the following row gets a left rail marker.
- Cancelled rows: dashed border, muted fill, struck title, badge — present and legible, never hidden.
- Third row ships with the focus ring **frozen on** (`.is-focused`) so the focus contract is visible in a screenshot.

### Meeting detail (`meeting.html`)

Two columns at ≥1024 px: **mirror** left, **owned** right.

- **Mirror panel** — `--kb-mirror-bg`, dashed internal rule, `Mirrored from the feed · read-only` flag, and a one-line warning that these fields change on sync. `<dl>` of feed fields, attendee chips, `white-space: pre-line` description, and the **occurrence key** (`UID` + `RECURRENCE-ID`) displayed and copyable — the detail route is keyed on these, not on a surrogate id.
- **Owned panels** — normal `--card` with a `Yours` flag in `--kb-note`: Notes, Transcript, Ask Claude Code, Prompt history.
- Notes: Write/Preview tabs, autosave state in the panel header, `Delete notes…` as a ghost-destructive in the footer next to the "stored as plain text, rendered without HTML" line.
- Transcript: link-source badge, `Relink…` / `Clear link`, copyable absolute path, bounded scroll region (`max-height: 22rem`, `tabindex="0"`, `role="region"`) so it is keyboard-scrollable, footer stating **read on demand, never copied into the database**.
- Ask Claude Code: question textarea → **the only `sk-btn--primary` on the page**, paired with the platform chord hint (`⌘ Enter` macOS / `Ctrl Enter` elsewhere, US-019) that fires the same launch — the chip is `aria-hidden`, and the fact reaches screen readers through `#question`'s `aria-describedby`, not the chip; ship one only with the other → the exact invocation rendered as three lines, script then argument one then argument two, each separately quoted. That formatting *is* the argument-array statement; Alex builds from an array and the quoting is display-only.
- Prompt history is designed now (FR-012, next phase) so the `prompt_launches` row shape does not have to change later.

### Calendar (`calendar.html`)

- Toolbar: prev / next / Today / month label / **Month · Week · Day** segmented control with `aria-pressed`.
- Month grid is per-week rows of three sub-rows: day numbers, an **all-day / multi-day span lane**, then timed events. The offsite (2 days) and the conference (3 days) are **single spanning bars** — not per-day continuation chips. Reproduce that with FullCalendar's own multi-day rendering.
- Coverage shown as a left border plus dots; **every event's accessible name states time, title and coverage**, so the marks are `aria-hidden` decoration on top, never the only signal.
- Overflow → `+2 more` that expands. Cancelled → dashed, struck, muted. All-day → its own lane, never a midnight-to-midnight block.
- Week and day views: hour gutter, all-day lane, current-day tint. Only the visible range is queried.

### States (`states.html`)

The wording is part of the contract, not decoration. These pairs must stay distinguishable in the UI and in tests:

- `never synced` ≠ `stale` ≠ `failed`
- transcript `missing` ≠ `unreadable` ≠ `not configured` ≠ `path rejected`
- launch blocked by `no transcript` ≠ `launcher unset` ≠ `not executable` ≠ `empty question`
- Outlook CTA `feed link` ≠ `template fallback` ≠ `unavailable` (disabled with reason, never a broken URL)

---

## Responsive & navigation

**Desktop-first by recorded decision.** The PRD `## UX & frontend` explicitly inverts Larapilot's mobile-first default: this is a localhost-bound tool driven from the machine that runs it, so no mobile-specific flow is designed. The obligation that remains is that **nothing breaks below 768 px** — which `mobile.html` demonstrates by rendering the real screens in 375 px frames rather than redrawing them, so the mockup cannot drift from the implementation.

| Width | Behaviour |
| --- | --- |
| **320** | Agenda and detail readable; month grid scrolls horizontally; no clipped controls |
| **375** | Reference narrow width (`mobile.html` frames) |
| **768** | `.sk-sidebar` hidden, `.sk-sidebar-toggle` appears; detail still one column; touch targets ≥44 px |
| **1024** | Sidebar pinned; detail becomes two columns (mirror 21 rem / owned fluid) |
| **1280** | Primary design width |
| **1920** | Content capped at 88 rem and left-aligned — no stretched line lengths |

**Navigation pattern — one for the whole app:** hamburger drawer, left-anchored, scrim over content (the kit's Flux sidebar sheet). Open state is a modal dialog: focus moves in, `Esc` and the scrim both close, focus returns to the trigger. No bottom bar or tab bar — two destinations do not justify one. Breadcrumbs on the detail page (`Agenda / {meeting}`).

**What reflows vs. what stays:** agenda rows three columns → stack; meeting detail two columns → one with the **mirror panel first**, so read-only context is read before the editors; month grid keeps seven columns and gains `overflow-x-auto` — the one place horizontal scroll is accepted, because a calendar with fewer than seven day columns stops being a calendar. Event time labels drop from month cells below 768 px; the accessible name keeps them. Nothing is height-dependent, so portrait/landscape differ only in how much is visible.

---

## Accessibility — WCAG 2.2 AA

Baseline set in the PRD. What Alex must preserve when this becomes Blade + Livewire + Flux:

- **Landmarks and headings** — one `<h1>` per page; `header` / `nav` / `main` / `aside`; day groups and panels are `<h2>`; agenda is a `<ul>` of links, not a table of divs.
- **Skip link** — `.kb-skip`, first focusable element, visible on focus, targets `#main`.
- **Focus** — every interactive element gets a 3 px `--ring` halo plus a border change. **Never outline-only and never suppressed.** The frozen `.is-focused` agenda row is the reference rendering.
- **Coverage never colour-only** — badges carry text; calendar event marks are `aria-hidden` on top of an accessible name that already states the coverage.
- **Forms** — `<label>` for every control (`.kb-sronly` where the visible label is the panel heading); errors use `aria-invalid` + `aria-describedby` + `role="alert"`.
- **Live regions** — sync pill and autosave indicator are `aria-live="polite"`; blocking errors are `role="alert"`. Livewire updates must land inside these, not replace them.
- **Bounded scroll regions** — transcript preview is `role="region"` with `tabindex="0"` and an accessible name, so keyboard users can scroll it.
- **Calendar grid** — FullCalendar's defaults are **not sufficient**: add arrow-key movement between days, `Home`/`End`, real focusable event links, and a `aria-pressed` view switcher.
- **Motion** — the only animation is the syncing dot pulse, and `prefers-reduced-motion: reduce` neutralises it globally in `kbms.css`.
- **Contrast** — verify both themes. The `--kb-*` tokens were chosen against `--card` in each theme, not converted from one to the other.
- **Touch** — ≥44×44 px for icon buttons, buttons and segmented controls below 768 px.

**Not applicable here:** no auth flows, no CAPTCHA, no media requiring captions, no third-party embeds.

---

## Brand assets

Minimal by PRD decision — nothing about this product is public.

| Asset | Status |
| --- | --- |
| `favicon.svg` | **Created.** Rounded plate + spine + three rules + a dot: an agenda with a mark against it. Inverts via `prefers-color-scheme` inside the SVG so it holds in both browser chromes. Ships to `public/favicon.svg`. |
| Logo, `og-default.png`, `apple-touch-icon.png`, social square, brand guide | **Deliberately absent.** US-001 states "no logo, OG image, or social assets". The favicon doubles as the sidebar mark. |

Wire-up: `<link rel="icon" href="/favicon.svg" type="image/svg+xml">` in the root layout, and the same file as the sidebar mark at 24×24 with `alt=""` (the adjacent wordmark carries the name).

---

## SEO & discoverability *(Emma)*

**Not a public site — the usual structural SEO set does not apply and must not be scaffolded.** There is nothing to crawl: the app binds to `127.0.0.1`, has no accounts, and is never deployed.

- `robots.txt` / `sitemap.xml` / `llms.txt`: **N/A.** Adding them would imply a public surface that does not exist.
- Ship a `<meta name="robots" content="noindex, nofollow">` in the root layout anyway — cheap insurance if the port is ever exposed.
- What still earns its keep from Emma's checklist: **unique, descriptive `<title>` per page** (`Q4 roadmap review — Personal KBMS`), correct heading structure, and descriptive link text — all of which are accessibility and browser-history wins rather than ranking ones. Every page in this folder already follows it.
- Lighthouse: **Accessibility ≥ 90** is a meaningful target and worth checking locally. Performance/SEO scores are not gates for a localhost tool.

## Social & marketing *(Lauren)*

**N/A.** No channels, no share metadata, no campaign surface. Revisit only if FR-022 (hosted deployment) is ever taken off the Won't list.

## Legal & regulatory *(Violet)*

- **Accessibility statement: not required.** No public or public-sector surface, single operator, EAA / EN 301 549 / Legge 4/2004 out of scope. WCAG 2.2 AA is targeted here as a **product-quality** choice recorded in the PRD, not a legal obligation — which means no one else will catch regressions, so Anne's checks matter more, not less.
- **Third-party personal data is on screen.** Transcripts and attendee lists carry colleagues' and clients' names and speech. The mockups reflect the PRD's household-exemption reasoning in two concrete places: transcript content is read on demand and never duplicated into the database, and the delete-notes dialog states that the transcript **file** is not touched. Keep both strings.
- The exemption lapses if output is shared in a work context or the instance becomes reachable by others. **US-017 adds the first deliberate export affordance** — Copy as Markdown and Print / Save as PDF on the notes and transcript panels (FR-025) — and with it, the exemption stops being passive: the operator now carries the responsibility at the moment they choose to share. No consent machinery is added for a single-operator local tool, but the two exports are the only paths that move note or transcript content off the machine, and both require an explicit click.

## Copy *(Marika)*

- Tone: **plain, specific, operator-to-operator.** No product voice, no exclamation marks, no "Welcome to your dashboard".
- Every error and blocked state names the **configuration key** or the **action** that fixes it (`KBMS_CLAUDE_LAUNCHER is not configured`, `chmod +x …`). Nothing says "Something went wrong".
- Empty states say **why** the emptiness is trustworthy ("Last successful sync 4 minutes ago, so this is a real gap rather than a missing mirror") — the whole point of US-004.
- Mockup data is fictional but realistic: an offsite, a roadmap review, a 1:1, a cancelled vendor demo, a multi-day conference. Enough shape to expose layout problems. No Lorem ipsum anywhere.
- Strings that carry a spec commitment and should survive review verbatim in meaning: *"Mirrored from the feed · read-only"*, *"Read on demand — never copied into the database"*, *"The application never captures Claude's reply"*, *"Two arguments, in order: context file, then question."*
- **Export actions (US-017):** button labels are *"Copy as Markdown"* and *"Print / Save as PDF"*, identical on both the notes and transcript panels. A successful copy shows the transient label *"Copied"* on the button itself; a failed one shows *"Copy failed"* in the same place, then both revert to the idle label after a short delay — **a failed copy must never show "Copied"**, so the two outcome strings are never interchangeable.

---

## Implementation mapping *(for Alex — Joe's notes)*

Mockups are layout and flow references, **not HTML to paste**. The `kb-*` classes exist to make the contract inspectable; the real app uses Flux components plus Tailwind utilities.

| Mockup | Implementation |
| --- | --- |
| `sk-layout` / `sk-sidebar` / `sk-inset` | Kit's `layouts.app` — `<flux:sidebar>`, `<flux:main>` |
| `sk-sidebar__nav a` | `<flux:navlist.item :current="…">` |
| `kb-sync` pill | Livewire component polled or refreshed on the sync job's broadcast; `wire:poll.60s` is acceptable for a local tool |
| `kb-row` | Blade partial in a `@foreach`; the whole row is one `<a>` to the occurrence route |
| `kb-tabs` (Write/Preview) | Hand-rolled ARIA tablist (`role="tablist"` + `role="tab"` + `aria-selected`) — `<flux:tab.group>` is **Flux Pro** and unavailable on the installed free `livewire/flux`; the mockup's own markup is the implementation, ported as-is |
| `kb-textarea` + autosave | Livewire `wire:model.live.debounce.750ms` + a `saving`/`saved`/`error` state property |
| `kb-scroll` transcript | Livewire component reading the file on render; **bounded** read, never `file_get_contents` on an unbounded path |
| `kb-command` | Rendered from the same array the job dispatches, so display and execution cannot diverge |
| `kb-cal__*` / `kb-week__*` | Shipped as `resources/js/calendar.js` (`@fullcalendar/core`, `@fullcalendar/daygrid`, `@fullcalendar/timegrid`, MIT core only) in a thin Alpine wrapper (`resources/views/pages/calendar.blade.php`); the static grids here define the visual result, not the DOM — FullCalendar's own markup is what actually gets styled (`resources/css/app.css`). **Recorded departure:** the coverage marks render as shape-plus-colour (circle for notes, square for transcript), not the mockup's dot-only pair, because two dots differing only in hue is a WCAG 1.4.1 Use-of-Colour failure |
| `kb-drawer-demo` | `<flux:sidebar>` collapsed variant / Flux sheet — do not hand-roll |
| `theme.js` | Kit's own appearance setting (light/dark/system, persisted) — delete `theme.js`, do not port it |
| `kb-print` / `kb-print__head` / `kb-print__meta` / `kb-print__body` / `kb-print--mono` | `resources/views/layouts/print.blade.php` + `resources/views/pages/print/{note,transcript,unavailable}.blade.php` + `resources/views/components/meeting/print-header.blade.php` (US-017). A separate chrome-free layout, not a variant of `layouts.app` — it deliberately omits `@fluxAppearance`, so the exported page is always light on paper regardless of the app's theme when the export was triggered. `kb-md` is reused for rendered Markdown rather than restated; `kb-print--mono` is the `.txt` equivalent, reusing `--kb-mono` |

**Do not** introduce a design-system layer beyond this: no component library on top of Flux, no bespoke tokens outside the `--kb-*` set documented above. Client performance is a non-issue at this scale; the only real budget is not shipping FullCalendar premium plugins (MIT core only) and not loading the whole mirror into a calendar view.

## Test contract *(for Anne — `settings.testing: NORMAL`)*

No Playwright, Dusk, or viewport matrix at this bar. What this mockup asks of feature tests:

- Assert the **distinguishing strings** in `states.html`, not just a status code — "never synced" vs "stale" vs "failed", missing vs unreadable vs unconfigured, and each blocked-launch reason.
- Assert the detail route is addressed by (`source_uid`, `recurrence_id`) and that coverage flags appear on the agenda row.
- Assert `rel="noopener"` and `target="_blank"` on both external CTAs, and that the Outlook CTA is disabled (with its reason rendered) when neither a feed link nor a template exists.
- Assert the rendered invocation matches the dispatched argument array, including for the adversarial question string in `states.html`.
- **Manual test handoff** for the UI itself: keyboard-only pass over agenda → detail → calendar; theme toggle in both directions; 375 px pass using `mobile.html` as the checklist; transcript region scrollable by keyboard.
- **Manual test handoff for export (US-017)**, on `http://personal-kbms.test` since the Clipboard API fallback cannot be exercised from PHPUnit: paste a copied note back into the editor and confirm it round-trips unchanged; copy a transcript larger than 2 MiB and check the pasted tail matches the file; confirm the transient "Copied" label and that a denied clipboard permission reports "Copy failed" rather than "Copied"; print both surfaces with the app in **dark** mode and confirm the printed page carries the meeting header, no application chrome, and legible black-on-white text; keyboard-only pass over both action pairs on the notes and transcript panels.
