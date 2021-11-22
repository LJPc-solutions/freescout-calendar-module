'use strict';

let CalendarList = [];
let ScheduleList = [];

function CalendarInfo() {
    this.id = null;
    this.name = null;
    this.checked = true;
    this.external = false;
    this.color = null;
    this.bgColor = null;
    this.borderColor = null;
    this.dragBgColor = null;
}

function addCalendar(calendar) {
    CalendarList.push(calendar);
}

function findCalendar(id) {
    let found;
    id = parseInt(id, 10)

    CalendarList.forEach(function(calendar) {
        if(calendar.id === id) {
            found = calendar;
        }
    });

    return found || CalendarList[0];
}

function ScheduleInfo() {
    this.id = null;
    this.calendarId = null;

    this.title = null;
    this.body = null;
    this.location = null;
    this.isAllday = false;
    this.start = null;
    this.end = null;
    this.category = '';

    this.color = null;
    this.bgColor = null;
    this.dragBgColor = null;
    this.borderColor = null;

    this.isVisible = true;
    this.isReadOnly = false;
    this.isPrivate = false;
    this.state = '';

    this.raw = {
        creator: {
            id: '',
            name: '',
            avatar: '',
            company: '',
            email: '',
            phone: ''
        }
    };
}


$(document).ready(function() {
    const Calendar = tui.Calendar;
    let cal, resizeThrottled;
    let useCreationPopup = true;
    let useDetailPopup = true;
    let datePicker, selectedCalendar;

    function uuidv4() {
        return ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, c =>
            (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
        );
    }

    cal = new Calendar('#calendar', {
        defaultView: 'week',
        useCreationPopup: useCreationPopup,
        useDetailPopup: useDetailPopup,
        calendars: CalendarList,
        taskView: false,
        usageStatistics: false,
        week: {
            startDayOfWeek: 1,
            daynames: dayTranslations
        },
        month: {
            startDayOfWeek: 1,
            daynames: dayTranslations
        },
        template: {
            allday: function(schedule) {
                return getTimeTemplate(schedule, true);
            },
            time: function(schedule) {
                return getTimeTemplate(schedule, false);
            },
            popupIsAllDay: function() {
                return translations.popupIsAllDay;
            },
            popupStateFree: function() {
                return translations.popupStateFree;
            },
            popupStateBusy: function() {
                return translations.popupStateBusy;
            },
            popupSave: function() {
                return translations.popupSave;
            },
            popupUpdate: function() {
                return translations.popupUpdate;

            },
            timegridDisplayPrimaryTime: function(time) {
                var hour = time.hour;
                return hour + ':00';
            },
            timegridCurrentTime: function(timezone) {
                var templates = [];

                if(timezone.dateDifference) {
                    templates.push('[' + timezone.dateDifferenceSign + timezone.dateDifference + ']<br>');
                }

                templates.push(moment(timezone.hourmarker.toUTCString()).format('HH:mm'));

                return templates.join('');
            },
            popupDetailDate: function(isAllDay, start, end) {
                var isSameDate = moment(start.toDate()).format('YYYY-MM-DD') === moment(end.toDate()).format('YYYY-MM-DD');
                var endFormat = (isSameDate ? '' : translations.dateFormat + ' ') + translations.timeFormat;

                if(isAllDay) {
                    return moment(start.toDate()).format(translations.dateFormat) + (isSameDate ? '' : ' - ' + moment(end.toDate()).format(translations.dateFormat));
                }

                return (moment(start.toDate()).format(translations.dateFormat + " " + translations.timeFormat) + ' - ' + moment(end.toDate()).format(endFormat));
            },
            popupDetailLocation: function(schedule) {
                return translations.popupDetailLocation + ': ' + schedule.location;
            },
            popupDetailUser: function(schedule) {
                return translations.popupDetailUser + ': ' + (schedule.attendees || []).join(', ');
            },
            popupDetailState: function(schedule) {
                return translations.popupDetailState + ': ' + schedule.state || translations.popupStateBusy;
            },
            popupDetailRepeat: function(schedule) {
                return translations.popupDetailRepeat + ': ' + schedule.recurrenceRule;
            },
            popupDetailBody: function(schedule) {
                return translations.popupDetailBody + ': ' + schedule.body;
            },
            popupEdit: function() {
                return translations.popupEdit;
            },
            popupDelete: function() {
                return translations.popupDelete;
            },
            titlePlaceholder: function() {
                return translations.titlePlaceholder;
            },
            locationPlaceholder: function() {
                return translations.locationPlaceholder
            },
            startDatePlaceholder: function() {
                return translations.startDatePlaceholder
            },
            endDatePlaceholder: function() {
                return translations.endDatePlaceholder
            },
            alldayTitle: function() {
                return '<span class="tui-full-calendar-left-content">' + translations.alldayTitle + '</span>';
            },
        }
    });


    function ajaxCall(data) {
        data = JSON.parse(JSON.stringify(data));
        $.ajax({
            type: "POST",
            url: ajaxHelpers.url,
            headers: {
                'X-CSRF-Token': ajaxHelpers.csrfToken,
            },
            data: data,
        });
    }

    // event handlers
    cal.on({
        'beforeCreateSchedule': function(e) {
            const calendar = e.calendar || findCalendar(e.calendarId);
            let schedule = {
                id: String(uuidv4()),
                title: e.title,
                isAllDay: e.isAllDay,
                start: e.start,
                end: e.end,
                category: e.isAllDay ? 'allday' : 'time',
                dueDateClass: '',
                color: calendar.color,
                bgColor: calendar.bgColor,
                dragBgColor: calendar.bgColor,
                borderColor: calendar.borderColor,
                location: e.location,
                isPrivate: e.isPrivate,
                state: e.state
            };
            const originalSchedule = Object.assign({}, schedule);
            schedule.start = schedule.start.getTime() / 1000;
            schedule.end = schedule.end.getTime() / 1000;

            ajaxCall({
                action: 'create',
                calendar: JSON.parse(JSON.stringify(calendar)),
                schedule: JSON.parse(JSON.stringify(schedule))
            });

            cal.createSchedules([originalSchedule]);

            refreshScheduleVisibility();
        },
        'beforeUpdateSchedule': function(e) {
            const schedule = e.schedule;
            let changes = e.changes;
            const changesClone = Object.assign({}, e.changes);

            if(changes && changes.isAllDay === false) {
                changes.category = 'time';
                e.schedule.category = 'time';
            } else if(changes && changes.isAllDay === true) {
                changes.category = 'allday';
                e.schedule.category = 'allday';
            }

            if(changes && changes.hasOwnProperty('start')) {
                changes.start = changes.start.getTime() / 1000;
            }
            if(changes && changes.hasOwnProperty('end')) {
                changes.end = changes.end.getTime() / 1000;
            }

            if(!changes) {
                changes = {};
            }

            ajaxCall({
                action: 'update',
                schedule: schedule,
                changes: changes
            });

            cal.updateSchedule(schedule.id, schedule.calendarId, changesClone);
            refreshScheduleVisibility();
        },
        'beforeDeleteSchedule': function(e) {
            ajaxCall({
                action: 'delete',
                schedule: e.schedule,
            });
            cal.deleteSchedule(e.schedule.id, e.schedule.calendarId);
        },
        'clickTimezonesCollapseBtn': function(timezonesCollapsed) {
            console.log('timezonesCollapsed', timezonesCollapsed);

            if(timezonesCollapsed) {
                cal.setTheme({
                    'week.daygridLeft.width': '77px',
                    'week.timegridLeft.width': '77px'
                });
            } else {
                cal.setTheme({
                    'week.daygridLeft.width': '60px',
                    'week.timegridLeft.width': '60px'
                });
            }

            return true;
        }
    });

    /**
     * Get time template for time and all-day
     * @param {Schedule} schedule - schedule
     * @param {boolean} isAllDay - isAllDay or hasMultiDates
     * @returns {string}
     */
    function getTimeTemplate(schedule, isAllDay) {
        var html = [];
        var start = moment(schedule.start.toUTCString());
        if(!schedule.isAllDay || start.format('HH:mm') !== '00:00') {
            html.push('<strong>' + start.format('HH:mm') + '</strong> ');
        }
        if(schedule.isPrivate) {
            html.push('<span class="calendar-font-icon ic-readonly-b"></span>');
            html.push(' ' + schedule.title);
        } else {
            if(schedule.isReadOnly) {
                html.push('<span class="calendar-font-icon ic-lock-b"></span>');
            } else if(schedule.location) {
                html.push('<span class="calendar-font-icon ic-location-b"></span>');
            }
            html.push(' ' + schedule.title);
        }

        return html.join('');
    }

    /**
     * A listener for click the menu
     * @param {Event} e - click event
     */
    function onClickMenu(e) {
        var target = $(e.target).closest('a[role="menuitem"]')[0];
        var action = getDataAction(target);
        var options = cal.getOptions();
        var viewName = '';

        switch (action) {
            case 'toggle-daily':
                viewName = 'day';
                break;
            case 'toggle-weekly':
                viewName = 'week';
                break;
            case 'toggle-monthly':
                options.month.visibleWeeksCount = 0;
                viewName = 'month';
                break;
            case 'toggle-workweek':
                options.month.workweek = !options.month.workweek;
                options.week.workweek = !options.week.workweek;
                viewName = cal.getViewName();

                target.querySelector('input').checked = !options.month.workweek;
                break;
            default:
                break;
        }

        cal.setOptions(options, true);
        cal.changeView(viewName, true);

        setDropdownCalendarType();
        setRenderRangeText();
        setSchedules();
    }

    function onClickNavi(e) {
        var action = getDataAction(e.target);

        switch (action) {
            case 'move-prev':
                cal.prev();
                break;
            case 'move-next':
                cal.next();
                break;
            case 'move-today':
                cal.today();
                break;
            default:
                return;
        }

        setRenderRangeText();
        setSchedules();
    }

    function onNewSchedule() {
        var title = $('#new-schedule-title').val();
        var location = $('#new-schedule-location').val();
        var isAllDay = document.getElementById('new-schedule-allday').checked;
        var start = datePicker.getStartDate();
        var end = datePicker.getEndDate();
        var calendar = selectedCalendar ? selectedCalendar : CalendarList[0];

        if(!title) {
            return;
        }

        cal.createSchedules([{
            id: String(uuidv4()),
            calendarId: calendar.id,
            title: title,
            isAllDay: isAllDay,
            location: location,
            start: start,
            end: end,
            category: isAllDay ? 'allday' : 'time',
            dueDateClass: '',
            color: calendar.color,
            bgColor: calendar.bgColor,
            dragBgColor: calendar.bgColor,
            borderColor: calendar.borderColor,
            state: 'Busy'
        }]);

        $('#modal-new-schedule').modal('hide');
    }

    function onChangeNewScheduleCalendar(e) {
        var target = $(e.target).closest('a[role="menuitem"]')[0];
        var calendarId = getDataAction(target);
        changeNewScheduleCalendar(calendarId);
    }

    function changeNewScheduleCalendar(calendarId) {
        var calendarNameElement = document.getElementById('calendarName');
        var calendar = findCalendar(calendarId);
        var html = [];

        html.push('<span class="calendar-bar" style="background-color: ' + calendar.bgColor + '; border-color:' + calendar.borderColor + ';"></span>');
        html.push('<span class="calendar-name">' + calendar.name + '</span>');

        calendarNameElement.innerHTML = html.join('');

        selectedCalendar = calendar;
    }

    function createNewSchedule(event) {
        var start = event.start ? new Date(event.start.getTime()) : new Date();
        var end = event.end ? new Date(event.end.getTime()) : moment().add(1, 'hours').toDate();

        if(useCreationPopup) {
            cal.openCreationPopup({
                start: start,
                end: end
            });
        }
    }

    function onChangeCalendars(e) {
        console.log(e);
        var calendarId = e.target.value;
        var checked = e.target.checked;
        var viewAll = document.querySelector('.lnb-calendars-item input');
        var calendarElements = Array.prototype.slice.call(document.querySelectorAll('#calendarList input'));
        var allCheckedCalendars = true;

        if(calendarId === 'all') {
            allCheckedCalendars = checked;

            calendarElements.forEach(function(input) {
                var span = input.parentNode;
                input.checked = checked;
                span.style.backgroundColor = checked ? span.style.borderColor : 'transparent';
            });

            CalendarList.forEach(function(calendar) {
                calendar.checked = checked;
            });
        } else {
            findCalendar(calendarId).checked = checked;

            allCheckedCalendars = calendarElements.every(function(input) {
                return input.checked;
            });

            if(allCheckedCalendars) {
                viewAll.checked = true;
            } else {
                viewAll.checked = false;
            }
        }

        refreshScheduleVisibility();
    }

    function refreshScheduleVisibility() {
        var calendarElements = Array.prototype.slice.call(document.querySelectorAll('#calendarList input'));

        CalendarList.forEach(function(calendar) {
            cal.toggleSchedules(calendar.id, !calendar.checked, false);
        });

        cal.render(true);

        calendarElements.forEach(function(input) {
            var span = input.nextElementSibling;
            span.style.backgroundColor = input.checked ? span.style.borderColor : 'transparent';
        });
    }

    function setDropdownCalendarType() {
        var calendarTypeName = document.getElementById('calendarTypeName');
        var calendarTypeIcon = document.getElementById('calendarTypeIcon');
        var options = cal.getOptions();
        var type = cal.getViewName();
        var iconClassName;

        if(type === 'day') {
            type = 'Day';
            iconClassName = 'calendar-icon ic_view_day';
        } else if(type === 'week') {
            type = 'Week';
            iconClassName = 'calendar-icon ic_view_week';
        } else {
            type = 'Month';
            iconClassName = 'calendar-icon ic_view_month';
        }

        calendarTypeName.innerHTML = type;
        calendarTypeIcon.className = iconClassName;
    }

    function currentCalendarDate(format) {
        var currentDate = moment([cal.getDate().getFullYear(), cal.getDate().getMonth(), cal.getDate().getDate()]);

        return currentDate.format(format);
    }

    function setRenderRangeText() {
        var renderRange = document.getElementById('renderRange');
        var options = cal.getOptions();
        var viewName = cal.getViewName();

        var html = [];
        if(viewName === 'day') {
            html.push(currentCalendarDate(translations.dateFormat));
        } else if(viewName === 'month' &&
            (!options.month.visibleWeeksCount || options.month.visibleWeeksCount > 4)) {
            html.push(currentCalendarDate(translations.monthFormat));
        } else {
            html.push(moment(cal.getDateRangeStart().getTime()).format(translations.dateFormat));
            html.push(' - ');
            html.push(moment(cal.getDateRangeEnd().getTime()).format(translations.dateFormat));
        }
        renderRange.innerHTML = html.join('');
    }

    function setSchedules() {
        $.ajax({
            type: "GET",
            url: ajaxHelpers.url,
            data: {
                start: cal.getDateRangeStart().getTime() / 1000,
                end: cal.getDateRangeEnd().getTime() / 1000
            },
            success: function(data) {
                cal.clear();
                ScheduleList = [];
                for (let i in data) {
                    const item = data[i];
                    const calendar = findCalendar(item.calendarId);
                    item.bgColor = calendar.bgColor;
                    item.dragBgColor = calendar.dragBgColor;
                    item.borderColor = calendar.borderColor;
                    item.color = calendar.color;

                    ScheduleList.push(item);
                }
                cal.createSchedules(ScheduleList);

                refreshScheduleVisibility();
            }
        });
    }

    function setEventListener() {
        $('#menu-navi').on('click', onClickNavi);
        $('.dropdown-menu a[role="menuitem"]').on('click', onClickMenu);
        $('#lnb-calendars').on('change', onChangeCalendars);

        $('#btn-save-schedule').on('click', onNewSchedule);
        $('#btn-new-schedule').on('click', createNewSchedule);

        $('#dropdownMenu-calendars-list').on('click', onChangeNewScheduleCalendar);

        window.addEventListener('resize', resizeThrottled);
    }

    function getDataAction(target) {
        return target.dataset ? target.dataset.action : target.getAttribute('data-action');
    }


    resizeThrottled = tui.util.throttle(function() {
        cal.render();
    }, 50);

    setTimeout(function() {
        var channel = poly.subscribe('calendar');
        channel.on('Modules\\LJPcCalendarModule\\Events\\CalendarUpdatedEvent', function(data, event) {
            setSchedules();
        });
    }, 2000);

    window.cal = cal;

    setDropdownCalendarType();
    setRenderRangeText();
    setSchedules();
    setEventListener();

    const calendarList = document.getElementById('calendarList');
    let html = [];
    CalendarList.forEach(function(calendar) {
        html.push('<div class="lnb-calendars-item"><label>' +
            '<input type="checkbox" class="tui-full-calendar-checkbox-round" value="' + calendar.id + '" checked>' +
            '<span style="border-color: ' + calendar.borderColor + '; background-color: ' + calendar.borderColor + ';"></span>' +
            '<span>' + calendar.name + '</span>' +
            '</label></div>'
        );
    });
    calendarList.innerHTML = html.join('\n');
});
