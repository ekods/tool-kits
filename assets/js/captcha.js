(function () {
    'use strict';
    if (window.tkCaptchaBound || !window.tkCaptcha) return;
    window.tkCaptchaBound = true;
    const config = window.tkCaptcha;
    const pending = new WeakMap();
    let refreshQueue = Promise.resolve();

    function status(field, message) {
        const output = field.querySelector('.tk-captcha-status');
        if (output) output.textContent = message || '';
    }

    async function request(url, values) {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(values) });
        const result = await response.json();
        if (!response.ok || !result.success) {
            const error = new Error(typeof result.data === 'string' ? result.data : 'request_failed');
            error.code = error.message;
            throw error;
        }
        return result.data;
    }

    function expand(field) {
        const puzzle = field.querySelector('.tk-captcha-puzzle');
        if (puzzle) { puzzle.hidden = false; puzzle.querySelector('input')?.focus(); }
    }

    function refresh(field, message, opened) {
        if (pending.has(field)) return pending.get(field);
        field.dataset.tkCaptchaReady = '0';
        field.setAttribute('aria-busy', 'true');
        status(field, config.loading);
        // Serial issuance ensures several forms share the same freshly issued HttpOnly browser cookie.
        const job = refreshQueue.catch(function () {}).then(async function () {
            if (!field.isConnected) return;
            const data = await request(config.refreshUrl, { nonce: config.refreshNonce,
                type: field.getAttribute('data-type') || 'text',
                old_token: field.querySelector('[name="tk_captcha_token"]')?.value || '' });
            const template = document.createElement('template');
            template.innerHTML = data.markup || '';
            const next = template.content.querySelector('.tk-captcha-field');
            if (!next) throw new Error('invalid_markup');
            next.dataset.tkCaptchaReady = '1';
            next.dataset.tkCaptchaNotBefore = String(Date.now() + Number(config.minSeconds || 2) * 1000);
            next.dataset.tkCaptchaExpires = String(Date.now() + 300000);
            if (!field.isConnected) return;
            field.replaceWith(next);
            if (opened) expand(next);
            status(next, message);
        }).catch(function (error) {
            field.removeAttribute('aria-busy');
            status(field, error.code === 'rate_limited' ? config.rateLimited : config.refreshFailed);
        }).finally(function () { pending.delete(field); });
        pending.set(field, job);
        refreshQueue = job;
        return job;
    }

    async function verify(field) {
        if (field.dataset.tkCaptchaReady !== '1' || field.dataset.tkCaptchaVerifying === '1') return;
        const answer = field.querySelector('.tk-captcha-puzzle-input')?.value.trim();
        if (!answer) { status(field, config.required); expand(field); return; }
        const button = field.querySelector('.tk-captcha-verify');
        field.dataset.tkCaptchaVerifying = '1';
        if (button) button.disabled = true;
        status(field, config.loading);
        try {
            const delay = Math.max(0, Number(field.dataset.tkCaptchaNotBefore || 0) - Date.now());
            if (delay) await new Promise(function (resolve) { window.setTimeout(resolve, delay); });
            const data = await request(config.verifyUrl, { nonce: config.verifyNonce,
                token: field.querySelector('[name="tk_captcha_token"]').value, answer: answer });
            if (!/^[a-f0-9]{64}$/.test(data.answer || '')) throw new Error('invalid_proof');
            if (!field.isConnected) return;
            field.querySelector('.tk-captcha-answer-field').value = data.answer;
            const box = field.querySelector('.tk-captcha-checkbox-box');
            box.classList.add('is-checked');
            box.setAttribute('aria-checked', 'true');
            field.querySelector('.tk-captcha-puzzle').hidden = true;
            status(field, '');
        } catch (error) {
            if (error.code === 'too_fast') status(field, config.tooFast);
            else if (error.code === 'rate_limited') status(field, config.rateLimited);
            else await refresh(field, config.incorrect, true);
        } finally {
            field.dataset.tkCaptchaVerifying = '0';
            if (button) button.disabled = false;
        }
    }

    function handleClick(event) {
        if (!event.target.closest) return;
        const field = event.target.closest('.tk-captcha-field');
        if (!field) return;
        if (event.target.closest('.tk-captcha-refresh')) {
            event.preventDefault(); refresh(field); return;
        }
        if (event.target.closest('.tk-captcha-verify')) { event.preventDefault(); verify(field); return; }
        if (event.target.closest('.tk-captcha-checkbox-left')) {
            event.preventDefault();
            if (field.querySelector('.is-checked')) return;
            if (field.dataset.tkCaptchaReady !== '1') refresh(field, '', true);
            else expand(field);
        }
    }
    document.addEventListener('click', handleClick);
    document.addEventListener('keydown', function (event) {
        const target = event.target;
        if (target.matches?.('.tk-captcha-puzzle-input') && event.key === 'Enter') {
            event.preventDefault(); verify(target.closest('.tk-captcha-field'));
        } else if (target.matches?.('.tk-captcha-checkbox-box') && (event.key === 'Enter' || event.key === ' ')) {
            handleClick(event);
        }
    });
    document.addEventListener('submit', function (event) {
        const fields = event.target.querySelectorAll?.('.tk-captcha-field') || [];
        for (const field of fields) {
            const answer = field.querySelector('[name="tk_captcha_answer"]')?.value.trim();
            const expired = Number(field.dataset.tkCaptchaExpires || 0) <= Date.now();
            if (field.dataset.tkCaptchaReady !== '1' || !answer || expired) {
                event.preventDefault(); event.stopImmediatePropagation();
                if (expired && field.dataset.tkCaptchaReady === '1') refresh(field, config.required);
                else { status(field, config.required); if (field.getAttribute('data-type') === 'checkbox') expand(field); }
                return;
            }
        }
    }, true);
    document.addEventListener('wpcf7submit', function (event) {
        const form = event.target.matches?.('form') ? event.target : event.target.querySelector('form');
        form?.querySelectorAll('.tk-captcha-field').forEach(function (field) { refresh(field); });
    });

    function scan(root) {
        root.querySelectorAll('.tk-captcha-field').forEach(function (field) {
            if (!field.dataset.tkCaptchaReady) refresh(field);
        });
    }
    function init() {
        scan(document);
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.matches('.tk-captcha-field') && !node.dataset.tkCaptchaReady) refresh(node);
                    scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
