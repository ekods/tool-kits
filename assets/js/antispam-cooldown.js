(function () {
    'use strict';

    const timers = new WeakMap();
    const labels = window.tkAntispamCooldown || {};
    const waiting = labels.waiting || 'Please wait %s before sending another message.';
    const ready = labels.ready || 'You can send another message now.';
    const submitSelector = 'input[type="submit"], button[type="submit"], button:not([type])';

    function formFor(target) {
        if (!target || !target.matches) return null;
        return target.matches('form.wpcf7-form') ? target : target.querySelector('form.wpcf7-form');
    }

    function acceptanceBlocked(form) {
        if (form.classList.contains('wpcf7-acceptance-as-validation')) return false;
        return Array.from(form.querySelectorAll('.wpcf7-acceptance:not(.optional)')).some(function (wrap) {
            const input = wrap.querySelector('input[type="checkbox"]');
            return input && (wrap.classList.contains('invert') ? input.checked : !input.checked);
        });
    }

    function release(form, state) {
        window.clearInterval(state.interval);
        const blocked = acceptanceBlocked(form);
        state.buttons.forEach(function (wasDisabled, button) {
            button.disabled = blocked || (wasDisabled && !state.acceptanceWasBlocked);
        });
        timers.delete(form);
    }

    function clearCooldownErrors(form, message) {
        form.querySelectorAll('.wpcf7-not-valid-tip').forEach(function (tip) {
            if (tip.textContent !== message) return;
            const wrap = tip.parentElement;
            tip.remove();
            wrap.querySelectorAll('.wpcf7-form-control').forEach(function (control) {
                if (typeof control.setCustomValidity === 'function') control.setCustomValidity('');
                control.classList.remove('wpcf7-not-valid');
                control.setAttribute('aria-invalid', 'false');
                control.removeAttribute('aria-describedby');
            });
        });
        form.querySelectorAll('.wpcf7-response-output').forEach(function (output) {
            if (output.textContent === message) output.textContent = ready;
        });
    }

    function tick(form, state) {
        if (!form.isConnected) { release(form, state); return; }
        const remaining = Math.max(0, Math.ceil((state.deadline - Date.now()) / 1000));
        if (remaining === 0) {
            release(form, state);
            state.notice.textContent = ready;
            state.notice.setAttribute('role', 'status');
            state.notice.setAttribute('aria-live', 'polite');
            if (state.rejected) clearCooldownErrors(form, state.message);
            return;
        }
        form.querySelectorAll(submitSelector).forEach(function (button) {
            if (!state.buttons.has(button)) state.buttons.set(button, button.disabled);
            button.disabled = true;
        });
        const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
        const seconds = String(remaining % 60).padStart(2, '0');
        state.notice.textContent = waiting.replace('%s', minutes + ':' + seconds);
    }

    document.addEventListener('wpcf7submit', function (event) {
        const form = formFor(event.target);
        if (!form) return;
        const response = event.detail && event.detail.apiResponse;
        const cooldown = response && response.tool_kits_cooldown;
        const seconds = Number(cooldown && cooldown.retry_after);
        const previous = timers.get(form);
        if (previous) release(form, previous);
        let notice = form.querySelector('.tk-antispam-cooldown');
        if (!Number.isFinite(seconds) || seconds <= 0) {
            if (notice) notice.hidden = true;
            return;
        }
        if (!notice) {
            notice = document.createElement('p');
            notice.className = 'tk-antispam-cooldown';
            form.appendChild(notice);
        }
        notice.hidden = false;
        notice.setAttribute('role', 'timer');
        notice.setAttribute('aria-live', 'off');
        const state = {
            deadline: Date.now() + Math.ceil(seconds) * 1000,
            buttons: new Map(), notice: notice,
            message: response.message || '', rejected: response.status !== 'mail_sent',
            acceptanceWasBlocked: acceptanceBlocked(form),
        };
        timers.set(form, state);
        state.interval = window.setInterval(function () { tick(form, state); }, 1000);
        tick(form, state);
    });

    // Block both clicks and Enter-key submissions before CF7 sends another request.
    document.addEventListener('submit', function (event) {
        const state = timers.get(event.target);
        if (!state) return;
        if (Date.now() >= state.deadline) { tick(event.target, state); return; }
        event.preventDefault();
        event.stopImmediatePropagation();
        tick(event.target, state);
    }, true);
}());
