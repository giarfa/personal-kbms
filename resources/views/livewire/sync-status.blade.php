<div wire:poll.60s="" aria-live="polite">
    <p class="kb-sync kb-sync--{{ $health->state->value }}"
        @if ($health->state->value === 'failed' && $health->lastError) title="{{ $health->lastError }}" @endif
    >
        <span class="kb-sync__dot" aria-hidden="true"></span>

        @if ($health->state->value === 'ok')
            <span>
                Synced
                <span class="kb-sync__label">{{ $health->lastSuccessAt->diffForHumans() }}</span>
                <span class="kb-note-inline">&middot;
                    <time datetime="{{ $health->lastSuccessAt->toIso8601String() }}" title="{{ $health->lastSuccessAt->toIso8601String() }}">{{ $health->lastSuccessAt->setTimezone(config('kbms.timezone'))->format('H:i') }}</time>
                </span>
            </span>
            <button type="button" class="kb-sync__resync" wire:click="resync">Resync</button>
        @elseif ($health->state->value === 'syncing')
            <span>
                <span class="kb-sync__label">Syncing now&hellip;</span>
                @if ($health->awaitingWorker)
                    <span class="kb-note-inline">queued &mdash; no worker picked this up</span>
                @endif
            </span>
        @elseif ($health->state->value === 'stale')
            <span>
                Last sync
                <span class="kb-sync__label">{{ $health->lastSuccessAt->diffForHumans() }}</span>
                <span class="kb-note-inline">&middot; stale</span>
            </span>
            <button type="button" class="kb-sync__resync" wire:click="resync">Resync</button>
        @elseif ($health->state->value === 'failed')
            <span>
                Sync <span class="kb-sync__label">failed</span>
                @if ($health->lastErrorAt)
                    <span class="kb-note-inline">&middot;
                        <time datetime="{{ $health->lastErrorAt->toIso8601String() }}" title="{{ $health->lastErrorAt->toIso8601String() }}">{{ $health->lastErrorAt->setTimezone(config('kbms.timezone'))->format('H:i') }}</time>
                    </span>
                @endif
            </span>
            <button type="button" class="kb-sync__resync" wire:click="resync">Retry</button>
        @else
            <span class="kb-sync__label">Never synced</span>
            <button type="button" class="kb-sync__resync" wire:click="resync">Sync now</button>
        @endif
    </p>
</div>
