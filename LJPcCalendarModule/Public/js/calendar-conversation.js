$(document).ready(function() {
    $(".conv-add-to-calendar").click(function(e) {
        showModalDialog(addToCalendar.html, {
            on_show: function(modal) {
                modal.children().find('.add-to-calendar-ok:first').click(function(e) {
                    fsAjax(
                        {
                            'action': 'create',
                            'conversation_id': getGlobalAttr('conversation_id'),
                            'calendar': {
                                'id': $('#calendar-select').val(),
                            },
                            'schedule': {
                                'isAllDay': 'false',
                                'isPrivate': 'false',
                                'title': $('#calendar-item-title').val(),
                                'state': addToCalendar.translationBusy,
                                'location': '',
                                'start': moment($('#calendar-item-datetime').val()).unix(),
                                'end': moment($('#calendar-item-datetime').val()).add(1, 'hour').unix(),
                            }
                        },
                        addToCalendar.route,
                        function(response) {
                            if(!isAjaxSuccess(response)) {
                                showAjaxError(response);
                            } else {
                                window.location.reload();
                            }
                        }
                    );
                    modal.modal('hide');
                    e.preventDefault();
                });
            }
        });
    });
});
