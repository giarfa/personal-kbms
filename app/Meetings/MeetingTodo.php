<?php

namespace App\Meetings;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;

/**
 * The todo an occurrence's mirrored `summary` declares, if any (US-013).
 *
 * Read-only and derived: nothing here is stored, no column exists, and nothing
 * is written back to the feed. The operator ticks items in Outlook; this class
 * only reads what arrived.
 */
final readonly class MeetingTodo
{
    /**
     * Accepted markers, hardcoded on purpose — there is no configuration key
     * and no `KBMS_*` env var for this vocabulary (decision c6e24e0c0aa251af).
     *
     * `[]` and `[ ]` (one or more spaces) are open; `[x]` and `[X]` are done.
     * Leading whitespace before the marker is forgiven, and the whitespace
     * after it is consumed so the displayed title starts at the first word.
     *
     * The alternation sits *inside* the brackets deliberately: it has to
     * consume everything up to `]`, which is what makes `[ x ]` an ordinary
     * title rather than a sloppily-typed todo. Anchoring with `^` is what
     * makes `Review [x] doc` an ordinary meeting — the marker is only a marker
     * at the very start.
     */
    private const string MARKER_PATTERN = '/^\s*\[(?<mark>\s*|x|X)\]\s*/';

    public function __construct(
        public TodoState $state,
        public string $displayTitle,
        public bool $isOverdue,
    ) {}

    /**
     * The todo this occurrence declares, or `null` when its summary is not one.
     *
     * `null` is the single source of "renders exactly as it did before this
     * spec": no glyph, no class, no reserved gutter.
     *
     * Two rules here are easy to break later and hard to notice:
     *
     * 1. **Cancelled wins over late.** Guarded here rather than in CSS, so no
     *    surface can accidentally paint a cancelled occurrence as overdue.
     * 2. **Now is re-derived on every call.** There is no cached timestamp,
     *    which is exactly what lets an open item cross into overdue on the next
     *    US-011 self-refresh with no operator action and no reload.
     *
     * All-day needs no special case: `ends_at` holds the exclusive midnight
     * boundary (see IcsParser), so the same comparison makes an all-day item
     * overdue once its day has ended rather than for the whole of it.
     */
    public static function for(CalendarEvent $event): ?self
    {
        $summary = $event->summary;

        if (! is_string($summary) || preg_match(self::MARKER_PATTERN, $summary, $matches) !== 1) {
            return null;
        }

        $state = trim($matches['mark']) === '' ? TodoState::Open : TodoState::Done;
        $title = substr($summary, strlen($matches[0]));

        return new self(
            state: $state,
            // A summary that is only a marker strips to nothing. Rendering that
            // as a blank row or a blank event body is explicitly forbidden, and
            // the application has no prior empty-title treatment to inherit.
            displayTitle: $title === '' ? __('Untitled') : $title,
            isOverdue: $state === TodoState::Open
                && $event->cancelled_at === null
                && CarbonImmutable::instance($event->ends_at)->lessThan(CarbonImmutable::now(config('kbms.timezone'))),
        );
    }

    /**
     * The status in words, for the accessible name and the visible tag.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->state === TodoState::Done => __('Done'),
            $this->isOverdue => __('To do, overdue'),
            default => __('To do'),
        };
    }

    /**
     * The class-name suffix for this status — one class chooses one glyph.
     */
    public function modifier(): string
    {
        return match (true) {
            $this->state === TodoState::Done => 'done',
            $this->isOverdue => 'overdue',
            default => 'open',
        };
    }
}
