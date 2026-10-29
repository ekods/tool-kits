<?php
// Isolated real WordPress dependency/hook/block APIs and CF7's actual enqueue controller.
// No wp-load, wp-config, database, mail or external HTTP requests.
error_reporting(E_ALL);
set_error_handler(function($level, $message) {
    if (error_reporting() & $level) { throw new RuntimeException($message); }
    return false;
});
$core = rtrim((string) getenv('TK_WP_ROOT'), '/');
$cf7 = rtrim((string) getenv('TK_CF7_ROOT'), '/');
if (!is_file($core . '/wp-includes/plugin.php') || !is_file($cf7 . '/includes/controller.php')) {
    throw new RuntimeException('Set TK_WP_ROOT and TK_CF7_ROOT to local WordPress and Contact Form 7 source directories.');
}
define('ABSPATH', $core . '/');
define('WPINC', 'wp-includes');
define('WPCF7_VERSION', 'fixture');
define('TK_URL', 'https://example.test/wp-content/plugins/tool-kits/');
foreach (array('compat.php', 'plugin.php', 'class-wp-error.php', 'class-wp-dependency.php', 'class-wp-dependencies.php', 'class-wp-scripts.php', 'class-wp-styles.php', 'functions.wp-scripts.php', 'functions.wp-styles.php', 'shortcodes.php', 'class-wp-block-parser.php', 'blocks.php') as $file) {
    require ABSPATH . WPINC . '/' . $file;
}
$state = array();
$options = array();
$checks = 0;
$writes = array();
$shortcode_tags = array('contact-form-7' => '__return_empty_string');
function is_admin() { return $GLOBALS['state']['admin'] ?? false; }
function is_user_logged_in() { return $GLOBALS['state']['logged_in'] ?? false; }
function tk_is_admin_user() { return $GLOBALS['state']['manager'] ?? false; }
function tk_license_features_enabled() { return $GLOBALS['state']['licensed'] ?? true; }
function wp_doing_ajax() { return $GLOBALS['state']['ajax'] ?? false; }
function is_feed() { return $GLOBALS['state']['feed'] ?? false; }
function is_trackback() { return $GLOBALS['state']['trackback'] ?? false; }
function is_robots() { return $GLOBALS['state']['robots'] ?? false; }
function is_singular() { return $GLOBALS['state']['singular'] ?? true; }
function get_queried_object_id() { return 123; }
function get_post($id) { return $GLOBALS['state']['refs'][$id] ?? null; }
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_asset_version($path) { return 'fixture'; }
function __($value, $domain = '') { return $value; }
function tk_update_option($key, $value) { $GLOBALS['writes'][] = $key; $GLOBALS['options'][$key] = $value; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_unslash($value) { return stripslashes($value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function nocache_headers() { $GLOBALS['state']['no_cache'] = true; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function home_url($path) { return 'https://example.test' . $path; }
function wp_safe_redirect($url) { $GLOBALS['state']['redirect'] = $url; throw new LogicException('redirect'); }
function wp_die($message, $title = '', $args = array()) { throw new DomainException($message); }
function tk_require_admin_post($action) {
    if (!tk_is_admin_user() || ($_POST['_tk_nonce'] ?? '') !== 'valid') { wp_die('Forbidden'); }
}
function tk_nonce_field($action) { echo '<input type="hidden" name="_tk_nonce" value="valid">'; }
function tk_notice($message, $type) { echo esc_html($message); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_textarea($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function selected($actual, $expected) { if ($actual === $expected) { echo 'selected="selected"'; } }
function wpcf7() { return true; }
function wpcf7_plugin_path($path) { return $GLOBALS['cf7'] . '/' . $path; }
function wpcf7_plugin_url($path) { return 'https://example.test/wp-content/plugins/contact-form-7/' . $path; }
function wpcf7_load_js() { return apply_filters('wpcf7_load_js', true); }
function wpcf7_load_css() { return apply_filters('wpcf7_load_css', true); }
function wpcf7_is_rtl() { return $GLOBALS['state']['rtl'] ?? false; }
function wpcf7_support_html5_fallback() { return false; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function sanitize_url($url) { return $url; }
function get_rest_url() { return 'https://example.test/wp-json/'; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function _doing_it_wrong($function, $message, $version) { throw new RuntimeException($message); }
function wp_is_valid_utf8($value) { return true; }
require $cf7 . '/includes/controller.php';
require dirname(__DIR__) . '/includes/cf7-assets.php';
require dirname(__DIR__) . '/includes/antispam-contact.php';
tk_cf7_assets_init();
tk_antispam_contact_init();
do_action('init');

function check_cf7($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function fixture_cf7($content = '', $mode = 'live') {
    $GLOBALS['options'] = array('cf7_assets_mode' => $mode);
    $GLOBALS['state'] = array();
    $GLOBALS['wp_query'] = (object) array('posts' => array((object) array('post_content' => $content)));
    $GLOBALS['wp_scripts'] = new WP_Scripts();
    $GLOBALS['wp_styles'] = new WP_Styles();
    unset($GLOBALS['tk_cf7_assets_result']);
    $_SERVER = array('REQUEST_URI' => '/about/', 'REQUEST_METHOD' => 'GET');
    $_POST = $_GET = array();
    // Real dependency registration includes every dependency declared by this CF7 build.
    $assets = include wpcf7_plugin_path('includes/js/index.asset.php');
    foreach (array_unique(array_merge($assets['dependencies'], array('swv', 'wp-i18n', 'jquery', 'google-recaptcha'))) as $handle) {
        wp_register_script($handle, 'https://example.test/' . $handle . '.js');
    }
}

fixture_cf7('', 'off');
do_action('wp_enqueue_scripts');
check_cf7(wp_script_is('contact-form-7', 'enqueued') && wp_style_is('contact-form-7', 'enqueued'), 'Default mode changed CF7 loading');
check_cf7(strpos(implode('', wp_scripts()->get_data('contact-form-7', 'before')), 'var wpcf7 =') !== false, 'CF7 localization is missing');

fixture_cf7();
wp_enqueue_script('jquery');
wp_register_script('wpcf7-recaptcha', '/recaptcha.js', array('google-recaptcha'));
wp_enqueue_script('wpcf7-recaptcha');
do_action('wp_enqueue_scripts');
check_cf7(!wp_script_is('contact-form-7', 'enqueued') && !wp_style_is('contact-form-7', 'enqueued'), 'Unused CF7 assets remained queued');
check_cf7(!wp_script_is('wpcf7-recaptcha', 'enqueued'), 'Unused CF7 captcha integration remained queued');
check_cf7(wp_script_is('jquery', 'enqueued') && wp_script_is('google-recaptcha', 'registered'), 'Shared dependency was removed or deregistered');
check_cf7(wp_script_is('contact-form-7', 'registered'), 'CF7 was deregistered');

foreach (array('[contact-form-7 id="12"]', '<!-- wp:contact-form-7/contact-form-selector {"id":12} /-->', '[container][contact-form-7 id="12"][/container]') as $content) {
    fixture_cf7($content);
    do_action('wp_enqueue_scripts');
    check_cf7(wp_script_is('contact-form-7', 'enqueued') && wp_style_is('contact-form-7', 'enqueued'), 'Detected form lost its assets');
    $localized = implode('', wp_scripts()->get_data('contact-form-7', 'before'));
    $config = json_decode(rtrim(substr($localized, strlen('var wpcf7 = ')), ';'), true);
    check_cf7(($config['api']['namespace'] ?? '') === 'contact-form-7/v1', 'Detected form lost REST localization');
}

foreach (array('123', '/about/', '*') as $rule) {
    fixture_cf7();
    $options['cf7_assets_always_load'] = $rule;
    $_SERVER['REQUEST_URI'] = '/about?utm_source=test';
    do_action('wp_enqueue_scripts');
    check_cf7(wp_script_is('contact-form-7', 'enqueued'), 'Always-load entry did not preserve assets');
}
fixture_cf7();
$options['cf7_assets_always_load'] = '/blog/contact/';
$_SERVER['REQUEST_URI'] = '/blog/contact/?test=1';
check_cf7(tk_cf7_assets_should_load(), 'Subfolder path did not match');
$_SERVER['REQUEST_URI'] = '/blog/contact-other/';
check_cf7(!tk_cf7_assets_should_load(), 'Path matching incorrectly used a prefix');
$options['cf7_assets_always_load'] = '123'; $state['singular'] = false;
check_cf7(!tk_cf7_assets_should_load(), 'Post ID incorrectly matched an archive');

fixture_cf7('<!-- wp:block {"ref":42} /-->');
$state['refs'][42] = (object) array('post_content' => '[contact-form-7 id="12"]');
check_cf7(tk_cf7_assets_should_load(), 'Reusable block form was missed');
$state['refs'][42]->post_content = '<!-- wp:block {"ref":42} /-->';
check_cf7(!tk_cf7_assets_should_load(), 'Reusable block cycle did not terminate');
unset($state['refs'][42]);
check_cf7(tk_cf7_assets_should_load(), 'Unresolvable block failed closed');
fixture_cf7(str_repeat('x', 262145));
check_cf7(tk_cf7_assets_should_load(), 'Oversized content failed closed');
fixture_cf7(); $wp_query->posts = array_fill(0, 51, (object) array('post_content' => ''));
check_cf7(tk_cf7_assets_should_load(), 'Oversized query failed closed');
fixture_cf7();
add_filter('tk_cf7_assets_should_load', function() { return true; });
check_cf7(tk_cf7_assets_should_load(), 'Theme integration filter ignored');
remove_all_filters('tk_cf7_assets_should_load');

foreach (array(array(), array('logged_in' => true), array('logged_in' => true, 'manager' => false)) as $visitor) {
    fixture_cf7('', 'preview'); $state = $visitor;
    $_GET['cf7_assets_mode'] = 'live';
    tk_cf7_assets_preview_headers();
    do_action('wp_enqueue_scripts');
    check_cf7(wp_script_is('contact-form-7', 'enqueued') && empty($state['no_cache']), 'Preview affected a visitor or unprivileged user');
}
fixture_cf7('', 'preview'); $state = array('logged_in' => true, 'manager' => true);
tk_cf7_assets_preview_headers();
do_action('wp_enqueue_scripts');
check_cf7(!wp_script_is('contact-form-7', 'enqueued') && !empty($state['no_cache']) && DONOTCACHEPAGE, 'Manager preview was not applied privately');
$bar = new class { public $node; public function add_node($node) { $this->node = $node; } };
tk_cf7_assets_preview_bar($bar);
check_cf7(strpos($bar->node['title'], 'unused assets skipped') !== false, 'Preview outcome missing from toolbar');

foreach (array('admin', 'ajax', 'feed', 'trackback', 'robots', 'unlicensed') as $context) {
    fixture_cf7();
    if ($context === 'unlicensed') { $state['licensed'] = false; } else { $state[$context] = true; }
    do_action('wp_enqueue_scripts');
    check_cf7(wp_script_is('contact-form-7', 'enqueued'), 'Optimization ran in bypass context ' . $context);
}

fixture_cf7();
wp_register_script('addon-bridge', '/bridge.js', array('contact-form-7'));
wp_register_script('addon', '/addon.js', array('addon-bridge'));
wp_enqueue_script('addon');
wp_register_style('addon-style', '/addon.css', array('contact-form-7-rtl'));
wp_enqueue_style('addon-style');
do_action('wp_enqueue_scripts');
check_cf7(wp_script_is('contact-form-7', 'enqueued'), 'Transitive addon dependency lost CF7');
wp_styles()->all_deps(wp_styles()->queue);
check_cf7(in_array('contact-form-7', wp_styles()->to_do, true), 'Addon style lost its CF7 dependency');
check_cf7(strpos(implode('', wp_scripts()->get_data('contact-form-7', 'before')), 'var wpcf7 =') !== false, 'Addon dependency lost CF7 initialization');

fixture_cf7(); $state['rtl'] = true;
do_action('wp_enqueue_scripts');
check_cf7(!wp_style_is('contact-form-7-rtl', 'enqueued'), 'Unused RTL stylesheet remained');

check_cf7(tk_cf7_assets_parse_rules("/contact/\n123\n/contact\n/\n*") === array('/contact', '123', '/', '*'), 'Rules were not normalized/deduplicated');
foreach (array('https://evil.test/form', '//evil.test/form', '/contact?x=1', '/contact#form', '/contact/*', '/bad path', "/bad\\path", '<script>', implode("\n", range(1, 201))) as $invalid) {
    check_cf7(is_wp_error(tk_cf7_assets_parse_rules($invalid)), 'Invalid always-load rule accepted: ' . substr($invalid, 0, 30));
}
fixture_cf7(); $options['cf7_assets_always_load'] = 'https://invalid-import.test';
check_cf7(tk_cf7_assets_should_load(), 'Invalid imported settings broke forms');

foreach (array('get', 'unauthorized', 'nonce', 'license', 'invalid-mode', 'invalid-rules') as $failure) {
    fixture_cf7(); $writes = array();
    $state = array('manager' => true);
    $_SERVER['REQUEST_METHOD'] = $failure === 'get' ? 'GET' : 'POST';
    $_POST = array('_tk_nonce' => 'valid', 'cf7_assets_mode' => 'live', 'cf7_assets_always_load' => '/contact/');
    if ($failure === 'unauthorized') { $state['manager'] = false; }
    if ($failure === 'nonce') { $_POST['_tk_nonce'] = 'invalid'; }
    if ($failure === 'license') { $state['licensed'] = false; }
    if ($failure === 'invalid-mode') { $_POST['cf7_assets_mode'] = 'other'; }
    if ($failure === 'invalid-rules') { $_POST['cf7_assets_always_load'] = '/contact?x=1'; }
    try { tk_cf7_assets_save(); throw new RuntimeException('Save unexpectedly succeeded'); }
    catch (DomainException $error) { check_cf7(!$writes, 'Invalid save wrote settings: ' . $failure); }
}
foreach (array(false, true) as $reset) {
    fixture_cf7(); $state = array('manager' => true);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('_tk_nonce' => 'valid', 'cf7_assets_mode' => 'preview', 'cf7_assets_always_load' => "/contact/\n123");
    if ($reset) { $_POST['cf7_assets_reset'] = '1'; }
    try { tk_cf7_assets_save(); } catch (LogicException $error) {
        check_cf7($options['cf7_assets_mode'] === ($reset ? 'off' : 'preview'), 'Save/reset mode incorrect');
        check_cf7($options['cf7_assets_always_load'] === ($reset ? '' : "/contact\n123"), 'Save/reset rules incorrect');
    }
}
fixture_cf7(); $state['manager'] = true;
$options['cf7_assets_always_load'] = '</textarea><script>alert(1)</script>';
ob_start(); tk_cf7_assets_render_panel(); $html = ob_get_clean();
check_cf7(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;/textarea&gt;') !== false, 'Settings panel did not escape saved content');
check_cf7(strpos($html, 'name="_tk_nonce"') !== false && strpos($html, 'name="cf7_assets_reset"') !== false, 'Save/reset controls missing');
// The optional timer is enqueued after CF7 asset optimization and never forces a form dependency.
foreach (array(false, true) as $has_form) {
    fixture_cf7($has_form ? '[contact-form-7 id="12"]' : '');
    $options['antispam_cf7_enabled'] = $options['antispam_cooldown_enabled'] = 1;
    do_action('wp_enqueue_scripts');
    do_action('wp_footer');
    check_cf7(wp_script_is('tk-antispam-cooldown', 'enqueued') === $has_form, 'Cooldown asset did not follow optimized CF7 loading');
    check_cf7(wp_script_is('contact-form-7', 'enqueued') === $has_form, 'Cooldown forced unused CF7 assets');
}
fixture_cf7('[contact-form-7 id="12"]');
$options['antispam_cf7_enabled'] = 1;
$options['antispam_cooldown_enabled'] = 0;
do_action('wp_enqueue_scripts'); do_action('wp_footer');
check_cf7(wp_script_is('contact-form-7', 'enqueued') && !wp_script_is('tk-antispam-cooldown', 'enqueued'), 'Disabling the timer affected CF7 asset loading');
echo "PASS: {$checks} CF7 loading, real WordPress dependencies/localization, detection, preview isolation, validation, save/reset, escaped UI and cooldown integration checks\n";
