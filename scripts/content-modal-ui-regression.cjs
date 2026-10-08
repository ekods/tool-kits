const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor() { this.dataset = {}; this.listeners = {}; this.children = []; this.disabled = false; this.value = ''; this.textContent = ''; }
    addEventListener(name, fn) { this.listeners[name] = fn; }
    setAttribute(key, value) { this[key] = value; }
    appendChild(child) { this.children.push(child); }
    replaceChildren() { this.children = []; this.textContent = ''; }
    focus() { this.focused = true; }
    showModal() { this.open = true; }
    close() { this.open = false; this.listeners.close(); }
    click() { if (!this.disabled && this.listeners.click) return this.listeners.click({preventDefault() {}, stopPropagation() {}}); }
}
const flush = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
const textOf = node => node.textContent + node.children.map(textOf).join(' ');
const find = (node, predicate) => predicate(node) ? node : node.children.map(child => find(child, predicate)).find(Boolean);
const stored = {version: 'v1', title: 'Project', excerpt: 'Saved excerpt', keyword: '', location: 'singapore', checked_at: 123,
    validation: {score: 42, priority: 'critical', words: 80, internal_links: 0, external_links: 0, issues: ['Low word count (<300)'],
        strategy_checks: [{category: 'Keyword targeting', label: 'Focus keyword', summary: 'Set a focus keyword.', score: 0, status: 'warning', finding: 'Focus keyword not set.', action: 'Set a relevant keyword.', target: 'https://site.example/edit'},
            {category: 'Structured data/schema', label: 'Schema', score: null, status: 'review', finding: 'Requires live validation.', action: 'Run GEO Audit.', target: 'javascript:alert(1)'},
            {category: 'URL consistency', label: 'URL format', score: 100, status: 'review', finding: 'Site origin matches.', action: 'Verify canonical live.'},
            {category: 'Portfolio SEO', label: 'Portfolio details', score: null, status: 'not-applicable', finding: 'Not a portfolio page.', action: 'Add portfolio context.'}]}};
const reply = data => Promise.resolve({ok: true, status: 200, text: async () => JSON.stringify({success: true, data})});
function fixture(fetcher, audit = true) {
    const root = new Element(), dialog = new Element(), opener = new Element(), selector = audit ? new Element() : null;
    root.dataset = {nonce: 'nonce', postId: audit ? '0' : '5', auditContext: audit ? '1' : '0'};
    opener.dataset.postId = '5';
    const keys = ['.tk-seo-content-save', '.tk-seo-content-validate', '.tk-seo-content-status', '.tk-seo-content-validation', '.tk-seo-content-summary'];
    const nodes = Object.fromEntries(keys.map(key => [key, new Element()]));
    const fields = ['content', 'links', 'excerpt', 'keyword', 'location', 'client', 'outcome'].map(name => { const field = new Element(); field.dataset.field = name; return field; });
    const tabs = ['checks', 'edit'].map(name => { const node = new Element(); node.dataset.contentPanel = name; return node; });
    const panels = ['checks', 'edit'].map(name => { const node = new Element(); node.dataset.panel = name; return node; });
    const closes = [new Element(), new Element()];
    root.querySelector = key => key === 'dialog' ? dialog : key === '.tk-seo-content-open' ? (audit ? null : opener) : key === '.tk-seo-content-page' ? selector : null;
    dialog.querySelector = key => nodes[key] || fields.find(field => key === '[data-field="' + field.dataset.field + '"]');
    dialog.querySelectorAll = key => key === '[data-field]' ? fields : key === '[data-content-panel]' ? tabs : key === '.tk-seo-content-panel' ? panels : key === '.tk-seo-content-close' ? closes : [];
    const timers = new Map(); let timerId = 0;
    const document = {readyState: 'complete', body: new Element(), querySelectorAll: key => key === '.tk-seo-content-editor' ? [root] : [opener], createElement: tag => { const node = new Element(); node.tagName = tag; return node; }};
    const location = {href: 'https://site.example/wp-admin/admin.php', hash: '', reload() { this.reloaded = true; }};
    vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/seo-content-modal.js', 'utf8'), {
        document, window: {location, confirm: () => true}, fetch: fetcher, ajaxurl: '/ajax', URL, URLSearchParams, AbortController, Set,
        setTimeout(fn) { timers.set(++timerId, fn); return timerId; }, clearTimeout(id) { timers.delete(id); }
    });
    return {root, dialog, opener, selector, nodes, fields, tabs, panels, closes, document, location, timers};
}
(async () => {
    const calls = [];
    const f = fixture((url, options) => { calls.push(options.body); assert.equal(options.cache, 'no-store'); return reply(stored); });
    f.opener.click(); await flush();
    assert.equal(calls[0].get('post_id'), '5');
    assert.equal(f.document.body.children[0], f.dialog);
    assert.equal(f.panels[0].hidden, false);
    assert.match(textOf(f.nodes['.tk-seo-content-summary']), /42\/100.*80/);
    assert.equal(find(f.nodes['.tk-seo-content-summary'], node => node.tagName === 'progress').value, 42);
    const grid = f.nodes['.tk-seo-content-validation'].children.at(-1);
    assert.equal(grid.children.length, 4);
    assert.match(grid.children[0].className, /warning/);
    assert.match(grid.children[1].className, /review/);
    assert.equal(find(grid.children[1], node => node.tagName === 'a'), undefined, 'Unsafe review URL rendered');
    assert.match(textOf(grid.children[0]), /Focus keyword.*0\/100/);
    assert.match(textOf(grid.children[1]), /Pending/);
    assert.match(textOf(grid.children[2]), /100\/100/);
    assert.match(textOf(grid.children[3]), /N\/A/);
    assert.equal(find(grid.children[3], node => node.tagName === 'button'), undefined, 'Not-applicable check offered improvements');
    const findings = find(f.nodes['.tk-seo-content-validation'], node => node.className === 'tk-content-findings');
    assert.equal(!!findings.open, false, 'Long audit findings expanded by default');
    const improve = find(grid.children[0], node => node.tagName === 'button' && node.textContent === 'Improve');
    improve.click();
    assert.equal(f.panels[1].hidden, false);
    assert.equal(f.fields[3].focused, true);
    f.fields[0].value = 'Unsaved draft'; f.fields[3].value = 'draft keyword';
    await f.nodes['.tk-seo-content-validate'].click();
    assert.equal(calls.at(-1).get('mode'), 'validate');
    assert.equal(calls.at(-1).has('content'), false, 'Validate submitted draft input');
    assert.equal(f.fields[0].value, 'Unsaved draft');
    assert.equal(f.fields[3].value, 'draft keyword');
    f.tabs[0].listeners.keydown({key: 'ArrowRight', preventDefault() {}});
    assert.equal(f.tabs[1]['aria-selected'], 'true');
    f.closes[0].click(); assert.equal(f.opener.focused, true);
    f.opener.click(); await flush();
    f.fields[0].value = 'New content';
    await f.nodes['.tk-seo-content-save'].click();
    assert.equal(calls.at(-1).get('content'), 'New content');
    assert.equal(f.location.hash, 'content-audit'); assert.equal(f.location.reloaded, true);

    const post = fixture((url, options) => { assert.equal(options.body.get('post_id'), '5'); return reply(stored); }, false);
    post.opener.click(); await flush(); assert.equal(post.panels[1].hidden, false); post.closes[1].click();
    const incomplete = fixture(() => reply({}));
    incomplete.opener.click(); await flush();
    assert.match(incomplete.nodes['.tk-seo-content-status'].textContent, /incomplete SEO validation/);
    assert.equal(incomplete.nodes['.tk-seo-content-save'].disabled, true);
    incomplete.tabs[1].click(); assert.equal(incomplete.panels[1].hidden, false); incomplete.closes[0].click();
    let attempts = 0;
    const retry = fixture(() => ++attempts === 2 ? Promise.reject(new Error('Network error')) : reply(stored));
    retry.opener.click(); await flush();
    await retry.nodes['.tk-seo-content-validate'].click();
    assert.equal(retry.nodes['.tk-seo-content-validate'].disabled, false);
    assert.match(retry.nodes['.tk-seo-content-status'].textContent, /retry/);
    await retry.nodes['.tk-seo-content-validate'].click();
    assert.match(retry.nodes['.tk-seo-content-status'].textContent, /Validation complete/);
    retry.closes[0].click();
    const timeout = fixture((url, options) => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('Aborted')))));
    timeout.opener.click(); [...timeout.timers.values()].forEach(fn => fn()); await flush();
    assert.match(timeout.nodes['.tk-seo-content-status'].textContent, /timed out/); timeout.closes[0].click();
    const stalled = fixture((url, options) => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('Aborted')))));
    stalled.opener.click(); stalled.closes[1].click(); await flush();
    assert.equal(stalled.dialog.open, false); assert.equal(stalled.opener.focused, true);
    console.log('PASS: compact check rows, total/per-check scores, pending/N/A states, collapsed details, audit-row selection, tabs, improvement focus, draft preservation, save refresh and request recovery');
})().catch(error => { console.error(error); process.exitCode = 1; });
