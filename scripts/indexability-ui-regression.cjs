const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(tag = 'div', text = '') { this.tagName = tag; this.textContent = text; this.children = []; this.listeners = {}; this.dataset = {}; this.style = {}; this.disabled = false; this.value = ''; }
    appendChild(node) { this.children.push(node); return node; }
    replaceChildren() { this.children = []; }
    setAttribute(key, value) { this[key] = value; }
    addEventListener(key, fn) { this.listeners[key] = fn; }
    click() { if (!this.disabled && this.listeners.click) return this.listeners.click(); }
}
const walk = node => [node, ...node.children.flatMap(walk)];
const textOf = node => node.textContent + node.children.map(textOf).join(' ');
const findButton = (root, text) => walk(root).find(node => node.tagName === 'button' && node.textContent === text);
const reply = data => ({json: async () => ({success: true, data})});
const issueItem = () => ({url: 'https://site.example/page/', scanned_at: 1780000000, editable: true, has_fix: false, toolkits_redirect: true,
    checks: Object.fromEntries(['noindex', 'canonical', 'robots', 'redirect'].map(kind => [kind, {label: kind, state: 'issue', detail: '<img src=x onerror=alert(1)>', recommendation: 'Review ' + kind}]))});
function fixture(saved, fetcher) {
    const root = new Element(); root.dataset = {nonce: 'valid', home: 'https://site.example/'};
    const results = new Element(), status = new Element(), scan = new Element('button'), data = new Element('script', JSON.stringify(saved));
    const fixAll = new Element('button'), stop = new Element('button'), includeRedirects = new Element('input'), bulkResults = new Element();
    includeRedirects.checked = false;
    root.children = [results, status, scan, fixAll, stop, includeRedirects, bulkResults, data];
    root.querySelector = selector => ({'[data-indexability-results]': results, '[data-indexability-status]': status, '[data-indexability-scan]': scan, '.tk-indexability-saved': data,
        '[data-indexability-fix-all]': fixAll, '[data-indexability-stop]': stop, '[data-indexability-include-redirects]': includeRedirects, '[data-indexability-bulk-results]': bulkResults}[selector]);
    root.querySelectorAll = selector => walk(root).filter(node => selector === 'input[data-indexability-canonical]' ? node.tagName === 'input' && !!node.dataset.indexabilityCanonical : ['button', 'input', 'textarea'].includes(node.tagName));
    vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/seo-indexability.js', 'utf8'), {
        document: {getElementById: () => root, createElement: tag => new Element(tag), createTextNode: text => new Element('#text', text)},
        window: {ajaxurl: '/wp-admin/admin-ajax.php'}, fetch: fetcher, URL, URLSearchParams, Map, Set, Date
    });
    return {root, results, status, scan, fixAll, stop, includeRedirects, bulkResults};
}
(async () => {
    const calls = []; let mode = 'fix';
    const item = issueItem();
    const f = fixture([item], async (url, options) => {
        calls.push(options.body);
        assert.equal(url, '/wp-admin/admin-ajax.php'); assert.equal(options.credentials, 'same-origin');
        assert.equal(options.body.get('nonce'), 'valid');
        if (mode === 'fail') return {json: async () => ({success: false, data: {message: 'Edit the server rule.'}})};
        if (options.body.get('operation') === 'fix') return reply({message: 'Saved reviewed override.'});
        const fresh = issueItem(); fresh.checks.noindex.state = 'pass'; fresh.has_fix = true;
        return reply({item: fresh});
    });
    assert.ok(findButton(f.results, 'Remove page noindex'));
    assert.ok(findButton(f.results, 'Set preferred canonical'));
    assert.ok(findButton(f.results, 'Allow this URL for Googlebot'));
    assert.ok(findButton(f.results, 'Disable Tool Kits redirect'));
    assert.match(textOf(f.results), /Allow: \/page\/\$/);
    assert.equal(walk(f.results).filter(node => node.tagName === 'img').length, 0, 'Unsafe evidence became HTML');
    await findButton(f.results, 'Remove page noindex').click();
    assert.equal(calls.length, 2, 'Fix and live recheck must use separate requests');
    assert.equal(calls[0].get('operation'), 'fix'); assert.equal(calls[0].get('kind'), 'noindex'); assert.equal(calls[1].get('operation'), 'scan');
    assert.match(f.status.textContent, /3 issue\(s\)/); assert.equal(f.scan.disabled, false);
    assert.equal(findButton(f.results, 'Remove page noindex'), undefined, 'Passed check still offers a fix');
    assert.ok(findButton(f.results, 'Remove page overrides'));

    const canonical = walk(f.results).find(node => node.tagName === 'input'); canonical.value = 'https://site.example/preferred/';
    await findButton(f.results, 'Set preferred canonical').click();
    assert.equal(calls[2].get('canonical'), canonical.value);
    mode = 'fail';
    await findButton(f.results, 'Allow this URL for Googlebot').click();
    assert.equal(f.status.textContent, 'Edit the server rule.'); assert.equal(f.scan.disabled, false);

    const deferred = []; const sequential = [];
    const batch = fixture([], (url, options) => { sequential.push(options.body); return new Promise(resolve => deferred.push(resolve)); });
    assert.equal(walk(batch.root).some(node => node.tagName === 'textarea'), false, 'Scan still needs a pasted URL list');
    const running = batch.scan.click(); assert.equal(batch.scan.disabled, true); batch.scan.click();
    assert.equal(sequential.length, 1, 'Batch requests ran concurrently or duplicate click was accepted');
    assert.equal(sequential[0].get('operation'), 'discover'); assert.equal(sequential[0].get('url'), '');
    deferred.shift()(reply({urls: ['https://site.example/page/', 'https://site.example/page/'], has_more: true, next_page: 2}));
    for (let i = 0; i < 10; i++) await Promise.resolve();
    assert.equal(sequential.length, 2); assert.equal(batch.scan.disabled, true);
    assert.equal(sequential[1].get('url'), 'https://site.example/page/');
    deferred.shift()(reply({item: issueItem()}));
    for (let i = 0; i < 10; i++) await Promise.resolve();
    assert.equal(sequential[2].get('operation'), 'discover'); assert.equal(sequential[2].get('page'), '2');
    deferred.shift()(reply({urls: ['https://site.example/page/', 'https://site.example/second/'], has_more: false, next_page: 3}));
    for (let i = 0; i < 10; i++) await Promise.resolve();
    assert.equal(sequential[3].get('url'), 'https://site.example/second/');
    const second = issueItem(); second.url = 'https://site.example/second/';
    deferred.shift()(reply({item: second})); await running;
    assert.equal(sequential.length, 4, 'Duplicate URL scanned twice'); assert.match(batch.status.textContent, /2\/2 URLs checked/); assert.equal(batch.scan.disabled, false);
    const discoveryError = fixture([], async () => ({json: async () => ({success: false, data: {message: 'Discovery unavailable.'}})}));
    await discoveryError.scan.click(); assert.match(discoveryError.status.textContent, /Discovery unavailable/); assert.equal(discoveryError.scan.disabled, false);
    const large = fixture([], async (url, options) => {
        if (options.body.get('operation') === 'discover') return reply({urls: Array.from({length: 105}, (_, i) => 'https://site.example/' + i), has_more: false, next_page: 2});
        const fresh = issueItem(); fresh.url = options.body.get('url'); return reply({item: fresh});
    });
    await large.scan.click(); assert.match(large.status.textContent, /105\/105 URLs checked/, 'Automatic scan stopped at the former 100 URL cap');
    const readonly = issueItem(); readonly.editable = false;
    const restricted = fixture([readonly], () => { throw new Error('Unexpected request'); });
    assert.equal(findButton(restricted.results, 'Remove page noindex'), undefined, 'Uneditable URL offers automatic mutation');
    const upstream = issueItem(); upstream.header_noindex = true; upstream.header_canonical = true; upstream.toolkits_redirect = false;
    const manual = fixture([upstream], () => { throw new Error('Unexpected request'); });
    assert.equal(findButton(manual.results, 'Remove page noindex'), undefined, 'Server header offers a misleading automatic fix');
    assert.equal(findButton(manual.results, 'Set preferred canonical'), undefined, 'HTTP canonical header offers an HTML-only fix');
    assert.equal(findButton(manual.results, 'Disable Tool Kits redirect'), undefined, 'Unknown redirect offers a Tool Kits mutation');

    // Bulk fixes run in order, preserve canonical choices, verify each change,
    // retain intentional redirects, and skip source-only or uneditable findings.
    const pageA = issueItem(); pageA.canonicals = [];
    const pageB = issueItem(); pageB.url = 'https://site.example/second/'; pageB.canonicals = ['https://site.example/a/', 'https://site.example/b/'];
    const external = issueItem(); external.url = 'https://site.example/upstream/'; external.header_noindex = true; external.header_canonical = true; external.toolkits_redirect = false; external.checks.robots.state = 'pass';
    const noEdit = issueItem(); noEdit.url = 'https://site.example/no-edit/'; noEdit.editable = false;
    const state = new Map([pageA, pageB, external, noEdit].map(item => [item.url, structuredClone(item)]));
    const bulkCalls = [];
    const bulk = fixture([pageA, pageB, external, noEdit], async (url, options) => {
        const body = options.body; bulkCalls.push(body);
        const item = state.get(body.get('url'));
        if (body.get('operation') === 'fix') {
            if (body.get('kind') === 'canonical') assert.equal(body.get('canonical'), item.url === pageB.url ? 'https://site.example/preferred/' : item.url);
            item.checks[body.get('kind')].state = 'pass'; item.has_fix = true; return reply({message: 'Saved.'});
        }
        return reply({item: structuredClone(item)});
    });
    const preferred = walk(bulk.results).find(node => node.dataset.indexabilityCanonical === pageB.url);
    assert.equal(preferred.value, '', 'Conflicting canonical targets silently selected the page itself');
    preferred.value = 'https://site.example/preferred/';
    await bulk.fixAll.click();
    const changes = bulkCalls.filter(body => body.get('operation') === 'fix');
    assert.deepEqual(changes.map(body => body.get('kind')), ['noindex', 'robots', 'canonical', 'noindex', 'robots', 'canonical']);
    assert.equal(bulkCalls.length, 12, 'Every bulk change must receive a separate live recheck');
    assert.equal(changes.some(body => body.get('kind') === 'redirect'), false, 'Intentional redirects disabled by default');
    assert.match(bulk.status.textContent, /6 changes saved, 6 checks verified fixed/);
    assert.equal(bulk.fixAll.disabled, true, 'Only manual/redirect findings remain');
    bulk.includeRedirects.checked = true; bulk.includeRedirects.listeners.change();
    assert.equal(bulk.fixAll.disabled, false); await bulk.fixAll.click();
    assert.deepEqual(bulkCalls.filter(body => body.get('operation') === 'fix').slice(-2).map(body => body.get('kind')), ['redirect', 'redirect']);
    assert.equal(bulk.stop.hidden, true); assert.equal(bulk.scan.disabled, false);
    assert.equal(fixture([], () => {}).fixAll.disabled, true, 'Fix All enabled without a report');

    const conflict = issueItem(); conflict.canonicals = ['https://site.example/a/', 'https://site.example/b/'];
    ['noindex', 'robots', 'redirect'].forEach(kind => { conflict.checks[kind].state = 'pass'; });
    const needsChoice = fixture([conflict], () => { throw new Error('Unchosen canonical must not be changed'); });
    assert.equal(needsChoice.fixAll.disabled, true);
    const choice = walk(needsChoice.results).find(node => node.dataset.indexabilityCanonical);
    choice.value = 'https://site.example/preferred/'; choice.listeners.input(); assert.equal(needsChoice.fixAll.disabled, false);

    const failedPage = issueItem(); const goodPage = issueItem(); goodPage.url = 'https://site.example/good/';
    [failedPage, goodPage].forEach(item => ['robots', 'canonical', 'redirect'].forEach(kind => { item.checks[kind].state = 'pass'; }));
    const recoveryState = new Map([failedPage, goodPage].map(item => [item.url, structuredClone(item)]));
    const recovery = fixture([failedPage, goodPage], async (url, options) => {
        const body = options.body, item = recoveryState.get(body.get('url'));
        if (body.get('operation') === 'fix') {
            if (item.url === failedPage.url) return {json: async () => ({success: false, data: {message: 'Origin refused change.'}})};
            item.checks.noindex.state = 'pass'; return reply({message: 'Saved.'});
        }
        return reply({item: structuredClone(item)});
    });
    await recovery.fixAll.click();
    assert.match(recovery.status.textContent, /1 changes saved, 1 checks verified fixed.*1 failed changes/);
    assert.match(textOf(recovery.bulkResults), /Origin refused change/); assert.equal(recovery.scan.disabled, false);

    const pending = fixture([goodPage], async (url, options) => options.body.get('operation') === 'fix'
        ? reply({message: 'Saved.'}) : {json: async () => ({success: false, data: {message: 'Live check unavailable.'}})});
    await pending.fixAll.click();
    assert.match(pending.status.textContent, /1 changes saved, 0 checks verified fixed.*1 pending verifications/);
    assert.match(textOf(pending.results), /Live verification pending/); assert.equal(pending.scan.disabled, false);

    let releaseFix; const stopCalls = [];
    const stoppable = fixture([failedPage, goodPage], (url, options) => {
        stopCalls.push(options.body);
        if (options.body.get('operation') === 'fix') return new Promise(resolve => { releaseFix = resolve; });
        const fresh = structuredClone(failedPage); fresh.checks.noindex.state = 'pass'; return Promise.resolve(reply({item: fresh}));
    });
    const fixing = stoppable.fixAll.click();
    assert.equal(stoppable.scan.disabled, true); assert.equal(stoppable.stop.hidden, false); assert.equal(stoppable.stop.disabled, false);
    stoppable.fixAll.click(); assert.equal(stopCalls.length, 1, 'Duplicate bulk requests overlapped');
    stoppable.stop.click(); releaseFix(reply({message: 'Saved.'})); await fixing;
    assert.equal(stopCalls.length, 2, 'Stopping must verify the current change and leave later URLs alone');
    assert.match(stoppable.status.textContent, /Fix All stopped/); assert.equal(stoppable.scan.disabled, false);
    console.log('PASS: automatic discovery and per-URL fixes; Fix All sequencing, canonical choices, redirect opt-in, source/permission exclusions, verification, failure recovery, progress and stop');
})().catch(error => { console.error(error); process.exitCode = 1; });
