const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor(tag = 'div') { this.tag = tag; this.dataset = {}; this.listeners = {}; this.children = []; this.textContent = ''; this.style = {}; this.disabled = false; this.hidden = false; }
    set innerHTML(value) { throw new Error('Unsafe HTML insertion into scanner report'); }
    addEventListener(name, fn) { this.listeners[name] = fn; }
    appendChild(node) { this.children.push(node); return node; }
    replaceChildren(...nodes) { this.children = nodes; this.textContent = ''; }
    get firstChild() { return this.children[0]; }
    closest(selector) { return selector === '[data-scan-restore]' && this.dataset.scanRestore !== undefined ? this : null; }
    querySelectorAll(selector) {
        const key = selector.slice(6, -1).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
        const all = []; const walk = item => { item.children.forEach(child => { if (Object.hasOwn(child.dataset, key)) all.push(child); walk(child); }); }; walk(this); return all;
    }
    click() { if (!this.disabled && this.listeners.click) { return this.listeners.click(); } }
}
const report = (status, findings = []) => ({status, phase: status === 'running' ? 'posts' : 'done', counts: {posts: 1, meta: 2, options: 3, urls: 4}, max_urls: 4, findings, issues: [], limited: status === 'partial'});
const evil = {severity: 'high', source: '<svg onload=steal()>', post_id: 44, line: 2, rule: 'event_handler', reason: '<script>steal()</script>', host: 'external.test', snippet: '<img src=x onerror=steal()>'};
const reply = data => ({ok: true, json: async () => ({success: true, data})});
const tick = async () => { for (let i = 0; i < 50; i++) { await Promise.resolve(); } };
const text = node => node.textContent + node.children.map(text).join(' ');
function fixture(fetcher, existingToken = '', contextual = false) {
    const root = new Element(), form = new Element('form'), submit = new Element('button'), status = new Element(), progress = new Element(), resume = new Element('button'), stop = new Element('button'), clear = new Element('button');
    const fields = [Object.assign(new Element('input'), {name: 'max_urls', value: '20'}), Object.assign(new Element('input'), {name: 'max_rows', value: '500'}), Object.assign(new Element('textarea'), {name: 'trusted_hosts', value: 'good.test'})];
    form.querySelectorAll = () => [...fields, submit];
    root.dataset = {endpoint: 'https://site.test/wp-admin/admin-ajax.php', editUrl: 'https://site.test/wp-admin/post.php', nonce: 'test-nonce', token: existingToken};
    const rootNodes = {'form': form, '[data-scan-status]': status, '[data-scan-progress]': progress, '[data-scan-resume]': resume, '[data-scan-stop]': stop, '[data-scan-clear]': clear};
    root.querySelector = key => rootNodes[key];
    const nodes = Object.fromEntries(['results', 'summary', 'issues', 'empty', ...(contextual ? ['review', 'suppressed', 'statistics'] : [])].map(key => [key, new Element()]));
    const reportRoot = contextual ? new Element() : null;
    const filters = contextual ? ['severity', 'type', 'source', 'classification', 'action', 'confidence', 'page'].map(key => Object.assign(new Element('select'), {dataset: {scanFilter: key}, value: ''})) : [];
    if (reportRoot) { reportRoot.children.push(...filters, nodes.results, nodes.review, nodes.suppressed); }
    const document = {getElementById: id => id === 'tk-html-scan' ? root : (id === 'tk-html-report' ? reportRoot : null), querySelector: key => nodes[key.match(/data-scan-(\w+)/)[1]], createElement: tag => new Element(tag)};
    const calls = []; let active = 0, peak = 0;
    vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/html-injection-scanner.js', 'utf8'), {
        document, window: {location: {href: 'https://site.test/wp-admin/admin.php?page=tool-kits-html-scanner'}}, URL, URLSearchParams, AbortController,
        FormData: class { constructor() { return fields.filter(field => !field.disabled).map(field => [field.name, field.value]); } },
        setTimeout() { return 1; }, clearTimeout() {},
        async fetch(url, options) {
            calls.push(options); active++; peak = Math.max(peak, active);
            assert.equal(options.method, 'POST'); assert.equal(options.credentials, 'same-origin'); assert.equal(options.cache, 'no-store');
            assert.equal(options.body.get('_ajax_nonce'), 'test-nonce');
            try { return await fetcher(options.body.get('action').replace('tk_html_scan_', ''), options.body); }
            finally { active--; }
        }
    });
    return {form, submit, fields, status, progress, resume, stop, clear, nodes, calls, reportRoot, filters, peak: () => peak, start() { form.listeners.submit({preventDefault() {}}); }};
}
(async () => {
    let steps = 0;
    const f = fixture(async (action, body) => {
        if (action === 'start') { assert.equal(body.get('trusted_hosts'), 'good.test'); return reply({token: 'token-1', report: report('running')}); }
        if (action === 'step') { steps++; return reply({done: steps === 2, report: report(steps === 2 ? 'partial' : 'running', [evil])}); }
        if (action === 'clear') { return reply({cleared: true}); }
        throw new Error(action);
    });
    f.start(); f.start(); await tick();
    assert.equal(f.calls.filter(call => call.body.get('action') === 'tk_html_scan_start').length, 1);
    assert.equal(f.peak(), 1); assert.equal(steps, 2); assert.match(f.status.textContent, /partial/);
    assert.equal(f.nodes.results.children.length, 1);
    assert.match(text(f.nodes.results), /<img src=x onerror=steal\(\)>/);
    assert.match(text(f.nodes.results), /<script>steal\(\)<\/script>/);
    assert.equal(f.nodes.results.children[0].children[1].children[1].href, 'https://site.test/wp-admin/post.php?post=44&action=edit');
    assert.equal(f.resume.disabled, true); assert.equal(f.submit.disabled, false);
    await f.clear.click(); assert.equal(f.nodes.results.children.length, 0); assert.equal(f.nodes.empty.hidden, false);

    let fail = true;
    const retry = fixture(async action => {
        if (action === 'start') { return reply({token: 'retry-token', report: report('running')}); }
        if (fail) { fail = false; throw new Error('Network interrupted'); }
        return reply({done: true, report: report('complete')});
    });
    retry.start(); await tick(); assert.match(retry.status.textContent, /Network interrupted/);
    assert.equal(retry.resume.disabled, false); assert.equal(retry.submit.disabled, true);
    await retry.resume.click(); await tick(); assert.match(retry.status.textContent, /complete/);
    assert.equal(retry.calls.filter(call => call.body.get('action') === 'tk_html_scan_start').length, 1);

    let finishBatch;
    const stop = fixture(async action => {
        if (action === 'start') { return reply({token: 'stop-token', report: report('running')}); }
        if (action === 'step') { return new Promise(resolve => { finishBatch = () => resolve(reply({done: false, report: report('running', [evil])})); }); }
        if (action === 'cancel') { return reply({done: true, report: report('cancelled', [evil])}); }
        throw new Error(action);
    });
    stop.start(); await tick(); stop.stop.click(); finishBatch(); await tick();
    assert.equal(stop.calls.filter(call => call.body.get('action') === 'tk_html_scan_step').length, 1);
    assert.equal(stop.calls.filter(call => call.body.get('action') === 'tk_html_scan_cancel').length, 1);
    assert.equal(stop.nodes.results.children.length, 1); assert.match(stop.status.textContent, /stopped/);

    const expired = fixture(async () => ({ok: false, json: async () => ({success: false, data: {message: 'Security nonce expired'}})}));
    expired.start(); await tick(); assert.match(expired.status.textContent, /nonce expired/); assert.equal(expired.submit.disabled, false);
    const safe = {...evil, severity: 'info', rule: 'event_handler', action: 'SKIP', classification: 'expected_behavior', confidence: .99, rule_id: 'SAFE_CSS_ONLOAD_001', fingerprint: 'a'.repeat(64), occurrences: 2, affected_urls: ['https://site.test/', 'https://site.test/about/'], timestamp: 10};
    const concern = {...evil, rule: 'cookie_outbound', action: 'REPORT', classification: 'dangerous_behavior', confidence: .94, affected_urls: ['https://site.test/']};
    const unknown = {...evil, rule: 'dynamic_script_loader', severity: 'medium', action: 'REVIEW', classification: 'unknown_integration', confidence: .65, affected_urls: ['https://site.test/']};
    const contextualReport = {...report('complete', [concern, unknown]), suppressed: [safe], statistics: {suppressed: 2, needs_review: 1, duplicates_grouped: 1}};
    const context = fixture(async (action, body) => {
        if (action === 'start') return reply({token: 'context', report: contextualReport});
        if (action === 'step') return reply({done: true, report: contextualReport});
        if (action === 'restore') { assert.equal(body.get('fingerprint'), safe.fingerprint); return reply({report: {...contextualReport, findings: [concern, unknown, {...safe, action: 'REVIEW', severity: 'low', classification: 'administrator_restored'}], suppressed: []}}); }
        throw new Error(action);
    }, '', true);
    assert.equal(context.calls.length, 0, 'Opening report automatically scans');
    context.start(); await tick();
    assert.equal(context.nodes.results.children.length, 1); assert.equal(context.nodes.review.children.length, 1); assert.equal(context.nodes.suppressed.children.length, 1);
    assert.match(text(context.nodes.suppressed), /SAFE_CSS_ONLOAD_001/); assert.match(text(context.nodes.suppressed), /Affected pages \(2\)/); assert.match(text(context.nodes.statistics), /Suppressed occurrences/);
    const allRows = () => [...context.nodes.results.children, ...context.nodes.review.children, ...context.nodes.suppressed.children];
    for (const [key, value] of Object.entries({severity: 'info', type: 'event_handler', source: safe.source, classification: 'expected_behavior', action: 'SKIP', confidence: '.98', page: 'https://site.test/about/'})) {
        const filter = context.filters.find(item => item.dataset.scanFilter === key); filter.value = value; filter.listeners.change();
        assert.equal(context.nodes.suppressed.children[0].hidden, false, key);
        if (key !== 'source') assert.equal(context.nodes.review.children[0].hidden, true, key);
        filter.value = ''; filter.listeners.change();
    }
    const restoreButton = context.reportRoot.querySelectorAll('[data-scan-restore]')[0]; assert.equal(restoreButton.disabled, false);
    await context.reportRoot.listeners.click({target: restoreButton});
    assert.equal(context.nodes.suppressed.children.length, 0); assert.equal(context.nodes.review.children.length, 2); assert.match(context.status.textContent, /restored/);
    assert.equal(context.calls.filter(call => call.body.get('action') === 'tk_html_scan_restore').length, 1);
    console.log('PASS: scanner start/batches, no overlapping requests, escaped evidence, source links, resume after failure, graceful stop, clear report and nonce errors');
    console.log('PASS: contextual report separation, seven filters, grouped pages/counts, suppression audit, manual restoration and no scan on page load');
})().catch(error => { console.error(error); process.exitCode = 1; });
