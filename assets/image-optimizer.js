(function () {
    'use strict';
    var root = document.getElementById('tk-image-workspace');
    if (!root) return;

    var tabs = Array.from(root.querySelectorAll('[data-image-tab]'));
    var storageKey = 'tk-image-tab:' + (new URLSearchParams(location.search).get('page') || location.pathname);
    function storedTab() {
        try { return sessionStorage.getItem(storageKey); } catch (error) { return null; }
    }
    function activate(id, remember) {
        if (!tabs.some(function (tab) { return tab.dataset.imageTab === id; })) id = 'image-settings';
        tabs.forEach(function (tab) {
            var active = tab.dataset.imageTab === id;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            document.getElementById(tab.dataset.imageTab).hidden = !active;
        });
        if (remember) {
            try { sessionStorage.setItem(storageKey, id); } catch (error) {}
            history.replaceState(null, '', '#' + id);
        }
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { activate(tab.dataset.imageTab, true); });
        tab.addEventListener('keydown', function (event) {
            var next = index;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else return;
            event.preventDefault();
            tabs[next].focus();
            activate(tabs[next].dataset.imageTab, true);
        });
    });
    activate(location.hash.slice(1) || storedTab(), false);
    window.addEventListener('hashchange', function () { activate(location.hash.slice(1), false); });

    var form = document.getElementById('tk-image-settings-form');
    var hires = document.getElementById('image_opt_preserve_hires');
    var quality = document.getElementById('image_opt_quality');
    var range = document.getElementById('tk-quality-range');
    function updateQuality() {
        if (quality.value !== '') range.value = quality.value;
        document.getElementById('tk-effective-quality').value = String(Math.max(hires.checked ? 95 : 30, Math.min(100, Number(quality.value) || 30)));
        ['width', 'height'].forEach(function (axis) {
            var field = document.getElementById('image_opt_max_' + axis);
            field.readOnly = hires.checked;
            field.setAttribute('aria-disabled', String(hires.checked));
        });
        document.getElementById('tk-resize-state').textContent = hires.checked ? 'Inactive in Hi-Res' : '0 = unlimited';
    }
    range.addEventListener('input', function () { quality.value = range.value; updateQuality(); });
    quality.addEventListener('input', updateQuality);
    hires.addEventListener('change', updateQuality);
    form.addEventListener('input', function () { document.getElementById('tk-image-save-state').textContent = 'Unsaved changes'; });
    form.addEventListener('submit', function () {
        document.getElementById('tk-image-save-state').textContent = 'Saving settings...';
        try { sessionStorage.setItem(storageKey, 'image-settings'); } catch (error) {}
    });
    updateQuality();

    var button = document.getElementById('tk-image-opt-batch');
    var library = document.getElementById('background-queue');
    var status = document.getElementById('tk-image-opt-status');
    var progress = document.getElementById('tk-image-opt-progress-bar');
    var progressText = document.getElementById('tk-image-opt-progress-text');
    var overlay = document.getElementById('tk-image-opt-overlay');
    var overlayText = document.getElementById('tk-image-opt-overlay-text');
    var processing = false;
    var retryOffset = 0;
    var savedBytes = 0;
    function setStatus(text) { status.textContent = text; overlayText.textContent = text; }
    function setProcessing(active) {
        processing = active;
        button.disabled = active;
        library.setAttribute('aria-busy', String(active));
        overlay.classList.toggle('is-active', active);
        overlay.setAttribute('aria-hidden', String(!active));
        library.querySelectorAll('a.button').forEach(function (link) {
            link.setAttribute('aria-disabled', String(active));
            if (active) link.setAttribute('tabindex', '-1');
            else link.removeAttribute('tabindex');
        });
    }
    library.addEventListener('click', function (event) {
        if (processing && event.target.closest('a.button')) event.preventDefault();
    });
    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }
    async function run() {
        setProcessing(true);
        setStatus('Processing images...');
        try {
            while (true) {
                var data = new URLSearchParams({ action: 'tk_image_opt_batch', nonce: button.dataset.nonce, offset: String(retryOffset) });
                var response = await fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: data.toString() });
                if (!response.ok) throw new Error('Request failed');
                var result = await response.json();
                if (!result || !result.success || !result.data) throw new Error('Invalid response');
                var payload = result.data;
                var percent = Math.max(0, Math.min(100, Number(payload.progress) || 0));
                savedBytes += Math.max(0, Number(payload.saved) || 0);
                progress.style.width = percent + '%';
                progress.parentElement.setAttribute('aria-valuenow', String(percent));
                progressText.textContent = percent + '%';
                setStatus(payload.processed + ' / ' + payload.total + ' processed. Saved ' + formatBytes(savedBytes) + '.');
                if (payload.done) {
                    retryOffset = 0;
                    savedBytes = 0;
                    break;
                }
                var next = Number(payload.next_offset);
                if (!Number.isFinite(next) || next <= retryOffset) throw new Error('No progress');
                retryOffset = next;
            }
            status.classList.remove('tk-image-error');
        } catch (error) {
            status.classList.add('tk-image-error');
            setStatus('Processing interrupted. Retry to continue from the last completed batch.');
        } finally {
            setProcessing(false);
            button.focus({ preventScroll: true });
        }
    }
    button.addEventListener('click', function () { if (!processing) run(); });
})();
