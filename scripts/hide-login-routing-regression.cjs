const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn} = require('node:child_process');

(async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'tk-hide-login-'));
    fs.writeFileSync(path.join(directory, 'wp-login.php'), `<?php
if (tk_hide_login_is_slug_path(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    header('Cache-Control: public, max-age=3600');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Robots-Tag: index, follow');
}
fixture_run('login_init');
if (is_user_logged_in() && ($_REQUEST['action'] ?? '') === 'logout') { wp_safe_redirect(wc_get_page_permalink('myaccount')); exit; }
add_action('login_head', 'wp_strict_cross_origin_referrer');
header('Content-Type: text/html; charset=UTF-8');
echo '<html><head>'; fixture_run('login_head'); echo '</head><body><form action="' . wp_login_url() . '" method="post">Login fixture</form></body></html>';
`);
    let server;
    try {
        const reservation = net.createServer();
        await new Promise((resolve, reject) => { reservation.once('error', reject); reservation.listen(0, '127.0.0.1', resolve); });
        const port = reservation.address().port;
        await new Promise(resolve => reservation.close(resolve));
        server = spawn(process.env.TK_PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, path.join(__dirname, 'hide-login-http-fixture.php')], {
            env: {...process.env, TK_HIDE_LOGIN_FIXTURE: '1', TK_HIDE_LOGIN_FIXTURE_ROOT: directory, TK_HIDE_LOGIN_SOURCE: path.resolve(__dirname, '../includes/security-hide-login.php')},
            stdio: ['ignore', 'ignore', 'pipe'],
        });
        let logs = '';
        server.stderr.on('data', data => { logs += data; });
        const origin = 'http://127.0.0.1:' + port;
        let ready = false;
        for (let attempt = 0; attempt < 50; attempt++) {
            if (server.exitCode !== null) throw new Error(logs);
            try { await fetch(origin); ready = true; break; } catch (_) { await new Promise(resolve => setTimeout(resolve, 50)); }
        }
        assert.ok(ready, logs);
        for (const agent of ['Mozilla/5.0', 'Googlebot', 'Security scanner']) {
            for (const route of ['/wp-login.php', '/wp-login.php?action=register', '/wp-admin/', '/wp-admin/admin.php', '/login', '/login.php', '/subsite/wp-login.php', '/subsite/wp-admin/', '/subsite/login']) {
                const response = await fetch(origin + route, {redirect: 'manual', headers: {'User-Agent': agent}});
                assert.equal(response.status, 404, agent + ' ' + route);
                assert.equal(response.headers.get('location'), null, 'Hidden entry redirected');
                assert.equal(response.headers.get('x-fixture-lock'), null, 'A GET visit must not lock the visitor');
                assert.doesNotMatch(await response.text(), /staff-door-7b9c/);
            }
        }
        for (const route of ['/wp-login.php', '/login', '/subsite/wp-login.php']) {
            const response = await fetch(origin + route, {method: 'POST', redirect: 'manual', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'log=scanner&pwd=invalid'});
            assert.equal(response.status, 404, 'Credential probe ' + route);
            assert.equal(response.headers.get('location'), null);
            assert.equal(response.headers.get('x-fixture-lock'), '1', 'Credential honeypot must retain its IP lock');
        }
        for (const route of ['/staff-door-7b9c/', '/staff-door-7b9c/?action=lostpassword', '/staff-door-7b9c/?action=rp&key=fixture', '/subsite/staff-door-7b9c/']) {
            for (const method of ['GET', 'HEAD', 'POST']) {
                const response = await fetch(origin + route, {method, redirect: 'manual'});
                assert.equal(response.status, 200, method + ' ' + route);
                assert.match(response.headers.get('x-robots-tag') || '', /noindex/);
                assert.match(response.headers.get('cache-control') || '', /no-store/);
                assert.equal(response.headers.get('referrer-policy'), 'no-referrer');
                if (method !== 'HEAD') {
                    const body = await response.text();
                    assert.match(body, /content="no-referrer"/);
                    assert.doesNotMatch(body, /strict-origin-when-cross-origin/);
                }
            }
        }
        for (const role of ['', 'customer']) {
            for (const route of ['/', '/admissions', '/my-account/', '/my-account/lost-password/', '/checkout/', '/wp-admin/admin-ajax.php', '/wp-admin/admin-post.php']) {
                const response = await fetch(origin + route, {headers: {'X-Fixture-Role': role}});
                assert.equal(response.status, 200, role + ' ' + route);
                assert.equal(response.headers.get('x-robots-tag'), null, 'Noindex leaked to content');
                const body = await response.text();
                assert.doesNotMatch(body, /staff-door-7b9c/);
                const urls = JSON.parse(body);
                assert.equal(urls.login, origin + '/my-account/');
                assert.equal(urls.lostpassword, origin + '/my-account/lost-password/');
                assert.match(urls.logout, /my-account\/customer-logout\/\?_wpnonce=/);
            }
        }
        const signedLogout = await fetch(origin + '/wp-login.php?action=logout&_wpnonce=valid', {redirect: 'manual', headers: {'X-Fixture-Role': 'customer'}});
        assert.equal(signedLogout.status, 302);
        assert.equal(signedLogout.headers.get('location'), origin + '/my-account/');
        const forgedLogout = await fetch(origin + '/wp-login.php?action=logout&_wpnonce=wrong', {redirect: 'manual', headers: {'X-Fixture-Role': 'customer'}});
        assert.equal(forgedLogout.status, 404);
        const disabled = await fetch(origin + '/wp-login.php', {headers: {'X-Fixture-Hide-Login': '0'}});
        assert.equal(disabled.status, 200);
        assert.equal(disabled.headers.get('x-robots-tag'), null);
        console.log('PASS: 404/no redirect disclosure, custom-login noindex/no-store/referrer, GET/HEAD/POST/reset paths, public/customer URLs, AJAX/admin-post, signed logout, disabled mode and subdirectories (isolated WordPress/WooCommerce fixtures)');
    } finally {
        if (server && server.pid && server.exitCode === null) {
            await new Promise(resolve => { server.once('exit', resolve); server.kill(); });
        }
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
