import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import { onRefreshTick } from './self-refresh.js';

function formatIso(year, month, day) {
    return [year, String(month).padStart(2, '0'), String(day).padStart(2, '0')].join('-');
}

function dayOfMonthFromIso(iso) {
    return Number(iso.split('-')[2]);
}

function addDaysToIso(iso, delta) {
    const date = new Date(`${iso}T00:00:00`);
    date.setDate(date.getDate() + delta);

    return formatIso(date.getFullYear(), date.getMonth() + 1, date.getDate());
}

function daysInMonth(year, month) {
    // Day 0 of the following month is the last day of this one.
    return new Date(year, month, 0).getDate();
}

/**
 * The ISO date `delta` whole months from the given (year, month), landing on
 * `dayOfMonth` clamped to the target month's length. No DOM and no calendar
 * state: numbers in, string out.
 *
 * The clamp is explicit because Date's own rollover turns 31 February into
 * 3 March, which would page the month grid past its own range. It takes a
 * year/month pair rather than an ISO date so no future caller can hand it a
 * full date and silently lose the day component.
 */
function monthStepToIso(year, month, delta, dayOfMonth) {
    const zeroBased = (year * 12) + (month - 1) + delta;
    const targetYear = Math.floor(zeroBased / 12);
    const targetMonth = (zeroBased % 12) + 1;

    return formatIso(targetYear, targetMonth, Math.min(dayOfMonth, daysInMonth(targetYear, targetMonth)));
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

        // The last payload that came back for a given window, so a failed
        // self-refresh can re-serve it instead of blanking the grid (US-011).
        lastFetch: { key: null, events: [] },

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
                    const key = `${from}|${to}`;

                    fetch(`${config.eventsUrl}?from=${from}&to=${to}`)
                        .then((response) => {
                            if (!response.ok) {
                                throw new Error(`Calendar feed responded with ${response.status}`);
                            }

                            return response.json();
                        })
                        .then((events) => {
                            this.lastFetch = { key, events };
                            this.hasEvents = events.length > 0;
                            successCallback(events);
                        })
                        .catch((error) => {
                            // A refresh that fails leaves the last good render
                            // on screen rather than blanking the grid or
                            // dropping to the empty state (US-011). A *first*
                            // fetch for a window has nothing to fall back on,
                            // so it still fails through — and hasEvents stays
                            // null, which keeps the empty block hidden instead
                            // of claiming the range is genuinely empty.
                            if (this.lastFetch.key === key) {
                                successCallback(this.lastFetch.events);

                                return;
                            }

                            failureCallback(error);
                        });
                },
                eventsSet: () => {
                    // Day cells survive an event-only re-render, but reasserting
                    // the roving tabindex is cheap and never moves focus, so the
                    // keyboard-focused cell cannot be lost to a refetch.
                    this.syncRovingTabindex();
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

            // refetchEvents() re-runs the events function for the visible
            // window only. It changes neither the view nor the anchor date and
            // does not fire datesSet, so the URL state, the pending-focus
            // machinery and the keyboard-focused day cell are all untouched.
            this.$cleanup(onRefreshTick(() => this.calendar.refetchEvents()));

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
            this.pendingFocusDate = this.pagedFocusDate(direction);
            this.pendingFocusShouldMoveFocus = true;

            direction > 0 ? this.calendar.next() : this.calendar.prev();
        },

        /**
         * Where focus lands after paging. Every view queues a target — a month
         * that queued nothing used to drop focus to <body>, because datesSet
         * then only reassigned tabindex and FullCalendar had already destroyed
         * the focused cell.
         */
        pagedFocusDate(direction) {
            if (this.currentView === 'week') {
                return addDaysToIso(this.focusedDate, direction * 7);
            }

            if (this.currentView === 'day') {
                return addDaysToIso(this.focusedDate, direction);
            }

            // Month steps off the calendar's own anchor, not focusedDate:
            // after an arrow-key walk focus can sit on a leading or trailing
            // cell belonging to a neighbouring month, and stepping from there
            // would target a month the grid is not about to render.
            const anchor = this.calendar.getDate();

            return monthStepToIso(
                anchor.getFullYear(),
                anchor.getMonth() + 1,
                direction,
                dayOfMonthFromIso(this.focusedDate),
            );
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
