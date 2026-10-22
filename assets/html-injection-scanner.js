(function () {
    'use strict';
    var root = document.getElementById('tk-html-scan');
    if (!root) { return; }
    var form = root.querySelector('form');
    var status = root.querySelector('[data-scan-status]');
    var progress = root.querySelector('[data-scan-progress]');
    var resume = root.querySelector('[data-scan-resume]');
    var stop = root.querySelector('[data-scan-stop]');
    var clear = root.querySelector('[data-scan-clear]');
    var results = document.querySelector('[data-scan-results]');
    var summary = document.querySelector('[data-scan-summary]');
    var issues = document.querySelector('[data-scan-issues]');
    var empty = document.querySelector('[data-scan-empty]');
    var reportRoot = document.getElementById('tk-html-report');
    var review = document.querySelector('[data-scan-review]');
    var suppressed = document.querySelector('[data-scan-suppressed]');
    var statistics = document.querySelector('[data-scan-statistics]');
    var filters = reportRoot ? reportRoot.querySelectorAll('[data-scan-filter]') : [];
    var token = root.dataset.token || '';
    var running = false;
    var stopping = false;
    function controls() {
        form.querySelectorAll('input, textarea, button[type="submit"]').forEach(function (input) { input.disabled = running || !!token; });
        resume.disabled = running || !token;
        stop.disabled = !token;
        clear.disabled = running || !!token;
        progress.hidden = !running;
        if (reportRoot) { reportRoot.querySelectorAll('[data-scan-restore]').forEach(function (button) { button.disabled = running || !!token; }); }
    }
    function node(tag, text) { var element = document.createElement(tag); element.textContent = text; return element; }
    function applyFilters() {
        if (!reportRoot) { return; }
        reportRoot.querySelectorAll('[data-finding-row]').forEach(function (row) {
            row.hidden = Array.from(filters).some(function (filter) {
                var value = filter.value, key = filter.dataset.scanFilter;
                if (!value) { return false; }
                if (key === 'confidence') { return Number(row.dataset.confidence) < Number(value); }
                if (key === 'page') { try { return JSON.parse(row.dataset.pages || '[]').indexOf(value) < 0; } catch (error) { return true; } }
                return row.dataset[key] !== value;
            });
        });
    }
    function populateFilters(findings) {
        Array.from(filters).forEach(function (filter) {
            var key = filter.dataset.scanFilter;
            if (['type', 'source', 'classification', 'page'].indexOf(key) < 0) { return; }
            var previous = filter.value, values = [];
            findings.forEach(function (f) { values = values.concat(key === 'page' ? f.affected_urls || [] : [key === 'type' ? f.rule : f[key] || 'legacy_unverified']); });
            filter.replaceChildren(node('option', 'All')); filter.firstChild.value = '';
            Array.from(new Set(values)).sort().forEach(function (value) { var option = node('option', value); option.value = value; filter.appendChild(option); });
            filter.value = values.indexOf(previous) >= 0 ? previous : '';
        });
    }
    function actionFor(finding) { return finding.action || (['high', 'critical'].indexOf(finding.severity) >= 0 ? 'REPORT' : 'REVIEW'); }
    function renderRow(finding) {
        var row = document.createElement('tr');
        var action = actionFor(finding), severity = finding.severity === 'review' ? 'low' : finding.severity;
        var classification = finding.classification || 'legacy_unverified', confidence = finding.confidence === undefined ? .5 : finding.confidence;
        Object.assign(row.dataset, {findingRow: '', severity: severity, type: finding.rule, source: finding.source, classification: classification, action: action, confidence: String(confidence), pages: JSON.stringify(finding.affected_urls || [])});
        var level = document.createElement('td'), badge = node('strong', severity.toUpperCase()); badge.className = 'tk-scan-severity tk-scan-' + severity; level.appendChild(badge); level.appendChild(node('p', action)); level.appendChild(node('small', Math.round(confidence * 100) + '% confidence')); row.appendChild(level);
        var source = node('td', finding.source + ' · line ' + finding.line);
        if (finding.post_id && root.dataset.editUrl) {
            var edit = new URL(root.dataset.editUrl, window.location.href);
            edit.searchParams.set('post', String(finding.post_id)); edit.searchParams.set('action', 'edit');
            var link = node('a', 'Edit source'); link.href = edit.toString();
            source.appendChild(document.createElement('br')); source.appendChild(link);
        }
        source.appendChild(node('p', (finding.occurrences || 1) + ' occurrence(s)'));
        if (finding.affected_urls && finding.affected_urls.length) {
            var pages = document.createElement('details'); pages.appendChild(node('summary', 'Affected pages (' + finding.affected_urls.length + ')'));
            var list = document.createElement('ul'); finding.affected_urls.forEach(function (url) { list.appendChild(node('li', url)); }); pages.appendChild(list); source.appendChild(pages);
        }
        row.appendChild(source);
        var detail = document.createElement('td');
        detail.appendChild(node('strong', finding.rule)); detail.appendChild(node('p', finding.reason)); detail.appendChild(node('code', finding.rule_id || finding.rule.toUpperCase())); detail.appendChild(node('p', classification));
        if (finding.provider) { detail.appendChild(node('p', finding.provider)); }
        if (finding.host) { detail.appendChild(node('code', finding.host)); }
        if (finding.timestamp) { detail.appendChild(node('p', new Date(finding.timestamp * 1000).toISOString())); }
        if (action === 'SKIP' && finding.fingerprint) { var restore = node('button', 'Restore to Needs Review'); restore.type = 'button'; restore.className = 'button'; restore.dataset.scanRestore = finding.fingerprint; restore.disabled = running || !!token; detail.appendChild(restore); }
        row.appendChild(detail);
        var evidence = document.createElement('td'), code = node('code', finding.snippet);
        code.style.whiteSpace = 'pre-wrap'; code.style.overflowWrap = 'anywhere'; evidence.appendChild(code); row.appendChild(evidence);
        return row;
    }
    function render(report) {
        summary.replaceChildren(node('p', 'Status: ' + report.status + ' · Phase: ' + report.phase + ' · Posts: ' + report.counts.posts + ' · Meta: ' + report.counts.meta + ' · Options: ' + report.counts.options + ' · URLs attempted: ' + report.counts.urls + '/' + report.max_urls + ' · Findings: ' + report.findings.length));
        issues.replaceChildren();
        if (report.limited) { issues.appendChild(node('li', 'Coverage is incomplete or limited to the configured sample. Review the items below.')); }
        report.issues.forEach(function (issue) { issues.appendChild(node('li', issue)); });
        results.replaceChildren(); if (review) { review.replaceChildren(); } if (suppressed) { suppressed.replaceChildren(); }
        var findings = report.findings.concat(report.suppressed || []);
        findings.forEach(function (finding) {
            var action = actionFor(finding), target = !reportRoot ? results : (action === 'SKIP' ? suppressed : (action === 'REVIEW' ? review : results));
            if (target) { target.appendChild(renderRow(finding)); }
        });
        if (statistics) {
            statistics.replaceChildren(); var stats = report.statistics || {};
            var labels = {pages_scanned: 'Pages scanned', files_analyzed: 'Files analyzed', critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low', needs_review: 'Needs review', suppressed: 'Suppressed occurrences', duplicates_grouped: 'Duplicate occurrences grouped'};
            Object.keys(labels).forEach(function (key) { var card = document.createElement('div'); card.appendChild(node('strong', String(stats[key] || 0))); card.appendChild(node('span', labels[key])); statistics.appendChild(card); });
        }
        populateFilters(findings); applyFilters();
        empty.hidden = !!findings.length;
        empty.textContent = 'No matching patterns in the sources checked so far. Review coverage and status above.';
    }
    async function request(action, values) {
        var body = new URLSearchParams({action: 'tk_html_scan_' + action, _ajax_nonce: root.dataset.nonce});
        Object.keys(values || {}).forEach(function (key) { body.set(key, values[key]); });
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 30000);
        try {
            var response = await fetch(root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body, signal: controller.signal});
            var json = await response.json();
            if (!response.ok || !json.success) { throw new Error(json.data && json.data.message || 'Scan request failed. Refresh the page if your login or security nonce expired.'); }
            return json.data;
        } finally { clearTimeout(timer); }
    }
    async function run(start) {
        if (running) { return; }
        var settings = Object.fromEntries(new FormData(form));
        running = true; stopping = false; controls();
        try {
            if (start) {
                status.textContent = 'Preparing a read-only scan...';
                var session = await request('start', settings); token = session.token; render(session.report); controls();
            }
            while (token && !stopping) {
                status.textContent = 'Scanning a small batch. Completed findings are saved automatically.';
                var step = await request('step', {token: token}); render(step.report);
                if (step.done) { token = ''; status.textContent = 'Scan finished (' + step.report.status + '). Review the report and coverage.'; break; }
            }
            if (stopping && token) {
                var stopped = await request('cancel', {token: token}); render(stopped.report);
                token = ''; status.textContent = 'Scan stopped. Completed findings remain available.';
            }
        } catch (error) {
            status.textContent = error.name === 'AbortError' ? 'Request timed out. Wait for the current batch to finish, then resume or reload this page. Saved findings are retained.' : error.message;
        } finally { running = false; controls(); }
    }
    form.addEventListener('submit', function (event) { event.preventDefault(); run(true); });
    resume.addEventListener('click', function () { run(false); });
    stop.addEventListener('click', async function () {
        if (running) { stopping = true; status.textContent = 'Stopping after the current batch...'; return; }
        if (!token) { return; }
        try { var data = await request('cancel', {token: token}); render(data.report); token = ''; status.textContent = 'Scan stopped.'; }
        catch (error) { status.textContent = error.message; }
        controls();
    });
    clear.addEventListener('click', async function () {
        if (running || token) { return; }
        clear.disabled = true;
        try { await request('clear', {}); render({status: 'empty', phase: '', counts: {posts: 0, meta: 0, options: 0, urls: 0}, max_urls: 0, findings: [], suppressed: [], issues: []}); summary.replaceChildren(); empty.hidden = false; empty.textContent = 'No scan report yet.'; status.textContent = 'Report cleared. Site content was not changed.'; }
        catch (error) { status.textContent = error.message; }
        controls();
    });
    Array.from(filters).forEach(function (filter) { filter.addEventListener('change', applyFilters); });
    if (reportRoot) { reportRoot.addEventListener('click', async function (event) {
        var button = event.target.closest('[data-scan-restore]');
        if (!button || running || token) { return; }
        button.disabled = true;
        try { var restored = await request('restore', {fingerprint: button.dataset.scanRestore}); render(restored.report); status.textContent = 'Finding restored to Needs Review. Future scans retain this override for the same fingerprint.'; }
        catch (error) { status.textContent = error.message; button.disabled = false; }
    }); }
    applyFilters(); controls();
}());
