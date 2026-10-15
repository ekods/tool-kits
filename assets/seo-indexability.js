(function () {
    'use strict';
    var root = document.getElementById('tk-seo-indexability');
    if (!root) return;
    var results = root.querySelector('[data-indexability-results]');
    var status = root.querySelector('[data-indexability-status]');
    var scan = root.querySelector('[data-indexability-scan]');
    var fixAll = root.querySelector('[data-indexability-fix-all]');
    var retry = root.querySelector('[data-indexability-retry]');
    var fixHint = root.querySelector('[data-indexability-fix-hint]');
    var stop = root.querySelector('[data-indexability-stop]');
    var includeRedirects = root.querySelector('[data-indexability-include-redirects]');
    var bulkResults = root.querySelector('[data-indexability-bulk-results]');
    var busy = false;
    var bulkRunning = false;
    var stopRequested = false;
    var items = new Map();
    var canonicalDrafts = new Map();
    var labels = { noindex: 'Remove page noindex', canonical: 'Set preferred canonical', robots: 'Allow this URL for Googlebot', redirect: 'Disable Tool Kits redirect' };
    function el(tag, text) { var node = document.createElement(tag); if (text !== undefined) node.textContent = text; return node; }
    function lock(value) {
        busy = value;
        root.querySelectorAll('button, input, textarea').forEach(function (node) { node.disabled = value; });
        updateBulkControls();
    }
    function sourceOnly(item, kind) {
        return (kind === 'noindex' && item.header_noindex) || (kind === 'canonical' && item.header_canonical) || (kind === 'redirect' && !item.toolkits_redirect);
    }
    function canFix(item, kind) {
        return item.editable && item.checks[kind] && item.checks[kind].state === 'issue' && !sourceOnly(item, kind);
    }
    function canonicalTarget(item) {
        if (canonicalDrafts.has(item.url)) return canonicalDrafts.get(item.url);
        var declared = Array.from(new Set(item.canonicals || []));
        if (!declared.length) return item.url;
        if (declared.length === 1 && declared[0]) {
            try { if (new URL(declared[0]).origin === new URL(root.dataset.home).origin) return declared[0]; }
            catch (error) { /* A malformed canonical needs a preferred URL. */ }
        }
        return '';
    }
    function bulkEligible(item, kind) {
        return canFix(item, kind) && (kind !== 'redirect' || includeRedirects.checked) && (kind !== 'canonical' || canonicalTarget(item).trim() !== '');
    }
    function updateBulkControls() {
        var reports = Array.from(items.values());
        var eligible = reports.some(function (item) {
            return ['noindex', 'robots', 'canonical', 'redirect'].some(function (kind) { return bulkEligible(item, kind); });
        });
        var unverified = reports.filter(needsRetry).length;
        var issues = reports.some(function (item) { return Object.values(item.checks).some(function (check) { return check.state === 'issue'; }); });
        fixAll.disabled = busy || !eligible;
        if (busy) fixHint.textContent = 'Wait for the current operation to finish.';
        else if (!reports.length) fixHint.textContent = 'Run Check Current Website to find issues before using Fix All.';
        else if (eligible) fixHint.textContent = 'Fix All is ready for verified, editable findings.';
        else if (unverified) fixHint.textContent = 'Fix All is unavailable because ' + unverified + ' URL(s) have unverified checks. Click Retry Unverified. A timeout does not confirm an indexing issue.';
        else if (issues) fixHint.textContent = 'No automatic fixes are ready. Choose preferred URLs for conflicting canonicals, include Tool Kits redirects if needed, or follow the source-level guidance in the table. SEO Toolkit and page editing access are required.';
        else fixHint.textContent = 'All verified checks passed. There is nothing for Fix All to change.';
        retry.hidden = unverified === 0;
        retry.style.display = unverified ? '' : 'none';
        retry.disabled = busy || unverified === 0;
        stop.hidden = !bulkRunning;
        stop.style.display = bulkRunning ? '' : 'none';
        stop.disabled = !bulkRunning || stopRequested;
    }
    function needsRetry(item) {
        return item.verification_pending || Object.values(item.checks).some(function (check) { return check.state === 'unknown'; });
    }
    function captureCanonicalTargets() {
        root.querySelectorAll('input[data-indexability-canonical]').forEach(function (input) { canonicalDrafts.set(input.dataset.indexabilityCanonical, input.value); });
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
        captureCanonicalTargets();
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
        if (!items.size) { lock(busy); return; }
        var wrapper = el('div'); wrapper.style.overflowX = 'auto';
        wrapper.setAttribute('role', 'region'); wrapper.setAttribute('aria-label', 'Indexing checks grouped by URL'); wrapper.tabIndex = 0;
        var table = el('table'); table.className = 'widefat striped'; table.style.minWidth = '900px';
        var head = el('thead'); var heading = el('tr');
        ['URL', 'Check', 'Result', 'Evidence & recommendation', 'Action'].forEach(function (label) {
            var column = el('th', label); column.setAttribute('scope', 'col'); heading.appendChild(column);
        });
        head.appendChild(heading); table.appendChild(head);
        items.forEach(function (item) {
            var kinds = Object.keys(item.checks);
            var body = el('tbody'); body.dataset.indexabilityUrl = item.url;
            var group = el('th'); group.setAttribute('scope', 'rowgroup'); group.setAttribute('rowspan', String(kinds.length));
            group.style.width = '24%'; group.style.verticalAlign = 'top'; group.style.overflowWrap = 'anywhere';
            group.appendChild(el('p', item.url));
            var timestamp = el('p', 'Checked: ' + new Date(item.scanned_at * 1000).toLocaleString()); timestamp.className = 'description'; group.appendChild(timestamp);
            if (item.verification_pending) group.appendChild(el('p', 'Live verification pending. Use Recheck URL before applying more fixes.'));
            kinds.forEach(function (kind, index) {
                var check = item.checks[kind]; var row = el('tr');
                if (index === 0) row.appendChild(group);
                var checkLabel = el('th', check.label); checkLabel.setAttribute('scope', 'row'); row.appendChild(checkLabel);
                var outcome = el('td', { pass: 'Pass', issue: 'Needs review', unknown: 'Unverified' }[check.state] || check.state); row.appendChild(outcome);
                var detail = el('td'); detail.appendChild(el('p', check.detail)); detail.appendChild(el('p', check.recommendation)); row.appendChild(detail);
                var action = el('td');
                if (check.state === 'issue' && sourceOnly(item, kind)) {
                    action.appendChild(el('p', kind === 'redirect' ? 'Review the redirect in WordPress, the originating plugin, server, or CDN. Keep it if intentional.' : 'Correct the HTTP header in the originating plugin, server, or CDN, then recheck.'));
                } else if (check.state === 'issue' && item.editable) {
                    var canonical;
                    if (kind === 'canonical') {
                        canonical = el('input'); canonical.type = 'url'; canonical.value = canonicalTarget(item); canonical.placeholder = 'Choose the preferred canonical URL'; canonical.dataset.indexabilityCanonical = item.url; canonical.setAttribute('aria-label', 'Preferred canonical URL for ' + item.url); canonical.style.width = '100%';
                        canonical.addEventListener('input', function () { canonicalDrafts.set(item.url, canonical.value); updateBulkControls(); }); action.appendChild(canonical);
                    }
                    var button = el('button', labels[kind]); button.type = 'button'; button.className = 'button button-small';
                    button.addEventListener('click', function () { return fix(item, kind, canonical ? canonical.value : ''); }); action.appendChild(button);
                    if (kind === 'robots') { action.appendChild(el('p', 'For a physical robots.txt file, add this rule to the matching Googlebot or * group:')); var path = new URL(item.url); action.appendChild(el('code', 'Allow: ' + path.pathname + path.search + '$')); }
                } else if (check.state === 'issue') { action.appendChild(el('p', 'Edit this alternate URL at its source, or enable SEO Toolkit for an editable public page.')); }
                if (index === 0) {
                    [group, checkLabel, outcome, detail, action].forEach(function (cell) { cell.style.borderTop = '2px solid var(--tk-border-soft, #dcdcde)'; });
                }
                row.appendChild(action); body.appendChild(row);
            });
            table.appendChild(body);
            var recheck = el('button', 'Recheck URL'); recheck.type = 'button'; recheck.className = 'button';
            recheck.addEventListener('click', async function () {
                if (busy) return;
                captureCanonicalTargets();
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
            group.appendChild(actions);
        });
        wrapper.appendChild(table); results.appendChild(wrapper);
        lock(busy);
    }
    includeRedirects.addEventListener('change', updateBulkControls);
    retry.addEventListener('click', async function () {
        if (busy) return;
        captureCanonicalTargets();
        var targets = Array.from(items.values()).filter(needsRetry);
        if (!targets.length) return;
        lock(true); var failures = []; var checked = 0;
        try {
            for (var i = 0; i < targets.length; i++) {
                status.textContent = 'Retrying unverified URL ' + (i + 1) + '/' + targets.length + ': ' + targets[i].url;
                try { var data = await request(targets[i].url); items.set(data.item.url, data.item); checked++; render(); }
                catch (error) { failures.push(targets[i].url + ': ' + error.message); }
            }
            var remaining = Array.from(items.values()).filter(needsRetry).length;
            status.textContent = 'Retry completed: ' + checked + '/' + targets.length + ' URLs checked; ' + remaining + ' URL(s) still unverified.' + (failures.length ? ' ' + failures.join(' · ') : '') + (remaining ? ' If requests keep timing out, check server loopback access and hosting/firewall logs.' : '');
        } finally { lock(false); }
    });
    stop.addEventListener('click', function () { stopRequested = true; stop.disabled = true; status.textContent = 'Stopping Fix All after the current change is checked…'; });
    fixAll.addEventListener('click', async function () {
        if (busy) return;
        captureCanonicalTargets();
        var targets = Array.from(items.values()).filter(function (item) {
            return ['noindex', 'robots', 'canonical', 'redirect'].some(function (kind) { return bulkEligible(item, kind); });
        });
        if (!targets.length) return;
        bulkRunning = true; stopRequested = false; lock(true); bulkResults.replaceChildren();
        var saved = 0; var verified = 0; var failed = 0; var pending = 0; var completed = 0; var notes = [];
        try {
            for (var i = 0; i < targets.length && !stopRequested; i++) {
                var item = items.get(targets[i].url);
                // Clear crawl/indexing blocks before validating a canonical destination.
                var kinds = ['redirect', 'noindex', 'robots', 'canonical'];
                for (var k = 0; k < kinds.length && !stopRequested; k++) {
                    var kind = kinds[k];
                    if (!bulkEligible(item, kind)) continue;
                    status.textContent = 'Fix All: page ' + (i + 1) + '/' + targets.length + ' — ' + labels[kind] + ' — ' + item.url;
                    var applied = false;
                    try { await request(item.url, 'fix', kind, kind === 'canonical' ? canonicalTarget(item) : ''); saved++; applied = true; }
                    catch (error) { failed++; notes.push(item.url + ' — ' + labels[kind] + ': ' + error.message); }
                    // Recheck even after a failed response: the server may have saved the change.
                    try {
                        var data = await request(item.url);
                        if (!data.item || data.item.url !== item.url || !data.item.checks || !data.item.checks[kind]) throw new Error('The server returned an incomplete live report. Recheck this URL.');
                        item = data.item;
                        items.set(item.url, item);
                        if (applied && item.checks[kind].state === 'pass') verified++;
                        else if (applied) notes.push(item.url + ' — ' + labels[kind] + ': still needs review after the live check.');
                    } catch (error) {
                        pending++; item.verification_pending = true; items.set(item.url, item);
                        notes.push(item.url + ' — live verification failed: ' + error.message);
                        render(); break;
                    }
                    render();
                }
                completed++;
            }
        } finally {
            var remaining = 0;
            items.forEach(function (item) { Object.values(item.checks).forEach(function (check) { if (check.state !== 'pass') remaining++; }); });
            status.textContent = (stopRequested ? 'Fix All stopped. ' : 'Fix All completed. ') + completed + '/' + targets.length + ' pages processed; ' + saved + ' changes saved, ' + verified + ' checks verified fixed, ' + remaining + ' findings remaining, ' + failed + ' failed changes, ' + pending + ' pending verifications.';
            if (notes.length) {
                var details = el('details'); details.appendChild(el('summary', 'Fix All details (' + notes.length + ')'));
                var list = el('ul'); notes.forEach(function (note) { list.appendChild(el('li', note)); }); details.appendChild(list); bulkResults.appendChild(details);
            }
            bulkRunning = false; lock(false);
        }
    });
    scan.addEventListener('click', async function () {
        if (busy) return;
        captureCanonicalTargets();
        bulkResults.replaceChildren();
        lock(true); var failures = []; var seen = new Set(); var checked = 0; var page = 1;
        try {
            var more = true;
            while (more) {
                status.textContent = 'Finding public pages on ' + root.dataset.home + '… ' + checked + ' checked.';
                var batch = await request('', 'discover', '', '', page);
                if (page === 1) { items.clear(); canonicalDrafts.clear(); render(); }
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
