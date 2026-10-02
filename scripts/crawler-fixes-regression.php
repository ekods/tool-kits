<?php
define('ABSPATH', '/tmp/');
$_SERVER['REQUEST_METHOD'] = 'POST';
$options = array(); $posted = array(); $authorized = true; $licensed = true; $purges = 0; $fetches = 0;
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function wp_strip_all_tags($s) { return strip_tags($s); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_url_raw($s) { return $s; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '/') { return 'https://example.com' . $path; }
function wp_json_encode($s, $flags = 0) { return json_encode($s, $flags); }
function tk_csp_nonce_attr() { return ' nonce="test-nonce"'; }
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_post($key, $default = '') { return $GLOBALS['posted'][$key] ?? $default; }
function tk_require_admin_post($action) { if (!$GLOBALS['authorized']) { throw new RuntimeException('Unauthorized'); } check(in_array($action, array('tk_geo_crawler_fix', 'tk_geo_crawler_preview', 'tk_geo_visibility_fix', 'tk_geo_visibility_scan')), 'Wrong nonce action'); }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function sanitize_textarea_field($s) { return trim(strip_tags($s)); }
function tk_page_cache_purge() { $GLOBALS['purges']++; }
function admin_url($path) { return 'https://example.com/wp-admin/' . $path; }
function wp_die($message) { throw new RuntimeException($message); }
class Redirected extends RuntimeException {}
function wp_safe_redirect($url) { throw new Redirected($url); }
function add_query_arg($params, $url) { return $url . '?' . http_build_query($params); }
function is_wp_error($response) { return false; }
function wp_remote_get($url, $args) {
    $GLOBALS['fetches']++;
    if (substr($url, -10) === 'robots.txt') { return array('status' => 200, 'body' => "User-agent: *\nAllow: /"); }
    if (!empty($GLOBALS['simulate_agent_fail']) && ($args['headers']['User-Agent'] ?? '') !== 'Tool Kits GEO AI Visibility') {
        return array('status' => 502, 'body' => 'Local WAF rejection');
    }
    return array('status' => 200, 'body' => '<html><head><title>Fresh</title></head><body>Content</body></html>');
}
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_retrieve_headers($response) { return array(); }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_feed() { return false; }
function is_preview() { return !empty($GLOBALS['preview']); }
function is_search() { return false; }
function is_404() { return false; }
function is_singular() { return true; }
function post_password_required() { return !empty($GLOBALS['protected']); }
function get_post_status() { return $GLOBALS['post_status'] ?? 'publish'; }
function get_post($id = null) { return (object) array('post_author' => 7); }
function url_to_postid($url) { return strpos($url, '/page/') !== false ? 42 : 0; }
function get_the_author_meta($field, $id) { return $field === 'display_name' && $id === 7 ? 'Editorial Author' : ''; }
function get_bloginfo($field) { return $field === 'name' ? 'Example Publisher' : ''; }
function wp_unslash($value) { return $value; }
function run_action($fn) { try { $fn(); } catch (Redirected $e) { return; } }
require dirname(__DIR__) . '/includes/geo.php';

$fix = array('fields' => array('title', 'description', 'author', 'publisher', 'canonical', 'schema', 'h1'), 'title' => 'A < B & C', 'description' => 'Real page content </script><script>alert(1)</script>', 'author' => 'Editorial Author', 'publisher' => 'Example Publisher');
$url = 'https://example.com/page/';
$html = '<!doctype html><html><head><title> </title><meta content="" name="description"><link href="" rel="canonical"></head><body><div>Original</div></body></html>';
$fixed = tk_geo_crawler_fix_html($html, $url, $fix);
$meta = tk_geo_crawler_metadata($fixed);
check($meta['title'] === $fix['title'] && $meta['description'] === $fix['description'] && $meta['author'] === $fix['author'] && $meta['publisher'] === $fix['publisher'] && $meta['canonical'] === $url && $meta['schema'], 'Missing fields were not filled');
check(substr_count($fixed, '<title>') === 1 && substr_count($fixed, 'name="description"') === 1 && substr_count($fixed, 'rel="canonical"') === 1, 'Empty metadata was duplicated');
check(strpos($fixed, '<div>Original</div>') !== false, 'Original body content changed');
check(strpos($fixed, '<h1 class="tk-geo-fallback-h1" hidden>A &lt; B &amp; C</h1>') !== false, 'Hidden fallback H1 was not added to source');
check(tk_geo_crawler_metadata($fixed)['h1'], 'Hidden fallback H1 was not detected');
check(strpos($fixed, '</script><script>alert') === false && strpos($fixed, 'nonce="test-nonce"') !== false, 'Unsafe JSON-LD or missing nonce');
check(tk_geo_crawler_fix_html($fixed, $url, $fix) === $fixed, 'Repeated fix is not idempotent');
$existing = '<html><head><title>Owned by theme</title><meta content="Existing > description" NAME = description><meta name="author" content="Existing Author"><meta name="publisher" content="Existing Publisher"><link HREF="https://example.com/canonical/" REL = canonical></head><body><h1>Existing heading</h1><script type="application/ld+json">{broken}</script></body></html>';
check(tk_geo_crawler_fix_html($existing, $url, $fix) === $existing, 'Existing metadata/schema overwritten');
$fake = '<html><head><!-- <title>fake</title> --><script>var x = \'<meta name="description" content="fake">\';</script></head><body></body></html>';
check(tk_geo_crawler_metadata($fake)['description'] === '', 'Script/comment mistaken for metadata');
$head_example = '<html><head><script>var closing = "</head>";</script></head><body></body></html>';
check(strpos(tk_geo_crawler_fix_html($head_example, $url, $fix), '<script>var closing = "</head>";</script>') !== false, 'Script head example corrupted');
check(tk_geo_crawler_fix_html('{"data":1}', $url, $fix) === '{"data":1}', 'Non HTML changed');
check(array_keys(tk_geo_crawler_issues(array('ok' => false, 'status' => 403))) === array('fetch'), 'Fetch failure misclassified as missing metadata');
check(isset(tk_geo_crawler_issues(array('ok' => true, 'schema_invalid' => 1))['invalid_schema']), 'Invalid schema not diagnosed');
foreach (array('https://evil.example/page', '//example.com/page', 'https://example.com:444/page', 'https://user@example.com/page', 'https://example.com/page?preview=1', 'http://example.com/page') as $invalid) {
    check(tk_geo_crawler_fix_url($invalid) === '', 'Unsafe URL allowed: ' . $invalid);
}

$options['geo_crawler_preview'] = array('url' => $url, 'ok' => true, 'status' => 200);
$posted = array('crawler_fix_url' => $url, 'crawler_fix_title' => 'Page title', 'crawler_fix_description' => 'Page description');
$authorized = false;
try { tk_geo_crawler_fix_handler(); throw new LogicException('Unauthorized request accepted'); } catch (RuntimeException $e) { check($e->getMessage() === 'Unauthorized', 'Unexpected auth failure'); }
check(empty($options['geo_crawler_fixes']), 'Unauthorized mutation');
$authorized = true;
run_action('tk_geo_crawler_fix_handler');
check(isset($options['geo_crawler_fixes'][$url]) && $purges === 1, 'Fix not persisted or cache not purged');
$before = $options['geo_crawler_fixes'];
$posted['crawler_fix_url'] = 'https://example.com/other/';
try { tk_geo_crawler_fix_handler(); throw new LogicException('Unscanned URL accepted'); } catch (RuntimeException $e) {}
check($options['geo_crawler_fixes'] === $before, 'Unscanned URL mutated');
$posted['crawler_fix_url'] = $url; $posted['crawler_fix_mode'] = 'remove';
run_action('tk_geo_crawler_fix_handler');
check(empty($options['geo_crawler_fixes']) && $purges === 2, 'Removal failed');

$visibility = array(
    'url' => $url, 'status' => 200,
    'snapshot' => array('title' => '', 'description' => '', 'canonical' => '', 'text_sample' => 'Useful page summary for AI visibility.'),
    'schema' => array('documents' => 0, 'invalid' => 0),
    'checks' => array(
        array('name' => 'H1', 'status' => 'warn'),
        array('name' => 'Meta robots', 'status' => 'fail'),
    ),
    'agents' => array(array('agent' => 'GPTBot', 'allowed' => false, 'visible' => false, 'status' => 403)),
);
check(tk_geo_visibility_fixable_fields($visibility) === array('title', 'description', 'author', 'publisher', 'canonical', 'h1', 'schema'), 'Visibility fix fields mismatch');
$recommendations = implode(' ', tk_geo_visibility_recommendations($visibility));
check(strpos($recommendations, 'robots.txt') !== false, 'Visibility recommendations incomplete');
$options['geo_visibility_report'] = $visibility;
$posted = array('visibility_fix_url' => $url, 'visibility_fix_title' => 'AI title', 'visibility_fix_description' => 'AI description');
run_action('tk_geo_visibility_fix_handler');
check($options['geo_crawler_fixes'][$url]['fields'] === array('title', 'description', 'author', 'publisher', 'canonical', 'h1', 'schema'), 'Visibility fix not persisted');
check($options['geo_crawler_fixes'][$url]['author'] === 'Editorial Author' && $options['geo_crawler_fixes'][$url]['publisher'] === 'Example Publisher', 'Author/publisher identity mismatch');
check($options['geo_visibility_report'] === array() && $purges === 3, 'Visibility report/cache not cleared');
$options['geo_visibility_report'] = $visibility;
$posted['visibility_fix_mode'] = 'remove';
run_action('tk_geo_visibility_fix_handler');
check(empty($options['geo_crawler_fixes']) && $purges === 4, 'Visibility fix removal failed');
$invalid_visibility = $visibility;
$invalid_visibility['schema'] = array('documents' => 1, 'invalid' => 1);
check(!in_array('schema', tk_geo_visibility_fixable_fields($invalid_visibility), true), 'Invalid existing schema received duplicate fallback');
$failed_visibility = $visibility; $failed_visibility['status'] = 403;
check(tk_geo_visibility_fixable_fields($failed_visibility) === array(), 'Failed URL offered metadata fix');

$options['geo_visibility_report'] = array(); $simulate_agent_fail = true;
$fetches = 0;
$simulated = tk_geo_run_visibility_scan($url);
check($fetches === 2, 'AI visibility performed per-crawler identity spoofing');
check(count(array_filter($simulated['agents'], function($row) { return ($row['verification'] ?? '') === 'eligible'; })) === count(tk_geo_ai_crawler_agents()), 'Allowed crawlers were not marked eligible');
check(!array_filter($simulated['issues'], function($issue) { return strpos($issue, 'may not be able to consume') !== false; }), 'Crawler eligibility created a false block issue');
$simulate_agent_fail = false; $fetches = 0;

$options['geo_crawler_fixes'] = array($url => $fix);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/other/';
$level = ob_get_level();
tk_geo_crawler_fix_start();
check(ob_get_level() === $level, 'Unrelated page buffered');
$_SERVER['REQUEST_URI'] = '/page/'; $protected = true;
tk_geo_crawler_fix_start();
check(ob_get_level() === $level, 'Password protected page buffered');
$protected = false; $post_status = 'private';
tk_geo_crawler_fix_start();
check(ob_get_level() === $level, 'Private page buffered');
$post_status = 'publish'; $preview = true;
tk_geo_crawler_fix_start();
check(ob_get_level() === $level, 'Preview page buffered');
$preview = false; $licensed = false;
tk_geo_crawler_fix_start();
check(ob_get_level() === $level, 'Unlicensed output changed');
$licensed = true;
foreach (array(200, 500) as $status) {
    http_response_code($status);
    ob_start();
    tk_geo_crawler_fix_start();
    check(ob_get_level() === $level + 2, 'Configured public page not buffered');
    echo $html;
    ob_end_flush();
    $output = ob_get_clean();
    check($status === 200 ? tk_geo_crawler_metadata($output)['canonical'] === $url : $output === $html, 'Response status guard failed');
}
http_response_code(200);
$_SERVER['REQUEST_METHOD'] = 'POST';

$posted = array('crawler_preview_url' => $url);
run_action('tk_geo_crawler_preview_handler');
check($fetches === 0, 'Saved preview unexpectedly crawled');
$posted['crawler_preview_refresh'] = 1;
run_action('tk_geo_crawler_preview_handler');
check($fetches === count(tk_geo_ai_crawler_agents()) && $options['geo_crawler_preview']['title'] === 'Fresh', 'Explicit rescan reused stale results');
echo "PASS: crawler and AI visibility fixes, idempotence, escaping, authorization, URL scope, removal and fresh scans\n";
