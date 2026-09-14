document.addEventListener('alpine:init', () => {
    /**
     * `Cmd`/`Ctrl`+`Enter` in the question textarea (US-019) — a pure alias
     * for the Launch session button, bound to `#question` only (recorded
     * decision `ask claude keyboard shortcut listener scope`). No page-,
     * window- or document-level handler.
     */
    Alpine.data('kbLaunchShortcut', (config) => ({
        busy: false,
        max: config.max,
        platform: 'other',

        /**
         * Client-side platform detection (recorded decision `ask claude
         * keyboard shortcut affordance`) — this is the only thing JS
         * decides. The words themselves come from `LaunchChord` in PHP via
         * `config.labels` / `config.spokenLabels`, which is what keeps the
         * chord vocabulary unit-testable without a browser suite.
         */
        init() {
            const platformHint = navigator.userAgentData?.platform ?? navigator.platform ?? '';

            this.platform = /mac|iphone|ipad/i.test(platformHint) ? 'mac' : 'other';
        },

        get chordLabel() {
            return config.labels[this.platform];
        },

        get spokenHint() {
            return config.spokenTemplate.replace(':chord', config.spokenLabels[this.platform]);
        },

        /**
         * `$event.repeat` (OS auto-repeat while the chord is held) and the
         * in-flight `busy` flag both short-circuit here, so holding the
         * chord down — or pressing it again while a launch is still queued —
         * queues exactly one launch (recorded decision `keyboard shortcut
         * launch claude session`).
         */
        async submit($event) {
            if ($event.repeat || this.busy) {
                return;
            }

            // Read the DOM directly, never `$wire.question`. This is the
            // whole defence against the `wire:model.live.debounce.400ms`
            // race (US-014's failure mode, restated for the keyboard path):
            // the debounced copy can lag the last keystroke by up to 400ms,
            // so consulting it here could launch with a stale or empty
            // question. The value the operator just typed lives on the
            // element itself regardless of whether Livewire has synced yet.
            const value = $event.target.value;

            if (value.trim() === '' || value.length > this.max) {
                // Inert exactly where the button is disabled — no server
                // call, so no validation message is raised. The existing
                // on-screen block reason stays the only explanation
                // (recorded decision `ask claude keyboard shortcut blocked
                // behaviour`).
                return;
            }

            this.busy = true;

            try {
                // Deferred (`live = false`): no network request on its own.
                // One commit carries both the update and the call, with
                // `updates` applied before `calls` — the launch always sees
                // the freshly typed value, not the debounced one.
                await this.$wire.set('question', value, false);
                await this.$wire.launch();
            } finally {
                this.busy = false;
            }
        },
    }));
});
