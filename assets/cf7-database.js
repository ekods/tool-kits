/* WordPress Thickbox owns the popup, overlay, close button and Escape handling. */
(function ($) {
    'use strict';
    $(function () {
        var config = window.tkCf7Database;
        var source = document.getElementById('tk-cf7dbc-modal');
        if (!config || !source || typeof window.tb_show !== 'function') return;
        var request = null;
        var sequence = 0;
        var active = false;
        var opener = null;

        function dimensions() {
            return {width: Math.min(940, window.innerWidth - 32), height: Math.min(780, window.innerHeight - 64)};
        }
        function resize() {
            if (!active) return;
            var size = dimensions();
            window.TB_WIDTH = size.width;
            window.TB_HEIGHT = size.height;
            $('#TB_ajaxContent').css({width: size.width - 30, height: size.height - 45});
            window.tb_position();
        }
        function showError(message) {
            $('#tk-cf7dbc-modal-content').empty().append($('<div class="notice notice-error"><p></p></div>').find('p').text(message || config.error).end());
        }
        function open(id, trigger) {
            opener = trigger;
            active = true;
            var current = ++sequence;
            var size = dimensions();
            $('#tk-cf7dbc-modal-content').empty().append($('<p class="cf7dbc-modal-loading" role="status"></p>').append('<span class="spinner is-active" aria-hidden="true"></span>').append(document.createTextNode(config.loading)));
            window.tb_show(config.title, '#TB_inline?width=' + (size.width - 30) + '&height=' + (size.height - 40) + '&inlineId=tk-cf7dbc-modal');
            $('#TB_window').addClass('tk-cf7dbc-modal').attr({role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'TB_ajaxWindowTitle'});
            $('#TB_ajaxContent').attr('aria-busy', 'true');
            request = $.post(config.ajaxUrl, {action: 'tk_cf7_database_detail', nonce: config.nonce, submission_id: id})
                .done(function (response) {
                    if (!active || current !== sequence) return;
                    if (!response.success || !response.data) { showError(response.data && response.data.message); return; }
                    // The server escapes every stored field before returning this markup.
                    $('#tk-cf7dbc-modal-content').html(response.data.html);
                    var row = $('[data-cf7-submission="' + Number(response.data.id) + '"]');
                    row.removeClass('unread');
                    row.find('.column-read_status').empty().append($('<span class="cf7dbc-badge cf7dbc-badge-read"></span>').text(config.read));
                    row.find('.cf7dbc-recent-actions .cf7dbc-badge-unread').remove();
                    $('[data-cf7-unread-count]').text(response.data.unread_count);
                    $('[data-cf7-unread-summary]').text(response.data.unread_summary);
                })
                .fail(function (xhr, status) {
                    if (!active || current !== sequence || status === 'abort') return;
                    showError(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message);
                })
                .always(function () {
                    if (active && current === sequence) $('#TB_ajaxContent').attr('aria-busy', 'false');
                });
        }

        $(document).on('click.tkCf7Database', '.tk-cf7dbc-view', function (event) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.which > 1) return;
            event.preventDefault();
            open(Number(this.dataset.submissionId), this);
        });
        $('body').on('thickbox:removed.tkCf7Database', function () {
            if (!active) return;
            active = false;
            sequence++;
            if (request) { request.abort(); request = null; }
            if (opener && document.contains(opener)) opener.focus();
        });
        // Keep Tab within this dialog; the core close button remains the first target.
        $(document).on('keydown.tkCf7Database', function (event) {
            if (!active || event.key !== 'Tab') return;
            var controls = $('#TB_window').find('a[href], button:not(:disabled), summary, [tabindex="0"]').filter(function () {
                if (!$(this).is(':visible')) return false;
                var control = this;
                // jQuery may count links inside a closed details element as visible.
                return $(this).parents('details:not([open])').toArray().every(function (disclosure) {
                    var summary = disclosure.querySelector('summary');
                    return summary && summary.contains(control);
                });
            });
            var first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && (document.activeElement === first || !$.contains($('#TB_window')[0], document.activeElement))) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !$.contains($('#TB_window')[0], document.activeElement))) {
                event.preventDefault(); first.focus();
            }
        });
        $(window).on('resize.tkCf7Database', resize);
        if (Number(source.dataset.openId)) {
            var id = Number(source.dataset.openId);
            var trigger = document.querySelector('.tk-cf7dbc-view[data-submission-id="' + id + '"]') || document.querySelector('.cf7dbc-actions .button');
            open(id, trigger);
        }
    });
})(jQuery);
