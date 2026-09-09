import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';

function addDaysToIso(iso, delta) {
    const date = new Date(`${iso}T00:00:00`);
    date.setDate(date.getDate() + delta);

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function markEl(kind) {
    const span = document.createElement('span');
    span.className = `kb-ev__mark kb-ev__mark--${kind}`;

    return span;
}

document.addEventListener('alpine:init', () => {
    Alpine.data('kbCalendar', (config) => ({
        calendar: null,
        rangeLabel: config.initialLabel,
        currentView: config.initialView,
        hasEvents: null,
        focusedDate: config.initialDate,
        pendingFocusDate: null,
        pendingFocusShouldMoveFocus: true,
        lastMoreLinkTrigger: null,

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
                eventDidMount: (info) => {
                    const props = info.event.extendedProps;
                    info.el.setAttribute('aria-label', props.accessibleName);

                    const marks = document.createElement('span');
                    marks.className = 'kb-ev__marks';
                    marks.setAttribute('aria-hidden', 'true');

                    if (props.hasNotes) {
                        marks.appendChild(markEl('note'));
                    }

                    if (props.hasTranscript) {
                        marks.appendChild(markEl('transcript'));
                    }

                    if (marks.childElementCount > 0) {
                        (info.el.querySelector('.fc-event-title-container') ?? info.el).appendChild(marks);
                    }
                },
                datesSet: (info) => {
                    this.rangeLabel = info.view.title;
                    this.currentView = this.viewKeyFor(info.view.type);
                    this.replaceUrlState();
                    this.updateGridAriaLabel();

                    this.$nextTick(() => {
                        if (this.pendingFocusDate) {
                            const date = this.pendingFocusDate;
                            this.pendingFocusDate = null;
                            this.focusOn(date, { moveFocus: this.pendingFocusShouldMoveFocus });
                        } else {
                            this.syncRovingTabindex();
                        }
                    });
                },
            });

            this.calendar.render();

            this.$refs.grid.addEventListener('keydown', (event) => this.onGridKeydown(event));
            this.$refs.grid.addEventListener('click', (event) => {
                if (event.target.closest('.fc-daygrid-more-link')) {
                    this.lastMoreLinkTrigger = event.target.closest('.fc-daygrid-more-link');
                }
            });

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

        updateGridAriaLabel() {
            // FullCalendar's own table already carries role="grid"; that is
            // the element a screen reader announces on entry, so the range
            // label belongs there too, not only on our wrapping div.
            const gridEl = this.$refs.grid.querySelector('[role="grid"]');

            if (gridEl) {
                gridEl.setAttribute('aria-label', `${this.rangeLabel}, ${this.currentView} view`);
            }
        },

        // --- Keyboard grid navigation (roving tabindex) -------------------

        dayCells() {
            return Array.from(this.$refs.grid.querySelectorAll('.fc-daygrid-day[data-date], .fc-timegrid-col[data-date]'));
        },

        cellFor(isoDate) {
            return this.dayCells().find((cell) => cell.dataset.date === isoDate) ?? null;
        },

        syncRovingTabindex() {
            const cells = this.dayCells();

            if (cells.length === 0) {
                return;
            }

            let target = cells.find((cell) => cell.dataset.date === this.focusedDate);

            if (!target) {
                target = cells.find((cell) => cell.dataset.date === config.todayIso) ?? cells[0];
                this.focusedDate = target.dataset.date;
            }

            cells.forEach((cell) => cell.setAttribute('tabindex', cell === target ? '0' : '-1'));
        },

        focusOn(isoDate, { moveFocus = true } = {}) {
            this.focusedDate = isoDate;
            const cell = this.cellFor(isoDate);

            if (cell) {
                this.syncRovingTabindex();

                if (moveFocus) {
                    cell.focus();
                }

                return;
            }

            // Outside the currently rendered range: navigate the calendar
            // there and pick the focus up again once datesSet re-fires.
            this.pendingFocusDate = isoDate;
            this.pendingFocusShouldMoveFocus = moveFocus;
            this.calendar.gotoDate(isoDate);
        },

        moveFocusByDays(delta) {
            this.focusOn(addDaysToIso(this.focusedDate, delta));
        },

        focusRowEdge(edge) {
            const cell = this.cellFor(this.focusedDate);

            if (!cell) {
                return;
            }

            const row = cell.closest('tr') ?? this.$refs.grid;
            const cells = Array.from(row.querySelectorAll('.fc-daygrid-day[data-date], .fc-timegrid-col[data-date]'));

            if (cells.length === 0) {
                return;
            }

            const target = edge === 'start' ? cells[0] : cells[cells.length - 1];
            this.focusOn(target.dataset.date);
        },

        pageView(direction) {
            const stepDays = this.currentView === 'week' ? 7 : (this.currentView === 'day' ? 1 : null);

            if (stepDays) {
                this.pendingFocusDate = addDaysToIso(this.focusedDate, direction * stepDays);
                this.pendingFocusShouldMoveFocus = true;
            }

            direction > 0 ? this.calendar.next() : this.calendar.prev();
        },

        onGridKeydown(event) {
            if (event.key === 'Escape') {
                const popover = this.$refs.grid.querySelector('.fc-popover');

                if (popover) {
                    event.preventDefault();
                    popover.querySelector('.fc-popover-close')?.click();
                    this.lastMoreLinkTrigger?.focus();

                    return;
                }
            }

            const cell = event.target.closest('.fc-daygrid-day[data-date], .fc-timegrid-col[data-date]');

            if (!cell) {
                return;
            }

            const rowStep = this.currentView === 'month' ? 7 : null;

            switch (event.key) {
                case 'ArrowLeft':
                    event.preventDefault();
                    this.moveFocusByDays(-1);
                    break;
                case 'ArrowRight':
                    event.preventDefault();
                    this.moveFocusByDays(1);
                    break;
                case 'ArrowUp':
                    if (rowStep) {
                        event.preventDefault();
                        this.moveFocusByDays(-rowStep);
                    }
                    break;
                case 'ArrowDown':
                    if (rowStep) {
                        event.preventDefault();
                        this.moveFocusByDays(rowStep);
                    }
                    break;
                case 'Home':
                    event.preventDefault();
                    this.focusRowEdge('start');
                    break;
                case 'End':
                    event.preventDefault();
                    this.focusRowEdge('end');
                    break;
                case 'PageUp':
                    event.preventDefault();
                    this.pageView(-1);
                    break;
                case 'PageDown':
                    event.preventDefault();
                    this.pageView(1);
                    break;
            }
        },
    }));
});
