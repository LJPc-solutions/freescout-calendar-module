document.addEventListener("DOMContentLoaded", function () {
    $(".conv-add-to-calendar").click(function (e) {
        if ($('.add-attachment-to-calendar a').length > 0) {
            $($('.add-attachment-to-calendar a')[0]).click();
            return;
        }
        showModalDialog(addToCalendar.html, {
            on_show: function (modal) {
                modal.children().find('.add-to-calendar-ok:first').click(function (e) {
                    fsAjax(
                        JSON.stringify({
                            'calendarId': $('#calendar-select').val(),
                            'title': $('#calendar-item-title').val(),
                            'start': moment($('#calendar-item-datetime').val()).toISOString(),
                            'end': moment($('#calendar-item-datetime').val()).add(1, 'hour').toISOString(),
                            'location': '',
                            'body': '',
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
                    e.preventDefault();
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
