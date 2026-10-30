<?php
// <img> rewriting must not touch scripts/JSON-LD; inline JS minify must keep valid code.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(function ($level, $message) { throw new RuntimeException($message); });
define('ABSPATH', '/tmp/');
$checks = 0;
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; echo 'PASS ' . $message . PHP_EOL; }
function apply_filters($hook, $value, ...$args) { return $value; }
function add_action(...$args) {}
function add_filter(...$args) {}
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function tk_get_option($name, $default = null) { return array('lazy_load_eager_images' => 1)[$name] ?? $default; }

require dirname(__DIR__) . '/includes/lazy-load.php';
$html = '<html><head><script type="application/ld+json">{"description":"<img src=\"x.png\">"}</script>'
    . '<script>var tpl = "<img src=\'/a.png\'>";</script></head><body><!-- <img src="old.png"> -->'
    . '<img src="hero.jpg"><p>Text</p><img src="second.jpg"></body></html>';
$out = tk_lazy_html_buffer($html);
check(strpos($out, '{"description":"<img src=\"x.png\">"}') !== false, 'lazy load leaves <img> text inside JSON-LD untouched');
check(strpos($out, 'var tpl = "<img src=\'/a.png\'>";') !== false, 'lazy load leaves <img> strings inside scripts untouched');
check(strpos($out, '<!-- <img src="old.png"> -->') !== false, 'lazy load leaves commented-out images untouched');
check(preg_match('/<img[^>]*loading="eager"[^>]*src="hero.jpg"/', $out) === 1, 'the first real image keeps the eager slot');
check(preg_match('/<img[^>]*loading="lazy"[^>]*src="second.jpg"/', $out) === 1, 'later real images are lazy loaded');

require dirname(__DIR__) . '/includes/minify.php';
$js_cases = array(
    "var t = `Line one\nVisit http://example.com now`;" => "var t = `Line one\nVisit http://example.com now`;",
    "return /^https?:\\/\\//.test(u) // comment" => "return /^https?:\\/\\//.test(u)",
    "var s = \"a/*b*/c\"; /* gone */ var d = 10 / 2 / 5;" => "var s = \"a/*b*/c\"; var d = 10 / 2 / 5;",
    'var n = `a ${ `b ${ x }` } c` // tail' => 'var n = `a ${ `b ${ x }` } c`',
    "var x = 1 // keep newline\n+ 2;" => "var x = 1\n+ 2;",
);
foreach ($js_cases as $source => $expected) {
    check(tk_minify_inline_js($source) === $expected, 'inline JS minify keeps ' . json_encode(strtok($source, "\n")));
}

function esc_url($url) { return $url; }
$tag = "<script id=\"wpcf7-js-translations\">wp.i18n.setLocaleData({});</script>\n"
    . "<script id=\"wpcf7-js-before\">var wpcf7 = {\"api\":{}};</script>\n"
    . "<script src=\"https://example.test/cf7.js\" id=\"wpcf7-js\"></script>\n"
    . "<script id=\"wpcf7-js-after\">wpcf7.init();</script>\n";
$delayed = tk_script_delay_tag($tag, 'https://example.test/cf7.js', 'data-tk-delay="1"', 'data-tk-src');
check(strpos($delayed, '<script id="wpcf7-js-translations">wp.i18n.setLocaleData({});</script>') !== false, 'delay JS keeps translation scripts executable');
check(strpos($delayed, '<script id="wpcf7-js-before">var wpcf7 = {"api":{}};</script>') !== false, 'delay JS keeps before scripts executable');
check(strpos($delayed, '<script type="text/plain" data-tk-delay="1" data-tk-src="https://example.test/cf7.js"></script>') !== false, 'delay JS replaces only the external script with a placeholder');
check(strpos($delayed, '<script type="text/plain" data-tk-delay="1" data-tk-inline="1" id="wpcf7-js-after">wpcf7.init();</script>') !== false, 'delay JS defers after scripts with their file');
check(substr_count($delayed, 'data-tk-src=') === 1, 'delay JS adds one loader entry per external file');

echo $checks . " HTML rewrite checks passed." . PHP_EOL;
