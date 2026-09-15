document.addEventListener('alpine:init', () => {
    /**
     * Copy as Markdown for the note and transcript panels (US-017).
     *
     * `source: 'wire'` (notes) reads `this.$wire.body` synchronously — the
     * live editor value, nothing prepended, so a paste round-trips back into
     * the editor unchanged. `source: 'url'` (transcript) fetches
     * `meetings.transcript.source` on first click and caches the text on the
     * component instance, so a second click never re-fetches.
     *
     * `navigator.clipboard` is undefined on `http://personal-kbms.test`:
     * browsers only treat `localhost`/`127.0.0.1` as a secure context over
     * plain HTTP, not a `.test` domain, so the Clipboard API silently does
     * not exist there. The hidden-textarea `execCommand('copy')` path is a
     * genuine fallback for that case — the Clipboard API branch stays the
     * default wherever it is actually available.
     */
    Alpine.data('kbCopy', (config) => ({
        state: 'idle',
        _timer: null,
        _cachedText: null,

        async copy() {
            if (this.state === 'copying') {
                return;
            }

            this.state = 'copying';

            let text;

            try {
                text = await this.resolveText();
            } catch (e) {
                this.settle('error');

                return;
            }

            const wrote = await this.write(text);

            this.settle(wrote ? 'copied' : 'error');
        },

        settle(state) {
            this.state = state;
            clearTimeout(this._timer);
            this._timer = setTimeout(() => {
                this.state = 'idle';
            }, state === 'copied' ? 2000 : 4000);
        },

        async resolveText() {
            if (config.source === 'wire') {
                return this.$wire.body;
            }

            if (this._cachedText !== null) {
                return this._cachedText;
            }

            const response = await fetch(config.url);

            if (!response.ok) {
                throw new Error('kb-copy: fetch failed');
            }

            this._cachedText = await response.text();

            return this._cachedText;
        },

        async write(text) {
            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(text);

                    return true;
                } catch (e) {
                    return false;
                }
            }

            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();

            let copied = false;

            try {
                copied = document.execCommand('copy');
            } catch (e) {
                copied = false;
            }

            document.body.removeChild(textarea);

            return copied;
        },
    }));
});
