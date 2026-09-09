@props(['outlookLink', 'teamsUrl'])

@if ($outlookLink->url !== null)
    <flux:button variant="outline" :href="$outlookLink->url" target="_blank" rel="noopener">
        <span aria-hidden="true">&#8599;</span> {{ __('Open in Outlook') }}
    </flux:button>
@else
    <flux:button variant="outline" disabled aria-describedby="outlook-cta-reason">
        <span aria-hidden="true">&#8599;</span> {{ __('Open in Outlook') }}
    </flux:button>
    <span class="kb-note-inline" id="outlook-cta-reason">{{ $outlookLink->reason }}</span>
@endif

@if ($teamsUrl !== null)
    <flux:button variant="outline" :href="$teamsUrl" target="_blank" rel="noopener">
        <span aria-hidden="true">&#9654;</span> {{ __('Join Teams meeting') }}
    </flux:button>
@endif
