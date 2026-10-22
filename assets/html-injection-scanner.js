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
    var token = root.dataset.token || '';
    var running = false;
    var stopping = false;
    function controls() {
        form.querySelectorAll('input, textarea, button[type="submit"]').forEach(function (input) { input.disabled = running || !!token; });
        resume.disabled = running || !token;
        stop.disabled = !token;
        clear.disabled = running || !!token;
        progress.hidden = !running;
    }
    function node(tag, text) { var element = document.createElement(tag); element.textContent = text; return element; }
    function render(report) {
        summary.replaceChildren(node('p', 'Status: ' + report.status + ' · Phase: ' + report.phase + ' · Posts: ' + report.counts.posts + ' · Meta: ' + report.counts.meta + ' · Options: ' + report.counts.options + ' · URLs attempted: ' + report.counts.urls + '/' + report.max_urls + ' · Findings: ' + report.findings.length));
        issues.replaceChildren();
        if (report.limited) { issues.appendChild(node('li', 'Coverage is incomplete or limited to the configured sample. Review the items below.')); }
        report.issues.forEach(function (issue) { issues.appendChild(node('li', issue)); });
        results.replaceChildren();
        report.findings.forEach(function (finding) {
            var row = document.createElement('tr');
            row.appendChild(node('td', finding.severity.toUpperCase()));
            var source = node('td', finding.source + ' · line ' + finding.line);
            if (finding.post_id && root.dataset.editUrl) {
                var edit = new URL(root.dataset.editUrl, window.location.href);
                edit.searchParams.set('post', String(finding.post_id)); edit.searchParams.set('action', 'edit');
                var link = node('a', 'Edit source'); link.href = edit.toString();
                source.appendChild(document.createElement('br')); source.appendChild(link);
            }
            row.appendChild(source);
            var detail = document.createElement('td');
            detail.appendChild(node('strong', finding.rule)); detail.appendChild(node('p', finding.reason));
            if (finding.host) { detail.appendChild(node('code', finding.host)); }
            row.appendChild(detail);
            var evidence = document.createElement('td'); var code = node('code', finding.snippet);
            code.style.whiteSpace = 'pre-wrap'; code.style.overflowWrap = 'anywhere';
            evidence.appendChild(code); row.appendChild(evidence); results.appendChild(row);
        });
        empty.hidden = !!report.findings.length;
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
        try { await request('clear', {}); results.replaceChildren(); issues.replaceChildren(); summary.replaceChildren(); empty.hidden = false; empty.textContent = 'No scan report yet.'; status.textContent = 'Report cleared. Site content was not changed.'; }
        catch (error) { status.textContent = error.message; }
        controls();
    });
    controls();
}());
