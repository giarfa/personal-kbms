<section class="kb-panel" aria-labelledby="notes-h">
    <div class="kb-panel__head">
        <div>
            <p class="kb-owned-flag">{{ __('Yours') }}</p>
            <h2 id="notes-h">{{ __('Notes') }}</h2>
        </div>
        <div class="kb-panel__actions">
            <span
                class="kb-savestate @if ($saveState === 'saving') kb-savestate--saving @elseif ($saveState === 'error') kb-savestate--error @endif"
                aria-live="polite"
                @if ($saveState === 'error') role="alert" @endif
            >
                <span class="kb-savestate__dot" aria-hidden="true"></span>
                @if ($saveState === 'saving')
                    {{ __('Saving…') }}
                @elseif ($saveState === 'saved')
                    {{ __('Saved :time', ['time' => $savedAt]) }}
                @elseif ($saveState === 'error')
                    {{ __('Not saved') }}
                @endif
            </span>

            @if ($saveState === 'error')
                <flux:button size="sm" variant="outline" wire:click="retry">{{ __('Retry') }}</flux:button>
            @endif

            <div class="kb-tabs" role="tablist" aria-label="{{ __('Note editor mode') }}">
                <button type="button" role="tab" aria-selected="{{ $mode === 'write' ? 'true' : 'false' }}" wire:click="switchMode('write')">{{ __('Write') }}</button>
                <button type="button" role="tab" aria-selected="{{ $mode === 'preview' ? 'true' : 'false' }}" wire:click="switchMode('preview')">{{ __('Preview') }}</button>
            </div>
        </div>
    </div>

    <div class="kb-panel__body">
        @if ($mode === 'preview')
            <div class="kb-md">{!! $preview !!}</div>
        @else
            <label class="kb-sronly" for="note">{{ __('Meeting notes, Markdown') }}</label>
            <textarea class="kb-textarea" id="note" spellcheck="false" wire:model.live.debounce.750ms="body"></textarea>
            @if ($saveState === 'error')
                <p class="kb-note-inline" style="margin-top:0.5rem">{{ __('Typed content stays in the editor. Nothing is discarded silently.') }}</p>
            @endif
        @endif
    </div>

    <div class="kb-panel__foot">
        <span>{{ __('Autosaves as you type · Markdown supported · stored as plain text, rendered without HTML') }}</span>
    </div>
</section>
