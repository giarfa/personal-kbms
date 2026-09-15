@props(['row', 'sourceFilename' => null])

<header class="kb-print__head">
    <h1>{{ $row->displayTitle }}</h1>
    <p class="kb-print__meta">
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
    </p>
    @if ($sourceFilename !== null)
        <p class="kb-print__meta"><code>{{ $sourceFilename }}</code></p>
    @endif
</header>
