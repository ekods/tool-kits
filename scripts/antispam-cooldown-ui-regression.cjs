const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(__dirname + '/../assets/js/antispam-cooldown.js', 'utf8');
let now = 1700000000000;
let sequence = 0;
const intervals = new Map();
const listeners = {};
function node() {
    return { textContent: '', hidden: false, attributes: {},
        setAttribute(key, value) { this.attributes[key] = value; },
    };
}
function form() {
    const result = { isConnected: true, notice: null, blocked: false, acceptance: [], tips: [],
        buttons: [{ disabled: false }, { disabled: true }], output: node(),
        classList: { contains() { return false; } },
        matches(selector) { return selector === 'form.wpcf7-form'; },
        querySelector(selector) { return selector === '.tk-antispam-cooldown' ? this.notice : null; },
        querySelectorAll(selector) {
            if (selector.includes('input[type="submit"]')) return this.buttons;
            if (selector === '.wpcf7-acceptance:not(.optional)') return this.acceptance;
            if (selector === '.wpcf7-not-valid-tip') return this.tips;
            if (selector === '.wpcf7-response-output') return [this.output];
            return [];
        },
        appendChild(element) { this.notice = element; },
    };
    return result;
}
vm.runInNewContext(source, {
    Date: { now: () => now },
    window: {
        tkAntispamCooldown: { waiting: 'Please wait %s before sending another message.', ready: 'Ready to send.' },
        setInterval(callback) { const id = ++sequence; intervals.set(id, callback); return id; },
        clearInterval(id) { intervals.delete(id); },
    },
    document: {
        addEventListener(name, listener, capture) { listeners[name] = listener; if (name === 'submit') assert.equal(capture, true); },
        createElement: node,
    },
});
function feedback(target, seconds, status = 'validation_failed') {
    listeners.wpcf7submit({ target, detail: { apiResponse: {
        status, message: status === 'mail_sent' ? 'Message sent.' : 'Please wait.',
        ...(seconds === null ? {} : { tool_kits_cooldown: { retry_after: seconds } }),
    } } });
}
function advance(seconds) {
    now += seconds * 1000;
    Array.from(intervals.values()).forEach(callback => callback());
}
function submit(target) {
    const event = { target, prevented: false, stopped: false,
        preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; },
    };
    listeners.submit(event);
    return event;
}

const first = form();
const other = form();
first.output.textContent = 'Please wait.';
feedback(first, 65);
assert.match(first.notice.textContent, /01:05/);
assert.equal(first.notice.attributes.role, 'timer');
assert.equal(first.buttons[0].disabled, true);
assert.equal(other.buttons[0].disabled, false);
assert.equal(submit(first).prevented, true);
assert.equal(submit(first).stopped, true);
assert.equal(submit(other).prevented, false);
advance(10);
assert.match(first.notice.textContent, /00:55/);
advance(55);
assert.equal(first.notice.textContent, 'Ready to send.');
assert.equal(first.output.textContent, 'Ready to send.');
assert.equal(first.buttons[0].disabled, false);
assert.equal(first.buttons[1].disabled, true, 'An independently disabled button was enabled');
assert.equal(submit(first).prevented, false);
assert.equal(intervals.size, 0);

// Successful delivery remains visible, even after its resend timer ends.
first.output.textContent = 'Message sent.';
feedback(first, 5, 'mail_sent');
advance(5);
assert.equal(first.output.textContent, 'Message sent.');
feedback(first, 40);
feedback(first, null);
assert.equal(first.notice.hidden, true);
assert.equal(first.buttons[0].disabled, false);
assert.equal(intervals.size, 0);

// Background tab timer throttling must not extend the deadline.
feedback(first, 30);
advance(60);
assert.equal(first.buttons[0].disabled, false);
assert.equal(submit(first).prevented, false);

// CF7 events may originate from their containing .wpcf7 element.
const wrapper = { matches() { return false; }, querySelector() { return other; } };
feedback(wrapper, 10);
assert.match(other.notice.textContent, /00:10/);
other.buttons.push({ disabled: false });
advance(1);
assert.equal(other.buttons[2].disabled, true, 'A dynamically added submit button escaped cooldown');
other.isConnected = false;
advance(1);
assert.equal(intervals.size, 0, 'Removed forms leaked interval timers');

// Acceptance conditions remain enforced after cooldown, including changes made while waiting.
const withAcceptance = form();
const checkbox = { checked: true };
withAcceptance.acceptance = [{ classList: { contains() { return false; } }, querySelector() { return checkbox; } }];
feedback(withAcceptance, 5);
checkbox.checked = false;
advance(5);
assert.equal(withAcceptance.buttons[0].disabled, true);
feedback(withAcceptance, 5);
checkbox.checked = true;
advance(5);
assert.equal(withAcceptance.buttons[0].disabled, false);

// Only the expired cooldown's field error is cleared; unrelated validation errors survive.
const errors = form();
const control = { validity: 'Please wait.', attrs: {}, removed: [],
    setCustomValidity(value) { this.validity = value; },
    classList: { remove() {} }, setAttribute(key, value) { this.attrs[key] = value; }, removeAttribute(key) { this.removed.push(key); },
};
let removed = false;
const tip = { textContent: 'Please wait.', parentElement: { querySelectorAll() { return [control]; } }, remove() { removed = true; } };
errors.tips = [tip, { textContent: 'Email is invalid.', remove() { throw new Error('Unrelated error removed'); } }];
feedback(errors, 1);
advance(1);
assert.equal(removed, true);
assert.equal(control.validity, '');
assert.equal(control.attrs['aria-invalid'], 'false');
assert.equal(intervals.size, 0);

feedback(first, 'invalid');
assert.equal(first.notice.hidden, true);
assert.equal(intervals.size, 0);
console.log('PASS: countdown, real deadlines, retry capture, form isolation, success messages, disabled buttons, acceptance and field error recovery');
