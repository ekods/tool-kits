(function () {
    'use strict';
    document.querySelectorAll('.tk-health-center').forEach(function (root) {
        var button = root.querySelector('.tk-health-run');
        var progress = root.querySelector('.tk-health-progress');
        var result = root.querySelector('.tk-health-result');
        var full = root.classList.contains('tk-health-full');
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
            button.disabled = true;
            progress.textContent = 'Starting manual scan…';
            try {
                var state = await request('start');
                while (!state.done) {
                    progress.textContent = state.message;
                    state = await request('step', state.token);
                }
                // Only the authenticated, escaped PHP renderer supplies this report fragment.
                var parsed = new DOMParser().parseFromString(state.html, 'text/html');
                result.replaceChildren.apply(result, Array.from(parsed.body.childNodes));
                progress.textContent = state.message;
            } catch (error) { progress.textContent = (error.name === 'AbortError' ? 'The scan request timed out.' : error.message || 'Connection lost.') + ' Previous results remain available. Interrupted sessions expire after 10 minutes.'; }
            finally { button.disabled = false; }
        }
        button.addEventListener('click', run);
    });
}());
