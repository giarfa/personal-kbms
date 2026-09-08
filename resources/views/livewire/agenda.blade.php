<div>
    @php
        $syncNote = $syncHealth->hasEverSynced()
            ? __('Last successful sync was :time.', ['time' => $syncHealth->lastSuccessAt->diffForHumans()])
            : __('No successful sync has completed yet.');
        $totalMeetings = $days->sum(fn ($day) => $day->meetingCount);
    @endphp

    <div class="kb-page-head">
        <div>
            <h1>{{ __('Agenda') }}</h1>
            <p>{{ __('Recent and upcoming meetings mirrored from the Outlook feed. Notes and transcripts are yours; everything else can change on the next sync.') }}</p>
        </div>
        <div class="kb-page-head__actions">
            <flux:button variant="outline" :href="route('calendar')" wire:navigate>{{ __('Calendar view') }}</flux:button>
        </div>
    </div>

    <div class="kb-toolbar" role="group" aria-label="{{ __('Date range') }}">
        <button type="button" class="kb-iconbtn" aria-label="{{ __('Previous week') }}" wire:click="previous">&#8249;</button>
        <button type="button" class="kb-iconbtn" aria-label="{{ __('Next week') }}" wire:click="next">&#8250;</button>
        <flux:button variant="outline" wire:click="today">{{ __('Today') }}</flux:button>
        <span class="kb-toolbar__range" aria-live="polite">{{ $range->label() }}</span>

        <span class="kb-toolbar__spacer"></span>

        <label class="kb-inline kb-note-inline" for="agenda-jump">
            <span>{{ __('Jump to') }}</span>
            <flux:input id="agenda-jump" type="date" wire:model.live="date" />
        </label>
    </div>

    <p class="kb-legend">
        <span>{{ __('Coverage:') }}</span>
        <span class="kb-tag kb-tag--note">{{ __('Notes') }}</span>
        <span class="kb-tag kb-tag--transcript">{{ __('Transcript') }}</span>
        <span class="kb-tag kb-tag--bare">{{ __('Not annotated') }}</span>
        <span class="kb-tag kb-tag--cancelled">{{ __('Cancelled') }}</span>
    </p>

    @if ($totalMeetings === 0)
        <div class="kb-empty">
            <h3>{{ __('Nothing in this range') }}</h3>
            <p>{{ __('The mirror is current — this range is genuinely empty rather than unsynced.') }} {{ $syncNote }}</p>
        </div>
    @else
        @foreach ($days as $day)
            <section class="kb-daygroup @if ($day->isToday) kb-daygroup--today @endif" aria-labelledby="day-{{ $day->date->format('Y-m-d') }}">
                <div class="kb-daygroup__head">
                    <h2 id="day-{{ $day->date->format('Y-m-d') }}">
                        @if ($day->isToday)
                            {{ __('Today') }} &middot; {{ $day->date->format('D j M') }}
                        @elseif ($day->date->isTomorrow())
                            {{ __('Tomorrow') }} &middot; {{ $day->date->format('D j M') }}
                        @else
                            {{ $day->date->format('D j M') }}
                        @endif
                    </h2>
                    <span class="kb-count">
                        @if ($day->meetingCount === 0)
                            {{ __('No meetings') }}
                        @else
                            {{ trans_choice(':count meeting|:count meetings', $day->meetingCount, ['count' => $day->meetingCount]) }}
                            &middot; {{ trans_choice(':count annotated|:count annotated', $day->annotatedCount, ['count' => $day->annotatedCount]) }}
                        @endif
                    </span>
                </div>

                @if ($day->meetingCount === 0)
                    <div class="kb-empty">
                        <h3>{{ __('Nothing on the calendar') }}</h3>
                        <p>{{ __('The mirror is current — this day is genuinely empty rather than unsynced.') }} {{ $syncNote }}</p>
                    </div>
                @else
                    @php
                        $nowIndex = null;

                        if ($day->isToday) {
                            foreach ($day->rows as $i => $row) {
                                if (! $row->isAllDay && $row->start->isFuture()) {
                                    $nowIndex = $i;
                                    break;
                                }
                            }
                        }
                    @endphp

                    <ul class="kb-agenda">
                        @foreach ($day->rows as $i => $row)
                            @if ($i === $nowIndex)
                                <li aria-hidden="true">
                                    <p class="kb-nowline"><span>{{ __('Now') }} &middot; {{ now(config('kbms.timezone'))->format('H:i') }}</span></p>
                                </li>
                            @endif

                            <li>
                                <a
                                    class="kb-row @if ($row->isCancelled) kb-row--cancelled @endif @if ($i === $nowIndex) kb-row--now @endif"
                                    href="{{ route('meetings.show', $row->routeKey) }}"
                                    wire:navigate
                                >
                                    <span class="kb-row__time">
                                        @if ($row->isAllDay)
                                            {{ __('All day') }}
                                            @if ($row->spanLabel)
                                                <small>{{ $row->spanLabel }}</small>
                                            @endif
                                        @else
                                            {{ $row->start->format('H:i') }}
                                            <small>{{ __(':minutes min', ['minutes' => $row->durationMinutes]) }}</small>
                                        @endif
                                    </span>
                                    <span>
                                        <span class="kb-row__title">{{ $row->event->summary }}</span>
                                        <span class="kb-row__meta">
                                            @if ($row->event->organizer)
                                                <span>{{ __('Organiser: :name', ['name' => $row->event->organizer]) }}</span>
                                            @endif
                                            @if ($row->event->location)
                                                <span>{{ $row->event->location }}</span>
                                            @elseif ($row->event->join_url)
                                                <span>{{ __('Microsoft Teams') }}</span>
                                            @endif
                                            @if (! empty($row->event->attendees))
                                                <span>{{ trans_choice(':count attendee|:count attendees', count($row->event->attendees), ['count' => count($row->event->attendees)]) }}</span>
                                            @endif
                                            @if ($row->isCancelled)
                                                <span>{{ __('Cancelled upstream · notes kept') }}</span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="kb-row__badges">
                                        @if ($row->isAllDay)
                                            <span class="kb-tag kb-tag--muted">{{ __('All-day') }}</span>
                                        @endif
                                        @if ($row->isCancelled)
                                            <span class="kb-tag kb-tag--cancelled">{{ __('Cancelled') }}</span>
                                        @endif
                                        @if ($row->coverage->hasNotes)
                                            <span class="kb-tag kb-tag--note">{{ __('Notes') }}</span>
                                        @endif
                                        @if ($row->coverage->hasTranscript)
                                            <span class="kb-tag kb-tag--transcript">{{ __('Transcript') }}</span>
                                        @endif
                                        @if (! $row->coverage->hasNotes && ! $row->coverage->hasTranscript)
                                            <span class="kb-tag kb-tag--bare">{{ __('Not annotated') }}</span>
                                        @endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    @endif
</div>
