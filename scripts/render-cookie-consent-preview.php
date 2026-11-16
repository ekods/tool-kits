<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
function tk_get_option($key, $default = null) {
    return $key === 'cookie_consent_text' && ($GLOBALS['argv'][1] ?? '') === 'legacy'
        ? 'Read our <a href="#" class="tk-cookie-consent-link">Privacy Policy</a>.' : $default;
}
function get_privacy_policy_url() { return ($GLOBALS['argv'][1] ?? '') === 'policy' ? '/privacy' : ''; }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function absint($value) { return abs((int) $value); }
function wp_kses_post($value) { return $value; }
require dirname(__DIR__) . '/includes/cookie-consent.php';
?><!doctype html><html lang="en"><head><title>Cookie preferences verification</title><meta name="viewport" content="width=device-width, initial-scale=1"><?php tk_cookie_consent_render_consent_mode(); ?></head>
<body><main><h1>Cookie preferences verification</h1><button type="button" id="outside-control">Page action</button></main><?php tk_cookie_consent_render_banner(); ?></body></html>
