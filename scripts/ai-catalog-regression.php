<?php
/** Standalone: php plugins/tool-kits/scripts/ai-catalog-regression.php */
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/geo.php';

$options = array('geo_ai_catalog_enabled' => 1, 'geo_llms_enabled' => 1);
$licensed = true;
$admin = false;
$home_path = '';
$site_origin = 'https://example.test';
$site_name = 'Café <b>Site</b>';
$types = array('post' => 'post', 'page' => 'page', 'product' => 'product', 'attachment' => 'attachment');
$queries = array();
$today = '2026-10-16';
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
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_post($key, $default = '') { return isset($_POST[$key]) ? stripslashes($_POST[$key]) : $default; }
function wp_date($format, $timestamp = null) { return $timestamp === null ? $GLOBALS['today'] : gmdate($format, $timestamp); }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function sanitize_textarea_field($text) { return trim(strip_tags($text)); }
class WP_Error {
    private $message;
    function __construct($code, $message) { $this->message = $message; }
    function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function is_admin() { return $GLOBALS['admin']; }
function get_bloginfo($key) { return array('name' => $GLOBALS['site_name'], 'description' => 'A public catalog', 'language' => 'id-ID')[$key] ?? ''; }
function home_url($path) { return $GLOBALS['site_origin'] . $GLOBALS['home_path'] . $path; }
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
function get_permalink($post) { return $GLOBALS['permalinks'][$post->ID] ?? home_url('/content-' . $post->ID . '/'); }
function get_the_title($post) { return $post->post_title; }
function get_post_modified_time($format, $gmt, $post) { return '2026-10-16T00:00:00+00:00'; }
function wp_strip_all_tags($text, $remove_breaks = false) { return trim(strip_tags(preg_replace('#<script\b[^>]*>.*?</script>#si', '', $text))); }
function strip_shortcodes($text) { return preg_replace('#\[secret\].*?\[/secret\]#s', '', $text); }
function wp_trim_words($text, $limit, $more) { $words = preg_split('/\s+/', trim($text)); return implode(' ', array_slice($words, 0, $limit)) . (count($words) > $limit ? $more : ''); }
function esc_url_raw($url) { return $url; }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES); }
function has_filter($hook) { return false; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function untrailingslashit($path) { return rtrim($path, '/'); }
function status_header($code) { check_catalog($code === 200, 'Wrong HTTP status'); }
function nocache_headers() {}
function add_action($hook, $callback, $priority = 10) { $GLOBALS['actions'][$hook][$priority][] = $callback; }
function add_filter($hook, $callback, $priority = 10) { $GLOBALS['filters'][$hook][$priority][] = $callback; }
function add_shortcode($tag, $callback) {}

tk_geo_init();
check_catalog(in_array('tk_geo_ai_catalog_maybe_render', $actions['wp_loaded'][0] ?? array(), true), 'JSON handler must precede request routing and 404 redirects');
check_catalog(in_array('tk_geo_ai_catalog_discovery_headers', $filters['wp_headers'][10] ?? array(), true), 'HTTP discovery links not registered');
check_catalog(in_array('tk_geo_ai_catalog_discovery_head', $actions['wp_head'][2] ?? array(), true), 'HTML discovery links not registered');

// Child processes exercise the handler's exit and fall-through behavior.
if (isset($argv[1])) {
    $_SERVER['REQUEST_URI'] = '/ai-catalog.json';
    switch ($argv[1]) {
        case 'disabled': $options['geo_ai_catalog_enabled'] = 0; break;
        case 'unlicensed': $licensed = false; break;
        case 'admin': $admin = true; break;
        case 'other-path': $_SERVER['REQUEST_URI'] = '/other.json'; break;
        case 'lookalike': $_SERVER['REQUEST_URI'] = '/nested/.well-known/ard.json'; break;
        case 'ard': $_SERVER['REQUEST_URI'] = '/.well-known/ard.json'; break;
        case 'ard-legacy': $_SERVER['REQUEST_URI'] = '/.well-known/ai-catalog.json?cache=1'; break;
        case 'ard-disabled':
            $options['geo_ai_catalog_enabled'] = 0;
            $_SERVER['REQUEST_URI'] = '/.well-known/ard.json';
            break;
        case 'ard-subfolder':
            $home_path = '/wordpress';
            $_SERVER['REQUEST_URI'] = '/wordpress/.well-known/ard.json/';
            break;
        case 'manual':
            $options['geo_ai_catalog_mode'] = 'manual';
            $options['geo_ai_catalog_entries'] = tk_geo_ai_catalog_example_entries();
            break;
        case 'subfolder':
            $home_path = '/wordpress';
            $_SERVER['REQUEST_URI'] = '/wordpress/ai-catalog.json/?download=1';
            $_GET['download'] = '1';
            break;
    }
    // A template_redirect 404 handler or HTML buffer would run after this hook.
    foreach ($actions['wp_loaded'][0] as $callback) { call_user_func($callback); }
    echo 'FALLTHROUGH';
    exit;
}

$query_count = count($queries);
$manifest = tk_geo_build_ard_manifest();
check_catalog($manifest === array('specVersion' => '1.0', 'host' => array('displayName' => 'Café Site'), 'entries' => array()), 'ARD schema or honest capability declarations changed');
check_catalog(count($queries) === $query_count, 'ARD unnecessarily queried all site content');
$headers = tk_geo_ai_catalog_discovery_headers(array('Link' => '<https://example.test/wp-json/>; rel="https://api.w.org/"', 'X-Test' => 'kept'));
check_catalog(strpos($headers['Link'], 'rel="https://api.w.org/"') !== false && strpos($headers['Link'], '/.well-known/ard.json>; rel="ard"') !== false && strpos($headers['Link'], '/.well-known/ai-catalog.json>; rel="ai-catalog"') !== false && $headers['X-Test'] === 'kept', 'Discovery headers replaced existing links or omitted ARD');
ob_start(); tk_geo_ai_catalog_discovery_head(); $head = ob_get_clean();
check_catalog(strpos($head, 'rel="ard"') !== false && strpos($head, 'rel="ai-catalog"') !== false && strpos($head, 'href="https://example.test/ai-catalog.json"') === false, 'Discovery advertised the custom schema as ARD');
foreach (array('disabled', 'unlicensed') as $gate) {
    $options['geo_ai_catalog_enabled'] = $gate === 'disabled' ? 0 : 1;
    $licensed = $gate !== 'unlicensed';
    ob_start(); tk_geo_ai_catalog_discovery_head(); $head = ob_get_clean();
    check_catalog($head === '' && tk_geo_ai_catalog_discovery_headers(array('Link' => 'existing')) === array('Link' => 'existing'), 'Disabled ARD advertised discovery links');
}
$options['geo_ai_catalog_enabled'] = 1; $licensed = true;
$home_path = '/wordpress';
check_catalog(strpos(tk_geo_ai_catalog_discovery_headers(array())['Link'], '/wordpress/.well-known/ard.json') !== false, 'Subfolder discovery URL missing');
$home_path = '';

// Catalog order: website profile, one entry per AI Radar platform, then public content.
$first_post = 1 + count(tk_geo_ai_radar_platforms());
$catalog = tk_geo_build_ai_catalog();
check_catalog(array_keys($catalog) === array('version', 'last_updated', 'catalog') && $catalog['version'] === '1.0.0' && $catalog['last_updated'] === '2026-10-16', 'Requested catalog document shape missing');
check_catalog(count($catalog['catalog']) === $first_post + 205 && count($queries) === 2, 'Pagination omitted website profile, Radar platforms or public content');
check_catalog($catalog['catalog'][$first_post + 204]['category'] === 'product', 'Public custom post type omitted');
check_catalog($catalog['catalog'][$first_post]['description'] === 'Résumé excerpt', 'Manual excerpt not preferred');
check_catalog($catalog['catalog'][$first_post + 1]['description'] === 'Public summary', 'Shortcode or script content leaked');
check_catalog(array_keys($catalog['catalog'][0]) === array('id', 'name', 'provider', 'category', 'description', 'website', 'features', 'pricing', 'tags', 'status'), 'Requested entry fields missing');
check_catalog($catalog['catalog'][$first_post]['id'] === 'post-1' && $catalog['catalog'][$first_post]['provider'] === 'Café Site' && $catalog['catalog'][$first_post]['features'] === array() && $catalog['catalog'][7]['status'] === 'active', 'Automatic entry mapping failed');
check_catalog($catalog['catalog'][0]['id'] === 'site-home' && $catalog['catalog'][0]['name'] === 'Café Site' && $catalog['catalog'][0]['website'] === home_url('/') && $catalog['catalog'][0]['description'] === 'A public catalog', 'Current website profile not generated');
$platforms = tk_geo_ai_radar_platforms();
$platform_entries = array_slice($catalog['catalog'], 1, count($platforms));
check_catalog(array_column($platform_entries, 'name') === array_column(array_values($platforms), 'name'), 'Catalog platform list drifted from AI Radar');
foreach ($platform_entries as $entry) {
    check_catalog($entry['status'] === 'unknown' && $entry['pricing'] === '' && strpos($entry['description'], 'Café Site (' . home_url('/') . ')') !== false, 'No-scan catalog invented eligibility, pricing or site context');
}
$rows = array();
foreach ($platforms as $platform) { foreach ($platform['agents'] as $agent) { $rows[] = array('agent' => $agent, 'ok' => true); } }
$radar = tk_geo_build_ai_radar(array('url' => home_url('/'), 'score' => 100), array('score' => 100, 'crawler_fetch_results' => $rows));
check_catalog(array_column($radar['engines'], 'name') === array_column($platform_entries, 'name'), 'Radar and catalog are not using the same platform registry');
$json = tk_geo_build_ai_catalog_json();
check_catalog(json_decode($json, true) === $catalog && strpos($json, 'Café') !== false && strpos($json, 'DO NOT EXPORT') === false, 'JSON encoding or content visibility failed');
$types = array();
check_catalog(count(tk_geo_build_ai_catalog()['catalog']) === $first_post, 'Sites without published posts lost their profile or Radar platforms');
$options['geo_ai_radar_report'] = array('url' => home_url('/'), 'scanned_at' => strtotime('2026-10-17T00:00:00Z'), 'engines' => array(
    array('name' => 'Google AI', 'status' => 'eligible'), array('name' => 'ChatGPT Search', 'status' => 'blocked'),
    array('name' => 'Claude', 'status' => 'partial'), array('name' => 'Perplexity', 'status' => 'invalid-value'), 'bad-row',
));
$with_radar = tk_geo_build_ai_catalog();
check_catalog(array_column(array_slice($with_radar['catalog'], 1), 'status') === array('eligible', 'blocked', 'partial', 'unknown', 'unknown', 'unknown'), 'Saved Radar access status not mapped safely');
check_catalog($with_radar['last_updated'] === '2026-10-17', 'New Radar scan did not advance catalog date');
$options['geo_ai_radar_report']['url'] = 'https://previous-site.test/';
$migrated = tk_geo_build_ai_catalog();
check_catalog(count(array_filter(array_slice($migrated['catalog'], 1), static fn($entry) => $entry['status'] !== 'unknown')) === 0 && $migrated['last_updated'] === '2026-10-16', 'Foreign-site report contaminated catalog statuses or date');
$site_origin = 'https://different-site.test'; $site_name = 'Another Site';
$other_site = tk_geo_build_ai_catalog();
check_catalog($other_site['catalog'][0]['name'] === 'Another Site' && $other_site['catalog'][0]['website'] === home_url('/') && strpos($other_site['catalog'][1]['description'], 'Another Site (' . home_url('/') . ')') !== false, 'Automatic catalog is tied to a hardcoded website');
$site_origin = 'https://example.test'; $site_name = 'Café <b>Site</b>';
$options['geo_ai_radar_report'] = array();
$types = array('post' => 'post', 'page' => 'page', 'product' => 'product', 'attachment' => 'attachment');
$permalinks = array(1 => home_url('/'));
$deduplicated = tk_geo_build_ai_catalog();
check_catalog(count($deduplicated['catalog']) === $first_post + 204 && count(array_filter($deduplicated['catalog'], static fn($entry) => $entry['website'] === home_url('/'))) === 1, 'Static homepage was exported twice');
$permalinks = array();

$example = tk_geo_ai_catalog_example_entries();
$document = array('version' => '1.0.0', 'last_updated' => '2026-10-16', 'catalog' => $example);
check_catalog(tk_geo_ai_catalog_parse_entries(wp_json_encode($example)) === $example, 'Example entries cannot be pasted');
check_catalog(tk_geo_ai_catalog_parse_entries(wp_json_encode($document)) === $example, 'Complete user document cannot be pasted');
foreach (array('{bad', 'null', '42', '{}', '{"catalog":{}}', '[42]', '[{"id":"x","name":"X","website":"javascript:alert(1)"}]', '[{"id":"x","name":"X","website":"https://user:pass@example.test"}]') as $invalid) {
    check_catalog(is_wp_error(tk_geo_ai_catalog_parse_entries($invalid)), 'Invalid catalog accepted: ' . $invalid);
}
$duplicate = array($example[0], $example[0]);
check_catalog(is_wp_error(tk_geo_ai_catalog_parse_entries(wp_json_encode($duplicate))), 'Duplicate IDs accepted');
$invalid = $example; $invalid[0]['features'] = 'text';
check_catalog(is_wp_error(tk_geo_ai_catalog_parse_entries(wp_json_encode($invalid))), 'Features must be an array');
$invalid = $example; $invalid[0]['tags'] = array(42);
check_catalog(is_wp_error(tk_geo_ai_catalog_parse_entries(wp_json_encode($invalid))), 'Tags must contain text');
$_POST = array();
check_catalog(tk_geo_ai_catalog_posted_settings() === null, 'Unrelated GEO save overwrites catalog configuration');
$_POST = array('geo_ai_catalog_use_example' => '1');
$settings = tk_geo_ai_catalog_posted_settings();
check_catalog($settings === array('mode' => 'manual', 'entries' => $example), 'Example button did not select manual mode');
tk_geo_ai_catalog_save_settings($settings);
check_catalog(tk_geo_build_ai_catalog() === $document, 'Manual output differs from the supplied example');
$query_count = count($queries); tk_geo_build_ai_catalog_json();
check_catalog(count($queries) === $query_count, 'Manual catalog queried website content');
$today = '2026-10-17'; tk_geo_ai_catalog_save_settings($settings);
check_catalog(tk_geo_build_ai_catalog()['last_updated'] === '2026-10-16', 'Unchanged catalog date advanced on save');
$settings['entries'][0]['description'] = 'Updated description'; tk_geo_ai_catalog_save_settings($settings);
check_catalog(tk_geo_build_ai_catalog()['last_updated'] === '2026-10-17', 'Changed catalog date was not updated');
$saved = $options;
$_POST = array('geo_ai_catalog_settings_present' => '1', 'geo_ai_catalog_mode' => 'manual', 'geo_ai_catalog_entries' => '{bad');
check_catalog(is_wp_error(tk_geo_ai_catalog_posted_settings()) && $options === $saved, 'Malformed input altered saved catalog');
$escaped = $example; $escaped[0]['description'] = 'A "quoted" path C:\\tools';
$_POST['geo_ai_catalog_entries'] = addslashes(wp_json_encode(array('catalog' => $escaped)));
check_catalog(tk_geo_ai_catalog_posted_settings()['entries'] === $escaped, 'Quotes or backslashes were lost when reading the editor');
$_POST = array('geo_ai_catalog_generate_auto' => '1', 'geo_ai_catalog_mode' => 'manual', 'geo_ai_catalog_entries' => '{bad');
$generated = tk_geo_build_ai_catalog(true)['catalog'];
$before_generate = $options;
$auto_settings = tk_geo_ai_catalog_posted_settings();
check_catalog($auto_settings === array('mode' => 'manual', 'entries' => $generated), 'Generation did not replace stale editor contents with a fresh editable website/Radar snapshot');
check_catalog($before_generate === $options, 'Generation wrote settings before the authorized shared save handler');
tk_geo_ai_catalog_save_settings($auto_settings);
check_catalog(count($options['geo_ai_catalog_entries']) === $first_post + 205 && $options['geo_ai_catalog_entries'] === $generated, 'Generated results were not saved to the Custom catalog entries editor');
check_catalog(tk_geo_build_ai_catalog()['catalog'] === $generated, 'Published catalog differs from generated editor contents');
$generated[0]['description'] = 'Edited "website" description C:\\catalog';
$_POST = array('geo_ai_catalog_settings_present' => '1', 'geo_ai_catalog_mode' => 'manual', 'geo_ai_catalog_entries' => addslashes(wp_json_encode($generated)));
$edited_settings = tk_geo_ai_catalog_posted_settings();
check_catalog(!is_wp_error($edited_settings), 'Generated entries cannot be edited and saved');
tk_geo_ai_catalog_save_settings($edited_settings);
check_catalog(tk_geo_build_ai_catalog()['catalog'] === $generated, 'Saved edits to generated entries were ignored by public output');
$site_name = 'Updated Site';
$_POST = array('geo_ai_catalog_generate_auto' => '1');
$regenerated = tk_geo_ai_catalog_posted_settings();
check_catalog($regenerated['entries'][0]['name'] === 'Updated Site' && $regenerated['entries'][0]['description'] === 'A public catalog', 'Regeneration reused the active custom catalog instead of current website data');
$site_name = '';
$posts[0]->post_title = '';
$untitled = tk_geo_ai_catalog_posted_settings();
check_catalog(!is_wp_error($untitled) && $untitled['entries'][0]['name'] === 'example.test' && $untitled['entries'][$first_post]['name'] === 'Untitled post #1', 'Untitled public content prevents generating an editable catalog');
$site_name = 'Café <b>Site</b>';
$posts[0]->post_title = 'Café "1"';

foreach (array('enabled', 'manual', 'subfolder', 'ard', 'ard-legacy', 'ard-subfolder', 'ard-disabled', 'disabled', 'unlicensed', 'admin', 'other-path', 'lookalike') as $mode) {
    $process = proc_open(array(PHP_BINARY, __FILE__, $mode), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    check_catalog(is_resource($process), 'Could not start endpoint check');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check_catalog(proc_close($process) === 0 && $errors === '', 'Endpoint error: ' . $mode . ' ' . $errors);
    if (in_array($mode, array('ard', 'ard-legacy', 'ard-subfolder'), true)) {
        check_catalog(json_decode($output, true) === $manifest, 'ARD endpoint returned HTML, a redirect, or the custom catalog schema: ' . $mode);
    } elseif ($mode === 'enabled' || $mode === 'subfolder' || $mode === 'manual') {
        $response = json_decode($output, true);
        check_catalog(is_array($response) && count($response['catalog']) === ($mode === 'manual' ? 2 : $first_post + 205), 'Invalid endpoint JSON: ' . $mode);
        if ($mode === 'manual') check_catalog($response === $document, 'Manual endpoint differs from requested example');
        if ($mode === 'subfolder') check_catalog($response['catalog'][0]['website'] === 'https://example.test/wordpress/' && $response['catalog'][$first_post]['website'] === 'https://example.test/wordpress/content-1/', 'Subfolder URL lost');
    } else {
        check_catalog($output === 'FALLTHROUGH', 'Endpoint ignores gate or path: ' . $mode);
    }
}
echo "PASS: website + shared AI Radar catalog, saved status/site isolation, dates, homepage deduplication, custom editor, auto generation, privacy/pagination, ARD, licensing and subfolder endpoints\n";
