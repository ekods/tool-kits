<?php
// Standalone fixtures: no live WordPress options or content are changed.
define('ABSPATH', '/tmp/');
define('WPSEO_VERSION', 'fixture');
$options = array('seo_enabled' => 1, 'seo_title_override_enabled' => 1);
$state = array('front' => true, 'singular' => false, 'home' => false, 'archive' => false, 'search' => false, '404' => false, 'admin' => false, 'ajax' => false, 'feed' => false);
$licensed = true; $authorized = true; $purges = 0; $hooks = array(); $query = array();
$site = 'Acme &amp; Co'; $tagline = 'WordPress Tagline'; $page_title = 'About Us';
$_SERVER['REQUEST_METHOD'] = 'GET';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function tk_require_admin_post($action) { check($action === 'tk_seo_global_save', 'Wrong authorization action'); if (!$GLOBALS['authorized']) { throw new RuntimeException('Unauthorized'); } }
function tk_post($key, $default = '') { return $_POST[$key] ?? $default; }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function sanitize_textarea_field($text) { return sanitize_text_field($text); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false); }
function esc_attr($text) { return esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_url_raw($text, $protocols = null) { return preg_match('~^https?://~i', $text) ? $text : ''; }
function __($text, $domain) { return $text; }
function number_format_i18n($number) { return (string) $number; }
function checked($value, $expected = true) { if ($value == $expected) { echo 'checked'; } }
function tk_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
function wp_die($message) { throw new RuntimeException($message); }
class TitleRedirect extends RuntimeException {}
function wp_safe_redirect($url) { throw new TitleRedirect($url); }
function admin_url($path) { return 'https://example.com/wp-admin/' . $path; }
function tk_page_cache_purge() { $GLOBALS['purges']++; }
function is_admin() { return $GLOBALS['state']['admin']; }
function wp_doing_ajax() { return $GLOBALS['state']['ajax']; }
function is_feed() { return $GLOBALS['state']['feed']; }
function is_front_page() { return $GLOBALS['state']['front']; }
function is_singular() { return $GLOBALS['state']['singular']; }
function is_home() { return $GLOBALS['state']['home']; }
function is_archive() { return $GLOBALS['state']['archive']; }
function is_search() { return $GLOBALS['state']['search']; }
function is_404() { return $GLOBALS['state']['404']; }
function get_bloginfo($key) { return $key === 'name' ? $GLOBALS['site'] : $GLOBALS['tagline']; }
function get_the_title($id) { check($id === 42, 'Title taken from the loop instead of queried page'); return $GLOBALS['page_title']; }
function get_queried_object_id() { return 42; }
function get_post_type($id) { return 'page'; }
function get_post_types($args, $output) { return array('page' => (object) array('name' => 'page')); }
function is_post_type_viewable($type) { return true; }
function get_post_meta($id, $key, $single) { return ''; }
function get_query_var($key) { return $GLOBALS['query'][$key] ?? 0; }
function get_the_archive_title() { return 'Category: News'; }
function get_search_query($escaped) { return 'design & code'; }
function get_theme_mod($key, $default = '') { return $default; }
function wp_get_theme() { return null; }
function apply_filters($hook, $value) { return $hook === 'tk_seo_theme_managed_seo' ? true : $value; }
function add_filter($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook] = array($callback, $priority); }
function add_action($hook, $callback, $priority = 10) { add_filter($hook, $callback, $priority); }
require dirname(__DIR__) . '/includes/seo-optimization.php';
tk_seo_title_init();
check($hooks['pre_get_document_title'] === array('tk_seo_title_filter', PHP_INT_MAX) && isset($hooks['wp_title']), 'Native and legacy title hooks missing');
check(tk_seo_has_third_party_plugin() && tk_seo_has_theme_managed_seo(), 'Conflict fixtures not active');
check(tk_seo_title_filter('Plugin title') === 'Acme &amp; Co - WordPress Tagline', 'Theme/plugin conflict blocked override');
// Both latest-posts and static front pages must ignore a page-specific title.
$state['singular'] = true;
check(tk_seo_title_value() === 'Acme & Co - WordPress Tagline', 'Static front page formula incorrect');
$state['front'] = false;
check(tk_seo_title_value() === 'About Us - Acme & Co', 'Page title formula incorrect');
$options['seo_title_separator'] = '•';
check(tk_seo_title_value() === 'About Us • Acme & Co', 'Custom Unicode separator ignored');
$state['singular'] = false; $state['home'] = true; $page_title = 'Blog';
check(tk_seo_title_value() === 'Blog • Acme & Co', 'Separate posts page used homepage tagline');
$state['home'] = false; $state['archive'] = true; $query['paged'] = 3;
check(tk_seo_title_value() === 'Category: News • Page 3 • Acme & Co', 'Archive/pagination title lost');
$state['archive'] = false; $query = array(); $state['search'] = true;
check(tk_seo_title_filter('Old') === 'Search Results for “design &amp; code” • Acme &amp; Co', 'Search title incorrectly escaped');
$state['search'] = false; $state['404'] = true;
check(tk_seo_title_value() === 'Page not found • Acme & Co', '404 title lost');
$state['404'] = false; $state['front'] = true; $tagline = '';
check(tk_seo_title_value() === 'Acme & Co', 'Empty tagline leaves dangling separator');
$tagline = 'WordPress Tagline';

// Keep raw-text samples, SVG titles, metadata, schema and body byte-for-byte.
$before = '<!doctype html><html><head data-x=">">';
$after = '<meta name="description" content="Existing"><meta property="og:title" content="Existing social title"><script type="application/ld+json">{"name":"Keep me"}</script></head><body><svg><title>Icon title</title></svg><h1>Keep body</h1></body></html>';
$samples = '<!-- <title>Comment demo</title> --><script>const sample = "</head><title>JS demo</title>";</script><style>/* <title>CSS demo</title> */</style><template><title>Template demo</title></template>';
$html = $before . $samples . '<TITLE data-x=">">Theme title</TITLE><title>Plugin title</title><title></title>' . $after;
$expected = $before . $samples . '<title>A &amp; B &lt; C</title>' . $after;
$fixed = tk_seo_title_replace_html($html, 'A & B < C');
check($fixed === $expected, 'Duplicate titles not replaced cleanly or unrelated HTML changed');
check(tk_seo_title_replace_html($fixed, 'A & B < C') === $fixed, 'Title replacement not idempotent');
check(tk_seo_title_replace_html('<html><head></head><body>Keep</body></html>', 'New') === '<html><head><title>New</title>' . "\n" . '</head><body>Keep</body></html>', 'Missing title not inserted');
foreach (array('{"title":"API"}', '<rss><title>Feed</title></rss>', '<html><head><title>Partial</title>') as $non_html) {
    check(tk_seo_title_replace_html($non_html, 'New') === $non_html, 'Non-HTML/partial response changed');
}

// Opt-out and request guards must skip filters and allocation of buffers.
$level = ob_get_level();
foreach (array('admin', 'ajax', 'feed') as $flag) {
    $state[$flag] = true;
    check(tk_seo_title_filter('Original') === 'Original', 'Unsafe title filter: ' . $flag);
    tk_seo_title_start(); check(ob_get_level() === $level, 'Unsafe response buffered: ' . $flag);
    $state[$flag] = false;
}
foreach (array('seo_enabled', 'seo_title_override_enabled') as $key) {
    $options[$key] = 0; tk_seo_title_start();
    check(ob_get_level() === $level && tk_seo_title_filter('Original') === 'Original', 'Disabled setting ignored: ' . $key);
    $options[$key] = 1;
}
$licensed = false; tk_seo_title_start(); check(ob_get_level() === $level, 'License guard bypassed'); $licensed = true;
$_SERVER['REQUEST_METHOD'] = 'POST'; tk_seo_title_start(); check(ob_get_level() === $level, 'POST buffered');
$_SERVER['REQUEST_METHOD'] = 'GET';
foreach (array(200, 301, 404, 500) as $status) {
    http_response_code($status); ob_start(); tk_seo_title_start(); echo $html; ob_end_flush(); $output = ob_get_clean();
    $expected_title = tk_seo_title_value();
    check($output === (in_array($status, array(200, 404), true) ? tk_seo_title_replace_html($html, $expected_title) : $html), 'HTTP response handling failed: ' . $status);
}
http_response_code(200);

// Save through the existing nonce/capability-checked form; reject invalid input
// before any global options change, and purge stale titles after valid saves.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('tk_seo_title_settings_present' => '1', 'seo_title_override_enabled' => '1', 'seo_title_separator' => ' | ');
try { tk_seo_global_save(); } catch (TitleRedirect $e) {}
check($options['seo_title_override_enabled'] === 1 && $options['seo_title_separator'] === '|' && $purges === 1, 'Override settings not saved/purged');
$before_options = $options;
$authorized = false;
try { tk_seo_global_save(); throw new LogicException('Unauthorized save accepted'); } catch (RuntimeException $e) { check($e->getMessage() === 'Unauthorized', 'Authorization failed'); }
check($options === $before_options, 'Unauthorized mutation'); $authorized = true;
foreach (array(array('bad'), 'separator-too-long') as $invalid) {
    $_POST['seo_title_separator'] = $invalid;
    try { tk_seo_global_save(); throw new LogicException('Invalid separator accepted'); } catch (RuntimeException $e) { check(strpos($e->getMessage(), 'separator') !== false, 'Unexpected separator error'); }
    check($options === $before_options, 'Invalid separator partially saved settings');
}
$_POST['seo_title_separator'] = ''; unset($_POST['seo_title_override_enabled']);
try { tk_seo_global_save(); } catch (TitleRedirect $e) {}
check($options['seo_title_override_enabled'] === 0 && $options['seo_title_separator'] === '-', 'Disable/default separator save failed');
$_POST = array('seo_global_enabled' => '1');
try { tk_seo_global_save(); } catch (TitleRedirect $e) {}
check($options['seo_title_override_enabled'] === 0 && $options['seo_title_separator'] === '-', 'Legacy global form unexpectedly changed title settings');
ob_start(); tk_seo_global_render_panel(); $panel = ob_get_clean();
check(strpos($panel, 'name="seo_title_override_enabled"') !== false && strpos($panel, 'name="seo_title_separator"') !== false, 'Title controls missing');
check(strpos($panel, 'Global Metadata Override or Meta Title Override') !== false, 'Plugin conflict message hides override availability');
define('REST_REQUEST', true);
tk_seo_title_start(); check(ob_get_level() === $level && tk_seo_title_filter('API title') === 'API title', 'REST response modified');
echo "PASS: front/static/blog/page/archive/search/404 titles, separators, theme/plugin override, duplicate removal, escaping, unchanged metadata/body, request guards, secure save and cache purge\n";
