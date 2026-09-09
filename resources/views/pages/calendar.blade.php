@php
    $syncNote = $syncHealth->hasEverSynced()
        ? __('Last successful sync was :time.', ['time' => $syncHealth->lastSuccessAt->diffForHumans()])
        : __('No successful sync has completed yet.');
@endphp

<x-layouts::app :title="__('Calendar')">
    <div
        x-data="kbCalendar({
            initialView: '{{ $range->view->value }}',
            initialFullCalendarView: '{{ $range->view->fullCalendarView() }}',
            initialDate: '{{ $range->anchor->toDateString() }}',
            initialLabel: '{{ $range->label() }}',
            eventsUrl: '{{ route('calendar.events') }}',
            viewMap: {
                month: 'dayGridMonth',
                week: 'timeGridWeek',
                day: 'timeGridDay',
            },
        })"
    >
        <div class="kb-page-head">
            <div>
                <flux:heading level="1">{{ __('Calendar') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Coverage map: which meetings you have already annotated, and where the gaps are.') }}</flux:text>
            </div>
            <div class="kb-page-head__actions">
                <flux:button variant="outline" :href="route('agenda')" wire:navigate>{{ __('Agenda view') }}</flux:button>
            </div>
        </div>

        <div class="kb-toolbar" role="group" aria-label="{{ __('Calendar navigation') }}">
            <button type="button" class="kb-iconbtn" :aria-label="'{{ __('Previous') }} ' + currentView" @click="prev()">&#8249;</button>
            <button type="button" class="kb-iconbtn" :aria-label="'{{ __('Next') }} ' + currentView" @click="next()">&#8250;</button>
            <flux:button variant="outline" @click="today()">{{ __('Today') }}</flux:button>
            <span class="kb-toolbar__range" aria-live="polite" x-text="rangeLabel"></span>

            <span class="kb-toolbar__spacer"></span>

            <div class="kb-segmented" role="group" aria-label="{{ __('View') }}">
                <button type="button" :aria-pressed="(currentView === 'month').toString()" @click="changeView('month')">{{ __('Month') }}</button>
                <button type="button" :aria-pressed="(currentView === 'week').toString()" @click="changeView('week')">{{ __('Week') }}</button>
                <button type="button" :aria-pressed="(currentView === 'day').toString()" @click="changeView('day')">{{ __('Day') }}</button>
            </div>
        </div>

        <p class="kb-legend">
            <span>{{ __('Left edge marks coverage:') }}</span>
            <span class="kb-inline"><span class="kb-ev__mark kb-ev__mark--note" aria-hidden="true"></span> {{ __('notes') }}</span>
            <span class="kb-inline"><span class="kb-ev__mark kb-ev__mark--transcript" aria-hidden="true"></span> {{ __('transcript') }}</span>
            <span>{{ __('both = note and transcript marks together · dashed and struck = cancelled · every event also states its coverage in its accessible name') }}</span>
        </p>

        <div class="kb-empty" x-show="hasEvents === false" x-cloak>
            <h3>{{ __('Nothing in this range') }}</h3>
            <p>{{ __('The mirror is current — this range is genuinely empty rather than unsynced.') }} {{ $syncNote }}</p>
        </div>

        <div
            class="kb-cal"
            x-ref="grid"
            :aria-label="rangeLabel + ', ' + currentView + ' view'"
        ></div>
    </div>
</x-layouts::app>
