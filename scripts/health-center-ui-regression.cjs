const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../assets/health-center.js', 'utf8');
const reply = data => ({ok: true, json: async () => ({success: true, data})});
function fixture(fetcher, full = false) {
    const button = {disabled: false, textContent: 'Run Manual Scan', addEventListener(name, fn) { this[name] = fn; }};
    const progress = {textContent: '', dataset: {}};
    const meter = {hidden: true, value: 0};
    const result = {children: ['saved report'], replaceChildren(...nodes) { this.children = nodes; }};
    const nodes = {'.tk-health-run': button, '.tk-health-progress': progress, '.tk-health-result': result, '.tk-health-scan-progress': meter};
    const attributes = {};
    const root = {dataset: {nonce: 'manual-nonce'}, classList: {contains: () => full}, querySelector: key => nodes[key], setAttribute: (key, value) => { attributes[key] = value; }};
    let active = 0, peak = 0; const calls = [];
    vm.runInNewContext(source, {
        document: {querySelectorAll: () => [root]}, window: {ajaxurl: '/wp-admin/admin-ajax.php'}, URLSearchParams, AbortController,
        DOMParser: class { parseFromString(html, type) { assert.equal(type, 'text/html'); return {body: {childNodes: [html]}}; } },
        setTimeout(fn, time) { assert.equal(time, 45000); return 1; }, clearTimeout() {},
        async fetch(url, options) {
            calls.push(options.body); active++; peak = Math.max(peak, active);
            assert.equal(url, '/wp-admin/admin-ajax.php'); assert.equal(options.method, 'POST'); assert.equal(options.cache, 'no-store'); assert.equal(options.credentials, 'same-origin');
            assert.equal(options.body.get('_ajax_nonce'), 'manual-nonce'); assert.equal(options.body.get('full'), full ? '1' : '');
            try { return await fetcher(options.body.get('action'), options.body); } finally { active--; }
        }
    });
    return {button, progress, result, calls, meter, attributes, peak: () => peak};
}
(async () => {
    let steps = 0;
    const manual = fixture(async (action, body) => {
        if (action === 'tk_health_center_start') { return reply({token: 'manual-token', done: false, progress: 0, message: 'Starting'}); }
        assert.equal(action, 'tk_health_center_step'); assert.equal(body.get('token'), 'manual-token');
        steps++; return reply({token: 'manual-token', done: steps === 4, progress: steps * 25, message: 'Saved', html: '<p>Measured report</p>'});
    }, true);
    assert.equal(manual.calls.length, 0, 'Opening dashboard starts HTTP scan');
    const first = manual.button.click(); const duplicate = manual.button.click(); await Promise.all([first, duplicate]);
    assert.equal(manual.calls.length, 5); assert.equal(manual.peak(), 1); assert.equal(steps, 4);
    assert.equal(manual.button.disabled, false); assert.deepEqual(manual.result.children, ['<p>Measured report</p>']); assert.equal(manual.progress.textContent, 'Saved');
    assert.equal(manual.progress.dataset.state, 'success'); assert.equal(manual.button.textContent, 'Run Manual Scan'); assert.equal(manual.attributes['aria-busy'], 'false'); assert.equal(manual.meter.hidden, true); assert.equal(manual.meter.value, 100);
    const denied = fixture(async () => ({ok: false, json: async () => ({success: false, data: {message: 'Nonce expired'}})}));
    await denied.button.click(); assert.deepEqual(denied.result.children, ['saved report']); assert.match(denied.progress.textContent, /Nonce expired/); assert.equal(denied.button.disabled, false);
    assert.equal(denied.progress.dataset.state, 'error'); assert.equal(denied.meter.hidden, true);
    const failed = fixture(async () => { const error = new Error('Timeout'); error.name = 'AbortError'; throw error; });
    await failed.button.click(); assert.deepEqual(failed.result.children, ['saved report']); assert.match(failed.progress.textContent, /timed out/); assert.equal(failed.button.disabled, false);
    const unfinished = fixture(async () => reply({done: false, token: 'token', message: 'Pending', progress: 25}));
    await unfinished.button.click(); assert.equal(unfinished.calls.length, 5); assert.equal(unfinished.progress.dataset.state, 'error'); assert.deepEqual(unfinished.result.children, ['saved report']);
    console.log('PASS: Health Center UI opens without scanning; explicit click, sequential phases, duplicate prevention, nonce/timeout errors and saved-result preservation');
})().catch(error => { console.error(error); process.exitCode = 1; });
