(function () {
    'use strict';
    var root = document.getElementById('tk-seo-indexability');
    if (!root) return;
    var results = root.querySelector('[data-indexability-results]');
    var status = root.querySelector('[data-indexability-status]');
    var scan = root.querySelector('[data-indexability-scan]');
    var busy = false;
    var items = new Map();
    var labels = { noindex: 'Remove page noindex', canonical: 'Set preferred canonical', robots: 'Allow this URL for Googlebot', redirect: 'Disable Tool Kits redirect' };
    function el(tag, text) { var node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node; }
    function lock(value) {
        busy = value;
        root.querySelectorAll('button, input, textarea').forEach(function (node) { node.disabled = value; });
    }
    async function request(url, operation, kind, canonical, page) {
        var body = new URLSearchParams({ action: 'tk_seo_indexability', nonce: root.dataset.nonce, url: url, operation: operation || 'scan', kind: kind || '', canonical: canonical || '', page: String(page || 1) });
        var response = await fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body });
        var data;
        try { data = await response.json(); }
        catch (error) { throw new Error('The server returned an unreadable response. Recheck this URL.'); }
        if (!data || !data.success) throw new Error(data && data.data && data.data.message || 'The request failed.');
        return data.data;
    }
    async function fix(item, kind, canonical) {
        if (busy) return;
        lock(true); status.textContent = 'Applying change and checking the live URL…';
        try {
            var applied = await request(item.url, 'fix', kind, canonical);
            status.textContent = applied.message + ' Checking the live URL…';
            var data = await request(item.url);
            items.set(data.item.url, data.item); render();
            var remaining = Object.values(data.item.checks).filter(function (check) { return check.state !== 'pass'; }).length;
            status.textContent = applied.message + ' Recheck: ' + remaining + ' issue(s) or unverified check(s) remain.';
        } catch (error) { status.textContent = (applied ? applied.message + ' Live verification failed; use Recheck URL. ' : '') + error.message; }
        finally { lock(false); }
    }
    function render() {
        results.replaceChildren();
        items.forEach(function (item) {
            var section = el('section');
            var title = el('h4', item.url); section.appendChild(title);
            var timestamp = el('p', 'Checked: ' + new Date(item.scanned_at * 1000).toLocaleString()); timestamp.className = 'description'; section.appendChild(timestamp);
            var table = el('table'); table.className = 'widefat striped';
            var head = el('thead'); var heading = el('tr');
            ['Check', 'Result', 'Evidence & recommendation', 'Action'].forEach(function (label) { heading.appendChild(el('th', label)); });
            head.appendChild(heading); table.appendChild(head);
            var body = el('tbody');
            Object.keys(item.checks).forEach(function (kind) {
                var check = item.checks[kind]; var row = el('tr');
                row.appendChild(el('td', check.label)); row.appendChild(el('td', { pass: 'Pass', issue: 'Needs review', unknown: 'Unverified' }[check.state] || check.state));
                var detail = el('td'); detail.appendChild(el('p', check.detail)); detail.appendChild(el('p', check.recommendation)); row.appendChild(detail);
                var action = el('td');
                var sourceOnly = (kind === 'noindex' && item.header_noindex) || (kind === 'canonical' && item.header_canonical) || (kind === 'redirect' && !item.toolkits_redirect);
                if (check.state === 'issue' && sourceOnly) {
                    action.appendChild(el('p', kind === 'redirect' ? 'Review the redirect in WordPress, the originating plugin, server, or CDN. Keep it if intentional.' : 'Correct the HTTP header in the originating plugin, server, or CDN, then recheck.'));
                } else if (check.state === 'issue' && item.editable) {
                    var canonical;
                    if (kind === 'canonical') {
                        canonical = el('input'); canonical.type = 'url'; canonical.value = item.url; canonical.setAttribute('aria-label', 'Preferred canonical URL for ' + item.url); canonical.style.width = '100%'; action.appendChild(canonical);
                    }
                    var button = el('button', labels[kind]); button.type = 'button'; button.className = 'button button-small';
                    button.addEventListener('click', function () { return fix(item, kind, canonical ? canonical.value : ''); }); action.appendChild(button);
                    if (kind === 'robots') { action.appendChild(el('p', 'For a physical robots.txt file, add this rule to the matching Googlebot or * group:')); var path = new URL(item.url); action.appendChild(el('code', 'Allow: ' + path.pathname + path.search + '$')); }
                } else if (check.state === 'issue') { action.appendChild(el('p', 'Edit this alternate URL at its source, or enable SEO Toolkit for an editable public page.')); }
                row.appendChild(action); body.appendChild(row);
            });
            table.appendChild(body); section.appendChild(table);
            var recheck = el('button', 'Recheck URL'); recheck.type = 'button'; recheck.className = 'button';
            recheck.addEventListener('click', async function () {
                if (busy) return;
                lock(true); status.textContent = 'Checking ' + item.url;
                try { var data = await request(item.url); items.set(item.url, data.item); render(); status.textContent = 'Live check completed.'; }
                catch (error) { status.textContent = error.message; }
                finally { lock(false); }
            });
            var actions = el('p'); actions.appendChild(recheck);
            if (item.has_fix) {
                var remove = el('button', 'Remove page overrides'); remove.type = 'button'; remove.className = 'button';
                remove.addEventListener('click', function () { return fix(item, 'remove'); }); actions.appendChild(document.createTextNode(' ')); actions.appendChild(remove);
            }
            section.appendChild(actions); results.appendChild(section);
        });
    }
    scan.addEventListener('click', async function () {
        if (busy) return;
        lock(true); var failures = []; var seen = new Set(); var checked = 0; var page = 1;
        try {
            var more = true;
            while (more) {
                status.textContent = 'Finding public pages on ' + root.dataset.home + '… ' + checked + ' checked.';
                var batch = await request('', 'discover', '', '', page);
                if (page === 1) { items.clear(); render(); }
                for (var i = 0; i < batch.urls.length; i++) {
                    var url = batch.urls[i];
                    if (seen.has(url)) continue;
                    seen.add(url);
                    status.textContent = 'Checking page ' + seen.size + ': ' + url;
                    try { var data = await request(url); items.set(data.item.url, data.item); checked++; render(); lock(true); }
                    catch (error) { failures.push(url + ': ' + error.message); }
                }
                more = batch.has_more; page = batch.next_page;
            }
            status.textContent = 'Website check completed: ' + checked + '/' + seen.size + ' URLs checked.' + (failures.length ? ' ' + failures.join(' · ') : '');
        } catch (error) {
            status.textContent = 'Website scan stopped after ' + checked + ' URLs: ' + error.message;
        } finally { lock(false); }
    });
    try { JSON.parse(root.querySelector('.tk-indexability-saved').textContent).forEach(function (item) { items.set(item.url, item); }); render(); }
    catch (error) { status.textContent = 'Run a check to create a report.'; }
}());
