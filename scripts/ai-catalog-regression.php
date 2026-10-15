<?php
/** Standalone: php plugins/tool-kits/scripts/ai-catalog-regression.php */
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/geo.php';

$options = array('geo_ai_catalog_enabled' => 1, 'geo_llms_enabled' => 1);
$licensed = true;
$admin = false;
$home_path = '';
$types = array('post' => 'post', 'page' => 'page', 'product' => 'product', 'attachment' => 'attachment');
$queries = array();
$posts = array();
foreach (range(1, 205) as $id) {
    $posts[] = (object) array(
        'ID' => $id, 'post_type' => $id === 205 ? 'product' : 'post',
        'post_status' => 'publish', 'post_password' => '',
        'post_title' => 'Café "' . $id . '"', 'post_excerpt' => '',
        'post_content' => '<p>Public summary</p>[secret]hidden value[/secret]<script>secret()</script>',
    );
}
$posts[0]->post_excerpt = '<b>Résumé</b> excerpt';
foreach (array('draft', 'private', 'password', 'attachment', 'internal') as $index => $kind) {
    $post = clone $posts[1];
    $post->ID = 300 + $index;
    $post->post_title = 'DO NOT EXPORT ' . $kind;
    if ($kind === 'draft' || $kind === 'private') $post->post_status = $kind;
    if ($kind === 'password') $post->post_password = 'secret';
    if ($kind === 'attachment' || $kind === 'internal') $post->post_type = $kind;
    $posts[] = $post;
}

function check_catalog($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function is_admin() { return $GLOBALS['admin']; }
function get_bloginfo($key) { return array('name' => 'Café <b>Site</b>', 'description' => 'A public catalog', 'language' => 'id-ID')[$key] ?? ''; }
function home_url($path) { return 'https://example.test' . $GLOBALS['home_path'] . $path; }
function get_post_types($args, $output) {
    check_catalog(!empty($args['public']) && !empty($args['publicly_queryable']), 'Non-public post types requested');
    return $GLOBALS['types'];
}
class WP_Query {
    public $posts;
    function __construct($args) {
        $GLOBALS['queries'][] = $args;
        check_catalog($args['post_status'] === 'publish' && $args['has_password'] === false, 'Query includes restricted content');
        check_catalog(!in_array('attachment', $args['post_type'], true), 'Query includes attachments');
        $eligible = array_values(array_filter($GLOBALS['posts'], function($post) use ($args) {
            return in_array($post->post_type, $args['post_type'], true) && $post->post_status === 'publish' && $post->post_password === '';
        }));
        $this->posts = array_slice($eligible, ($args['paged'] - 1) * $args['posts_per_page'], $args['posts_per_page']);
    }
}
function is_post_publicly_viewable($post) { return $post->post_status === 'publish' && in_array($post->post_type, array('post', 'page', 'product'), true); }
function get_permalink($post) { return home_url('/content-' . $post->ID . '/'); }
function get_the_title($post) { return $post->post_title; }
function get_post_modified_time($format, $gmt, $post) { return '2026-10-16T00:00:00+00:00'; }
function wp_strip_all_tags($text, $remove_breaks = false) { return trim(strip_tags(preg_replace('#<script\b[^>]*>.*?</script>#si', '', $text))); }
function strip_shortcodes($text) { return preg_replace('#\[secret\].*?\[/secret\]#s', '', $text); }
function wp_trim_words($text, $limit, $more) { $words = preg_split('/\s+/', trim($text)); return implode(' ', array_slice($words, 0, $limit)) . (count($words) > $limit ? $more : ''); }
function esc_url_raw($url) { return $url; }
function has_filter($hook) { return false; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function untrailingslashit($path) { return rtrim($path, '/'); }
function status_header($code) { check_catalog($code === 200, 'Wrong HTTP status'); }
function nocache_headers() {}

// Child processes exercise the handler's exit and fall-through behavior.
if (isset($argv[1])) {
    $_SERVER['REQUEST_URI'] = '/ai-catalog.json';
    switch ($argv[1]) {
        case 'disabled': $options['geo_ai_catalog_enabled'] = 0; break;
        case 'unlicensed': $licensed = false; break;
        case 'admin': $admin = true; break;
        case 'other-path': $_SERVER['REQUEST_URI'] = '/other.json'; break;
        case 'subfolder':
            $home_path = '/wordpress';
            $_SERVER['REQUEST_URI'] = '/wordpress/ai-catalog.json/?download=1';
            $_GET['download'] = '1';
            break;
    }
    tk_geo_ai_catalog_maybe_render();
    echo 'FALLTHROUGH';
    exit;
}

$catalog = tk_geo_build_ai_catalog();
check_catalog(count($catalog['items']) === 205 && count($queries) === 2, 'Pagination omitted public content');
check_catalog($catalog['items'][204]['type'] === 'product', 'Public custom post type omitted');
check_catalog($catalog['items'][0]['description'] === 'Résumé excerpt', 'Manual excerpt not preferred');
check_catalog($catalog['items'][1]['description'] === 'Public summary', 'Shortcode or script content leaked');
check_catalog($catalog['items'][0]['language'] === 'id-ID' && $catalog['items'][0]['modified'] === '2026-10-16T00:00:00+00:00', 'Language or modified metadata missing');
$json = tk_geo_build_ai_catalog_json();
check_catalog(json_decode($json, true) === $catalog && strpos($json, 'Café') !== false && strpos($json, 'DO NOT EXPORT') === false, 'JSON encoding or content visibility failed');
$options['geo_llms_enabled'] = 0;
check_catalog(!isset(tk_geo_build_ai_catalog()['links']['llms']), 'Disabled llms.txt linked');
$types = array();
check_catalog(tk_geo_build_ai_catalog()['items'] === array(), 'Empty catalog not supported');

foreach (array('enabled', 'subfolder', 'disabled', 'unlicensed', 'admin', 'other-path') as $mode) {
    $process = proc_open(array(PHP_BINARY, __FILE__, $mode), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    check_catalog(is_resource($process), 'Could not start endpoint check');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check_catalog(proc_close($process) === 0 && $errors === '', 'Endpoint error: ' . $mode . ' ' . $errors);
    if ($mode === 'enabled' || $mode === 'subfolder') {
        $response = json_decode($output, true);
        check_catalog(is_array($response) && count($response['items']) === 205, 'Invalid endpoint JSON: ' . $mode);
        if ($mode === 'subfolder') check_catalog($response['site']['url'] === 'https://example.test/wordpress/', 'Subfolder URL lost');
    } else {
        check_catalog($output === 'FALLTHROUGH', 'Endpoint ignores gate or path: ' . $mode);
    }
}
echo "PASS: JSON, pagination, Unicode, public custom content, summaries, restricted content, empty catalog, license/setting gates and subfolder routing\n";
