(function () {
    'use strict';
    document.querySelectorAll('.tk-seo-content-editor').forEach(function (root) {
        var dialog = root.querySelector('dialog');
        var opener = root.querySelector('.tk-seo-content-open');
        var save = root.querySelector('.tk-seo-content-save');
        var status = root.querySelector('.tk-seo-content-status');
        var selector = root.querySelector('.tk-seo-content-page');
        var version = '';
        var pending = false;
        var sequence = 0;
        var controllers = new Set();
        function request(mode) {
            var controller = new AbortController();
            controllers.add(controller);
            var timer = setTimeout(function () { controller.abort(); }, mode === 'save' ? 60000 : 20000);
            var data = new URLSearchParams({action: 'tk_seo_content_editor', nonce: root.dataset.nonce,
                post_id: selector ? selector.value : root.dataset.postId, mode: mode, version: version});
            if (mode === 'save') root.querySelectorAll('[data-field]').forEach(function (field) { data.set(field.dataset.field, field.value); });
            return fetch(ajaxurl, {method: 'POST', credentials: 'same-origin', body: data, signal: controller.signal}).then(function (response) {
                return response.text().then(function (body) {
                    if (body.trim() === '-1') throw new Error('Your session expired. Refresh the page and try again.');
                    var result;
                    try { result = JSON.parse(body); } catch (error) { throw new Error('The server returned an invalid response (HTTP ' + response.status + ').'); }
                    if (!response.ok) throw new Error(result && result.data && result.data.message || 'Request failed (HTTP ' + response.status + ').');
                    return result;
                });
            }).then(function (result) {
                if (!result.success) throw new Error(result.data && result.data.message || 'Request failed.');
                return result.data;
            }).catch(function (error) {
                if (error.name === 'AbortError') throw new Error('Request timed out. Reopen the dialog to retry.');
                throw error;
            }).finally(function () { clearTimeout(timer); controllers.delete(controller); });
        }
        function load() {
            var current = ++sequence;
            save.disabled = true;
            status.textContent = 'Loading page…';
            root.querySelectorAll('[data-field]').forEach(function (field) { field.value = ''; });
            request('load').then(function (data) {
                if (current !== sequence) return;
                version = data.version;
                root.querySelector('[data-field="excerpt"]').value = data.excerpt;
                root.querySelector('[data-field="keyword"]').value = data.keyword;
                root.querySelector('[data-field="location"]').value = data.location;
                root.querySelector('.tk-seo-content-summary').textContent = data.title + ' — Stored content: ' + data.words + ' words';
                status.textContent = '';
                save.disabled = false;
            }).catch(function (error) { if (current === sequence) status.textContent = error.message; });
        }
        opener.addEventListener('click', function () {
            if (root.dataset.postId !== '0' && window.confirm('Save any unsaved page editor changes before using this dialog. Continue?') === false) return;
            dialog.showModal(); load();
        });
        if (selector) selector.addEventListener('change', load);
        var closes = root.querySelectorAll('.tk-seo-content-close');
        closes.forEach(function (button) { button.addEventListener('click', function () { if (!pending) dialog.close(); }); });
        dialog.addEventListener('cancel', function (event) { if (pending) event.preventDefault(); });
        dialog.addEventListener('close', function () { sequence++; controllers.forEach(function (controller) { controller.abort(); }); opener.focus(); });
        save.addEventListener('click', function () {
            if (save.disabled) return;
            pending = true; save.disabled = true;
            closes.forEach(function (button) { button.disabled = true; });
            root.querySelectorAll('[data-field]').forEach(function (field) { field.disabled = true; });
            if (selector) selector.disabled = true;
            status.textContent = 'Saving content and refreshing audit…';
            request('save').then(function () { window.location.reload(); }).catch(function (error) {
                status.textContent = error.message + ' Reopen the dialog to check the saved state before retrying.';
                pending = false;
                closes.forEach(function (button) { button.disabled = false; });
                root.querySelectorAll('[data-field]').forEach(function (field) { field.disabled = false; });
                if (selector) selector.disabled = false;
            });
        });
    });
}());
