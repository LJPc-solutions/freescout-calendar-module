document.addEventListener("DOMContentLoaded", function () {
    $(".conv-add-to-calendar").click(function (e) {
        if ($('.add-attachment-to-calendar a').length > 0) {
            $($('.add-attachment-to-calendar a')[0]).click();
            return;
        }
        showModalDialog(addToCalendar.html, {
            on_show: function (modal) {
                // Fetch calendar data and custom fields for the selected calendar
                const calendarSelect = modal.find('#calendar-select');
                const titleContainer = modal.find('#title-field-container');
                const titleInput = modal.find('#calendar-item-title');
                const customFieldsContainer = modal.find('#custom-fields-container');

                // Initial check for template
                const hasTemplate = calendarSelect.find('option:selected').data('has-template');
                titleContainer.toggle(!hasTemplate);

                // Update title when calendar is selected
                calendarSelect.on('change', function () {
                    const selectedOption = $(this).find('option:selected');
                    const hasTemplate = selectedOption.data('has-template');
                    titleContainer.toggle(!hasTemplate);
                });

                // Fetch custom fields for the selected calendar
                const fetchCustomFields = async (calendarId) => {
                    try {
                        const response = await fetch(laroute.route('ljpccalendarmodule.api.calendar.get', {id: calendarId}));
                        const calendar = await response.json();
                        return calendar.custom_fields.fields || [];
                    } catch (error) {
                        console.error('Error fetching custom fields:', error);
                        return [];
                    }
                };

                // Render custom fields
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
                            default:
                                return;
                        }

                        if (field.type === 'email' && jQuery('.contact-main').length) {
                            input.value = jQuery('.contact-main').text();
                        }

                        input.id = `custom_field_${field.id}`
                        input.disabled = !canEdit;

                        fieldElement.appendChild(input);
                        container.appendChild(fieldElement);
                    });
                };

                // Function to update custom fields
                const updateCustomFields = async () => {
                    const selectedCalendarId = calendarSelect.val();
                    const customFields = await fetchCustomFields(selectedCalendarId);
                    const canEdit = addToCalendar.permissions[selectedCalendarId]?.editItems || false;
                    renderCustomFields(customFields, customFieldsContainer[0], canEdit);
                };

                // Update custom fields when calendar selection changes
                calendarSelect.on('change', updateCustomFields);

                // Initial update of custom fields when modal opens
                updateCustomFields();

                modal.children().find('.add-to-calendar-ok:first').click(function (e) {
                    e.preventDefault();

                    const customFieldsData = {};
                    customFieldsContainer.find('input, select').each(function () {
                        const field = $(this);
                        customFieldsData[field.attr('name')] = field.val();
                    });

                    fsAjax(
                        JSON.stringify({
                            'calendarId': $('#calendar-select').val(),
                            'title': $('#calendar-item-title').val(),
                            'start': moment($('#calendar-item-datetime').val()).toISOString(),
                            'end': moment($('#calendar-item-datetime').val()).add(1, 'hour').toISOString(),
                            'location': '',
                            'body': '',
                            'customFields': customFieldsData
                        }),
                        addToCalendar.route,
                        function (response) {
                            if (!isAjaxSuccess(response)) {
                                showAjaxError(response);
                            } else {
                                window.location.reload();
                            }
                        }, false, null, {
                            contentType: "application/json",
                        }
                    );
                    modal.modal('hide');
                });
            }
        });
    });

    $('.add-attachment-to-calendar a').click(function (e) {
        e.preventDefault();
        var attachmentId = $(this).parent().data('attachment-id');

        showModalDialog(addAttachmentToCalendar.html, {
            on_show: function (modal) {
                modal.children().find('.add-to-calendar-ok:first').click(function (e) {
                    fsAjax(
                        JSON.stringify({
                            'calendarId': $('#calendar-select').val(),
                            'attachmentId': attachmentId,
                            'conversationId': addAttachmentToCalendar.conversation_id
                        }),
                        laroute.route('ljpccalendarmodule.api.event.create_from_attachment'),
                        function (response) {
                            if (!isAjaxSuccess(response)) {
                                showAjaxError(response);
                            } else {
                                window.location.reload();
                            }
                        }, false, null, {
                            contentType: "application/json",
                        }
                    );
                    modal.modal('hide');
                    e.preventDefault();
                });
            }
        });
    });
});
