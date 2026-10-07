const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor() { this.dataset = {}; this.listeners = {}; this.children = []; this.disabled = false; this.value = ''; this.textContent = ''; }
    addEventListener(name, handler) { this.listeners[name] = handler; }
    setAttribute(key, value) { this[key] = value; }
    appendChild(child) { this.children.push(child); }
    querySelectorAll(selector) { return this.children.flatMap(child => (selector === 'button' && child.tagName === 'button' ? [child] : []).concat(child.querySelectorAll(selector))); }
    replaceChildren() { this.children = []; this.textContent = ''; }
    focus() { this.focused = true; }
    showModal() { this.open = true; }
    close() { this.open = false; if (this.listeners.close) this.listeners.close(); }
    click() { if (!this.disabled) return this.listeners.click(); }
}
const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
const helperContext = {module: {exports: {}}};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/schema-editor-tools.js', 'utf8'), helperContext);
const schemaTools = helperContext.module.exports;
function fixture(fetcher) {
    const dialog = new Element(); dialog.dataset.nonce = 'nonce';
    const keys = ['textarea', '.tk-schema-editor-status', '.tk-schema-live', '.tk-schema-save', '.tk-schema-validate', '.tk-schema-retry', '.tk-schema-editor-url', '.tk-schema-custom-nodes', '.tk-schema-undo', '.tk-schema-missing', '.tk-schema-add-type', '.tk-schema-add-name', '.tk-schema-add-url', '.tk-schema-add'];
    const nodes = Object.fromEntries(keys.map(key => [key, new Element()]));
    nodes['.tk-schema-add-type'].value = 'WebSite';
    const cross = new Element(), footer = new Element(), opener = new Element();
    const tabs = ['live', 'edit', 'add'].map(name => { const tab = new Element(); tab.dataset.schemaPanel = name; return tab; });
    const panels = ['live', 'edit', 'add'].map(name => { const panel = new Element(); panel.dataset.panel = name; return panel; });
    opener.dataset.url = 'https://site.example/';
    dialog.querySelector = key => nodes[key];
    dialog.querySelectorAll = selector => selector === '.tk-schema-close' ? [cross, footer] : selector === '[data-schema-panel]' ? tabs : selector === '.tk-schema-panel' ? panels : [];
    const timers = new Map(); let timerId = 0;
    const document = {body: new Element(), readyState: 'complete', getElementById: () => dialog, querySelectorAll: () => [opener], createElement: tag => { const node = new Element(); node.tagName = tag; return node; }};
    vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/geo-schema-editor.js', 'utf8'), {
        document, window: {location: {href: 'https://site.example/wp-admin/admin.php'}, ToolKitsSchemaEditor: schemaTools}, fetch: fetcher, ajaxurl: '/ajax', URLSearchParams, URL, AbortController, Set,
        setTimeout: (fn, ms) => { timers.set(++timerId, {fn, ms}); return timerId; }, clearTimeout: id => timers.delete(id)
    });
    return {dialog, nodes, cross, footer, opener, timers, tabs, panels, document};
}
const reply = data => Promise.resolve({ok: true, status: 200, text: async () => JSON.stringify({success: true, data})});
const stored = {json: '{"@type":"WebSite"}', version: 'v1', validation: {messages: ['Valid']}};
(async () => {
    let liveAttempts = 0;
    const f = fixture((url, options) => {
        const mode = options.body.get('mode');
        if (mode === 'load') return reply(stored);
        if (mode === 'live' && ++liveAttempts > 1) return reply({live: {documents: [], combined: {messages: ['No JSON-LD found.']}}});
        return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), {name: 'AbortError'}))));
    });
    await f.opener.click(); await flush();
    assert.equal(f.nodes.textarea.value, stored.json);
    assert.equal(f.nodes['.tk-schema-save'].disabled, false, 'Live fetch blocked editor');
    assert.equal(f.cross.disabled, false, 'Cannot close during live fetch');
    assert.match(f.nodes['.tk-schema-live'].textContent, /Loading/);
    for (const timer of [...f.timers.values()]) timer.fn();
    await flush();
    assert.match(f.nodes['.tk-schema-live'].textContent, /timed out/);
    assert.equal(f.nodes['.tk-schema-retry'].disabled, false);
    f.nodes['.tk-schema-retry'].click(); await flush();
    assert.equal(f.nodes['.tk-schema-live'].textContent, '');
    assert.ok(f.nodes['.tk-schema-live'].children.length);
    f.cross.click(); assert.equal(f.dialog.open, false); assert.equal(f.opener.focused, true);

    const bad = fixture(() => Promise.resolve({ok: false, status: 500, text: async () => '<html>Failure</html>'}));
    await bad.opener.click();
    assert.match(bad.nodes['.tk-schema-editor-status'].textContent, /invalid response.*500/);
    assert.equal(bad.cross.disabled, false);
    bad.footer.click(); assert.equal(bad.dialog.open, false);

    const nonce = fixture(() => Promise.resolve({ok: false, status: 403, text: async () => '-1'}));
    await nonce.opener.click();
    assert.match(nonce.nodes['.tk-schema-editor-status'].textContent, /session expired/);

    const stalled = fixture((url, options) => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('Aborted')))));
    const pending = stalled.opener.click();
    stalled.cross.click(); await pending;
    assert.equal(stalled.dialog.open, false);
    assert.equal(stalled.opener.focused, true);

    // An old/cached endpoint can return editor data for a live request. This
    // must produce a recoverable message while all modal tabs still work.
    const incompatible = fixture((url, options) => {
        if (options.body.get('mode') === 'live') {
            assert.equal(options.body.get('action'), 'tk_geo_schema_editor_live');
            assert.equal(options.cache, 'no-store');
            assert.equal(new URL(url).searchParams.get('tk_schema_operation'), 'live');
        }
        return reply(stored);
    });
    await incompatible.opener.click(); await flush();
    assert.match(incompatible.nodes['.tk-schema-live'].textContent, /did not return live schema data/);
    assert.doesNotMatch(incompatible.nodes['.tk-schema-live'].textContent, /Cannot read properties/);
    assert.equal(incompatible.nodes['.tk-schema-retry'].disabled, false);
    assert.equal(incompatible.nodes['.tk-schema-save'].disabled, false);
    incompatible.tabs.forEach((tab, index) => {
        tab.listeners.click({preventDefault() {}, stopPropagation() {}});
        assert.equal(incompatible.panels[index].hidden, false);
        assert.equal(tab['aria-selected'], 'true');
    });
    assert.equal(incompatible.document.body.children[0], incompatible.dialog, 'Dialog remains inside the parent form');
    incompatible.cross.click();
    for (const liveResponse of [null, {}, {documents: null, combined: {}}, {documents: [null], combined: {messages: []}}]) {
        const incomplete = fixture((url, options) => options.body.get('mode') === 'load' ? reply(stored) : reply({live: liveResponse}));
        await incomplete.opener.click(); await flush();
        assert.match(incomplete.nodes['.tk-schema-live'].textContent, /Live validation unavailable/);
        assert.doesNotMatch(incomplete.nodes['.tk-schema-live'].textContent, /Cannot read properties/);
        incomplete.tabs[1].listeners.click({preventDefault() {}, stopPropagation() {}});
        assert.equal(incomplete.panels[1].hidden, false);
        incomplete.cross.click();
    }

    // On a post editor, Target Location is the first select. It must never
    // substitute for the post ID used by the content-editor AJAX endpoint.
    const seo = fs.readFileSync(__dirname + '/../assets/seo-content-modal.js', 'utf8');
    assert.ok(seo.includes("root.querySelector('.tk-seo-content-page')"));
    const duplicateJson = JSON.stringify({'@context': 'https://schema.org', '@graph': [
        {'@type': 'WebSite', '@id': '#site', name: 'One'}, {'@type': 'WebSite', '@id': '#site', name: 'Two'},
        {'@type': 'Organization', '@id': '#org', name: 'Company'}
    ]});
    const marked = fixture((url, options) => options.body.get('mode') === 'load'
        ? reply({...stored, json: duplicateJson, defaults: {name: 'Company', url: 'https://site.example/'}})
        : reply({live: {documents: [{json: duplicateJson, validation: {messages: []}}], combined: {
            types: {WebSite: 2, Organization: 1}, duplicate_types: {WebSite: 2}, duplicate_ids: {'#site': 2}, messages: ['Repeated type: WebSite']
        }}}));
    await marked.opener.click(); await flush();
    const cards = marked.nodes['.tk-schema-custom-nodes'].children;
    assert.equal(cards.filter(card => card.className.includes('tk-schema-duplicate')).length, 2, 'Repeated nodes not marked red');
    assert.ok(cards[0].children.find(child => child.tagName === 'details').children.find(child => child.tagName === 'pre').children.some(line => line.className === 'tk-schema-mark'), 'JSON lines not marked');
    cards[0].querySelectorAll('button')[0].click();
    assert.equal(JSON.parse(marked.nodes.textarea.value)['@graph'].length, 2, 'Remove did not modify draft');
    marked.nodes['.tk-schema-undo'].click();
    assert.equal(marked.nodes.textarea.value, duplicateJson, 'Undo lost original JSON');
    marked.nodes['.tk-schema-add-type'].value = 'ImageObject';
    marked.nodes['.tk-schema-add-type'].listeners.change();
    marked.nodes['.tk-schema-add-name'].value = 'Company logo';
    marked.nodes['.tk-schema-add-url'].value = 'https://site.example/logo.png';
    marked.nodes['.tk-schema-add'].click();
    const added = JSON.parse(marked.nodes.textarea.value)['@graph'].find(node => node['@type'] === 'ImageObject');
    assert.equal(added.contentUrl, 'https://site.example/logo.png');
    const afterAdd = marked.nodes.textarea.value;
    marked.nodes['.tk-schema-add'].click();
    assert.equal(marked.nodes.textarea.value, afterAdd, 'Duplicate template was added');
    const orgCard = marked.nodes['.tk-schema-custom-nodes'].children.find(card => card.children[0].textContent.includes('Organization'));
    orgCard.querySelectorAll('button')[1].click();
    assert.equal(marked.panels[2].hidden, false, 'Missing-field action did not open Add Schema panel');
    marked.nodes['.tk-schema-add-name'].value = 'Changed company';
    marked.nodes['.tk-schema-add-url'].value = 'https://site.example/';
    marked.nodes['.tk-schema-add'].click();
    const filled = JSON.parse(marked.nodes.textarea.value)['@graph'].find(node => node['@type'] === 'Organization');
    assert.equal(filled.name, 'Company', 'Existing name overwritten');
    assert.equal(filled.url, 'https://site.example/', 'Missing URL not filled');
    assert.equal(marked.panels[1].hidden, false, 'Draft changes did not return to JSON editor');
    marked.tabs[0].listeners.keydown({key: 'ArrowRight', preventDefault() {}});
    assert.equal(marked.tabs[1]['aria-selected'], 'true', 'Keyboard tab navigation failed');
    marked.cross.click();
    // Matching types alone must not mark FAQ/list/people/image nodes red.
    const normalGraph = {'@context': 'https://schema.org', '@graph': [
        {'@type': 'FAQPage', mainEntity: Array.from({length: 20}, (_, index) => ({'@type': 'Question', '@id': 'question-' + index, name: 'Question ' + index,
            acceptedAnswer: {'@type': 'Answer', text: 'Answer ' + index}}))},
        {'@type': 'BreadcrumbList', itemListElement: Array.from({length: 12}, (_, index) => ({'@type': 'ListItem', position: index + 1, name: 'Item ' + index}))},
        ...Array.from({length: 2}, (_, index) => ({'@type': 'ImageObject', '@id': '#image-' + index})),
        ...Array.from({length: 8}, (_, index) => ({'@type': 'Place', name: 'Place ' + index,
            ...(index < 2 ? {address: {'@type': 'PostalAddress', streetAddress: 'Address ' + index}} : {})})),
        ...Array.from({length: 12}, (_, index) => ({'@type': 'Person', name: 'Person ' + index}))
    ]};
    const normalJson = JSON.stringify(normalGraph);
    const normal = fixture((url, options) => options.body.get('mode') === 'load' ? reply({...stored, json: normalJson})
        : reply({live: {documents: [{json: normalJson, validation: {messages: ['Valid']}}], combined: {messages: ['Valid'], duplicate_ids: {}}}}));
    normal.opener.dataset.duplicates = JSON.stringify({Question: 20, ImageObject: 2, Person: 12});
    await normal.opener.click(); await flush();
    assert.ok(normal.nodes['.tk-schema-custom-nodes'].children.every(card => !card.className.includes('tk-schema-duplicate')));
    const liveDoc = normal.nodes['.tk-schema-live'].children.find(child => child.tagName === 'details' && child.children[0].textContent.includes('Live document'));
    assert.notEqual(liveDoc.open, true, 'Normal FAQ/list nodes expanded as duplicate errors');
    const liveCards = liveDoc.children.find(child => child.tagName === 'div').children;
    assert.ok(liveCards.every(card => !card.className.includes('tk-schema-duplicate')));
    const inventory = normal.nodes['.tk-schema-live'].children.find(child => child.tagName === 'details' && child.children[0].textContent.includes('Inventory'));
    assert.ok(inventory.children[1].children.some(item => item.textContent === 'Question: 20' && !item.className));
    normal.cross.click();
    const anonymous = JSON.stringify([{'@type': 'WebSite', name: 'Site', url: 'https://site.example'}, {url: 'https://site.example', name: 'Site', '@type': 'WebSite', '@context': 'https://schema.org'}]);
    const exact = fixture((url, options) => options.body.get('mode') === 'load' ? reply({...stored, json: anonymous})
        : reply({live: {documents: [{json: anonymous, validation: {messages: []}}], combined: {messages: [], duplicate_ids: {}}}}));
    await exact.opener.click(); await flush();
    assert.equal(exact.nodes['.tk-schema-custom-nodes'].children.filter(card => card.className.includes('tk-schema-duplicate')).length, 2);
    exact.cross.click();
    const sharedAddress = schemaTools.analyze([{'@type': 'Person', '@id': '#a', address: {'@type': 'PostalAddress', streetAddress: 'Same'}},
        {'@type': 'Person', '@id': '#b', address: {'@type': 'PostalAddress', streetAddress: 'Same'}}]);
    assert.equal(Object.keys(sharedAddress.anonymous).length, 0, 'Nested shared data flagged as anonymous top-level duplicates');
    assert.equal(schemaTools.analyze([{'@type': 'Organization', '@id': '#org'}, {'@id': '#org', '@context': 'https://schema.org'}]).ids['#org'], 1);
    console.log('PASS: independent live loading, timeout, retry, server errors, expired nonce, close during loading, focus restoration and page selector');
    console.log('PASS: missing/incompatible live responses, dedicated uncached live requests and clickable tabs after fetch failure');
    console.log('PASS: red duplicate nodes/JSON lines, draft removal, undo, missing schema addition, duplicate prevention and missing-field preservation');
    console.log('PASS: repeated types remain neutral inventories; red marks require repeated identities or identical top-level nodes');
})().catch(error => { console.error(error); process.exit(1); });
