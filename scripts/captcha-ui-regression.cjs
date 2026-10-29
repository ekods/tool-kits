const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../assets/js/captcha.js', 'utf8');
const listeners = {};
const requests = [];
const replacements = new Map();
const forms = [];
let now = 1700000000000;
let serial = 0;
function classes() {
    const values = new Set();
    return { add: value => values.add(value), contains: value => values.has(value), remove: value => values.delete(value) };
}
function field(type, owner) {
    const current = { owner, dataset: {}, isConnected: true, attributes: { 'data-type': type },
        output: { textContent: '' }, token: { value: 'token-' + (++serial) }, answer: { value: '' },
        input: { value: '', focused: false }, button: { disabled: false },
        box: { classList: classes(), setAttribute(key, value) { this[key] = value; }, removeAttribute(key) { delete this[key]; } },
        puzzle: { hidden: true },
        getAttribute(key) { return this.attributes[key]; },
        setAttribute(key, value) { this.attributes[key] = value; },
        removeAttribute(key) { delete this.attributes[key]; },
        matches(selector) { return selector === '.tk-captcha-field'; },
        querySelector(selector) {
            return { '.tk-captcha-status': this.output, '.tk-captcha-puzzle': this.puzzle,
                '.tk-captcha-puzzle-input': this.input, '.tk-captcha-verify': this.button,
                '[name="tk_captcha_token"]': this.token, '[name="tk_captcha_answer"]': this.answer,
                '.tk-captcha-answer-field': this.answer, '.tk-captcha-checkbox-box': this.box,
                '.is-checked': this.box.classList.contains('is-checked') ? this.box : null }[selector] || null;
        },
        querySelectorAll() { return []; },
        replaceWith(next) { next.owner = this.owner; this.owner.fields = [next]; this.isConnected = false; },
    };
    current.puzzle.querySelector = () => current.input;
    current.input.focus = () => { current.input.focused = true; };
    [current.box, current.button, current.input].forEach((element) => {
        element.closest = selector => {
            if (selector === '.tk-captcha-field') return current;
            if (selector === '.tk-captcha-checkbox-left' && element === current.box) return current.box;
            if (selector === '.tk-captcha-verify' && element === current.button) return current.button;
            return null;
        };
        element.matches = selector => (selector === '.tk-captcha-checkbox-box' && element === current.box)
            || (selector === '.tk-captcha-puzzle-input' && element === current.input);
    });
    return current;
}
function form(type) {
    const owner = { fields: [], matches: selector => selector === 'form', querySelectorAll() { return this.fields; } };
    owner.fields = [field(type, owner)]; forms.push(owner); return owner;
}
const checkboxForm = form('checkbox');
const classicForm = form('text');
const context = vm.createContext({
    Date: { now: () => now }, URLSearchParams,
    window: { tkCaptcha: { refreshUrl: '/refresh', verifyUrl: '/verify', refreshNonce: 'refresh-nonce', verifyNonce: 'verify-nonce',
        minSeconds: 1, loading: 'Loading', required: 'Required', incorrect: 'Incorrect', verifyFailed: 'Check the box again', refreshFailed: 'Refresh failed', tooFast: 'Wait', rateLimited: 'Rate limited' },
        setTimeout(resolve, ms) { now += ms; resolve(); },
    },
    document: { readyState: 'loading', body: {},
        addEventListener(event, handler, capture) { listeners[event] = handler; if (event === 'submit') assert.equal(capture, true); },
        querySelectorAll() { return forms.flatMap(form => form.fields); },
        createElement(tag) {
            assert.equal(tag, 'template'); const template = {};
            template.content = { querySelector() { return replacements.get(template.innerHTML); } };
            return template;
        },
    },
    MutationObserver: class { observe() {} },
    fetch(url, options) { return new Promise(resolve => requests.push({ url, options, resolve })); },
});
vm.runInContext(source, context);
const flush = () => new Promise(resolve => setImmediate(resolve));
async function refreshed(index, type) {
    const marker = 'markup-' + index;
    const next = field(type, null); replacements.set(marker, next);
    requests[index].resolve({ ok: true, json: async () => ({ success: true, data: { markup: marker } }) });
    await flush(); return next;
}
function click(target) {
    const event = { target, prevented: false, preventDefault() { this.prevented = true; } };
    listeners.click(event); return event;
}
function submit(owner) {
    const event = { target: owner, prevented: false, stopped: false,
        preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } };
    listeners.submit(event); return event;
}

(async () => {
    listeners.DOMContentLoaded();
    await flush();
    assert.equal(requests.length, 1, 'Fresh browser cookie issuance must be serialized');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(requests[0].options.cache, 'no-store');
    assert.equal(submit(checkboxForm).prevented, true, 'Submission was allowed before challenge issuance');
    const checkbox = await refreshed(0, 'checkbox');
    assert.equal(requests.length, 2);
    const classic = await refreshed(1, 'text');
    assert.equal(checkbox.dataset.tkCaptchaReady, '1');
    assert.equal(classic.dataset.tkCaptchaReady, '1');

    click(checkbox.box);
    await flush();
    assert.equal(checkbox.puzzle.hidden, true, 'Checkbox revealed a code input');
    assert.equal(checkbox.input.focused, false, 'Checkbox requested keyboard input');
    assert.equal(requests.length, 3, 'One click must automatically request verification');
    assert.equal(checkbox.answer.value, '');
    assert.equal(submit(checkboxForm).stopped, true, 'Checkbox bypassed pending server verification');
    click(checkbox.box);
    await flush();
    assert.equal(requests.length, 3, 'Repeated clicks started overlapping verification requests');
    assert.equal(requests[2].url, '/verify');
    assert.equal(requests[2].options.body.get('checked'), '1');
    assert.equal(requests[2].options.body.get('answer'), null, 'Checkbox still submits a human-entered answer');
    assert.equal(requests[2].options.body.get('interact'), null, 'Client interaction counters must not be a security credential');
    assert.equal(checkbox.box.classList.contains('is-loading'), true);
    requests[2].resolve({ ok: true, json: async () => ({ success: true, data: { answer: 'a'.repeat(64) } }) });
    await flush();
    assert.equal(checkbox.answer.value, 'a'.repeat(64));
    assert.equal(checkbox.box.classList.contains('is-checked'), true);
    assert.equal(checkbox.puzzle.hidden, true);
    assert.equal(submit(checkboxForm).prevented, false);
    assert.equal(classic.answer.value, '', 'Solving one form changed another form');

    classic.answer.value = 'CODE234';
    assert.equal(submit(classicForm).prevented, false);
    listeners.wpcf7submit({ target: checkboxForm });
    await flush();
    assert.equal(requests.length, 4, 'A consumed challenge was not refreshed after CF7 response');
    assert.equal(requests[3].options.body.get('old_token'), checkbox.token.value);
    const nextCheckbox = await refreshed(3, 'checkbox');
    assert.equal(nextCheckbox.answer.value, '');
    assert.equal(nextCheckbox.box.classList.contains('is-checked'), false);
    assert.equal(classicForm.fields[0], classic, 'CF7 feedback refreshed unrelated forms');

    // The old constant answer can never check the widget. It requests a new challenge instead.
    click(nextCheckbox.box); await flush();
    requests[4].resolve({ ok: true, json: async () => ({ success: true, data: { answer: 'VERIFIED_CLICK' } }) });
    await flush();
    assert.equal(nextCheckbox.box.classList.contains('is-checked'), false);
    assert.equal(nextCheckbox.answer.value, '');
    const retry = await refreshed(5, 'checkbox');
    assert.equal(retry.output.textContent, 'Check the box again');
    assert.equal(retry.puzzle.hidden, true);

    now += 300001;
    assert.equal(submit(classicForm).prevented, true, 'Expired client challenge was submitted');
    await flush();
    await refreshed(6, 'text');
    assert.equal(classicForm.fields[0].answer.value, '');
    assert.equal(submit(classicForm).prevented, true);
    // A click during initial loading continues once the fresh token arrives, without another click.
    const loadingForm = form('checkbox');
    const loadingField = loadingForm.fields[0];
    listeners.wpcf7submit({ target: loadingForm }); await flush();
    click(loadingField.box); click(loadingField.box); await flush();
    assert.equal(requests.length, 8, 'Clicks during loading started extra challenge requests');
    const loaded = await refreshed(7, 'checkbox');
    assert.equal(requests.length, 9);
    assert.equal(requests[8].url, '/verify');
    requests[8].resolve({ ok: true, json: async () => ({ success: true, data: { answer: 'b'.repeat(64) } }) });
    await flush();
    assert.equal(loaded.box.classList.contains('is-checked'), true);
    assert.equal(submit(loadingForm).prevented, false);
    console.log('PASS: one-click checkbox, no code input, automatic server verification, opaque proof, duplicate-click guard, loading recovery, form isolation, expiry and retry refresh');
})().catch(error => { console.error(error); process.exitCode = 1; });
