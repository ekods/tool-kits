(function () {
    'use strict';
    if (window.tkCaptchaBound || !window.tkCaptcha) return;
    window.tkCaptchaBound = true;
    const config = window.tkCaptcha;
    const pending = new WeakMap();
    let refreshQueue = Promise.resolve();

    // Synchronous SHA-256: crypto.subtle costs one promise per hash, far too slow for proof-of-work.
    // Constants are the fractional parts of prime square/cube roots, as in FIPS 180-4.
    const primes = [];
    for (let n = 2; primes.length < 64; n++) { if (primes.every(function (p) { return n % p; })) primes.push(n); }
    const K = Uint32Array.from(primes, function (p) { const r = Math.cbrt(p); return (r - Math.floor(r)) * 4294967296; });
    const IV = Uint32Array.from(primes.slice(0, 8), function (p) { const r = Math.sqrt(p); return (r - Math.floor(r)) * 4294967296; });
    const W = new Uint32Array(64);

    function sha256Head(bytes) {
        const length = bytes.length, total = ((length + 72) >> 6) << 6;
        const buffer = new Uint8Array(total);
        buffer.set(bytes); buffer[length] = 0x80;
        const bits = length * 8;
        buffer[total - 4] = bits >>> 24; buffer[total - 3] = bits >>> 16; buffer[total - 2] = bits >>> 8; buffer[total - 1] = bits;
        const H = IV.slice();
        for (let offset = 0; offset < total; offset += 64) {
            for (let i = 0; i < 16; i++) {
                const j = offset + i * 4;
                W[i] = buffer[j] << 24 | buffer[j + 1] << 16 | buffer[j + 2] << 8 | buffer[j + 3];
            }
            for (let i = 16; i < 64; i++) {
                const x = W[i - 15], y = W[i - 2];
                W[i] = W[i - 16] + ((x >>> 7 | x << 25) ^ (x >>> 18 | x << 14) ^ (x >>> 3)) + W[i - 7]
                    + ((y >>> 17 | y << 15) ^ (y >>> 19 | y << 13) ^ (y >>> 10));
            }
            let a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
            for (let i = 0; i < 64; i++) {
                const t1 = h + ((e >>> 6 | e << 26) ^ (e >>> 11 | e << 21) ^ (e >>> 25 | e << 7)) + (e & f ^ ~e & g) + K[i] + W[i] | 0;
                const t2 = ((a >>> 2 | a << 30) ^ (a >>> 13 | a << 19) ^ (a >>> 22 | a << 10)) + (a & b ^ a & c ^ b & c) | 0;
                h = g; g = f; f = e; e = d + t1 | 0; d = c; c = b; b = a; a = t1 + t2 | 0;
            }
            H[0] += a; H[1] += b; H[2] += c; H[3] += d; H[4] += e; H[5] += f; H[6] += g; H[7] += h;
        }
        return H[0];
    }

    async function solvePow(field) {
        const parts = (field.dataset.pow || '').split(':');
        const bits = Number(parts[1]);
        if (!/^[a-f0-9]{32}$/.test(parts[0]) || !(bits >= 1 && bits <= 32)) throw new Error('invalid_pow');
        const token = field.querySelector('[name="tk_captcha_token"]').value;
        const encoder = new TextEncoder();
        const prefix = encoder.encode(parts[0] + ':' + token + ':');
        const bytes = new Uint8Array(prefix.length + 12);
        bytes.set(prefix);
        for (let nonce = 0; nonce < 1e12; nonce++) {
            const digits = encoder.encode(String(nonce));
            bytes.set(digits, prefix.length);
            if (sha256Head(bytes.subarray(0, prefix.length + digits.length)) >>> (32 - bits) === 0) return String(nonce);
            if (nonce % 4096 === 4095) await new Promise(function (resolve) { window.setTimeout(resolve, 0); });
        }
        throw new Error('invalid_pow');
    }

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

    function refresh(field, message) {
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
            status(next, message);
            return next;
        }).catch(function (error) {
            field.removeAttribute('aria-busy');
            status(field, error.code === 'rate_limited' ? config.rateLimited : config.refreshFailed);
        }).finally(function () { pending.delete(field); });
        pending.set(field, job);
        refreshQueue = job;
        return job;
    }

    async function verify(field, trusted) {
        if (field.dataset.tkCaptchaReady !== '1' || field.dataset.tkCaptchaVerifying === '1') return;
        const box = field.querySelector('.tk-captcha-checkbox-box');
        if (!box || box.classList.contains('is-checked')) return;
        field.dataset.tkCaptchaVerifying = '1';
        box.classList.add('is-loading');
        box.setAttribute('aria-busy', 'true');
        status(field, config.loading);
        try {
            let data = {};
            // The server may ask for more work when click-time signals look automated (capped at 24 bits).
            for (let round = 0; round < 3 && !data.answer; round++) {
                const pow = await solvePow(field);
                const delay = Math.max(0, Number(field.dataset.tkCaptchaNotBefore || 0) - Date.now());
                if (delay) await new Promise(function (resolve) { window.setTimeout(resolve, delay); });
                if (!field.isConnected) return;
                data = await request(config.verifyUrl, { nonce: config.verifyNonce,
                    token: field.querySelector('[name="tk_captcha_token"]').value, checked: '1', pow_nonce: pow,
                    automation: navigator.webdriver ? '1' : '0', interaction: trusted ? '1' : '0',
                    tk_robot_honeypot: field.querySelector('[name="tk_robot_honeypot"]')?.value || '' });
                if (!field.isConnected) return;
                if (data.work) field.dataset.pow = field.dataset.pow.split(':')[0] + ':' + Number(data.work);
            }
            if (!/^[a-f0-9]{64}$/.test(data.answer || '')) throw new Error('invalid_proof');
            field.querySelector('.tk-captcha-answer-field').value = data.answer;
            box.classList.add('is-checked');
            box.setAttribute('aria-checked', 'true');
            status(field, '');
        } catch (error) {
            if (error.code === 'too_fast') status(field, config.tooFast);
            else if (error.code === 'rate_limited') status(field, config.rateLimited);
            else await refresh(field, config.verifyFailed);
        } finally {
            field.dataset.tkCaptchaVerifying = '0';
            box.classList.remove('is-loading');
            box.removeAttribute('aria-busy');
        }
    }

    function handleClick(event) {
        if (!event.target.closest) return;
        const field = event.target.closest('.tk-captcha-field');
        if (!field) return;
        if (event.target.closest('.tk-captcha-refresh')) {
            event.preventDefault(); refresh(field); return;
        }
        if (event.target.closest('.tk-captcha-checkbox-left')) {
            event.preventDefault();
            if (field.querySelector('.is-checked')) return;
            if (field.dataset.tkCaptchaReady !== '1') {
                refresh(field).then(function (next) { if (next) verify(next, event.isTrusted); });
            } else verify(field, event.isTrusted);
        }
    }
    document.addEventListener('click', handleClick);
    document.addEventListener('keydown', function (event) {
        const target = event.target;
        if (target.matches?.('.tk-captcha-checkbox-box') && (event.key === 'Enter' || event.key === ' ')) {
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
                else status(field, config.required);
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
