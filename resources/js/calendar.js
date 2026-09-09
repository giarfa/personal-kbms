import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';

document.addEventListener('alpine:init', () => {
    Alpine.data('kbCalendar', (config) => ({
        calendar: null,
        rangeLabel: config.initialLabel,
        currentView: config.initialView,
        hasEvents: null,

        init() {
            this.calendar = new Calendar(this.$refs.grid, {
                plugins: [dayGridPlugin, timeGridPlugin],
                initialView: config.initialFullCalendarView,
                initialDate: config.initialDate,
                headerToolbar: false,
                firstDay: 1,
                timeZone: 'local',
                dayMaxEventRows: true,
                eventDisplay: 'block',
                nowIndicator: true,
                height: 'auto',
                events: (fetchInfo, successCallback, failureCallback) => {
                    const from = fetchInfo.startStr.slice(0, 10);
                    const to = fetchInfo.endStr.slice(0, 10);

                    fetch(`${config.eventsUrl}?from=${from}&to=${to}`)
                        .then((response) => {
                            if (!response.ok) {
                                throw new Error(`Calendar feed responded with ${response.status}`);
                            }

                            return response.json();
                        })
                        .then((events) => {
                            this.hasEvents = events.length > 0;
                            successCallback(events);
                        })
                        .catch(failureCallback);
                },
                datesSet: (info) => {
                    this.rangeLabel = info.view.title;
                    this.currentView = this.viewKeyFor(info.view.type);
                    this.replaceUrlState();
                },
            });

            this.calendar.render();

            // The calendar owns DOM the Alpine directive system never touches
            // directly, so it needs an explicit teardown when this element
            // is removed — otherwise a wire:navigate away leaks the instance.
            this.$cleanup(() => this.calendar?.destroy());
        },

        viewKeyFor(fullCalendarViewType) {
            return Object.entries(config.viewMap).find(([, value]) => value === fullCalendarViewType)?.[0]
                ?? this.currentView;
        },

        prev() {
            this.calendar.prev();
        },

        next() {
            this.calendar.next();
        },

        today() {
            this.calendar.today();
        },

        changeView(viewKey) {
            this.currentView = viewKey;
            this.calendar.changeView(config.viewMap[viewKey]);
        },

        replaceUrlState() {
            const date = this.calendar.getDate();
            const isoDate = [
                date.getFullYear(),
                String(date.getMonth() + 1).padStart(2, '0'),
                String(date.getDate()).padStart(2, '0'),
            ].join('-');

            const url = new URL(window.location.href);
            url.searchParams.set('view', this.currentView);
            url.searchParams.set('date', isoDate);
            window.history.replaceState({}, '', url);
        },
    }));
});
