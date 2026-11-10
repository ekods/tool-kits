// Real HTTP headers/status from the production CAPTCHA router, with isolated WordPress stubs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');

(async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'tk-captcha-routing-'));
    const router = path.join(directory, 'router.php');
    const source = path.resolve(__dirname, '../includes/security-captcha.php');
    // Pass paths as environment values so PHP string escaping is unnecessary.
    fs.writeFileSync(router, `<?php
error_reporting(E_ALL);
set_error_handler(function ($level, $message) { throw new RuntimeException($message); });
define('ABSPATH', __DIR__ . '/');
define('TK_URL', '/tool-kits/');
$hooks = array();
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_verify_nonce($value, $action) { return false; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path) { return 'https://example.test/subsite' . $path; }
function nocache_headers() { header('Cache-Control: no-cache, no-store, must-revalidate'); }
function wp_send_json_error($data, $status = 200) {
    http_response_code($status); header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'data' => $data)); exit;
}
function tk_get_option($key, $default = null) { return $key === 'captcha_enabled' ? 1 : $default; }
function is_admin() { return false; }
function doing_action($hook) { return $hook === 'login_footer'; }
function wp_script_is(...$args) { return false; }
function apply_filters($name, $value) { return $value; }
function wp_enqueue_script(...$args) {}
function tk_asset_version($asset) { return 'fixture'; }
function wp_create_nonce($action) { return $action; }
function determine_locale() { return 'en_US'; }
function wp_localize_script($handle, $name, $config) { $GLOBALS['config'] = $config; }
require getenv('TK_CAPTCHA_ROUTER_SOURCE');
tk_captcha_init();
foreach (array('init', 'template_redirect') as $hook) {
    foreach ($hooks[$hook] as $callback) { $callback(); }
}
tk_captcha_refresh_script();
header('Content-Type: application/json');
echo json_encode(array('page' => 'ordinary content', 'config' => $GLOBALS['config']));
`);
    let server;
    try {
        const reservation = net.createServer();
        await new Promise((resolve, reject) => { reservation.once('error', reject); reservation.listen(0, '127.0.0.1', resolve); });
        const port = reservation.address().port;
        await new Promise(resolve => reservation.close(resolve));
        server = spawn(process.env.TK_PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, router], {
            env: { ...process.env, TK_CAPTCHA_ROUTER_SOURCE: source }, stdio: ['ignore', 'ignore', 'pipe'],
        });
        let logs = '', startupError;
        server.stderr.on('data', data => { logs += data; });
        server.on('error', error => { startupError = error; });
        const origin = `http://127.0.0.1:${port}`;
        let ready = false;
        for (let attempt = 0; attempt < 50; attempt++) {
            if (startupError) throw startupError;
            if (server.exitCode !== null) throw new Error(logs);
            try { await fetch(origin); ready = true; break; } catch (_) { await new Promise(resolve => setTimeout(resolve, 50)); }
        }
        assert.ok(ready, 'PHP HTTP server did not start: ' + logs);
        const cases = [
            ['GET', '?tk_captcha_action=', '', 400, 'invalid_action'],
            ['GET', '?tk_captcha_action=unknown', '', 400, 'invalid_action'],
            ['GET', '?tk_captcha_action[]=refresh', '', 400, 'invalid_action'],
            ['GET', '?tk_captcha_action=refresh', '', 405, 'method_not_allowed'],
            ['HEAD', '?tk_captcha_action=verify_click', '', 405, null],
            ['POST', '?tk_captcha_action=refresh', 'nonce=invalid', 403, 'invalid_nonce'],
            ['POST', '?tk_captcha_action=verify_click', 'nonce=invalid', 403, 'invalid_nonce'],
            ['POST', '', 'tk_captcha_action=refresh&nonce=invalid', 403, 'invalid_nonce'],
            ['POST', '', 'tk_captcha_action=verify_click&nonce=invalid', 403, 'invalid_nonce'],
            ['POST', '', 'tk_captcha_action=', 400, 'invalid_action'],
            ['POST', '', 'tk_captcha_action[]=refresh', 400, 'invalid_action'],
            ['POST', '?tk_captcha_action=', 'tk_captcha_action=refresh', 400, 'invalid_action'],
        ];
        for (const [method, query, body, status, data] of cases) {
            const response = await fetch(origin + '/subsite/contact/' + query, {
                method, ...(method === 'POST' ? { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body } : {}),
            });
            const label = method + ' ' + query + ' ' + body;
            assert.equal(response.status, status, label);
            assert.match(response.headers.get('x-robots-tag') || '', /\bnoindex\b/, label);
            assert.match(response.headers.get('cache-control') || '', /no-store/, label);
            if (data) assert.equal((await response.json()).data, data, label);
        }
        for (const method of ['GET', 'POST']) {
            const response = await fetch(origin + '/subsite/contact/?utm_source=example', {
                method, ...(method === 'POST' ? { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'message=hello' } : {}),
            });
            assert.equal(response.status, 200);
            assert.equal(response.headers.get('x-robots-tag'), null, 'CAPTCHA noindex leaked to content');
            const page = await response.json();
            assert.equal(page.page, 'ordinary content');
            assert.equal(page.config.refreshUrl, 'https://example.test/subsite/');
            assert.equal(page.config.verifyUrl, 'https://example.test/subsite/');
        }
        console.log('PASS: 12 CAPTCHA HTTP routes (status, noindex, no-store), legacy URLs, POST actions, malformed input, ordinary content and query-free subdirectory URLs');
    } finally {
        if (server && server.pid && server.exitCode === null) {
            await new Promise(resolve => { server.once('exit', resolve); server.kill(); });
        }
        fs.rmSync(directory, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
