/** Unified Inbox controls and Gecka ident_switch selector adapter. */
$(function () {
    var $switcher = $('#plugin-ident_switch-account');
    if ($switcher.length) {
        var sentinel = '__unified_inbox__';
        if (!$switcher.find('option').filter(function () { return this.value === sentinel; }).length) {
            $('<option>', { value: sentinel, text: 'Unified Inbox' }).prependTo($switcher);
        }
        var geckaHandler = $switcher[0].onchange;
        $switcher[0].onchange = function (event) {
            if (this.value === sentinel) {
                window.location.href = './?_task=mail&_action=plugin.unified_inbox';
                return false;
            }
            if (typeof geckaHandler === 'function') {
                return geckaHandler.call(this, event);
            }
        };
    }

    $('#unified-select-all').on('click', function () {
        $('#unified-inbox input[name="_accounts[]"]').prop('checked', true);
    });
    $('#unified-select-none').on('click', function () {
        $('#unified-inbox input[name="_accounts[]"]').prop('checked', false);
    });
});
