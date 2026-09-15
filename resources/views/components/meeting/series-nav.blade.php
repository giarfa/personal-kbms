@props(['neighbours'])

{{-- Previous / next occurrence of the same series (US-018). Both controls are
     ordinary links: no window.open, no click handler and no wire:navigate, so
     cmd/ctrl-click, middle-click, the context menu and back/forward keep their
     ordinary meaning. The inert shape is the one x-meeting.external-links
     already uses for an unavailable "Open in Outlook" — one page, one
     vocabulary for "offered but leads nowhere". --}}
<nav class="kb-series-nav" aria-label="{{ __('Occurrences of this series') }}">
    @foreach ([$neighbours->previous, $neighbours->next] as $neighbour)
        @php
            $isPrevious = $neighbour->direction === \App\Meetings\SeriesDirection::Previous;
            $reasonId = 'series-'.$neighbour->direction->value.'-reason';
        @endphp

        <div class="kb-series-nav__side kb-series-nav__side--{{ $neighbour->direction->value }}">
            @if ($neighbour->isAvailable())
                <flux:button
                    variant="outline"
                    :href="route('meetings.show', $neighbour->routeKey())"
                    target="_blank"
                    rel="noopener"
                    :aria-label="$neighbour->accessibleName()"
                >
                    @if ($isPrevious)
                        <span aria-hidden="true">&larr;</span> {{ $neighbour->label() }}
                    @else
                        {{ $neighbour->label() }} <span aria-hidden="true">&rarr;</span>
                    @endif
                </flux:button>
            @else
                <flux:button variant="outline" disabled :aria-describedby="$reasonId">
                    @if ($isPrevious)
                        <span aria-hidden="true">&larr;</span> {{ $neighbour->directionLabel() }}
                    @else
                        {{ $neighbour->directionLabel() }} <span aria-hidden="true">&rarr;</span>
                    @endif
                </flux:button>
                <span class="kb-note-inline" id="{{ $reasonId }}">{{ $neighbour->reason() }}</span>
            @endif
        </div>
    @endforeach
</nav>
