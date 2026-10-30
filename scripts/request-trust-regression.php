<?php
// Client IP trust, partial-form saves, Form Guard scope and HTML minify fallbacks.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(function ($level, $message) { throw new RuntimeException($message); });
define('ABSPATH', '/tmp/');
$checks = 0; $options = array(); $logged_in = false;
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; echo 'PASS ' . $message . PHP_EOL; }
function apply_filters($hook, $value, ...$args) { return $value; }
function add_action(...$args) {}
function add_filter(...$args) {}
function tk_get_option($name, $default = null) { return $GLOBALS['options'][$name] ?? $default; }
function tk_update_option($name, $value) { $GLOBALS['options'][$name] = $value; }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : (is_array($value) ? array_map('wp_unslash', $value) : $value); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function rest_get_url_prefix() { return 'wp-json'; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }

// helpers.php needs a full WordPress bootstrap, so load only the functions under test.
function load_helper_functions(string $file, array $names): void {
    $src = file_get_contents($file);
    $code = '';
    foreach ($names as $name) {
        $start = strpos($src, 'function ' . $name . '(');
        if ($start === false) { throw new RuntimeException('Missing helper ' . $name); }
        $depth = 0; $i = strpos($src, '{', $start);
        for ($j = $i; $j < strlen($src); $j++) {
            if ($src[$j] === '{') { $depth++; }
            if ($src[$j] === '}' && --$depth === 0) { break; }
        }
        $code .= substr($src, $start, $j - $start + 1) . "\n";
    }
    eval($code);
}
load_helper_functions(dirname(__DIR__) . '/includes/helpers.php', array(
    'tk_ip_in_ranges', 'tk_cloudflare_ip_ranges', 'tk_trusted_proxy_ranges', 'tk_get_ip',
    'tk_form_has_field', 'tk_update_posted_flag',
));

$ip_cases = array(
    'spoofed X-Forwarded-For from the internet is ignored' => array(array('REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'), '203.0.113.9'),
    'spoofed CF-Connecting-IP from the internet is ignored' => array(array('REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'), '203.0.113.9'),
    'CF-Connecting-IP is used from a Cloudflare edge' => array(array('REMOTE_ADDR' => '172.68.10.5', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'), '198.51.100.7'),
    'CF-Connecting-IP is used from a Cloudflare IPv6 edge' => array(array('REMOTE_ADDR' => '2606:4700:10::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::5'), '2001:db8::5'),
    'local proxy uses the right-most untrusted X-Forwarded-For hop' => array(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7'), '198.51.100.7'),
    'private load balancer chain skips trusted hops' => array(array('REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.9'), '198.51.100.7'),
    'local proxy falls back to X-Real-IP' => array(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '198.51.100.7'), '198.51.100.7'),
    '172.32.0.0 is outside the 172.16.0.0/12 private range' => array(array('REMOTE_ADDR' => '172.32.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'), '172.32.0.1'),
);
foreach ($ip_cases as $label => list($server, $expected)) {
    $_SERVER = $server;
    check(tk_get_ip() === $expected, $label);
}

$options = array('hardening_hsts_enabled' => 1, 'hardening_disable_pingbacks' => 1);
$_POST = array('tk_switches' => array('pingbacks'));
tk_update_posted_flag('hardening_hsts_enabled', 'hsts');
tk_update_posted_flag('hardening_disable_pingbacks', 'pingbacks');
check($options['hardening_hsts_enabled'] === 1, 'a setting without a form control keeps its value on save');
check($options['hardening_disable_pingbacks'] === 0, 'an unchecked switch rendered on the form is saved as off');
$_POST = array('tk_switches' => array('pingbacks'), 'pingbacks' => '1');
tk_update_posted_flag('hardening_disable_pingbacks', 'pingbacks');
check($options['hardening_disable_pingbacks'] === 1, 'a checked switch is saved as on');

require dirname(__DIR__) . '/includes/security-form-guard.php';
$_POST = array('title' => 'x');
$_GET = array();
$_SERVER = array('REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.9', 'REQUEST_URI' => '/wp-json/wp/v2/posts/12');
$logged_in = true;
check(!tk_form_guard_is_public_post(), 'logged-in block editor REST saves skip the public form rate limit');
$logged_in = false;
check(tk_form_guard_is_public_post(), 'anonymous REST posts stay guarded');
$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart/add-item';
check(!tk_form_guard_is_public_post(), 'WooCommerce Store API cart requests skip the public form rate limit');
$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout';
$_GET = array('rest_route' => '/wc/store/v1/checkout');
check(!tk_form_guard_is_public_post(), 'Store API via rest_route skips the public form rate limit');
$_GET = array('wc-ajax' => 'add_to_cart');
$_SERVER['REQUEST_URI'] = '/?wc-ajax=add_to_cart';
check(!tk_form_guard_is_public_post(), 'wc-ajax requests are not treated as public form posts');
$_GET = array();
$_SERVER['REQUEST_URI'] = '/wp-json/contact-form-7/v1/contact-forms/5/feedback';
check(tk_form_guard_is_public_post(), 'anonymous Contact Form 7 REST submissions stay guarded');

$options = array('minify_html' => 1, 'minify_inline_css' => 1, 'minify_inline_js' => 1);
require dirname(__DIR__) . '/includes/minify.php';
$page = "<!doctype html><html><body>\n  <p><strong>Price:</strong> <em>10</em></p>\n</body></html>";
$out = tk_minify_buffer_callback($page);
check(strpos($out, '<strong>Price:</strong> <em>10</em>') !== false, 'minify keeps the space between inline elements');
ini_set('pcre.backtrack_limit', '1000');
ini_set('pcre.jit', '0');
$big = '<!doctype html><html><head><script>var d = "' . str_repeat('x', 20000) . '";</script></head><body><p>Hi</p></body></html>';
$out = tk_minify_buffer_callback($big);
check($out === $big, 'minify returns the original document when PCRE fails instead of an empty page');

load_helper_functions(dirname(__DIR__) . '/includes/antispam-contact.php', array('tk_antispam_message_is_generic_phrase', 'tk_antispam_text_has_keyword'));
check(tk_antispam_message_is_generic_phrase('Hi!', 'hi'), 'a message that is only a generic greeting is rejected');
check(tk_antispam_message_is_generic_phrase('see it here: https://spam.example', 'see it here'), 'a generic opener followed by a link is rejected');
check(!tk_antispam_message_is_generic_phrase('Please send me this quote', 'hi'), '"this" does not match the generic phrase "hi"');
check(!tk_antispam_message_is_generic_phrase('I have a question about pricing for 500 units', 'i have a question'), 'a real question is not treated as a generic phrase');
check(!tk_antispam_message_is_generic_phrase('Latest contest results', 'test'), '"contest" does not match the generic phrase "test"');
check(!tk_antispam_text_has_keyword('Our office is in Essex', 'sex'), 'keyword "sex" does not match "Essex"');
check(tk_antispam_text_has_keyword('cheap loans today', 'loan'), 'keyword "loan" still matches "loans"');
check(tk_antispam_text_has_keyword('visit bit.ly/abc', 'bit.ly'), 'punctuated keywords still match');

echo $checks . " request trust checks passed." . PHP_EOL;
