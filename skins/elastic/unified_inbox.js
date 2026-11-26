$(function () {
    $('#unified-select-all').on('click', function () {
        $('#unified-inbox input[name="_accounts[]"]').prop('checked', true);
    });
    $('#unified-select-none').on('click', function () {
        $('#unified-inbox input[name="_accounts[]"]').prop('checked', false);
    });
});
