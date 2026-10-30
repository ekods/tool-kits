const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../assets/db-cleanup.js', 'utf8');
function fixture(initial = {}) {
    const calls = [], replies = [], timers = [];
    const status = {textContent: ''}, counts = {textContent: ''}, submit = {};
    const buttons = ['pause', 'resume', 'cancel'].map(command => ({dataset: {command}, style: {}, hidden: true, addEventListener(_, callback) { this.click = callback; }}));
    const form = {elements: {_tk_nonce: {value: 'test-nonce'}}, querySelector() { return submit; }, addEventListener(_, callback) { this.submit = callback; }};
    const panel = {dataset: {job: JSON.stringify(initial), owner: '7', url: '/ajax'},
        querySelector(selector) { return selector.includes('status') ? status : selector.includes('counts') ? counts : submit; },
        querySelectorAll() { return buttons; }};
    class FormData {
        constructor(form) { this.data = form ? {do_revisions: '1', _tk_nonce: 'test-nonce'} : {}; }
        set(key, value) { this.data[key] = value; }
    }
    const context = {document: {getElementById(id) { return id.includes('form') ? form : panel; }}, FormData,
        window: {confirm() { return true; }}, setTimeout(callback) { timers.push(callback); },
        async fetch(url, args) {
            calls.push(args.body.data);
            const next = replies.shift();
            if (next instanceof Error) throw next;
            if (typeof next === 'function') return next();
            return {async json() { return next || {success: true, data: job('completed')}; }};
        }};
    vm.runInNewContext(source, context);
    return {calls, replies, timers, status, counts, submit, buttons, form};
}
function job(status = 'running') { return {id: 'job-id', owner: 7, status, tasks: ['revisions'], index: status === 'completed' ? 1 : 0, counts: {revisions: 50}}; }
async function flush() { for (let i = 0; i < 15; i++) await Promise.resolve(); }
(async () => {
    const f = fixture();
    f.replies.push({success: true, data: job()});
    f.form.submit({preventDefault() {}}); await flush();
    assert.equal(f.calls[0].command, 'start');
    assert.equal(f.calls[0]._tk_nonce, 'test-nonce');
    assert.equal(f.timers.length, 1);
    assert.equal(f.submit.disabled, true);
    let resolve;
    f.replies.push(() => new Promise(r => { resolve = r; }));
    f.timers.shift()(); await flush();
    f.buttons[0].click();
    assert.equal(f.calls.length, 2, 'pause must wait for the in-flight batch');
    resolve({async json() { return {success: true, data: job()}; }});
    f.replies.push({success: true, data: job('paused')}); await flush();
    assert.equal(f.calls[2].command, 'pause');
    assert.equal(f.calls[2].job_id, 'job-id');
    assert.match(f.status.textContent, /paused/);
    assert.equal(f.buttons[1].hidden, false);
    f.replies.push({success: true, data: job()}); f.buttons[1].click(); await flush();
    assert.equal(f.calls[3].command, 'resume');
    f.replies.push({success: true, data: job('completed')}); f.timers.shift()(); await flush();
    assert.match(f.status.textContent, /completed/);
    assert.equal(f.submit.disabled, false);
    assert.equal(f.timers.length, 0);
    const reloaded = fixture(job());
    assert.equal(reloaded.calls.length, 0, 'opening the page must not resume deletes automatically');
    assert.equal(reloaded.buttons[1].hidden, false);
    const error = fixture(); error.replies.push(new Error('Network interrupted'));
    error.form.submit({preventDefault() {}}); await flush();
    assert.match(error.status.textContent, /Network interrupted/);
    assert.equal(error.timers.length, 0, 'uncertain mutation must not retry automatically');
    const forbidden = fixture({...job(), owner: 8});
    assert(forbidden.buttons.every(b => b.hidden));
    const escaped = fixture({...job('paused'), error: '<img src=x onerror=alert(1)>'});
    assert.match(escaped.status.textContent, /<img/, 'server errors must be assigned as text');
    console.log('PASS: cleanup UI start, sequential batches, pause during request, resume, reload, completion and network failure');
})().catch(error => { console.error(error); process.exitCode = 1; });
