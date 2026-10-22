const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor(tag = 'div') { this.tag = tag; this.dataset = {}; this.listeners = {}; this.children = []; this.textContent = ''; this.style = {}; this.disabled = false; this.hidden = false; }
    set innerHTML(value) { throw new Error('Unsafe HTML insertion into scanner report'); }
    addEventListener(name, fn) { this.listeners[name] = fn; }
    appendChild(node) { this.children.push(node); return node; }
    replaceChildren(...nodes) { this.children = nodes; this.textContent = ''; }
    click() { if (!this.disabled && this.listeners.click) { return this.listeners.click(); } }
}
const report = (status, findings = []) => ({status, phase: status === 'running' ? 'posts' : 'done', counts: {posts: 1, meta: 2, options: 3, urls: 4}, max_urls: 4, findings, issues: [], limited: status === 'partial'});
const evil = {severity: 'high', source: '<svg onload=steal()>', post_id: 44, line: 2, rule: 'event_handler', reason: '<script>steal()</script>', host: 'external.test', snippet: '<img src=x onerror=steal()>'};
const reply = data => ({ok: true, json: async () => ({success: true, data})});
const tick = async () => { for (let i = 0; i < 50; i++) { await Promise.resolve(); } };
const text = node => node.textContent + node.children.map(text).join(' ');
function fixture(fetcher, existingToken = '') {
    const root = new Element(), form = new Element('form'), submit = new Element('button'), status = new Element(), progress = new Element(), resume = new Element('button'), stop = new Element('button'), clear = new Element('button');
    const fields = [Object.assign(new Element('input'), {name: 'max_urls', value: '20'}), Object.assign(new Element('input'), {name: 'max_rows', value: '500'}), Object.assign(new Element('textarea'), {name: 'trusted_hosts', value: 'good.test'})];
    form.querySelectorAll = () => [...fields, submit];
    root.dataset = {endpoint: 'https://site.test/wp-admin/admin-ajax.php', editUrl: 'https://site.test/wp-admin/post.php', nonce: 'test-nonce', token: existingToken};
    const rootNodes = {'form': form, '[data-scan-status]': status, '[data-scan-progress]': progress, '[data-scan-resume]': resume, '[data-scan-stop]': stop, '[data-scan-clear]': clear};
    root.querySelector = key => rootNodes[key];
    const nodes = Object.fromEntries(['results', 'summary', 'issues', 'empty'].map(key => [key, new Element()]));
    const document = {getElementById: id => id === 'tk-html-scan' ? root : null, querySelector: key => nodes[key.match(/data-scan-(\w+)/)[1]], createElement: tag => new Element(tag)};
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
    return {form, submit, fields, status, progress, resume, stop, clear, nodes, calls, peak: () => peak, start() { form.listeners.submit({preventDefault() {}}); }};
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
    console.log('PASS: scanner start/batches, no overlapping requests, escaped evidence, source links, resume after failure, graceful stop, clear report and nonce errors');
})().catch(error => { console.error(error); process.exitCode = 1; });
