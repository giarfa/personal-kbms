<x-layouts::app :title="$row->event->summary">
    <x-slot:breadcrumbs>
        <flux:breadcrumbs.item :href="route('agenda')" wire:navigate>{{ __('Agenda') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $row->event->summary }}</flux:breadcrumbs.item>
    </x-slot:breadcrumbs>

    <div class="kb-page-head">
        <div>
            <h1>{{ $row->event->summary }}</h1>
            <p>
                @if ($row->isAllDay)
                    {{ $row->spanLabel ? $row->spanLabel.', '.$row->start->format('Y') : $row->start->format('D j M Y') }}
                    &middot; {{ __('All day') }}
                @else
                    {{ $row->start->format('D j M Y') }}
                    &middot; {{ $row->start->format('H:i') }}&ndash;{{ $row->end->format('H:i') }} {{ config('kbms.timezone') }}
                @endif
                @if ($row->event->recurrence_id !== '')
                    &middot; {{ __('one occurrence of a weekly series') }}
                @endif
            </p>
        </div>
        <div class="kb-page-head__actions">
            <x-meeting.external-links :outlook-link="$outlookLink" :teams-url="$teamsUrl" />
        </div>
    </div>

    @if ($row->isCancelled)
        <div class="kb-banner kb-banner--warn" role="status">
            <span aria-hidden="true">&#9888;</span>
            <span>
                <strong>{{ __('Cancelled upstream') }}</strong>
                {{ __('This occurrence was cancelled in the feed. It is kept, not hidden — your notes and transcript link are unaffected.') }}
            </span>
        </div>
    @endif

    <div class="kb-detail">
        <x-meeting.mirror-panel :row="$row" />

        <div class="kb-owned">
            <section class="kb-panel" aria-labelledby="notes-h">
                <div class="kb-panel__head">
                    <div>
                        <p class="kb-owned-flag">{{ __('Yours') }}</p>
                        <h2 id="notes-h">{{ __('Notes') }}</h2>
                    </div>
                </div>
                <div class="kb-panel__body">
                    <p class="kb-note-inline">{{ __('Meeting notes arrive with US-006.') }}</p>
                </div>
            </section>

            <section class="kb-panel" aria-labelledby="transcript-h">
                <div class="kb-panel__head">
                    <div>
                        <p class="kb-owned-flag">{{ __('Yours') }}</p>
                        <h2 id="transcript-h">{{ __('Transcript') }}</h2>
                    </div>
                </div>
                <div class="kb-panel__body">
                    <p class="kb-note-inline">{{ __('Transcript linking arrives with US-007.') }}</p>
                </div>
            </section>

            <section class="kb-panel" aria-labelledby="ask-h">
                <div class="kb-panel__head">
                    <div>
                        <p class="kb-owned-flag">{{ __('Yours') }}</p>
                        <h2 id="ask-h">{{ __('Ask Claude Code') }}</h2>
                    </div>
                </div>
                <div class="kb-panel__body">
                    <p class="kb-note-inline">{{ __('The local Claude Code launch bridge arrives with US-009.') }}</p>
                </div>
            </section>
        </div>
    </div>
</x-layouts::app>
