@props(['row'])

@php
    $event = $row->event;

    $when = match (true) {
        $row->isAllDay => $row->spanLabel ?? $row->start->format('D j M Y'),
        // A timed occurrence that carries into another day needs both dates —
        // "Wed 9 Sep, 22:00 – 06:00" reads as an eight-hour trip backwards in time.
        $row->spanLabel !== null => $row->start->format('D j M Y, H:i').' – '.$row->end->format('D j M Y, H:i'),
        default => $row->start->format('D j M Y, H:i').' – '.$row->end->format('H:i'),
    };

    $duration = match (true) {
        $row->isAllDay => trans_choice(':count day|:count days', max(1, $row->start->diffInDays($row->end)), ['count' => max(1, $row->start->diffInDays($row->end))]),
        $row->durationMinutes < 60 => __(':minutes min', ['minutes' => $row->durationMinutes]),
        $row->durationMinutes % 60 === 0 => trans_choice(':count hour|:count hours', intdiv($row->durationMinutes, 60), ['count' => intdiv($row->durationMinutes, 60)]),
        default => __(':hours h :minutes m', ['hours' => intdiv($row->durationMinutes, 60), 'minutes' => $row->durationMinutes % 60]),
    };

    $attendees = $event->attendees ?? [];
    $visibleAttendees = array_slice($attendees, 0, 6);
    $remainingAttendees = count($attendees) - count($visibleAttendees);
@endphp

<section class="kb-mirror" aria-labelledby="feed-h">
    <p class="kb-mirror__flag">{{ __('Mirrored from the feed · read-only') }}</p>

    <h2 id="feed-h">{{ $event->summary }}</h2>
    <p class="kb-note-inline">{{ __('These fields can change under you on the next sync. Your notes and transcript link cannot.') }}</p>

    <dl class="kb-facts">
        <dt>{{ __('When') }}</dt>
        <dd>
            {{ $when }}
            @unless ($row->isAllDay)
                <br><span class="kb-note-inline">{{ __('TZID :timezone', ['timezone' => config('kbms.timezone')]) }}</span>
            @endunless
        </dd>

        <dt>{{ __('Duration') }}</dt>
        <dd>{{ $duration }}</dd>

        <dt>{{ __('Location') }}</dt>
        <dd>{{ $event->location ?: '—' }}</dd>

        <dt>{{ __('Organiser') }}</dt>
        <dd>{{ $event->organizer ?: '—' }}</dd>

        <dt>
            {{ __('Attendees') }}
            @if (! empty($attendees))
                <span class="kb-note-inline" style="text-transform:none;letter-spacing:0">({{ count($attendees) }})</span>
            @endif
        </dt>
        <dd>
            @if (empty($attendees))
                —
            @else
                <ul class="kb-chips">
                    @foreach ($visibleAttendees as $attendee)
                        <li>{{ $attendee['name'] ?? $attendee['email'] ?? __('Unknown') }}</li>
                    @endforeach
                    @if ($remainingAttendees > 0)
                        <li>{{ __('+ :count more', ['count' => $remainingAttendees]) }}</li>
                    @endif
                </ul>
            @endif
        </dd>

        <dt>{{ __('Description') }}</dt>
        <dd class="kb-facts__desc">{{ $event->description ?: '—' }}</dd>

        <dt>{{ __('Occurrence key') }}</dt>
        <dd>
            <span class="kb-path" style="margin-bottom:0.25rem">
                <code>UID {{ $event->source_uid }}</code>
                <button
                    type="button"
                    class="kb-iconbtn"
                    style="width:1.75rem;height:1.75rem"
                    aria-label="{{ __('Copy UID') }}"
                    x-data
                    x-on:click="navigator.clipboard.writeText(@js($event->source_uid))"
                >&#10697;</button>
            </span>
            @if ($event->recurrence_id !== '')
                <span class="kb-path">
                    <code>RECURRENCE-ID {{ $event->recurrence_id }}</code>
                    <button
                        type="button"
                        class="kb-iconbtn"
                        style="width:1.75rem;height:1.75rem"
                        aria-label="{{ __('Copy recurrence id') }}"
                        x-data
                        x-on:click="navigator.clipboard.writeText(@js($event->recurrence_id))"
                    >&#10697;</button>
                </span>
            @endif
        </dd>

        <dt>{{ __('Last seen in feed') }}</dt>
        <dd>
            {{ $event->last_seen_at->setTimezone(config('kbms.timezone'))->format('D j M, H:i') }}
            @if ($row->isCancelled)
                <span class="kb-tag kb-tag--cancelled">{{ __('Cancelled') }}</span>
            @else
                <span class="kb-tag kb-tag--ok">{{ __('Active') }}</span>
            @endif
        </dd>
    </dl>
</section>
