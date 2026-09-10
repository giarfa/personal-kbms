/**
 * The one place the self-refresh cadence is written (US-011).
 *
 * 60 seconds, deliberately hardcoded to match the `wire:poll.60s` the sync
 * indicator already runs on, so the two never drift apart. US-011 adds no
 * `KBMS_*` key and no operator-facing refresh control on purpose: this is
 * product shape for a single-operator local tool, not machine shape.
 */
export const KB_REFRESH_MS = 60000;

/**
 * Subscribers to the shared tick. One `setInterval` serves all of them, so the
 * agenda, the calendar, and anything added later wake on the same tick rather
 * than each owning a timer that drifts against the others.
 */
const subscribers = new Set();

let timer = null;

/**
 * Set when a tick was skipped because the tab was hidden, and read by the
 * `visibilitychange` listener below to decide whether returning to the tab
 * owes the operator an immediate catch-up.
 */
let missedTickWhileHidden = false;

function runSubscribers() {
    subscribers.forEach((callback) => callback());
}

function tick() {
    // Hand-rolled rather than `wire:poll`, and this is the reason: Livewire's
    // poll directive does not pause in a background tab, it *throttles* to a
    // 5% chance per tick (`throttleWhile(() => theTabIsInTheBackground() ...)`
    // in livewire.esm.js) and never catches up when the tab comes back. US-011
    // asks for a real pause plus a catch-up, so the cadence is ours.
    if (document.hidden) {
        missedTickWhileHidden = true;

        return;
    }

    runSubscribers();
}

function startTimer() {
    if (timer === null) {
        timer = setInterval(tick, KB_REFRESH_MS);
    }
}

function stopTimer() {
    if (timer !== null && subscribers.size === 0) {
        clearInterval(timer);
        timer = null;
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden || !missedTickWhileHidden) {
        return;
    }

    missedTickWhileHidden = false;
    runSubscribers();
});

/**
 * Run `callback` roughly once a minute while the tab is visible, plus once
 * immediately when a hidden tab is brought back and a tick was missed.
 *
 * Returns a teardown closure — hand it to Alpine's `$cleanup()` so a
 * `wire:navigate` away does not leave the subscription behind.
 */
export function onRefreshTick(callback) {
    subscribers.add(callback);
    startTimer();

    return () => {
        subscribers.delete(callback);
        stopTimer();
    };
}

document.addEventListener('alpine:init', () => {
    /**
     * Subscribes a Livewire component root to the shared tick. One attribute
     * on the root element is the whole wiring: `x-data="kbSelfRefresh"`.
     */
    Alpine.data('kbSelfRefresh', () => ({
        init() {
            this.$cleanup(onRefreshTick(() => this.$wire.$refresh()));
        },
    }));
});

document.addEventListener('livewire:init', () => {
    /**
     * A refresh that fails has nothing to say. Without this, a stopped queue
     * worker, a restarting PHP process, or a session that expired while the
     * tab sat in the background would put Livewire's full-screen HTML error
     * modal — or its "This page has expired" confirm — over a perfectly good
     * last render, once a minute.
     *
     * Scoped to `$refresh` by name, which is exactly the no-side-effect
     * re-render: our tick above, and the sync indicator's own `wire:poll`.
     * Every user-initiated action keeps Livewire's default error surface,
     * because a failed save is something the operator must be told about.
     */
    Livewire.interceptAction(({ action, onError }) => {
        if (action.name !== '$refresh') {
            return;
        }

        onError(({ preventDefault }) => preventDefault());
    });
});
