<script {!! \Helper::cspNonceAttr() !!} src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/moment.min.js' }}"></script>
<script {!! \Helper::cspNonceAttr() !!} src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/toastui-calendar.min.js' }}"></script>
<script {!! \Helper::cspNonceAttr() !!} >
    document.addEventListener("DOMContentLoaded", (event) => {
        // Set the locale
        moment.locale('{{Helper::getRealAppLocale()}}');

        // Constants
        const calendars = {!! json_encode($calendars) !!};
        const csrfToken = '{{ csrf_token() }}';
        const calendarOptions = {
            defaultView: 'week',
            useDetailPopup: false,
            usageStatistics: false,
            scheduleView: ["time"],
            week: {
                startDayOfWeek: 1,
                taskView: false,
                eventView: ['allday', 'time'],
                dayNames: window.ljpccalendarmoduledaytranslations,
            },
            calendars: calendars,
        };

        // DOM elements
        const dom = {
            general: {
                calendar: document.getElementById('calendar'),
            },
            calendarPicker: {
                container: document.getElementById('calendar-picker'),
            },
            topBar: {
                previousButton: document.getElementById('previous-button'),
                nextButton: document.getElementById('next-button'),
                todayButton: document.getElementById('today-button'),
                currentDate: document.getElementById('current-date'),
            },
            newEventModal: {
                modal: document.getElementById('event-modal'),
                modalClose: document.querySelector('.event-modal-close'),
                title: document.getElementById('event-title'),
                start: document.getElementById('event-start'),
                end: document.getElementById('event-end'),
                location: document.getElementById('event-location'),
                body: document.getElementById('event-body'),
                calendar: document.getElementById('event-calendar'),
                saveButton: document.getElementById('save-button'),
            },
            eventDetailModal: {
                modal: document.getElementById('event-details-modal'),
                modalClose: document.querySelector('#event-details-modal .event-modal-close'),
                title: document.getElementById('event-details-title'),
                start: document.getElementById('event-details-start'),
                end: document.getElementById('event-details-end'),
                location: document.getElementById('event-details-location'),
                body: document.getElementById('event-details-body'),
                calendar: document.getElementById('event-details-calendar'),
                hiddenCalendar: document.getElementById('hidden-event-details-calendar'),
                hiddenUid: document.getElementById('hidden-event-details-uid'),
                updateButton: document.getElementById('update-button'),
                deleteButton: document.getElementById('delete-button'),
            }
        };

        // Helper functions
        const hasPermissions = (calendarId, permission) => {
            const calendar = calendars.find(calendar => calendar.id === calendarId);
            if (typeof calendar === 'undefined')
                return false;
            return calendar.permissions.hasOwnProperty(permission) && calendar.permissions[permission];
        }
        const outerHeight = (element) => {
            const height = element.offsetHeight,
                style = window.getComputedStyle(element)

            return ['top', 'bottom']
                .map(side => parseInt(style[`margin-${side}`]))
                .reduce((total, side) => total + side, height)
        }

        // Calendar
        const calendar = new tui.Calendar(dom.general.calendar, calendarOptions);

        // Calendar picker
        const calendarButtons = document.createElement('div');
        calendarButtons.classList.add('calendar-buttons');

        const calendarInstances = [];

        calendars.forEach(calendarObj => {
            const button = document.createElement('button');
            button.classList.add('calendar-button');
            button.innerHTML = `<span class="calendar-button-dot" style="color: ${calendarObj.backgroundColor};"></span>${calendarObj.name}`;

            const calendarInstance = {
                id: calendarObj.id,
                name: calendarObj.name,
                backgroundColor: calendarObj.backgroundColor,
                isVisible: true,
                render: () => {
                    const dot = button.querySelector('.calendar-button-dot');
                    dot.classList.toggle('transparent', !calendarInstance.isVisible);
                    calendar.setCalendarVisibility(calendarInstance.id, calendarInstance.isVisible);
                }
            };

            button.addEventListener('click', () => {
                calendarInstance.isVisible = !calendarInstance.isVisible;
                calendarInstance.render();
            });

            calendarButtons.appendChild(button);
            calendarInstances.push(calendarInstance);
        });

        dom.calendarPicker.container.appendChild(calendarButtons);

        // Rerender functions
        const rerender = () => {
            const navbarHeight = outerHeight(document.querySelector('.navbar'));
            const footerHeight = outerHeight(document.querySelector('.footer'));
            const maxHeight = window.innerHeight - navbarHeight - footerHeight;

            const calendarNavbarHeight = outerHeight(document.querySelector('.calendar-wrapper .navbar'));

            document.querySelector('.ljpc_calendar_module').style.minHeight = `${maxHeight}px`;
            dom.general.calendar.style.minHeight = `${maxHeight - calendarNavbarHeight}px`;
            dom.general.calendar.style.height = `${maxHeight - calendarNavbarHeight}px`;

            const isMobile = window.innerWidth < 768;
            const wantedView = isMobile ? 'day' : 'week';

            if (calendar.getViewName() !== wantedView) {
                calendar.changeView(wantedView);

                //set all calendars visible
                for (const calendarInstance of calendarInstances) {
                    calendarInstance.isVisible = true;
                    calendarInstance.render();
                }
            }

            calendar.render();

            updateTime()
        }

        const updateTime = () => {
            const start = calendar.getDateRangeStart().toDate();1
            const end = calendar.getDateRangeEnd().toDate();

            const isMobile = window.innerWidth < 768;

            if (isMobile) {
                dom.topBar.currentDate.innerHTML = moment(start).format(window.ljpccalendarmoduletranslations.dateFormat)
            } else {
                dom.topBar.currentDate.innerHTML = `${moment(start).format(window.ljpccalendarmoduletranslations.dateFormat)} - ${moment(end).format(window.ljpccalendarmoduletranslations.dateFormat)}`;
            }

            getEvents()
        }

        const api = {
            getEvents: async (start, end) => {
                return (await fetch(laroute.route('ljpccalendarmodule.api.events') + `?start=${start}&end=${end}`)).json();
            },
            createEvent: async (event) => {
                return (await fetch(laroute.route('ljpccalendarmodule.api.event.create'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(event),
                })).json();
            },
            updateEvent: async (event) => {
                return (await fetch(laroute.route('ljpccalendarmodule.api.event.update') + `?id=${event.uid}&_method=PUT`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(event),
                })).json();
            },
            deleteEvent: async (eventId, calendarId) => {
                return (await fetch(laroute.route('ljpccalendarmodule.api.event.delete') + `?id=${eventId}&calendarId=${calendarId}&_method=DELETE`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                    },
                })).json();
            },
        }

        const getEvents = () => {
            const start = moment(calendar.getDateRangeStart().toDate()).set({"hour": 0, "minute": 0}).toISOString();
            const end = moment(calendar.getDateRangeEnd().toDate()).set({"hour": 23, "minute": 59}).toISOString();

            api.getEvents(start, end).then(data => {
                calendar.clear();
                calendar.createEvents(data);

                for (const calendarInstance of calendarInstances) {
                    calendar.setCalendarVisibility(calendarInstance.id, calendarInstance.isVisible);
                }
            });
        }

        dom.topBar.previousButton.addEventListener('click', () => {
            calendar.prev();
            updateTime()
        });
        dom.topBar.nextButton.addEventListener('click', () => {
            calendar.next();
            updateTime()
        });
        dom.topBar.todayButton.addEventListener('click', () => {
            calendar.today();
            updateTime()
        });

        /**
         * New event modal
         */
        const openNewEventModal = (event) => {
            calendar.clearGridSelections();

            dom.newEventModal.modal.style.display = 'block';

            dom.newEventModal.start.value = moment(event.start).format('YYYY-MM-DD HH:mm');
            dom.newEventModal.end.value = moment(event.end).format('YYYY-MM-DD HH:mm');

            // Populate calendar options
            dom.newEventModal.calendar.innerHTML = '<option value="" hidden>{{__('Select a calendar')}}</option>';
            calendars.forEach(calendar => {
                if (!hasPermissions(calendar.id, 'createItems')) {
                    return;
                }
                if (calendar.type === 'normal' || calendar.type === 'caldav') {
                    const option = document.createElement('option');
                    option.value = calendar.id;
                    option.text = calendar.name;
                    dom.newEventModal.calendar.add(option);
                }
            });
        }
        const closeNewEventModal = () => {
            dom.newEventModal.modal.style.display = 'none';
            dom.newEventModal.title.value = '';
            dom.newEventModal.start.value = '';
            dom.newEventModal.end.value = '';
            dom.newEventModal.location.value = '';
            dom.newEventModal.body.value = '';
            dom.newEventModal.calendar.value = '';
        }
        dom.newEventModal.modalClose.addEventListener('click', closeNewEventModal);
        window.addEventListener('click', (event) => {
            if (event.target === dom.newEventModal.modal) {
                closeNewEventModal();
            }
        });
        const eventForm = document.querySelector('#event-modal form');
        eventForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            //disable save button
            dom.newEventModal.saveButton.disabled = true;

            try {
                api.createEvent({
                    calendarId: dom.newEventModal.calendar.value,
                    title: dom.newEventModal.title.value,
                    start: moment(dom.newEventModal.start.value).toISOString(),
                    end: moment(dom.newEventModal.end.value).toISOString(),
                    location: dom.newEventModal.location.value,
                    body: dom.newEventModal.body.value,
                }).then(response => {
                    //enable save button
                    dom.newEventModal.saveButton.disabled = false;

                    if (response.ok) {
                        getEvents();
                        closeNewEventModal();
                        showFloatingAlert('success', '{{__('Event created successfully')}}');
                    } else {
                        showFloatingAlert('error', '{{__('Failed to create event')}}');
                    }
                });
            } catch (error) {
                showFloatingAlert('error', '{{__('Failed to create event')}}');
            }
        });

        /**
         * Event details modal
         */
        const openEventDetailsModal = (event) => {
            dom.eventDetailModal.modal.style.display = 'block';

            dom.eventDetailModal.title.value = event.title;
            dom.eventDetailModal.start.value = moment(event.start.toDate()).format('YYYY-MM-DDTHH:mm');
            dom.eventDetailModal.end.value = moment(event.end.toDate()).format('YYYY-MM-DDTHH:mm');
            dom.eventDetailModal.location.value = event.location;
            dom.eventDetailModal.body.value = event.body;
            dom.eventDetailModal.hiddenUid.value = event.id;

            // Clear previous options
            dom.eventDetailModal.calendar.innerHTML = '';

            // Populate calendar options
            calendars.forEach(calendar => {
                if (calendar.id === event.calendarId) {
                    dom.eventDetailModal.calendar.innerHTML = calendar.name;
                    dom.eventDetailModal.hiddenCalendar.value = calendar.id;
                    if (!hasPermissions(calendar.id, 'editItems') || (calendar.type !== 'normal' && calendar.type !== 'caldav')) {
                        dom.eventDetailModal.title.readOnly = true;
                        dom.eventDetailModal.start.readOnly = true;
                        dom.eventDetailModal.end.readOnly = true;
                        dom.eventDetailModal.location.readOnly = true;
                        dom.eventDetailModal.body.readOnly = true;
                        dom.eventDetailModal.updateButton.disabled = true;
                        dom.eventDetailModal.deleteButton.disabled = true;
                    } else {
                        dom.eventDetailModal.title.readOnly = false;
                        dom.eventDetailModal.start.readOnly = false;
                        dom.eventDetailModal.end.readOnly = false;
                        dom.eventDetailModal.location.readOnly = false;
                        dom.eventDetailModal.body.readOnly = false;
                        dom.eventDetailModal.updateButton.disabled = false;
                        dom.eventDetailModal.deleteButton.disabled = false;

                    }
                }

            });
        }

        dom.eventDetailModal.deleteButton.addEventListener('click', async () => {
            const eventId = dom.eventDetailModal.hiddenUid.value;
            const calendarId = dom.eventDetailModal.hiddenCalendar.value;

            //disable delete and update button
            dom.eventDetailModal.deleteButton.disabled = true;
            dom.eventDetailModal.updateButton.disabled = true;

            try {
                api.deleteEvent(eventId, calendarId).then(response => {
                    //enable delete and update button
                    dom.eventDetailModal.deleteButton.disabled = false;
                    dom.eventDetailModal.updateButton.disabled = false;

                    if (response.ok) {
                        getEvents();
                        closeEventDetailsModal();
                        showFloatingAlert('success', '{{__('Event deleted successfully')}}');
                    } else {
                        showFloatingAlert('error', '{{__('Failed to delete event')}}');
                    }
                });
            } catch (error) {
                showFloatingAlert('error', '{{__('Failed to delete event')}}');
            }
        });

        const closeEventDetailsModal = () => {
            dom.eventDetailModal.modal.style.display = 'none';
            dom.eventDetailModal.title.value = '';
            dom.eventDetailModal.start.value = '';
            dom.eventDetailModal.end.value = '';
            dom.eventDetailModal.location.value = '';
            dom.eventDetailModal.body.value = '';
            dom.eventDetailModal.calendar.innerHTML = '';
        }

        dom.eventDetailModal.modalClose.addEventListener('click', closeEventDetailsModal);

        window.addEventListener('click', (event) => {
            if (event.target === dom.eventDetailModal.modal) {
                closeEventDetailsModal();
            }
        });


        document.querySelector('#event-details-modal form').addEventListener('submit', async (event) => {
            event.preventDefault();

            //disable update and delete button
            dom.eventDetailModal.updateButton.disabled = true;
            dom.eventDetailModal.deleteButton.disabled = true;

            const updatedEvent = {
                uid: dom.eventDetailModal.hiddenUid.value,
                calendarId: dom.eventDetailModal.hiddenCalendar.value,
                title: dom.eventDetailModal.title.value,
                start: moment(dom.eventDetailModal.start.value).toISOString(),
                end: moment(dom.eventDetailModal.end.value).toISOString(),
                location: dom.eventDetailModal.location.value,
                body: dom.eventDetailModal.body.value,
            };

            try {
                api.updateEvent(updatedEvent).then(response => {
                    //enable update and delete button
                    dom.eventDetailModal.updateButton.disabled = false;
                    dom.eventDetailModal.deleteButton.disabled = false;
                    if (response.ok) {
                        getEvents();
                        closeEventDetailsModal();
                        showFloatingAlert('success', '{{__('Event updated successfully')}}');
                    } else {
                        showFloatingAlert('error', '{{__('Failed to update event')}}');
                    }
                });
            } catch (error) {
                showFloatingAlert('error', '{{__('Failed to update event')}}');
            }
        });

        const dragEvent = (eventInfo) => {
            calendar.clearGridSelections();

            const changes = eventInfo.changes;
            const event = eventInfo.event;


            //updatedEvent = merge of changes + uid
            const updatedEvent = {
                uid: event.id,
                calendarId: event.calendarId,
                title: event.title,
                location: event.location,
                body: event.body,
                start: moment(event.start.toDate()).toISOString(),
                end: moment(event.end.toDate()).toISOString(),
            };

            if (changes.start) {
                updatedEvent.start = moment(changes.start.toDate()).toISOString();
            }

            if (changes.end) {
                updatedEvent.end = moment(changes.end.toDate()).toISOString();
            }

            try {
                api.updateEvent(updatedEvent);
            } catch (error) {
                showFloatingAlert('error', '{{__('Failed to update event')}}');
            }

            calendar.updateEvent(event.id, event.calendarId, changes);
        }

        /**
         * Calendar events
         */
        calendar.on('selectDateTime', openNewEventModal);
        calendar.on('beforeCreateEvent', (event) => {
            calendar.clearGridSelections();
        });
        calendar.on('clickEvent', (event) => {
            calendar.clearGridSelections();

            openEventDetailsModal(event.event);
        });
        calendar.on('beforeUpdateEvent', dragEvent);

        window.addEventListener('resize', () => {
            rerender()
        });
        rerender();

    });
</script>

