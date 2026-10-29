/* GEO Setup → Custom JSON-LD: validator.schema.org-style report for the setting draft or a live URL. Saving stays with the settings form. */
(function () {
    'use strict';
    function init() {
        var root = document.querySelector('.tk-geo-schema-check');
        var tools = window.ToolKitsSchemaEditor;
        if (!root || !tools || root.dataset.initialized) return;
        root.dataset.initialized = '1';
        var editor = root.querySelector('textarea');
        var summary = root.querySelector('.tk-geo-schema-summary');
        var source = root.querySelector('.tk-geo-schema-source');
        var report = root.querySelector('.tk-sv-report');
        var validate = root.querySelector('.tk-geo-schema-validate');
        var urlInput = root.querySelector('#tk-geo-schema-url');
        var fetchButton = root.querySelector('.tk-geo-schema-fetch');
        var vocab = null, vocabState = 'loading', mode = 'code', live = null, timer = 0;

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }
        function plural(count, word) { return count + ' ' + word + (count === 1 ? '' : 's'); }
        // Errors and warnings mirror validator.schema.org; tips are Tool Kits extras and only shown when present.
        function counts(errors, warnings, items, notices) {
            var box = el('span', 'tk-sv-counts');
            box.append(el('span', errors ? 'is-error' : '', plural(errors, 'error')), el('span', warnings ? 'is-warning' : '', plural(warnings, 'warning')));
            if (notices) box.appendChild(el('span', 'is-notice', plural(notices, 'tip')));
            box.appendChild(el('span', '', plural(items, 'item')));
            return box;
        }
        function messages(parent, findings) {
            findings.forEach(function (finding) { parent.appendChild(el('div', 'tk-sv-msg is-' + finding.severity, finding.message)); });
        }
        function same(a, b) { return a.length === b.length && a.every(function (key, index) { return key === b[index]; }); }
        // Property table for one node; nested nodes render as nested tables carrying their own findings.
        function table(node, path, findings) {
            var wrap = el('div', 'tk-sv-node');
            messages(wrap, findings.filter(function (finding) { return !finding.property && same(finding.path, path); }));
            var grid = el('table', 'tk-sv-props');
            Object.keys(node).forEach(function (key) {
                var values = Array.isArray(node[key]) ? node[key] : [node[key]];
                var own = findings.filter(function (finding) { return finding.property === key && same(finding.path, path); });
                var row = grid.insertRow(), name = el('th', '', key), cell = row.insertCell();
                row.prepend(name);
                ['error', 'warning', 'notice'].some(function (severity) {
                    return own.some(function (finding) { return finding.severity === severity; }) && (row.className = 'is-' + severity);
                });
                values.forEach(function (value, index) {
                    var childPath = Array.isArray(node[key]) ? path.concat(key, index) : path.concat(key);
                    if (value && typeof value === 'object' && !Array.isArray(value)) {
                        var keys = Object.keys(value);
                        if (keys.length && keys.every(function (k) { return k === '@id' || k === '@context'; })) cell.appendChild(el('div', 'tk-sv-value tk-sv-ref', '→ ' + value['@id']));
                        else {
                            var nested = el('div', 'tk-sv-nested');
                            nested.appendChild(el('div', 'tk-sv-nested-type', [].concat(value['@type'] || 'Untyped node').join(', ')));
                            nested.appendChild(table(value, childPath, findings));
                            cell.appendChild(nested);
                        }
                    } else {
                        var text = value === null ? 'null' : typeof value === 'object' ? JSON.stringify(value) : String(value);
                        var line = el('div', 'tk-sv-value', text.length > 300 ? text.slice(0, 300) + '…' : text);
                        if (text.length > 300) line.title = text;
                        cell.appendChild(line);
                    }
                });
                messages(cell, own);
            });
            wrap.appendChild(grid);
            return wrap;
        }
        function vocabNote() {
            if (vocabState === 'ready') return 'Checked against the schema.org vocabulary (' + Object.keys(vocab.types).length + ' types, snapshot ' + vocab.generated + ').';
            return vocabState === 'loading' ? 'Loading the schema.org vocabulary…' : 'schema.org vocabulary unavailable. Only structure and recommended properties were checked.';
        }
        function render() {
            report.replaceChildren(); source.replaceChildren();
            summary.className = 'tk-geo-schema-summary';
            var value, extra = [], editable = mode === 'code';
            if (mode === 'url') {
                source.append(el('strong', '', 'Live URL: '), el('span', '', live.url + ' '));
                var back = el('button', 'button-link', 'Back to code snippet'); back.type = 'button';
                back.addEventListener('click', function () { mode = 'code'; render(); });
                source.appendChild(back);
                value = [];
                live.blocks.forEach(function (raw, index) {
                    try { value = value.concat(JSON.parse(raw)); }
                    catch (error) { extra.push({path: [], property: '', severity: 'error', message: 'JSON-LD block ' + (index + 1) + ' is not valid JSON: ' + error.message}); }
                });
                if (!live.blocks.length) { summary.textContent = 'No JSON-LD found on this page.'; return; }
                if (!value.length) value = null;
            } else {
                source.appendChild(el('strong', '', 'Code snippet: Custom JSON-LD setting'));
                if (!editor.value.trim()) { summary.textContent = 'No custom JSON-LD. Nothing will be printed.'; return; }
                try { value = JSON.parse(editor.value); }
                catch (error) {
                    summary.className += ' tk-schema-warning';
                    summary.textContent = 'Invalid JSON: ' + error.message + '. Settings will not save until this is fixed.';
                    return;
                }
            }
            var result = value ? tools.check(value, vocab) : {document: [], groups: [], errors: 0, warnings: 0, notices: 0, items: 0};
            var errors = result.errors + extra.length, warnings = result.warnings;
            if (errors) summary.className += ' tk-schema-warning';
            summary.textContent = (errors ? 'Fix the errors before publishing. ' : warnings ? 'Review the warnings below. ' : 'No errors or warnings. ') +
                (result.notices ? 'Tips are Tool Kits suggestions that validator.schema.org does not report. ' : '') + vocabNote();
            var head = el('div', 'tk-sv-head');
            head.append(el('strong', '', 'Detected'), counts(errors, warnings, result.items, result.notices));
            report.appendChild(head);
            messages(report, extra.concat(result.document));
            result.groups.forEach(function (group) {
                var details = el('details', 'tk-sv-group' + (group.errors ? ' is-error' : group.warnings ? ' is-warning' : ''));
                var title = el('summary');
                title.append(el('span', 'tk-sv-type', group.type), counts(group.errors, group.warnings, group.items.length, group.notices));
                details.appendChild(title);
                details.open = group.errors > 0;
                group.items.forEach(function (item, index) {
                    var box = el('div', 'tk-sv-item');
                    var bar = el('div', 'tk-sv-item-bar');
                    bar.appendChild(el('span', '', group.items.length > 1 ? 'Item ' + (index + 1) : 'Item'));
                    if (editable) {
                        var remove = el('button', 'button button-small', 'Remove Node'); remove.type = 'button';
                        remove.addEventListener('click', function () {
                            var updated = tools.remove(value, item.path);
                            editor.value = updated ? JSON.stringify(updated, null, 2) : '';
                            render();
                            summary.textContent = 'Node removed. Save settings to apply. ' + summary.textContent;
                        });
                        bar.appendChild(remove);
                    }
                    box.append(bar, table(item.node, item.path, item.findings));
                    details.appendChild(box);
                });
                report.appendChild(details);
            });
        }
        async function fetchUrl() {
            var address = urlInput.value.trim();
            if (!address) { urlInput.focus(); return; }
            fetchButton.disabled = true;
            summary.className = 'tk-geo-schema-summary';
            summary.textContent = 'Fetching ' + address + '…';
            try {
                var response = await fetch(ajaxurl, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    body: new URLSearchParams({action: 'tk_geo_schema_check_url', nonce: root.dataset.nonce, url: address})});
                var body = await response.text();
                if (body.trim() === '-1') throw new Error('Your session expired. Refresh the page and try again.');
                var result;
                try { result = JSON.parse(body); } catch (error) { throw new Error('The server returned an invalid response (HTTP ' + response.status + ').'); }
                if (!result.success || !result.data || !Array.isArray(result.data.blocks)) throw new Error(result.data && result.data.message || 'Request failed (HTTP ' + response.status + ').');
                live = result.data; mode = 'url'; render();
            } catch (error) {
                summary.className += ' tk-schema-warning';
                summary.textContent = error.message;
            } finally { fetchButton.disabled = false; }
        }
        editor.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { mode = 'code'; render(); }, 300); });
        validate.addEventListener('click', function () { mode = 'code'; render(); });
        fetchButton.addEventListener('click', fetchUrl);
        urlInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); fetchUrl(); } });
        render();
        fetch(root.dataset.vocabulary, {credentials: 'same-origin'}).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }).then(function (data) {
            if (!data || !data.types || !data.properties) throw new Error('Invalid vocabulary');
            vocab = data; vocabState = 'ready';
        }).catch(function () { vocabState = 'failed'; }).then(render);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
