@php
    // Same vocabulary as the agenda legend (US-013). Overdue carries an added
    // shape, not only the alert hue.
    $todoGlyphs = ['open' => '☐', 'done' => '☑', 'overdue' => '☐ !'];
    $todoTagClasses = ['open' => 'kb-tag--bare', 'done' => 'kb-tag--muted', 'overdue' => 'kb-tag--overdue'];
@endphp

<x-layouts::app :title="$row->displayTitle">
    <x-slot:breadcrumbs>
        <flux:breadcrumbs.item :href="route('agenda')" wire:navigate>{{ __('Agenda') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $row->displayTitle }}</flux:breadcrumbs.item>
    </x-slot:breadcrumbs>

    <div class="kb-page-head">
        <div>
            {{-- The heading shows the stripped title and the parsed status; the
                 mirror panel below still renders the raw feed summary verbatim,
                 marker and all (FR-004). That difference is the deliverable, not
                 an inconsistency to tidy away. --}}
            <h1>
                @if ($row->todo)
                    <span class="kb-todo kb-todo--{{ $row->todo->modifier() }}" aria-hidden="true">{{ $todoGlyphs[$row->todo->modifier()] }}</span>
                @endif
                {{ $row->displayTitle }}
                @if ($row->todo)
                    <span class="kb-sronly">{{ $row->todo->statusLabel() }}</span>
                    <span class="kb-tag {{ $todoTagClasses[$row->todo->modifier()] }}" aria-hidden="true">{{ $row->todo->statusLabel() }}</span>
                @endif
            </h1>
            <p>
                @if ($row->isAllDay)
                    {{ $row->spanLabel ? $row->spanLabel.', '.$row->start->format('Y') : $row->start->format('D j M Y') }}
                    &middot; {{ __('All day') }}
                @elseif ($row->spanLabel)
                    {{ $row->start->format('D j M Y, H:i') }}
                    &ndash; {{ $row->end->format('D j M Y, H:i') }} {{ config('kbms.timezone') }}
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

    @if ($neighbours)
        <x-meeting.series-nav :neighbours="$neighbours" />
    @endif

    <div class="kb-detail">
        <x-meeting.mirror-panel :row="$row" />

        <div class="kb-owned">
            <livewire:meeting-notes :occurrence="$row->event" />

            <livewire:transcript-panel :occurrence="$row->event" />

            <livewire:ask-claude :occurrence="$row->event" />
        </div>
    </div>
</x-layouts::app>
