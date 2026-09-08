<div>
    @if ($health->needsAttention())
        @if ($health->state->value === 'failed')
            <div class="kb-banner" role="alert">
                <span aria-hidden="true">&#9888;</span>
                <span>
                    <strong>Last sync failed at {{ $health->lastErrorAt?->setTimezone(config('kbms.timezone'))->format('H:i') }}</strong>
                    <code>{{ $health->lastError }}</code> &mdash; feed <code>KBMS_ICS_URL</code>.
                    {{ $health->lastSuccessAt ? 'Showing the mirror from '.$health->lastSuccessAt->diffForHumans().'.' : 'No mirror exists yet.' }}
                    Run <code>php artisan kbms:doctor</code> to check reachability.
                </span>
                <span class="kb-banner__actions">
                    <flux:button wire:click="retry">Retry now</flux:button>
                </span>
            </div>
        @else
            @php
                $minutesAgo = (int) round(now()->diffInMinutes($health->lastSuccessAt, true));
            @endphp
            <div class="kb-banner kb-banner--warn" role="status">
                <span aria-hidden="true">&#9888;</span>
                <span>
                    <strong>Mirror may be stale</strong>
                    No successful sync for {{ $minutesAgo }} minutes &mdash; more than {{ config('kbms.sync_stale_multiplier') }}&times; the {{ config('kbms.sync_minutes') }}-minute interval. Nothing has failed; the scheduler may not be running.
                </span>
                <span class="kb-banner__actions">
                    <flux:button wire:click="retry">Resync now</flux:button>
                </span>
            </div>
        @endif
    @endif
</div>
