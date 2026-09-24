(function () {
    'use strict';
    function initialize() {
    var root = document.getElementById('tk-geo-batch');
    if (!root) { return; }
    var all = root.querySelector('.tk-geo-run-all');
    var selected = root.querySelector('.tk-geo-run-selected');
    var stop = root.querySelector('.tk-geo-stop');
    var status = root.querySelector('.tk-geo-status');
    var progress = root.querySelector('progress');
    var results = root.querySelector('.tk-geo-batch-results');
    var running = false;
    var stopping = false;
    var reportStatus = document.querySelector('.tk-geo-report-status');
    function showStatus(message) {
        status.textContent = message;
        if (reportStatus) { reportStatus.textContent = message; }
    }
    function setRunning(value) {
        running = value;
        all.disabled = selected.disabled = value;
        stop.disabled = !value;
        root.querySelectorAll('input').forEach(function (input) { input.disabled = value; });
        document.querySelectorAll('.tk-geo-report-selected, .tk-geo-retry-failed, .tk-geo-report-target, .tk-geo-report-all').forEach(function (control) { control.disabled = value; });
    }
    async function request(action, data) {
        var body = new URLSearchParams({ action: action, _ajax_nonce: root.dataset.nonce });
        Object.keys(data).forEach(function (key) {
            if (Array.isArray(data[key])) { data[key].forEach(function (item) { body.append(key + '[]', item); }); }
            else { body.append(key, data[key]); }
        });
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 45000);
        try {
            var response = await fetch(root.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal });
            var json = await response.json();
            if (!response.ok || !json.success) { throw new Error(json.data && json.data.message || 'Audit request failed.'); }
            return json.data;
        } finally { clearTimeout(timer); }
    }
    async function run(mode) {
        if (running) { return; }
        var ids = Array.from(root.querySelectorAll('.tk-geo-target:checked')).map(function (input) { return input.value; });
        var urls = Array.from(document.querySelectorAll('.tk-geo-report-target:checked')).map(function (input) { return input.value; });
        if (mode === 'report-selected' && !urls.length) { showStatus('Select at least one report URL.'); return; }
        if (mode === 'selected' && !ids.length) { showStatus('Select at least one URL.'); return; }
        stopping = false;
        results.replaceChildren();
        progress.value = 0;
        setRunning(true);
        showStatus('Starting audit...');
        try {
            var session = await request('tk_geo_batch_start', { mode: mode, ids: ids, urls: urls });
            progress.max = session.total;
            while (!stopping) {
                showStatus('Checking ' + (progress.value + 1) + '/' + session.total + '...');
                var data = await request('tk_geo_batch_step', { token: session.token });
                progress.value = data.checked;
                if (data.item) {
                    var row = document.createElement('li');
                    row.textContent = data.item.url + ' — ' + (data.item.score === null ? 'Not verified: ' + data.item.issues.join('; ') : data.item.score + '/100');
                    row.style.overflowWrap = 'anywhere';
                    results.appendChild(row);
                }
                if (data.done) {
                    showStatus('Completed: ' + data.checked + '/' + data.total + '. Refreshing report...');
                    var reportUrl = new URL(window.location.href);
                    reportUrl.searchParams.set('tk_geo_refresh', String(Date.now()));
                    reportUrl.hash = 'geo-audit';
                    window.location.replace(reportUrl.toString());
                    break;
                }
                await new Promise(function (resolve) { setTimeout(resolve, 1000); });
            }
            if (stopping) { showStatus('Stopped. Completed results have been saved.'); }
        } catch (error) {
            showStatus(error.name === 'AbortError' ? 'Audit request timed out. Completed results remain saved.' : error.message);
        } finally { setRunning(false); }
    }
    all.addEventListener('click', function () { run('all'); });
    selected.addEventListener('click', function () { run('selected'); });
    document.querySelectorAll('.tk-geo-report-selected').forEach(function (button) { button.addEventListener('click', function () { run('report-selected'); }); });
    document.querySelectorAll('.tk-geo-retry-failed').forEach(function (button) { button.addEventListener('click', function () { run('failed'); }); });
    var reportAll = document.querySelector('.tk-geo-report-all');
    if (reportAll) { reportAll.addEventListener('change', function () {
        document.querySelectorAll('.tk-geo-report-target').forEach(function (input) { input.checked = reportAll.checked; });
    }); }
    stop.addEventListener('click', function () { stopping = true; stop.disabled = true; showStatus('Stopping after the current URL...'); });
    root.querySelector('.tk-geo-select-all').addEventListener('change', function (event) {
        root.querySelectorAll('.tk-geo-target').forEach(function (input) { input.checked = event.target.checked; });
    });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initialize, { once: true }); }
    else { initialize(); }
}());
