$(document).ready(function () {
    // Check if there is an element with the class "add-attachment-to-calendar"
    if ($('.add-attachment-to-calendar').length) {
        // Get the data-attachment-id value from the element with the class "add-attachment-to-calendar"
        var attachmentId = $('.add-attachment-to-calendar').data('attachment-id');

        // Check if there is another element with the same data-attachment-id but without the "add-attachment-to-calendar" class
        var elementToRemove = $('[data-attachment-id="' + attachmentId + '"]:not(.add-attachment-to-calendar)');

        // If the element exists, remove it
        if (elementToRemove.length) {
            elementToRemove.remove();
        }
    }
});
