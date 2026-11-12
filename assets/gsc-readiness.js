(function () {
    'use strict';
    var root = document.getElementById('tk-gsc-readiness');
    if (!root) return;
    var run = root.querySelector('[data-gsc-run]');
    var summary = root.querySelector('[data-gsc-summary]');
    var list = root.querySelector('[data-gsc-results]');
    var labels = { pass: 'Ready', issue: 'Needs fixing', review: 'Review', unknown: 'Unverified' };
    var colors = { pass: '#007017', issue: '#b32d2e', review: '#996800', unknown: '#50575e' };
    function el(tag, text) { var node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node; }
    async function check(item, first) {
        var body = new URLSearchParams({ action: 'tk_gsc_readiness', nonce: root.dataset.nonce, item: item, first: first ? '1' : '' });
        var response = await fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body });
        var data;
        try { data = await response.json(); } catch (error) { throw new Error('The server returned an unreadable response.'); }
        if (!data || !data.success) throw new Error(data && data.data && data.data.message || 'The request failed.');
        return data.data.item;
    }
    function row(item) {
        var li = el('li'); li.style.cssText = 'padding:10px 0;border-top:1px solid #dcdcde;';
        var head = el('p'); head.style.margin = '0';
        var badge = el('strong', labels[item.state] || item.state); badge.style.color = colors[item.state] || '#50575e';
        head.appendChild(badge); head.appendChild(document.createTextNode(' — ' + item.label));
        li.appendChild(head); li.appendChild(el('p', item.detail));
        if (item.recommendation && item.state !== 'pass') { var rec = el('p', item.recommendation); rec.className = 'description'; li.appendChild(rec); }
        return li;
    }
    run.addEventListener('click', async function () {
        run.disabled = true; list.replaceChildren();
        var keys = JSON.parse(root.dataset.items); var counts = { pass: 0, issue: 0, review: 0, unknown: 0 };
        for (var i = 0; i < keys.length; i++) {
            summary.textContent = 'Checking ' + (i + 1) + '/' + keys.length + '…';
            var item;
            try { item = await check(keys[i], i === 0); }
            catch (error) { item = { label: keys[i], state: 'unknown', detail: error.message, recommendation: 'Run the check again.' }; }
            counts[item.state] = (counts[item.state] || 0) + 1; list.appendChild(row(item));
        }
        summary.textContent = counts.pass + ' of ' + keys.length + ' ready · ' + counts.issue + ' need fixing · ' + counts.review + ' to review · ' + counts.unknown + ' unverified.' + (counts.issue ? '' : ' No blocking problems found; this does not guarantee indexing.');
        run.disabled = false;
    });
}());
