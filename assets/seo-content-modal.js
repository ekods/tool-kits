(function () {
    'use strict';
    function init() {
        document.querySelectorAll('.tk-seo-content-editor').forEach(function (root) {
            if (root.dataset.initialized) return;
            root.dataset.initialized = '1';
            var dialog = root.querySelector('dialog');
            var opener = root.querySelector('.tk-seo-content-open');
            var selector = root.querySelector('.tk-seo-content-page');
            document.body.appendChild(dialog);
            var save = dialog.querySelector('.tk-seo-content-save');
            var validate = dialog.querySelector('.tk-seo-content-validate');
            var status = dialog.querySelector('.tk-seo-content-status');
            var results = dialog.querySelector('.tk-seo-content-validation');
            var summary = dialog.querySelector('.tk-seo-content-summary');
            var fields = dialog.querySelectorAll('[data-field]');
            var closes = dialog.querySelectorAll('.tk-seo-content-close');
            var tabs = dialog.querySelectorAll('[data-content-panel]');
            var version = '', pending = false, busy = false, loaded = false, sequence = 0;
            var controllers = new Set();
            function showPanel(name) {
                tabs.forEach(function (button) {
                    var selected = button.dataset.contentPanel === name;
                    button.setAttribute('aria-selected', String(selected)); button.tabIndex = selected ? 0 : -1;
                });
                dialog.querySelectorAll('.tk-seo-content-panel').forEach(function (panel) { panel.hidden = panel.dataset.panel !== name; });
            }
            tabs.forEach(function (button, index) {
                button.addEventListener('click', function (event) {
                    event.preventDefault(); event.stopPropagation(); showPanel(button.dataset.contentPanel);
                });
                button.addEventListener('keydown', function (event) {
                    var next;
                    if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                    else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                    else if (event.key === 'Home') next = 0;
                    else if (event.key === 'End') next = tabs.length - 1;
                    else return;
                    event.preventDefault(); showPanel(tabs[next].dataset.contentPanel); tabs[next].focus();
                });
            });
            function lock(value) {
                busy = value;
                save.disabled = validate.disabled = value || !loaded;
                fields.forEach(function (field) { field.disabled = value || !loaded; });
                if (selector) selector.disabled = value;
                closes.forEach(function (button) { button.disabled = pending; });
            }
            async function request(mode) {
                var controller = new AbortController(); controllers.add(controller);
                var timedOut = false;
                var timer = setTimeout(function () { timedOut = true; controller.abort(); }, mode === 'save' ? 60000 : 20000);
                var data = new URLSearchParams({action: 'tk_seo_content_editor', nonce: root.dataset.nonce,
                    post_id: selector ? selector.value : root.dataset.postId, mode: mode, version: version});
                if (mode === 'save') fields.forEach(function (field) { data.set(field.dataset.field, field.value); });
                try {
                    var response = await fetch(ajaxurl, {method: 'POST', cache: 'no-store', credentials: 'same-origin', body: data, signal: controller.signal});
                    var body = await response.text();
                    if (body.trim() === '-1') throw new Error('Your session expired. Refresh the page and try again.');
                    var result;
                    try { result = JSON.parse(body); } catch (error) { throw new Error('The server returned an invalid response (HTTP ' + response.status + ').'); }
                    if (!response.ok || !result || !result.success) throw new Error(result && result.data && result.data.message || 'Request failed (HTTP ' + response.status + ').');
                    var payload = result.data;
                    if (!payload || typeof payload.version !== 'string' || typeof payload.title !== 'string' ||
                        !payload.validation || !Array.isArray(payload.validation.issues) || !Array.isArray(payload.validation.strategy_checks)) {
                        throw new Error('The server returned incomplete SEO validation data. Refresh the page and retry.');
                    }
                    return payload;
                } catch (error) {
                    if (timedOut) throw new Error('Request timed out. Please retry.');
                    throw error;
                } finally { clearTimeout(timer); controllers.delete(controller); }
            }
            function element(tag, text, className) {
                var node = document.createElement(tag);
                if (text !== undefined) node.textContent = text;
                if (className) node.className = className;
                return node;
            }
            function improve(parent, fieldName) {
                var button = element('button', 'Improve Content', 'button button-small'); button.type = 'button';
                button.addEventListener('click', function () {
                    showPanel('edit'); dialog.querySelector('[data-field="' + fieldName + '"]').focus();
                });
                parent.appendChild(button);
            }
            function review(parent, target) {
                if (!target) return;
                try { if (!/^https?:$/.test(new URL(target, window.location.href).protocol)) return; } catch (error) { return; }
                var link = element('a', 'Review', 'button button-small'); link.href = target;
                link.target = '_blank'; link.rel = 'noopener'; parent.appendChild(link);
            }
            function renderValidation(data) {
                var audit = data.validation;
                summary.textContent = data.title + ' — Score: ' + audit.score + '/100 · Priority: ' + audit.priority +
                    ' · Words: ' + audit.words + ' · Links (Int/Ext): ' + audit.internal_links + '/' + audit.external_links;
                results.replaceChildren();
                var checked = new Date(data.checked_at * 1000);
                if (!isNaN(checked.getTime())) results.appendChild(element('p', 'Last validation: ' + checked.toLocaleString(), 'description'));
                results.appendChild(element('h3', 'Content Audit Findings'));
                if (!audit.issues.length) results.appendChild(element('p', 'No stored-content issues found.', 'tk-content-check-pass'));
                else {
                    var list = element('ul', undefined, 'tk-content-audit-issues');
                    audit.issues.forEach(function (issue) { list.appendChild(element('li', issue)); }); results.appendChild(list);
                }
                results.appendChild(element('h3', 'SEO Checks & Optimization'));
                var grid = element('div', undefined, 'tk-content-check-grid');
                var fieldMap = {'Content SEO': 'content', 'Keyword targeting': 'keyword', 'Portfolio SEO': 'client', 'Local SEO Jakarta/Singapore': 'location'};
                audit.strategy_checks.forEach(function (check) {
                    if (!check || typeof check.category !== 'string') return;
                    var state = ['pass', 'warning', 'review', 'not-applicable'].indexOf(check.status) !== -1 ? check.status : 'review';
                    var card = element('section', undefined, 'tk-content-check tk-content-check-' + state);
                    var heading = element('div', undefined, 'tk-content-check-heading');
                    heading.appendChild(element('h4', check.category));
                    heading.appendChild(element('span', state === 'not-applicable' ? 'Not Applicable' : state.charAt(0).toUpperCase() + state.slice(1), 'tk-content-check-badge'));
                    card.appendChild(heading); card.appendChild(element('p', check.finding));
                    card.appendChild(element('p', check.action, 'description'));
                    var actions = element('div', undefined, 'tk-content-check-actions');
                    if (fieldMap[check.category] && state !== 'not-applicable') improve(actions, fieldMap[check.category]);
                    review(actions, check.target); card.appendChild(actions); grid.appendChild(card);
                });
                results.appendChild(grid);
            }
            async function load() {
                controllers.forEach(function (controller) { controller.abort(); });
                var current = ++sequence;
                loaded = false; lock(true); version = '';
                status.textContent = 'Loading page and validating SEO checks…';
                summary.textContent = ''; results.textContent = 'Loading SEO validation…';
                fields.forEach(function (field) { field.value = ''; });
                try {
                    var data = await request('load');
                    if (current !== sequence) return;
                    version = data.version;
                    ['excerpt', 'keyword', 'location'].forEach(function (name) { dialog.querySelector('[data-field="' + name + '"]').value = data[name] || ''; });
                    renderValidation(data); status.textContent = ''; loaded = true;
                } catch (error) { if (current === sequence) { status.textContent = error.message + ' Close and reopen the dialog to retry.'; results.textContent = 'SEO validation unavailable.'; } }
                finally { if (current === sequence) lock(false); }
            }
            function open(button) {
                if (root.dataset.auditContext !== '1' && root.dataset.postId !== '0' && window.confirm('Save any unsaved page editor changes before using this dialog. Continue?') === false) return;
                if (selector && button.dataset.postId) {
                    selector.value = button.dataset.postId;
                    if (selector.value !== button.dataset.postId) return;
                }
                opener = button; pending = false;
                showPanel(root.dataset.auditContext === '1' ? 'checks' : 'edit'); dialog.showModal(); load();
            }
            if (opener) opener.addEventListener('click', function () { open(opener); });
            if (root.dataset.auditContext === '1') document.querySelectorAll('.tk-seo-content-audit-open').forEach(function (button) {
                button.addEventListener('click', function () { open(button); });
            });
            if (selector) selector.addEventListener('change', function () { showPanel('checks'); load(); });
            closes.forEach(function (button) { button.addEventListener('click', function () { if (!pending) dialog.close(); }); });
            dialog.addEventListener('cancel', function (event) { if (pending) event.preventDefault(); });
            dialog.addEventListener('close', function () { sequence++; controllers.forEach(function (controller) { controller.abort(); }); if (opener) opener.focus(); });
            validate.addEventListener('click', async function () {
                if (busy || !loaded) return;
                var current = sequence;
                showPanel('checks'); lock(true); status.textContent = 'Validating saved content…';
                try {
                    var data = await request('validate');
                    if (current !== sequence) return;
                    renderValidation(data);
                    status.textContent = data.version === version ? 'Validation complete. Unsaved inputs have been preserved.' : 'Validation complete. This page changed since loading; reopen the dialog before saving.';
                } catch (error) { if (current === sequence) status.textContent = error.message + ' Click Validate Page to retry.'; }
                finally { if (current === sequence) lock(false); }
            });
            save.addEventListener('click', async function () {
                if (busy || !loaded) return;
                var current = sequence;
                pending = true; lock(true); status.textContent = 'Saving content and refreshing audit…';
                try {
                    await request('save');
                    if (current !== sequence) return;
                    if (root.dataset.auditContext === '1') window.location.hash = 'content-audit';
                    window.location.reload();
                } catch (error) {
                    if (current !== sequence) return;
                    status.textContent = error.message + ' Reopen the dialog to check the saved state before retrying.';
                    pending = false; loaded = false; lock(false);
                }
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
