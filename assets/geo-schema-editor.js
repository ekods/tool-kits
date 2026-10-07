(function () {
    'use strict';
    function init() {
        var dialog = document.getElementById('tk-schema-editor');
        if (!dialog || dialog.dataset.initialized) return;
        dialog.dataset.initialized = '1';
        // Keep the dialog outside the GEO settings form and its tab panels.
        document.body.appendChild(dialog);
        var editor = dialog.querySelector('textarea');
        var status = dialog.querySelector('.tk-schema-editor-status');
        var live = dialog.querySelector('.tk-schema-live');
        var save = dialog.querySelector('.tk-schema-save');
        var validate = dialog.querySelector('.tk-schema-validate');
        var retry = dialog.querySelector('.tk-schema-retry');
        var tools = window.ToolKitsSchemaEditor;
        var customNodes = dialog.querySelector('.tk-schema-custom-nodes');
        var undo = dialog.querySelector('.tk-schema-undo');
        var missing = dialog.querySelector('.tk-schema-missing');
        var addType = dialog.querySelector('.tk-schema-add-type');
        var addName = dialog.querySelector('.tk-schema-add-name');
        var addUrl = dialog.querySelector('.tk-schema-add-url');
        var add = dialog.querySelector('.tk-schema-add');
        var closes = dialog.querySelectorAll('.tk-schema-close');
        var url = '', version = '', opener, saving = false, loaded = false, sequence = 0;
        var liveData = null, liveAnalysis = null, defaults = {}, history = [], fillPath = null, draftBusy = false;
        var controllers = new Set();
        var tabs = dialog.querySelectorAll('[data-schema-panel]');
        function showPanel(name) {
            tabs.forEach(function (button) {
                var selected = button.dataset.schemaPanel === name;
                button.setAttribute('aria-selected', String(selected)); button.tabIndex = selected ? 0 : -1;
            });
            dialog.querySelectorAll('.tk-schema-panel').forEach(function (panel) { panel.hidden = panel.dataset.panel !== name; });
        }
        tabs.forEach(function (button, index) {
            button.addEventListener('click', function (event) {
                event.preventDefault(); event.stopPropagation(); showPanel(button.dataset.schemaPanel);
            });
            button.addEventListener('keydown', function (event) {
                var next;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                else return;
                event.preventDefault(); showPanel(tabs[next].dataset.schemaPanel); tabs[next].focus();
            });
        });
        function lock(value) {
            save.disabled = value || !loaded; validate.disabled = value || !loaded;
            editor.disabled = value;
            draftBusy = value;
            add.disabled = value || !loaded || (!fillPath && !liveData);
            undo.disabled = value || !history.length;
            addType.disabled = value || !loaded;
            addName.disabled = addUrl.disabled = value || !loaded;
            customNodes.querySelectorAll('button').forEach(function (button) { button.disabled = value; });
            closes.forEach(function (button) { button.disabled = saving; });
        }
        async function request(mode) {
            var controller = new AbortController();
            controllers.add(controller);
            var timedOut = false;
            var timer = setTimeout(function () { timedOut = true; controller.abort(); }, mode === 'save' ? 45000 : 20000);
            try {
                var endpoint = new URL(ajaxurl, window.location.href);
                endpoint.searchParams.set('tk_schema_operation', mode);
                endpoint.searchParams.set('tk_schema_request', String(Date.now()) + '-' + sequence);
                var response = await fetch(endpoint.href, {method: 'POST', cache: 'no-store', credentials: 'same-origin', signal: controller.signal,
                    body: new URLSearchParams({action: mode === 'live' ? 'tk_geo_schema_editor_live' : 'tk_geo_schema_editor', nonce: dialog.dataset.nonce, mode: mode, url: url, version: version, json: editor.value})});
                var body = await response.text();
                if (body.trim() === '-1') throw new Error('Your session expired. Refresh the page and try again.');
                var result;
                try { result = JSON.parse(body); } catch (error) { throw new Error('The server returned an invalid response (HTTP ' + response.status + ').'); }
                if (!response.ok || !result || !result.success) throw new Error(result && result.data && result.data.message || 'Request failed (HTTP ' + response.status + ').');
                if (!result.data || typeof result.data !== 'object' || Array.isArray(result.data)) throw new Error('The server returned incomplete schema data. Refresh the page and retry.');
                return result.data;
            } catch (error) {
                if (timedOut) throw new Error('Request timed out. Please retry.');
                throw error;
            } finally { clearTimeout(timer); controllers.delete(controller); }
        }
        function messages(parent, items) {
            var list = document.createElement('ul');
            (Array.isArray(items) ? items : []).forEach(function (message) {
                var item = document.createElement('li'); item.textContent = message;
                if (/Repeated entity|Identical top-level|Invalid |No typed/.test(message)) item.className = 'tk-schema-warning';
                list.appendChild(item);
            });
            parent.appendChild(list);
        }
        function parseDraft() { return editor.value.trim() ? JSON.parse(editor.value) : null; }
        function nodeCards(parent, json, editable, analysis) {
            var value;
            try { value = JSON.parse(json); } catch (error) { return; }
            var info = tools.analyze(value);
            analysis = analysis || liveAnalysis;
            var repeatedIds = liveData && liveData.combined ? liveData.combined.duplicate_ids || {} : {};
            info.nodes.forEach(function (entry) {
                var repeatedId = entry.id && (repeatedIds[entry.id] > 1 || info.ids[entry.id] > 1 || analysis && analysis.ids[entry.id] > 1);
                var identical = entry.signature && (info.anonymous[entry.signature] > 1 || analysis && analysis.anonymous[entry.signature] > 1);
                var card = document.createElement('div'); card.className = 'tk-schema-node' + (identical || repeatedId ? ' tk-schema-duplicate' : '');
                var title = document.createElement('h4'); title.textContent = entry.types.join(', ') + (entry.id ? ' — ' + entry.id : ''); card.appendChild(title);
                if (identical || repeatedId) {
                    var warning = document.createElement('p'); warning.className = 'tk-schema-warning';
                    warning.textContent = repeatedId ? 'Repeated @id definition — compare complementary descriptions and overlapping properties before removing a node.' : 'Identical top-level node — review whether multiple sources output the same entity before removing.';
                    card.appendChild(warning);
                }
                var pre = document.createElement('pre');
                JSON.stringify(entry.node, null, 2).split('\n').forEach(function (line) {
                    var span = document.createElement('span'); span.textContent = line + '\n';
                    if (identical && line.indexOf('"@type"') !== -1 || repeatedId && line.indexOf('"@id"') !== -1) span.className = 'tk-schema-mark';
                    pre.appendChild(span);
                });
                var code = document.createElement('details');
                var codeTitle = document.createElement('summary'); codeTitle.textContent = 'View Node JSON';
                code.open = Boolean(identical || repeatedId);
                code.appendChild(codeTitle); code.appendChild(pre); card.appendChild(code);
                var suggested = entry.types.indexOf('ImageObject') !== -1 ? ['contentUrl'] : entry.types.some(function (type) { return type === 'WebSite' || type === 'Organization'; }) ? ['name', 'url'] : [];
                var absentFields = suggested.filter(function (field) { return entry.node[field] === undefined || entry.node[field] === ''; });
                if (absentFields.length) {
                    var absentNote = document.createElement('p'); absentNote.textContent = 'Suggested fields to review: ' + absentFields.join(', ') + '. Add verified values when applicable.'; card.appendChild(absentNote);
                }
                if (editable) {
                    var actions = document.createElement('div'); actions.className = 'tk-schema-node-actions';
                    var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button'; remove.textContent = 'Remove Node from Draft'; remove.disabled = draftBusy;
                    remove.addEventListener('click', function () {
                        try {
                            var draft = parseDraft();
                            if (JSON.stringify(draft) !== JSON.stringify(value)) { renderCustom(); status.textContent = 'JSON changed. Review the updated nodes before removing.'; return; }
                            var updated = tools.remove(draft, entry.path);
                            var remaining = tools.analyze(updated), removedIds = Object.keys(tools.analyze(entry.node).ids);
                            var dangling = remaining.references.filter(function (id) { return removedIds.indexOf(id) !== -1 && !remaining.ids[id]; });
                            applyDraft(updated);
                            status.textContent = 'Node removed from draft. Save to apply.' + (dangling.length ? ' References to ' + Array.from(new Set(dangling)).join(', ') + ' remain; update or remove them if no other definition exists.' : '');
                        } catch (error) { status.textContent = 'Fix the JSON syntax before removing nodes.'; }
                    });
                    actions.appendChild(remove);
                    if (entry.types.some(function (type) { return ['WebSite', 'Organization', 'ImageObject'].indexOf(type) !== -1; })) {
                        var fill = document.createElement('button'); fill.type = 'button'; fill.className = 'button'; fill.textContent = 'Fill Missing Fields'; fill.disabled = draftBusy;
                        fill.addEventListener('click', function () {
                            fillPath = entry.path;
                            addType.value = entry.types.filter(function (type) { return ['WebSite', 'Organization', 'ImageObject'].indexOf(type) !== -1; })[0];
                            addName.value = entry.node.name || (addType.value === 'ImageObject' ? '' : defaults.name || '');
                            addUrl.value = entry.node.contentUrl || entry.node.url || (addType.value === 'ImageObject' ? '' : defaults.url || '');
                            add.textContent = 'Apply Missing Fields'; add.disabled = draftBusy;
                            showPanel('add'); addName.focus();
                            status.textContent = 'Review the name and URL below. Existing properties will be preserved.';
                        });
                        actions.appendChild(fill);
                    }
                    card.appendChild(actions);
                } else {
                    var source = document.createElement('p'); source.textContent = 'Read-only live output. Remove or edit this node in its source settings, or in Custom JSON-LD below if it belongs to Tool Kits.'; card.appendChild(source);
                }
                parent.appendChild(card);
            });
        }
        function resetAdd() {
            fillPath = null; add.textContent = 'Add JSON Node';
            addName.value = addType.value === 'ImageObject' ? '' : defaults.name || '';
            addUrl.value = addType.value === 'ImageObject' ? '' : defaults.url || '';
        }
        function renderCustom() {
            customNodes.replaceChildren();
            var info;
            try { info = tools.analyze(parseDraft()); nodeCards(customNodes, editor.value || 'null', true); }
            catch (error) { customNodes.textContent = 'Invalid JSON. Fix the syntax in the editor before using node actions.'; add.disabled = true; return; }
            var existing = liveData && liveData.combined ? liveData.combined.types || {} : {};
            var absent = ['WebSite', 'Organization', 'ImageObject'].filter(function (type) { return !existing[type] && !info.types[type]; });
            missing.textContent = liveData ? (absent.length ? 'Not found in live output or draft: ' + absent.join(', ') + '. Add only relevant types.' : 'All suggested types are already present.') : 'Validate the live page before adding a new type.';
            add.disabled = draftBusy || !loaded || (!fillPath && !liveData);
            undo.disabled = draftBusy || !history.length;
        }
        function applyDraft(value) {
            history.push(editor.value); if (history.length > 20) history.shift();
            editor.value = value ? JSON.stringify(value, null, 2) : '';
            resetAdd(); renderCustom();
        }
        function render(data) {
            if (typeof data.json !== 'string' || typeof data.version !== 'string' || !data.validation || !Array.isArray(data.validation.messages)) {
                throw new Error('The server returned incomplete editor data. Refresh the page and reopen the dialog.');
            }
            editor.value = data.json; version = data.version; loaded = true;
            defaults = data.defaults || {}; history = []; resetAdd(); renderCustom();
            status.textContent = (data.saved ? 'Custom JSON-LD saved. ' : '') + data.validation.messages.join(' ');
        }
        async function loadLive(current) {
            retry.disabled = true;
            live.textContent = 'Loading live JSON-LD…';
            try {
                var data = await request('live');
                if (current !== sequence) return;
                live.replaceChildren();
                liveData = null; liveAnalysis = null;
                if (!data.live || typeof data.live !== 'object' || Array.isArray(data.live)) {
                    throw new Error('The server did not return live schema data. Click Retry Live Check or refresh the page.');
                }
                if (data.live.error) { renderCustom(); live.textContent = 'Live validation unavailable: ' + data.live.error; return; }
                if (!Array.isArray(data.live.documents) || !data.live.combined || !Array.isArray(data.live.combined.messages)) {
                    throw new Error('The live schema response is incomplete. Click Retry Live Check.');
                }
                if (data.live.documents.some(function (documentData) { return !documentData || typeof documentData.json !== 'string'; })) {
                    throw new Error('The live response contains an invalid schema document. Click Retry Live Check.');
                }
                liveData = data.live;
                messages(live, data.live.combined.messages);
                var combined = tools.analyze(data.live.documents.map(function (documentData) { try { return JSON.parse(documentData.json); } catch (error) { return null; } }));
                liveAnalysis = combined;
                var inventory = document.createElement('details');
                var inventoryTitle = document.createElement('summary'); inventoryTitle.textContent = 'Schema Type Inventory (counts are not duplicate warnings)';
                inventory.appendChild(inventoryTitle);
                messages(inventory, Object.keys(combined.types).sort().map(function (type) { return type + ': ' + combined.types[type]; }));
                live.appendChild(inventory);
                data.live.documents.forEach(function (documentData, index) {
                    var details = document.createElement('details');
                    var summary = document.createElement('summary'); summary.textContent = 'Live document ' + (index + 1) + ' (read-only)';
                    try {
                        var documentInfo = tools.analyze(JSON.parse(documentData.json));
                        if (documentInfo.nodes.some(function (entry) { return entry.id && combined.ids[entry.id] > 1 || entry.signature && combined.anonymous[entry.signature] > 1; })) {
                            details.open = true; summary.className = 'tk-schema-warning'; summary.textContent += ' — repeated entity definitions: review marked nodes';
                        }
                    } catch (error) { summary.className = 'tk-schema-warning'; summary.textContent += ' — invalid JSON'; }
                    details.appendChild(summary); messages(details, documentData.validation && documentData.validation.messages);
                    var nodes = document.createElement('div'); nodeCards(nodes, documentData.json, false, combined); details.appendChild(nodes);
                    var raw = document.createElement('details');
                    var rawTitle = document.createElement('summary'); rawTitle.textContent = 'View Full Document JSON';
                    var pre = document.createElement('pre'); pre.textContent = documentData.json; raw.appendChild(rawTitle); raw.appendChild(pre); details.appendChild(raw); live.appendChild(details);
                });
                renderCustom();
            } catch (error) { if (current === sequence) { liveData = null; liveAnalysis = null; renderCustom(); live.textContent = 'Live validation unavailable: ' + error.message; } }
            finally { if (current === sequence) retry.disabled = false; }
        }
        document.querySelectorAll('.tk-schema-editor-open').forEach(function (button) {
            button.addEventListener('click', async function () {
                opener = button; url = button.dataset.url; loaded = false; saving = false; editor.value = ''; live.replaceChildren();
                liveData = null; liveAnalysis = null; history = []; customNodes.replaceChildren(); resetAdd();
                showPanel('live');
                var current = ++sequence;
                dialog.querySelector('.tk-schema-editor-url').textContent = url;
                dialog.showModal(); lock(true); retry.disabled = true; status.textContent = 'Loading custom JSON-LD…';
                try {
                    var data = await request('load');
                    if (current !== sequence) return;
                    render(data); loadLive(current);
                } catch (error) { if (current === sequence) status.textContent = error.message + ' Close and reopen the dialog to retry.'; }
                finally { if (current === sequence) lock(false); }
            });
        });
        retry.addEventListener('click', function () { loadLive(sequence); });
        validate.addEventListener('click', async function () {
            showPanel('edit');
            lock(true); status.textContent = 'Validating…'; var current = sequence;
            try {
                var data = await request('validate');
                if (!data.validation || !Array.isArray(data.validation.messages)) throw new Error('The validation response is incomplete. Refresh the page and retry.');
                if (current === sequence) { status.textContent = data.validation.messages.join(' '); renderCustom(); }
            }
            catch (error) { if (current === sequence) status.textContent = error.message; }
            finally { if (current === sequence) lock(false); }
        });
        editor.addEventListener('input', function () { fillPath = null; add.textContent = 'Add JSON Node'; renderCustom(); });
        addType.addEventListener('change', function () { resetAdd(); renderCustom(); });
        undo.addEventListener('click', function () { if (history.length) { editor.value = history.pop(); resetAdd(); renderCustom(); status.textContent = 'Last draft edit undone.'; } });
        add.addEventListener('click', function () {
            try {
                var draft = parseDraft(), type = addType.value, name = addName.value.trim(), address = addUrl.value.trim();
                var parsed = new URL(address);
                if (!name || ['http:', 'https:'].indexOf(parsed.protocol) === -1 || parsed.username || parsed.password) throw new Error('Enter a name and an HTTP(S) URL.');
                var properties = {name: name}; properties[type === 'ImageObject' ? 'contentUrl' : 'url'] = address;
                if (fillPath) {
                    applyDraft(tools.fill(draft, fillPath, properties)); status.textContent = 'Missing properties filled in draft. Review and save to apply.';
                } else {
                    if (!liveData) throw new Error('Validate the live page before adding a node.');
                    if (tools.analyze(draft).types[type] || liveData.combined.types && liveData.combined.types[type]) throw new Error(type + ' is already present. Use Fill Missing Fields or edit the existing JSON.');
                    properties['@type'] = type;
                    parsed.hash = type === 'WebSite' ? 'website' : type === 'Organization' ? 'organization' : 'image';
                    properties['@id'] = parsed.href;
                    if (tools.analyze(draft).ids[properties['@id']] || liveData.combined.duplicate_ids && liveData.combined.duplicate_ids[properties['@id']]) throw new Error('That @id is already defined. Edit the existing entity instead.');
                    applyDraft(tools.add(draft, properties)); status.textContent = 'JSON node added to draft. Review and save to apply.';
                }
                showPanel('edit');
            } catch (error) { status.textContent = error instanceof SyntaxError ? 'Fix the JSON syntax before adding a node.' : error.message; }
        });
        save.addEventListener('click', async function () {
            sequence++;
            controllers.forEach(function (controller) { controller.abort(); });
            saving = true; lock(true); status.textContent = 'Saving custom JSON-LD…'; retry.disabled = true;
            try { var data = await request('save'); render(data); showPanel('live'); loadLive(sequence); }
            catch (error) { status.textContent = error.message + ' Reopen the dialog to check the saved state.'; loaded = false; retry.disabled = false; }
            finally { saving = false; lock(false); }
        });
        closes.forEach(function (button) { button.addEventListener('click', function () { if (!saving) dialog.close(); }); });
        dialog.addEventListener('cancel', function (event) { if (saving) event.preventDefault(); });
        dialog.addEventListener('close', function () { sequence++; controllers.forEach(function (controller) { controller.abort(); }); if (opener) opener.focus(); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
