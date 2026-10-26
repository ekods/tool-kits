(function () {
    'use strict';
    document.querySelectorAll('.tk-health-center').forEach(function (root) {
        var button = root.querySelector('.tk-health-run');
        var progress = root.querySelector('.tk-health-progress');
        var result = root.querySelector('.tk-health-result');
        var meter = root.querySelector('.tk-health-scan-progress');
        var full = root.classList.contains('tk-health-full');
        var busy = false;
        function status(kind, message, percent) {
            progress.dataset.state = kind;
            progress.textContent = message;
            if (meter) { meter.hidden = kind !== 'running'; meter.value = Math.max(0, Math.min(100, Number(percent) || 0)); }
        }
        function request(action, token, check, fields) {
            var data = new URLSearchParams({action: 'tk_health_center_' + action, _ajax_nonce: root.dataset.nonce, token: token || '', full: full ? '1' : ''});
            if (check) { data.set('check', check); }
            if (fields) { Object.keys(fields).forEach(function (key) { data.set(key, fields[key]); }); }
            var controller = new AbortController();
            var timer = setTimeout(function () { controller.abort(); }, 45000);
            return fetch(window.ajaxurl, {method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal, body: data}).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload.success) { throw new Error(payload.data && payload.data.message || 'Scan failed. Previous results remain available.'); }
                    return payload.data;
                });
            }).finally(function () { clearTimeout(timer); });
        }
        function replaceReport(html) {
            replaceFragment(result, html);
        }
        function replaceFragment(container, html) {
            if (!container) { throw new Error('The saved view is unavailable. Reload the page.'); }
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            container.replaceChildren.apply(container, Array.from(parsed.body.childNodes));
        }
        function lock(value) {
            busy = value;
            button.disabled = value;
            root.querySelectorAll('.tk-health-recheck, .tk-health-policy button, .tk-health-policy input, .tk-health-history button, .tk-health-history select').forEach(function (control) { control.disabled = value; });
            root.setAttribute('aria-busy', value ? 'true' : 'false');
        }
        async function run(check, trigger) {
            if (busy || button.disabled) { return; }
            var idleLabel = button.textContent;
            lock(true);
            button.textContent = check ? 'Rechecking…' : 'Scanning…';
            if (trigger) { trigger.textContent = 'Checking…'; }
            status('running', check ? 'Starting individual recheck…' : 'Starting manual scan…', 0);
            try {
                var state = await request('start', '', check);
                var limit = Math.min(4, Math.max(1, Number(state.steps) || 4));
                var steps = 0;
                while (!state.done) {
                    if (++steps > limit) { throw new Error('The scan did not finish within its expected stages.'); }
                    status('running', state.message, state.progress);
                    state = await request('step', state.token);
                }
                // Only the authenticated, escaped PHP renderer supplies this fragment.
                replaceReport(state.html);
                status('success', state.message, 100);
            } catch (error) { status('error', (error.name === 'AbortError' ? 'The scan request timed out.' : error.message || 'Connection lost.') + ' Previous results remain available. Interrupted sessions expire after 10 minutes.', 0); }
            finally {
                lock(false); button.textContent = idleLabel;
                if (trigger) { trigger.textContent = 'Recheck'; }
            }
        }
        button.addEventListener('click', function () { return run(''); });
        root.addEventListener('click', function (event) {
            var target = event.target.closest('.tk-health-recheck');
            if (target && root.contains(target)) { return run(target.dataset.healthCheck, target); }
        });
        root.addEventListener('submit', async function (event) {
            var form = event.target;
            var history = form.matches('.tk-health-history-form');
            if (!history && !form.matches('.tk-health-policy-form')) { return; }
            event.preventDefault();
            if (busy) { return; }
            var fields = {};
            new FormData(form).forEach(function (value, key) { fields[key] = value; });
            if (!history) { fields.object_cache_required = form.querySelector('[name="object_cache_required"]').checked ? '1' : '0'; }
            lock(true); status('running', history ? 'Comparing saved full scans…' : 'Saving diagnostic thresholds…', 0);
            try {
                var reply = await request(history ? 'history' : 'settings', '', '', fields);
                if (history) { replaceFragment(root.querySelector('.tk-health-history-body'), reply.html); }
                else { replaceReport(reply.html); }
                status('success', reply.message, 100);
            } catch (error) { status('error', error.name === 'AbortError' ? (history ? 'Comparison timed out. Saved scans are unchanged.' : 'Saving timed out. Reload to verify the saved thresholds.') : error.message || (history ? 'Could not compare saved scans.' : 'Could not save thresholds.'), 0); }
            finally { lock(false); }
        });
    });
}());
