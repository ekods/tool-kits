<?php
define('ABSPATH', '/tmp/');
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function buffer_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/includes/seo-content-output.php';
$body = '<body>' . str_repeat('<p>Keep content</p><!-- <meta name="description" content="body"> -->', 10000) . '</body>';
$html = '<html><head><title>Old</title><meta name="description" content="old"><link rel="canonical" href="/original"><script>var x="</head><title>fake</title>";</script></head>' . $body . '</html>';
foreach (array(array('global', 'content'), array('content', 'global')) as $order) {
    $level = ob_get_level(); ob_start();
    foreach ($order as $source) {
        tk_seo_metadata_buffer($source, $source === 'global' ? array('title' => 'Global', 'description' => 'Global description', 'author' => 'Ayu') : array('title' => 'Page', 'description' => 'Page description'), true);
        buffer_check(ob_get_level() === $level + 2, 'Global and explicit metadata started separate buffers');
    }
    // A late theme buffer still runs inside both metadata policies.
    ob_start(function ($content) { return str_replace('<title>Old</title>', '<title>Late theme</title>', $content); });
    echo $html; ob_end_flush(); ob_end_flush(); $output = ob_get_clean();
    buffer_check(strpos($output, '<title>Page</title>') !== false && strpos($output, 'content="Page description"') !== false && strpos($output, 'content="Ayu"') !== false, 'Page-over-global precedence changed');
    buffer_check(strpos($output, $body) !== false && strpos($output, '<script>var x="</head><title>fake</title>";</script>') !== false && strpos($output, 'href="/original"') !== false, 'Body, script or canonical changed');
    buffer_check(!isset($GLOBALS['tk_seo_metadata_buffer']), 'Completed buffer leaked policy into a later render');
}
// A buffer registered between the two policies must retain its original position.
ob_start();
tk_seo_metadata_buffer('content', array('title' => 'Explicit'), true);
ob_start(function ($content) { return str_replace('content="Global Author"', 'content="Integration Author"', $content); });
tk_seo_metadata_buffer('global', array('author' => 'Global Author'), true);
echo $html; ob_end_flush(); ob_end_flush(); ob_end_flush(); $output = ob_get_clean();
buffer_check(strpos($output, 'content="Integration Author"') !== false && strpos($output, '<title>Explicit</title>') !== false, 'Intervening third-party buffer ordering changed');
http_response_code(404); ob_start(); tk_seo_metadata_buffer('global', array('title' => 'Wrong'), true); echo $html; ob_end_flush(); $output = ob_get_clean();
buffer_check($output === $html, 'Error response was rewritten');
http_response_code(200); ob_start(); tk_seo_metadata_buffer('global', array('author' => 'Fresh'), false); echo $html; ob_end_flush(); $output = ob_get_clean();
buffer_check(strpos($output, '<title>Old</title>') !== false && strpos($output, 'Fresh') !== false && strpos($output, 'Page description') === false, 'A new buffer inherited stale page metadata');
echo "PASS: one metadata buffer, page/global precedence in either registration order, late theme output, unchanged large body/scripts/canonical, HTTP guards and render isolation\n";
