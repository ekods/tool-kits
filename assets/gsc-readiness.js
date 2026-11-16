(function () {
    'use strict';
    var root = document.getElementById('tk-gsc-readiness');
    if (!root) return;
    var run = root.querySelector('[data-gsc-run]');
    var summary = root.querySelector('[data-gsc-summary]');
    var list = root.querySelector('[data-gsc-results]');
    var lastCheck = root.querySelector('[data-gsc-last-check]');
    var keys = JSON.parse(root.dataset.items);
    var items = new Map();
    var actions = JSON.parse(root.dataset.actions || '{}');
    var labels = { pass: 'Ready', issue: 'Needs fixing', review: 'Review', unknown: 'Unverified' };
    var colors = { pass: '#007017', issue: '#b32d2e', review: '#996800', unknown: '#50575e' };
    function el(tag, text) { var node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node; }
    async function check(item, first, runId, errorMessage) {
        var body = new URLSearchParams({ action: 'tk_gsc_readiness', nonce: root.dataset.nonce, item: item, first: first ? '1' : '', run: runId, operation: errorMessage ? 'record_error' : 'check' });
        if (errorMessage) body.set('error', errorMessage);
        var response = await fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body });
        var data;
        try { data = await response.json(); } catch (error) { throw new Error('The server returned an unreadable response.'); }
        if (!data || !data.success) throw new Error(data && data.data && data.data.message || 'The request failed.');
        return data.data;
    }
    function summarize() {
        var counts = { pass: 0, issue: 0, review: 0, unknown: 0 };
        items.forEach(function (item) { counts[item.state]++; });
        summary.textContent = counts.pass + ' of ' + keys.length + ' ready · ' + counts.issue + ' need fixing · ' + counts.review + ' to review · ' + counts.unknown + ' unverified.'
            + (items.size < keys.length ? ' ' + (keys.length - items.size) + ' not checked yet.' : '')
            + (!counts.issue && !counts.unknown && items.size === keys.length ? ' No blocking problems found; this does not guarantee indexing.' : '');
    }
    function savedAt(timestamp, count) {
        lastCheck.textContent = timestamp ? 'Last saved check: ' + new Date(timestamp * 1000).toLocaleString() + ' · ' + count + '/' + keys.length + ' results saved.' : '';
    }
    function row(item) {
        var li = el('li'); li.style.cssText = 'padding:10px 0;border-top:1px solid #dcdcde;';
        var head = el('p'); head.style.margin = '0';
        var badge = el('strong', labels[item.state] || item.state); badge.style.color = colors[item.state] || '#50575e';
        head.appendChild(badge); head.appendChild(document.createTextNode(' — ' + item.label));
        li.appendChild(head); li.appendChild(el('p', item.detail));
        if (item.recommendation && item.state !== 'pass') { var rec = el('p', item.recommendation); rec.className = 'description'; li.appendChild(rec); }
        if (item.state !== 'pass' && actions[item.key]) {
            var links = el('p'); links.className = 'tk-inline';
            actions[item.key].forEach(function (action) {
                var url;
                try { url = new URL(action.url, window.location.href); } catch (error) { return; }
                if (url.protocol !== 'https:' && url.protocol !== 'http:') return;
                var link = el('a', action.label); link.href = url.href; link.className = 'button';
                link.target = '_blank'; link.rel = 'noopener noreferrer';
                link.setAttribute('aria-label', action.label + ' (opens in a new tab)'); links.appendChild(link);
            });
            if (links.children.length) li.appendChild(links);
        }
        return li;
    }
    run.addEventListener('click', async function () {
        if (run.disabled) return;
        run.disabled = true; list.replaceChildren();
        items.clear();
        var runId = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
        var savedCount = 0; var timestamp = 0;
        for (var i = 0; i < keys.length; i++) {
            summary.textContent = 'Checking ' + (i + 1) + '/' + keys.length + '…';
            var item;
            try {
                var result = await check(keys[i], i === 0, runId);
                item = result.item; timestamp = result.scanned_at; savedCount++;
            } catch (error) {
                item = { key: keys[i], label: keys[i], state: 'unknown', detail: error.message, recommendation: 'Run the check again.' };
                try {
                    var failed = await check(keys[i], false, runId, error.message);
                    item = failed.item; timestamp = failed.scanned_at; savedCount++;
                } catch (saveError) { /* Keep the unverified result visible when WordPress is unreachable. */ }
            }
            items.set(keys[i], item); list.appendChild(row(item));
            savedAt(timestamp, savedCount);
        }
        summarize();
        if (savedCount < keys.length) summary.textContent += ' Some results could not be saved. Run the check again when WordPress is reachable.';
        run.disabled = false;
    });
    var saved = root.querySelector('.tk-gsc-readiness-saved');
    try {
        var report = saved ? JSON.parse(saved.textContent) : {};
        keys.forEach(function (key) {
            var item = report.items && report.items[key];
            if (!item || item.key !== key || !labels[item.state]) return;
            items.set(key, item); list.appendChild(row(item));
        });
        if (items.size) { summarize(); savedAt(report.scanned_at, items.size); }
    } catch (error) { summary.textContent = 'The saved report could not be loaded. Run the readiness check again.'; }
}());
