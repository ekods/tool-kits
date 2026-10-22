(function () {
    'use strict';
    document.querySelectorAll('.tk-health-center').forEach(function (root) {
        var button = root.querySelector('.tk-health-run');
        var progress = root.querySelector('.tk-health-progress');
        var result = root.querySelector('.tk-health-result');
        var meter = root.querySelector('.tk-health-scan-progress');
        var full = root.classList.contains('tk-health-full');
        function status(kind, message, percent) {
            progress.dataset.state = kind;
            progress.textContent = message;
            if (meter) { meter.hidden = kind !== 'running'; meter.value = Math.max(0, Math.min(100, Number(percent) || 0)); }
        }
        function request(action, token) {
            var data = new URLSearchParams({action: 'tk_health_center_' + action, _ajax_nonce: root.dataset.nonce, token: token || '', full: full ? '1' : ''});
            var controller = new AbortController();
            var timer = setTimeout(function () { controller.abort(); }, 45000);
            return fetch(window.ajaxurl, {method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal, body: data}).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload.success) { throw new Error(payload.data && payload.data.message || 'Scan failed. Previous results remain available.'); }
                    return payload.data;
                });
            }).finally(function () { clearTimeout(timer); });
        }
        async function run() {
            if (button.disabled) { return; }
            var idleLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Scanning…';
            root.setAttribute('aria-busy', 'true');
            status('running', 'Starting manual scan…', 0);
            try {
                var state = await request('start');
                var steps = 0;
                while (!state.done) {
                    if (++steps > 4) { throw new Error('The scan did not finish within its four stages.'); }
                    status('running', state.message, state.progress);
                    state = await request('step', state.token);
                }
                // Only the authenticated, escaped PHP renderer supplies this report fragment.
                var parsed = new DOMParser().parseFromString(state.html, 'text/html');
                result.replaceChildren.apply(result, Array.from(parsed.body.childNodes));
                status('success', state.message, 100);
            } catch (error) { status('error', (error.name === 'AbortError' ? 'The scan request timed out.' : error.message || 'Connection lost.') + ' Previous results remain available. Interrupted sessions expire after 10 minutes.', 0); }
            finally { button.disabled = false; button.textContent = idleLabel; root.setAttribute('aria-busy', 'false'); }
        }
        button.addEventListener('click', run);
    });
}());
