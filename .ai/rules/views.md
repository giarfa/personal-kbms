---
paths:
  - 'resources/views/**'
---

# Views

## UI contract lives in .larapilot/mockups/kbms-app
Read `.larapilot/mockups/kbms-app/README.md` before building any view. It is the agreed visual contract for the whole app (agenda, meeting detail, calendar, every error/empty state) and maps each `kb-*` mockup class to its Flux/Livewire implementation.

Two non-obvious rules from it:
- Feed-derived data and operator-owned data must stay visually separate surfaces (`--kb-mirror-bg` panel with a "Mirrored from the feed · read-only" flag vs. normal cards with a "Yours" flag). It is the point of the meeting detail page, not decoration.
- The Starter Kit's user menu must be deleted, not stubbed. There is no `User` model and no auth (US-001), so any avatar/name placeholder re-introduces a login surface.

Layout is desktop-first by recorded PRD decision (inverting the Larapilot mobile-first default); the obligation is only that nothing breaks below 768px.
