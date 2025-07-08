<script {!! \Helper::cspNonceAttr() !!} src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/moment.min.js' }}"></script>
<script {!! \Helper::cspNonceAttr() !!} src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/toastui-calendar.min.js' }}"></script>
<script {!! \Helper::cspNonceAttr() !!} >
    // Ensure laroute routes are available for calendar module
    if (typeof laroute !== 'undefined' && typeof laroute.routes !== 'undefined') {
        // Define calendar routes if they're not already defined
        var calendarRoutes = {
            'ljpccalendarmodule.api.events': '/calendar/api/events',
            'ljpccalendarmodule.api.event.get': '/calendar/api/event/{eventId}',
            'ljpccalendarmodule.api.event.create': '/calendar/api/events',
            'ljpccalendarmodule.api.event.update': '/calendar/api/events',
            'ljpccalendarmodule.api.event.delete': '/calendar/api/events',
            'ljpccalendarmodule.api.calendar.get': '/calendar/api/calendars/{id}',
            'ljpccalendarmodule.api.event.create_from_attachment': '/calendar/api/events/attachment',
            'ljpccalendarmodule.api.event.create_from_conversation': '/calendar/api/events/{conversation}',
            'ljpccalendarmodule.index': '/calendar'
        };
        
        // Manually add routes if needed
        for (var routeName in calendarRoutes) {
            if (!laroute.route(routeName, [], false)) {
                // Route doesn't exist, we'll need to handle this differently
            }
        }
    }
</script>
<script {!! \Helper::cspNonceAttr() !!} >
    document.addEventListener("DOMContentLoaded", (event) => {
        // Set the locale
        moment.locale('{{Helper::getRealAppLocale()}}');

        // Constants
        const MOBILE_BREAKPOINT = 768;
        const VIEW_DAY = 'day';
        const VIEW_WEEK = 'week';
        const VIEW_MONTH = 'month';

        // Parse hash parameters for permalinks
        let hashParams;
        try {
            // Get the hash portion (remove the # character)
            const hashString = window.location.hash.substring(1);

            // Check for direct event ID format (just the ID without event= prefix)
            if (hashString && hashString.indexOf('=') === -1 && hashString.length > 0) {
                // If the hash doesn't contain '=', treat it as the event ID directly
                const params = new URLSearchParams();
                params.set('event', hashString);
                hashParams = params;
            } else {
                // Normal URLSearchParams parsing for event=ID format
                hashParams = new URLSearchParams(hashString);
            }
        } catch (err) {
            hashParams = new URLSearchParams();
        }

        const calendars = {!! json_encode($calendars) !!};
        const csrfToken = '{{ csrf_token() }}';
        let templates = {};
        @if ( Helper::isTimeFormat24())
            templates = {
            timegridDisplayPrimaryTime({time}) {
                var hour = time.getHours();
                var formattedHour = hour < 10 ? '0' + hour : hour;

                return `${formattedHour}:00`;
            }
        }
        @endif
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
            month: {
                startDayOfWeek: 1,
                dayNames: window.ljpccalendarmoduledaytranslations,
            },
            calendars: calendars,
            template: templates
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
                createdBy: document.getElementById('event-details-created-by'),
                hiddenCalendar: document.getElementById('hidden-event-details-calendar'),
                hiddenUid: document.getElementById('hidden-event-details-uid'),
                hiddenCustomFields: document.getElementById('hidden-event-details-custom-fields'),
                updateButton: document.getElementById('update-button'),
                deleteButton: document.getElementById('delete-button'),
                createCopyButton: document.getElementById('create-copy-button'),
                copyPermalinkButton: document.getElementById('copy-permalink-button'),
                originalStart: null,
                originalEnd: null,
            },
            viewSelector: {
                dayButton: document.getElementById('day-view-button'),
                weekButton: document.getElementById('week-view-button'),
                monthButton: document.getElementById('month-view-button'),
            }
        };

        // Helper functions
        const hasPermissions = (calendarId, permission) => {
            calendarId = parseInt(calendarId, 10);
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

        const isMobileView = () => window.innerWidth < MOBILE_BREAKPOINT;

        const setCalendarView = (view) => {
            calendar.changeView(view);
            updateViewButtons(view);
            updateTime();
        };

        const updateViewButtons = (currentView) => {
            dom.viewSelector.dayButton.classList.toggle('active', currentView === VIEW_DAY);
            dom.viewSelector.weekButton.classList.toggle('active', currentView === VIEW_WEEK);
            dom.viewSelector.monthButton.classList.toggle('active', currentView === VIEW_MONTH);

            // Hide week and month buttons on mobile
            const isMobile = isMobileView();
            dom.viewSelector.weekButton.style.display = isMobile ? 'none' : 'inline-block';
            dom.viewSelector.monthButton.style.display = isMobile ? 'none' : 'inline-block';
        };

        // Event listeners for view buttons
        dom.viewSelector.dayButton.addEventListener('click', () => setCalendarView(VIEW_DAY));
        dom.viewSelector.weekButton.addEventListener('click', () => setCalendarView(VIEW_WEEK));
        dom.viewSelector.monthButton.addEventListener('click', () => setCalendarView(VIEW_MONTH));


        // Function to open event by ID from permalink
        const openEventById = async (eventId) => {
            if (!eventId) {
                return false;
            }

            try {
                // Use the optimized direct lookup API first for best performance
                let event = null;
                
                try {
                    event = await api.getEventById(eventId);
                } catch (directLookupError) {
                    // If direct lookup fails, fall back to searching in current view
                    console.warn('Direct event lookup failed, falling back to view search:', directLookupError);
                    
                    const start = moment(calendar.getDateRangeStart().toDate()).set({"hour": 0, "minute": 0}).toISOString();
                    const end = moment(calendar.getDateRangeEnd().toDate()).set({"hour": 23, "minute": 59}).toISOString();
                    const eventsInView = await api.getEvents(start, end);

                    // Try exact match first
                    event = eventsInView.find(e => e.id === eventId);

                    // Then try with string conversion
                    if (!event) {
                        event = eventsInView.find(e => String(e.id) === String(eventId));
                    }

                    // Then try with number conversion (if possible)
                    if (!event && !isNaN(parseInt(eventId))) {
                        const numericId = parseInt(eventId);
                        event = eventsInView.find(e => parseInt(e.id) === numericId);
                    }
                }

                if (event && event.id && event.calendarId && event.start && event.end) {
                    try {
                        // Navigate calendar to event date
                        const eventDate = new Date(event.start);
                        calendar.setDate(eventDate);

                        // Create view model for event detail modal
                        const eventViewModel = {
                            id: event.id,
                            calendarId: event.calendarId,
                            title: event.title || '',
                            start: {toDate: () => new Date(event.start)},
                            end: {toDate: () => new Date(event.end)},
                            location: event.location || '',
                            body: event.body || '',
                            raw: event.raw || {customFields: {}}
                        };

                        // Make sure calendar for this event is visible
                        let calendarFound = false;
                        for (const calendarInstance of calendarInstances) {
                            if (calendarInstance.id === parseInt(event.calendarId)) {
                                calendarInstance.isVisible = true;
                                calendarInstance.render();
                                calendarFound = true;
                                break;
                            }
                        }

                        // Need to fully render the calendar before opening the modal
                        setTimeout(() => {
                            try {
                                openEventDetailsModal(eventViewModel);
                                getEvents(event.id);
                                updateTime();
                            } catch (err) {
                                showFloatingAlert('error', '{{__('Error displaying event details')}}');
                            }
                        }, 300);

                        return true;
                    } catch (err) {
                        showFloatingAlert('error', '{{__('Error processing event data')}}');
                        return false;
                    }
                } else {
                    // Check if the calendar module is installed
                    const calendarExists = calendars && calendars.length > 0;
                    if (!calendarExists) {
                        showFloatingAlert('error', '{{__('Calendar module appears to not be fully installed or initialized')}}');
                    } else {
                        showFloatingAlert('error', '{{__('Event not found or you do not have access to it')}}');
                    }
                    return false;
                }
            } catch (error) {
                showFloatingAlert('error', '{{__('Failed to load event')}}');
                return false;
            }
        };

        // Rerender functions
        const rerender = () => {
            const navbarHeight = outerHeight(document.querySelector('.navbar'));
            const footerHeight = outerHeight(document.querySelector('.footer'));
            const maxHeight = window.innerHeight - navbarHeight - footerHeight;

            const calendarNavbarHeight = outerHeight(document.querySelector('.calendar-wrapper .navbar'));

            document.querySelector('.ljpc_calendar_module').style.minHeight = `${maxHeight}px`;
            dom.general.calendar.style.minHeight = `${maxHeight - calendarNavbarHeight}px`;
            dom.general.calendar.style.height = `${maxHeight - calendarNavbarHeight}px`;

            const isMobile = isMobileView();
            const currentView = calendar.getViewName();
            const wantedView = isMobile ? VIEW_DAY : (currentView === VIEW_DAY ? VIEW_DAY : VIEW_WEEK);

            if (currentView !== wantedView) {
                setCalendarView(wantedView);

                // Set all calendars visible
                for (const calendarInstance of calendarInstances) {
                    calendarInstance.isVisible = true;
                    calendarInstance.render();
                }
            }

            updateViewButtons(wantedView);
            calendar.render();
            updateTime();
        }

        const updateTime = () => {
            const start = calendar.getDateRangeStart().toDate();
            const end = calendar.getDateRangeEnd().toDate();
            const currentView = calendar.getViewName();

            let dateFormat;
            if (currentView === VIEW_DAY) {
                dateFormat = moment(start).format(window.ljpccalendarmoduletranslations.dateFormat);
            } else if (currentView === VIEW_WEEK) {
                dateFormat = `${moment(start).format(window.ljpccalendarmoduletranslations.dateFormat)} - ${moment(end).format(window.ljpccalendarmoduletranslations.dateFormat)}`;
            } else {
                const middle = new Date(start.getTime() + (end.getTime() - start.getTime()) / 2);
                dateFormat = moment(middle).format('MMMM YYYY');
            }

            dom.topBar.currentDate.innerHTML = dateFormat;

            getEvents();
        }

        const api = {
            getEvents: async (start, end) => {
                const url = '{{ route('ljpccalendarmodule.api.events') }}' + `?start=${start}&end=${end}`;
                return (await fetch(url)).json();
            },
            getEventById: async (eventId) => {
                // Use the dedicated endpoint for better performance
                const safeEventId = encodeURIComponent(eventId);
                const url = '/calendar/api/event/' + safeEventId;

                try {
                    // Add timeout to prevent hanging on large calendars
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout
                    
                    const response = await fetch(url, {
                        signal: controller.signal
                    });
                    
                    clearTimeout(timeoutId);

                    if (!response.ok) {
                        if (response.status === 404) {
                            return null; // Event not found
                        }
                        throw new Error(`Failed to fetch event`);
                    }

                    const data = await response.json();
                    return data;
                } catch (error) {
                    if (error.name === 'AbortError') {
                        console.error('Event fetch timed out - calendar may be too large');
                    }
                    console.error('Error fetching event:', error);
                    return null;
                }
            },
            createEvent: async (event) => {
                const url = '{{ route('ljpccalendarmodule.api.event.create') }}';
                return (await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(event),
                })).json();
            },
            updateEvent: async (event) => {
                const url = '{{ route('ljpccalendarmodule.api.event.update') }}' + `?id=${event.uid}&_method=PUT`;
                return (await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(event),
                })).json();
            },
            deleteEvent: async (eventId, calendarId) => {
                const url = '{{ route('ljpccalendarmodule.api.event.delete') }}' + `?id=${eventId}&calendarId=${calendarId}&_method=DELETE`;
                return (await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                    },
                })).json();
            },
        }

        const getEvents = (scrollToEventId) => {
            const start = moment(calendar.getDateRangeStart().toDate()).set({"hour": 0, "minute": 0}).toISOString();
            const end = moment(calendar.getDateRangeEnd().toDate()).set({"hour": 23, "minute": 59}).toISOString();

            return api.getEvents(start, end).then(data => {
                calendar.clear();
                calendar.createEvents(data);

                for (const calendarInstance of calendarInstances) {
                    calendar.setCalendarVisibility(calendarInstance.id, calendarInstance.isVisible);
                }

                // Check if there's an event ID in the URL hash and open that event
                const eventId = hashParams.get('event');
                // replace url to remove the event id
                if (eventId && eventId.length > 0) {
                    const url = new URL(window.location.href);
                    url.hash = ''; // Remove the hash
                    window.history.replaceState({}, document.title, url.toString());
                    hashParams = new URLSearchParams(); // Clear the hashParams
                }

                if (eventId && eventId.length > 0) {

                    try {
                        // Try to find the event in current view
                        let event = null;

                        // First look for exact match
                        event = data.find(e => e.id === eventId);

                        // If not found, try a more flexible approach (numeric comparison if the ID is a number)
                        if (!event && !isNaN(parseInt(eventId))) {
                            const numericId = parseInt(eventId);
                            event = data.find(e => parseInt(e.id) === numericId);
                        }

                        if (event && event.id && event.calendarId) {
                            // Event is in current view, open it
                            const eventViewModel = {
                                id: event.id,
                                calendarId: event.calendarId,
                                title: event.title || '',
                                start: {toDate: () => new Date(event.start)},
                                end: {toDate: () => new Date(event.end)},
                                location: event.location || '',
                                body: event.body || '',
                                raw: event.raw || {customFields: {}}
                            };

                            // Make sure calendar for this event is visible
                            let calendarFound = false;
                            for (const calendarInstance of calendarInstances) {
                                if (calendarInstance.id === parseInt(event.calendarId)) {
                                    calendarInstance.isVisible = true;
                                    calendarInstance.render();
                                    calendarFound = true;
                                    break;
                                }
                            }

                            scrollToEventId = event.id;

                            // Open event details modal
                            setTimeout(() => {
                                try {
                                    openEventDetailsModal(eventViewModel);
                                } catch (err) {
                                    showFloatingAlert('error', '{{__('Error displaying event details')}}');
                                }
                            }, 300); // Short delay to ensure UI is ready
                        } else {
                            // Event not in current view, use direct method to fetch and open it
                            openEventById(eventId);
                        }
                    } catch (err) {
                        showFloatingAlert('error', '{{__('Error processing permalink')}}');
                    }
                }

                if (scrollToEventId) {
                    setTimeout(function () {
                        const dom = document.querySelector('.toastui-calendar-time');
                        const eventTop = document.querySelector('.toastui-calendar-event-time[data-event-id="' + scrollToEventId + '"]');
                        const scrollTop = eventTop.offsetTop - dom.offsetTop - 100; // Adjust scroll position

                        dom.scrollTo({top: scrollTop});
                    }, 100);
                }

                return data;
            }).catch(error => {
                showFloatingAlert('error', '{{__('Failed to load calendar events')}}');
                return [];
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

        const fetchCustomFields = async (calendarId) => {
            try {
                const baseUrl = '{{ route('ljpccalendarmodule.api.calendar.get', '') }}';
                // Remove any trailing placeholders and construct the proper URL
                const url = baseUrl.replace(/\{.*?\}$/, '') + '/' + calendarId;
                const response = await fetch(url);
                if (!response.ok) {
                    return [];
                }
                const calendar = await response.json();
                if (!calendar.custom_fields || calendar.custom_fields === null) {
                    return [];
                }
                const fields = calendar.custom_fields.fields || [];
                return fields;
            } catch (error) {
                return [];
            }
        };

        const renderCustomFields = (fields, container, canEdit, values = {}) => {
            container.innerHTML = '';
            fields.forEach(field => {
                const fieldElement = document.createElement('div');
                fieldElement.className = 'form-group';

                const label = document.createElement('label');
                label.textContent = field.name + (field.required ? ' *' : '');
                label.htmlFor = `custom_field_${field.id}`;
                fieldElement.appendChild(label);

                let input;
                switch (field.type) {
                    case 'text':
                    case 'number':
                    case 'email':
                    case 'date':
                        input = document.createElement('input');
                        input.type = field.type;
                        input.className = 'form-control';
                        input.name = `custom_field_${field.id}`;
                        input.required = field.required;
                        input.value = values[`custom_field_${field.id}`] || '';
                        break;
                    case 'dropdown':
                    case 'multiselect':
                        input = document.createElement('select');
                        input.className = 'form-control';
                        input.style = 'height: auto;';
                        input.name = `custom_field_${field.id}`;
                        input.required = field.required;
                        if (field.type === 'multiselect') {
                            input.multiple = true;
                        }
                        field.options.forEach(option => {
                            const optionElement = document.createElement('option');
                            optionElement.value = option;
                            optionElement.textContent = option;
                            if (values[`custom_field_${field.id}`] === option || (Array.isArray(values[`custom_field_${field.id}`]) && values[`custom_field_${field.id}`].includes(option))) {
                                optionElement.selected = true;
                            }
                            input.appendChild(optionElement);
                        });
                        break;
                    case 'boolean':
                        input = document.createElement('input');
                        input.type = 'checkbox';
                        input.name = `custom_field_${field.id}`;
                        input.checked = values[`custom_field_${field.id}`] === true;
                        input.style = 'margin-left:5px;'
                        break;
                    case 'source':
                        if (!values.hasOwnProperty('conversation_url') || !values.hasOwnProperty('conversation_id')) {
                            return;
                        }
                        input = document.createElement('a');
                        input.href = values.conversation_url;
                        input.textContent = '#' + values.conversation_id;
                        input.target = '_blank';
                        input.style = "display:block;";
                        break;
                    default:
                        return;
                }

                input.id = `custom_field_${field.id}`
                input.disabled = !canEdit;

                fieldElement.appendChild(input);
                container.appendChild(fieldElement);
            });
        };


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

            // Add event listener for calendar selection
            dom.newEventModal.calendar.addEventListener('change', async function () {
                const selectedCalendarId = this.value;
                const customFields = await fetchCustomFields(selectedCalendarId);
                const canEdit = hasPermissions(selectedCalendarId, 'createItems');
                renderCustomFields(customFields, document.getElementById('event-custom-fields'), canEdit);
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

            // Clear custom fields
            document.getElementById('event-custom-fields').innerHTML = '';
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

            const customFieldsData = {};
            const fields = jQuery('#event-custom-fields input, #event-custom-fields select');
            for (const fieldId of fields) {
                const field = jQuery(fieldId);
                customFieldsData[field.attr('name')] = field.attr('type') === 'checkbox' ? field.prop('checked') : field.val();
            }

            try {
                api.createEvent({
                    calendarId: dom.newEventModal.calendar.value,
                    title: dom.newEventModal.title.value,
                    start: moment(dom.newEventModal.start.value).toISOString(),
                    end: moment(dom.newEventModal.end.value).toISOString(),
                    location: dom.newEventModal.location.value,
                    body: dom.newEventModal.body.value,
                    customFields: customFieldsData
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
        const openEventDetailsModal = async (event) => {
            dom.eventDetailModal.modal.style.display = 'block';

            // Store original times
            dom.eventDetailModal.originalStart = event.start.toDate();
            dom.eventDetailModal.originalEnd = event.end.toDate();

            dom.eventDetailModal.title.value = event.title;
            dom.eventDetailModal.start.value = moment(event.start.toDate()).format('YYYY-MM-DDTHH:mm');
            dom.eventDetailModal.end.value = moment(event.end.toDate()).format('YYYY-MM-DDTHH:mm');
            dom.eventDetailModal.location.value = event.location;
            dom.eventDetailModal.body.value = event.body;
            dom.eventDetailModal.hiddenUid.value = event.id;
            if (event.raw.hasOwnProperty('customFields')) {
                dom.eventDetailModal.hiddenCustomFields.value = JSON.stringify(event.raw.customFields);
            } else {
                dom.eventDetailModal.hiddenCustomFields.value = '{}';
            }

            // Clear previous options
            dom.eventDetailModal.calendar.innerHTML = '';

            // Populate calendar options
            for (const calendar of calendars) {
                if (calendar.id === event.calendarId) {
                    dom.eventDetailModal.calendar.innerHTML = calendar.name;
                    dom.eventDetailModal.hiddenCalendar.value = calendar.id;

                    if (event.raw.customFields && event.raw.customFields.hasOwnProperty('author_name')) {
                        dom.eventDetailModal.createdBy.innerHTML = event.raw.customFields.author_name;
                    } else {
                        jQuery('.event-details-created-by-form-group').hide();
                    }

                    const canEdit = hasPermissions(calendar.id, 'editItems') && (calendar.type === 'normal' || calendar.type === 'caldav');
                    const canCreate = hasPermissions(calendar.id, 'createItems') && (calendar.type === 'normal' || calendar.type === 'caldav');

                    dom.eventDetailModal.title.readOnly = !canEdit;
                    dom.eventDetailModal.start.readOnly = !canEdit;
                    dom.eventDetailModal.end.readOnly = !canEdit;
                    dom.eventDetailModal.location.readOnly = !canEdit;
                    dom.eventDetailModal.body.readOnly = !canEdit;
                    dom.eventDetailModal.updateButton.disabled = !canEdit;
                    dom.eventDetailModal.deleteButton.disabled = !canEdit;
                    dom.eventDetailModal.createCopyButton.disabled = !canCreate;

                    // Fetch and render custom fields
                    try {
                        const customFields = await fetchCustomFields(calendar.id);
                        const customFieldsContainer = document.getElementById('event-details-custom-fields');
                        const customFieldValues = (event.raw && event.raw.customFields) ? event.raw.customFields : {};
                        renderCustomFields(customFields, customFieldsContainer, canEdit, customFieldValues);
                    } catch (error) {
                        showFloatingAlert('error', '{{__('Failed to load custom fields')}}');
                    }

                    break; // Exit the loop once we've found the matching calendar
                }
            }
        };

        dom.eventDetailModal.deleteButton.addEventListener('click', async () => {
            const eventId = dom.eventDetailModal.hiddenUid.value;
            const calendarId = dom.eventDetailModal.hiddenCalendar.value;

            if (!hasPermissions(calendarId, 'editItems')) {
                showFloatingAlert('error', '{{__('You do not have permission to delete this event')}}');
                return;
            }

            if (!confirm('{{__('Are you sure you want to delete this event?')}}')) {
                return;
            }

            // Disable delete and update buttons
            dom.eventDetailModal.deleteButton.disabled = true;
            dom.eventDetailModal.updateButton.disabled = true;

            try {
                const response = await api.deleteEvent(eventId, calendarId);
                if (response.ok) {
                    getEvents();
                    closeEventDetailsModal();
                    showFloatingAlert('success', '{{__('Event deleted successfully')}}');
                } else {
                    showFloatingAlert('error', '{{__('Failed to delete event')}}');
                }
            } catch (error) {
                showFloatingAlert('error', '{{__('Failed to delete event')}}');
            } finally {
                // Enable delete and update buttons
                dom.eventDetailModal.deleteButton.disabled = false;
                dom.eventDetailModal.updateButton.disabled = false;
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
            dom.eventDetailModal.originalStart = null;
            dom.eventDetailModal.originalEnd = null;


            // Clear custom fields
            document.getElementById('event-details-custom-fields').innerHTML = '';

            jQuery('.event-details-created-by-form-group').show();
        }

        // Copy permalink button handler
        dom.eventDetailModal.copyPermalinkButton.addEventListener('click', () => {
            const eventId = dom.eventDetailModal.hiddenUid.value;
            if (!eventId) {
                showFloatingAlert('error', '{{__('Could not generate permalink')}}');
                return;
            }

            // Create the permalink with the event ID - use simple #eventID format for better compatibility
            const url = new URL(window.location.href);
            url.hash = eventId; // Simple hash format that will be detected by our hash parser

            const permalinkUrl = url.toString();

            // Copy to clipboard
            navigator.clipboard.writeText(url.toString())
                .then(() => {
                    showFloatingAlert('success', '{{__('Permalink copied to clipboard')}}');
                })
                .catch(() => {
                    // Fallback for browsers that don't support clipboard API
                    const tempInput = document.createElement('input');
                    tempInput.value = url.toString();
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);
                    showFloatingAlert('success', '{{__('Permalink copied to clipboard')}}');
                });
        });

        dom.eventDetailModal.createCopyButton.addEventListener('click', async () => {
            const calendarId = dom.eventDetailModal.hiddenCalendar.value;

            if (!hasPermissions(calendarId, 'createItems')) {
                showFloatingAlert('error', '{{__('You do not have permission to create events in this calendar')}}');
                return;
            }

            // Disable buttons while processing
            dom.eventDetailModal.createCopyButton.disabled = true;
            dom.eventDetailModal.updateButton.disabled = true;
            dom.eventDetailModal.deleteButton.disabled = true;

            const customFieldsData = {};
            const fields = jQuery('#event-details-custom-fields input, #event-details-custom-fields select');
            for (const fieldId of fields) {
                const field = jQuery(fieldId);
                customFieldsData[field.attr('name')] = field.attr('type') === 'checkbox' ? field.prop('checked') : field.val();
            }

            // Remove conversation_id and author_id from custom fields if they exist
            delete customFieldsData['conversation_id'];
            delete customFieldsData['author_id'];

            const currentStart = new Date(dom.eventDetailModal.start.value);
            const currentEnd = new Date(dom.eventDetailModal.end.value);

            // Determine if we need to modify the title
            let title = dom.eventDetailModal.title.value;
            if (dom.eventDetailModal.originalStart.getTime() === currentStart.getTime() &&
                dom.eventDetailModal.originalEnd.getTime() === currentEnd.getTime()) {
                title = '{{__('Copy of')}} ' + title;
            }

            try {
                const response = await api.createEvent({
                    calendarId: calendarId,
                    title: title,
                    start: moment(dom.eventDetailModal.start.value).toISOString(),
                    end: moment(dom.eventDetailModal.end.value).toISOString(),
                    location: dom.eventDetailModal.location.value,
                    body: dom.eventDetailModal.body.value,
                    customFields: customFieldsData
                });

                if (response.ok) {
                    getEvents();
                    closeEventDetailsModal();
                    showFloatingAlert('success', '{{__('Event copy created successfully')}}');
                } else {
                    showFloatingAlert('error', '{{__('Failed to create event copy')}}');
                }
            } catch (error) {
                showFloatingAlert('error', '{{__('Failed to create event copy')}}');
            } finally {
                // Re-enable buttons
                dom.eventDetailModal.createCopyButton.disabled = false;
                dom.eventDetailModal.updateButton.disabled = false;
                dom.eventDetailModal.deleteButton.disabled = false;
            }
        });

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

            const customFieldsData = {};
            const fields = jQuery('#event-details-custom-fields input, #event-details-custom-fields select');
            for (const fieldId of fields) {
                const field = jQuery(fieldId);
                customFieldsData[field.attr('name')] = field.attr('type') === 'checkbox' ? field.prop('checked') : field.val();
            }

            const originalCustomFields = JSON.parse(dom.eventDetailModal.hiddenCustomFields.value);
            let newCustomFields = originalCustomFields;

            for (const key in customFieldsData) {
                if (customFieldsData[key] !== originalCustomFields[key] || !originalCustomFields.hasOwnProperty(key)) {
                    newCustomFields[key] = customFieldsData[key];
                }
            }

            const updatedEvent = {
                uid: dom.eventDetailModal.hiddenUid.value,
                calendarId: dom.eventDetailModal.hiddenCalendar.value,
                title: dom.eventDetailModal.title.value,
                start: moment(dom.eventDetailModal.start.value).toISOString(),
                end: moment(dom.eventDetailModal.end.value).toISOString(),
                location: dom.eventDetailModal.location.value,
                body: dom.eventDetailModal.body.value,
                customFields: newCustomFields
            };

            try {
                const response = await api.updateEvent(updatedEvent);
                if (response.ok) {
                    getEvents();
                    closeEventDetailsModal();
                    showFloatingAlert('success', '{{__('Event updated successfully')}}');
                } else {
                    showFloatingAlert('error', '{{__('Failed to update event')}}');
                }
            } catch (error) {
                showFloatingAlert('error', '{{__('Failed to update event')}}');
            } finally {
                // Enable update and delete buttons
                dom.eventDetailModal.updateButton.disabled = false;
                dom.eventDetailModal.deleteButton.disabled = false;
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

