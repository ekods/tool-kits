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
    const history = {children: ['saved history'], replaceChildren(...nodes) { this.children = nodes; }};
    const nodes = {'.tk-health-run': button, '.tk-health-progress': progress, '.tk-health-result': result, '.tk-health-scan-progress': meter, '.tk-health-history-body': history};
    const attributes = {};
    const handlers = {};
    const root = {dataset: {nonce: 'manual-nonce'}, classList: {contains: () => full}, querySelector: key => nodes[key], querySelectorAll: () => [], contains: () => true, addEventListener: (name, handler) => { handlers[name] = handler; }, setAttribute: (key, value) => { attributes[key] = value; }};
    let active = 0, peak = 0; const calls = [];
    vm.runInNewContext(source, {
        document: {querySelectorAll: () => [root]}, window: {ajaxurl: '/wp-admin/admin-ajax.php'}, URLSearchParams, AbortController,
        FormData: class { constructor(form) { this.fields = form.fields; } forEach(callback) { Object.entries(this.fields).forEach(([key, value]) => callback(value, key)); } },
        DOMParser: class { parseFromString(html, type) { assert.equal(type, 'text/html'); return {body: {childNodes: [html]}}; } },
        setTimeout(fn, time) { assert.equal(time, 45000); return 1; }, clearTimeout() {},
        async fetch(url, options) {
            calls.push(options.body); active++; peak = Math.max(peak, active);
            assert.equal(url, '/wp-admin/admin-ajax.php'); assert.equal(options.method, 'POST'); assert.equal(options.cache, 'no-store'); assert.equal(options.credentials, 'same-origin');
            assert.equal(options.body.get('_ajax_nonce'), 'manual-nonce'); assert.equal(options.body.get('full'), full ? '1' : '');
            try { return await fetcher(options.body.get('action'), options.body); } finally { active--; }
        }
    });
    return {button, progress, result, history, calls, meter, attributes, handlers, peak: () => peak};
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
    let recheckSteps = 0;
    const recheck = fixture(async (action, body) => {
        if (action === 'tk_health_center_start') { assert.equal(body.get('check'), 'homepage-title'); return reply({token: 'recheck-token', steps: 2, done: false}); }
        assert.equal(body.get('token'), 'recheck-token');
        return reply({token: 'recheck-token', done: ++recheckSteps === 2, html: '<p>Selected result refreshed</p>', message: 'Rechecked'});
    }, true);
    const trigger = {dataset: {healthCheck: 'homepage-title'}, textContent: 'Recheck'};
    const event = {target: {closest: () => trigger}};
    await Promise.all([recheck.handlers.click(event), recheck.handlers.click(event)]);
    assert.equal(recheck.calls.length, 3); assert.equal(recheckSteps, 2); assert.equal(trigger.textContent, 'Recheck');
    assert.deepEqual(recheck.result.children, ['<p>Selected result refreshed</p>']);
    const save = fixture(async (action, body) => {
        assert.equal(action, 'tk_health_center_settings'); assert.equal(body.get('ttfb_warning'), '1.2'); assert.equal(body.get('object_cache_required'), '0');
        return reply({html: '<p>Run again: policy changed</p>', message: 'Thresholds saved'});
    }, true);
    let prevented = false;
    const form = {matches: selector => selector === '.tk-health-policy-form', fields: {ttfb_warning: '1.2'}, querySelector: () => ({checked: false})};
    await save.handlers.submit({target: form, preventDefault: () => { prevented = true; }});
    assert.ok(prevented); assert.equal(save.calls.length, 1); assert.equal(save.progress.dataset.state, 'success'); assert.equal(save.button.disabled, false);
    const invalid = fixture(async () => ({ok: false, json: async () => ({success: false, data: {message: 'Critical must be worse than warning'}})}), true);
    await invalid.handlers.submit({target: form, preventDefault() {}});
    assert.deepEqual(invalid.result.children, ['saved report']); assert.match(invalid.progress.textContent, /Critical must be worse/);
    const compare = fixture(async (action, body) => {
        assert.equal(action, 'tk_health_center_history'); assert.equal(body.get('older'), 'a'.repeat(24)); assert.equal(body.get('newer'), 'b'.repeat(24));
        assert.equal(body.has('check'), false); assert.equal(body.has('object_cache_required'), false);
        return reply({html: '<p>Compared saved scans</p>', message: 'Saved comparison'});
    }, true);
    const historyForm = {matches: selector => selector === '.tk-health-history-form', fields: {older: 'a'.repeat(24), newer: 'b'.repeat(24)}};
    await compare.handlers.submit({target: historyForm, preventDefault() {}});
    assert.equal(compare.calls.length, 1); assert.deepEqual(compare.result.children, ['saved report']); assert.deepEqual(compare.history.children, ['<p>Compared saved scans</p>']);
    const lostHistory = fixture(async () => ({ok: false, json: async () => ({success: false, data: {message: 'Selected scan is no longer retained'}})}), true);
    await lostHistory.handlers.submit({target: historyForm, preventDefault() {}});
    assert.deepEqual(lostHistory.result.children, ['saved report']); assert.deepEqual(lostHistory.history.children, ['saved history']); assert.equal(lostHistory.progress.dataset.state, 'error'); assert.equal(lostHistory.button.disabled, false);
    console.log('PASS: Health Center UI manual scans, rechecks, policy saves, read-only history comparison, duplicate prevention and failure preservation');
})().catch(error => { console.error(error); process.exitCode = 1; });
