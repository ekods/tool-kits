<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('TK_URL', 'https://example.test/plugins/tool-kits/');
$options = array('google_analytics_gtag_id' => 'G-EXISTING');
$manage = true; $unfiltered = true; $valid_nonce = true; $admin = false; $ajax = false; $feed = false; $nonce = false;
$hooks = array(); $assets = array(); $editor_settings = array('codemirror' => array('mode' => 'htmlmixed')); $checks = 0;
function add_action($hook, $callback, $priority = 10, ...$args) { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function add_filter(...$args) {}
function tk_toolkits_can_manage() { return $GLOBALS['manage']; }
function current_user_can($cap) { return $cap === 'unfiltered_html' && $GLOBALS['unfiltered']; }
function tk_check_nonce($action) { if (!$GLOBALS['valid_nonce'] || $action !== 'tk_analytics_save') { throw new RuntimeException('Bad nonce'); } }
function tk_get_option($key, $default = '') { return $GLOBALS['options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_post($key, $default = '') { return isset($_POST[$key]) ? stripslashes($_POST[$key]) : $default; }
function sanitize_text_field($value) { return strip_tags($value); }
function wp_die($message) { throw new RuntimeException($message); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($key, $value, $url) { return $url . '&' . $key . '=' . $value; }
function wp_safe_redirect($url) { throw new RuntimeException('Redirect'); }
function is_admin() { return $GLOBALS['admin']; }
function wp_doing_ajax() { return $GLOBALS['ajax']; }
function is_feed() { return $GLOBALS['feed']; }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_textarea($value) { return htmlspecialchars($value, ENT_QUOTES); }
function tk_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
function tk_csp_add_nonce_to_tag($tag, $element = 'script') { return $GLOBALS['nonce'] ? str_replace('<script', '<script nonce="test-nonce"', $tag) : $tag; }
function tk_asset_version($file) { return 'fixture'; }
function wp_enqueue_code_editor($args) { $GLOBALS['assets']['editor'] = $args; return $GLOBALS['editor_settings']; }
function wp_enqueue_style(...$args) { $GLOBALS['assets']['styles'][] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['assets']['scripts'][] = $args; }
function wp_localize_script(...$args) { $GLOBALS['assets']['localized'][] = $args; }
require dirname(__DIR__) . '/includes/analytics.php';
require dirname(__DIR__) . '/includes/general.php';
function check_gtm($ok, $message) { if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } $GLOBALS['checks']++; }
function capture_gtm($callback) { ob_start(); $callback(); return ob_get_clean(); }
function save_gtm(array $data, $expected = 'Redirect') {
    $_POST = array_map(function ($value) { return is_string($value) ? addslashes($value) : $value; }, $data);
    try { tk_analytics_save(); } catch (RuntimeException $error) { check_gtm($error->getMessage() === $expected, 'Save result: ' . $error->getMessage()); return; }
    throw new RuntimeException('Save did not redirect or reject');
}
$head = "<!-- Google Tag Manager -->\n<script>(function(w){w.dataLayer=w.dataLayer||[];w.dataLayer.push({'event':'gtm.js','path':'a\\\\b'});})(window);</script>";
$body = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TEST123&amp;gtm_auth=example" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
if (in_array('--preview', $argv, true)) {
    $options['google_tag_manager_head'] = $head; $options['google_tag_manager_body'] = $body;
    echo '<!doctype html><html><head><meta charset="utf-8"></head><body><main class="tk-overview-main">';
    tk_analytics_render_gtm_form(); echo '</main></body></html>'; exit;
}
tk_analytics_init();
check_gtm(isset($hooks['wp_head'][-100]) && $hooks['wp_head'][-100][0] === 'tk_analytics_render_gtm_head', 'Head renders early');
check_gtm($hooks['wp_body_open'][1][0] === 'tk_analytics_render_gtm_body', 'Body uses opening body hook');
save_gtm(array('google_tag_manager_head' => $head, 'google_tag_manager_body' => $body));
check_gtm($options['google_tag_manager_head'] === $head && $options['google_tag_manager_body'] === $body, 'Script, noscript, quotes and backslashes round-trip');
check_gtm($options['google_analytics_gtag_id'] === 'G-EXISTING', 'GTM save preserves Analytics ID');
save_gtm(array('google_analytics_gtag_id' => 'G-UPDATED'));
check_gtm($options['google_tag_manager_head'] === $head && $options['google_tag_manager_body'] === $body, 'Analytics save preserves GTM');
check_gtm(capture_gtm('tk_analytics_render_gtm_head') === "\n$head\n", 'Head snippet stays executable and unmodified');
check_gtm(capture_gtm('tk_analytics_render_gtm_body') === "\n$body\n", 'Noscript iframe stays intact');
check_gtm(capture_gtm('tk_analytics_render_gtm_head') === '' && capture_gtm('tk_analytics_render_gtm_body') === '', 'Output only once per location');
unset($GLOBALS['tk_analytics_gtm_rendered']); $nonce = true;
check_gtm(strpos(capture_gtm('tk_analytics_render_gtm_head'), '<script nonce="test-nonce">') !== false, 'CSP nonce applied');
foreach (array('admin', 'ajax', 'feed') as $context) {
    unset($GLOBALS['tk_analytics_gtm_rendered']); $GLOBALS[$context] = true;
    check_gtm(capture_gtm('tk_analytics_render_gtm_head') === '' && capture_gtm('tk_analytics_render_gtm_body') === '', 'No output for ' . $context);
    $GLOBALS[$context] = false;
}
$before = $options;
$unfiltered = false;
save_gtm(array('google_tag_manager_head' => '<script>bad()</script>', 'google_analytics_gtag_id' => 'G-CHANGED'), 'Forbidden');
check_gtm($options === $before, 'Unprivileged request writes nothing');
check_gtm(strpos(capture_gtm('tk_analytics_render_gtm_form'), '<textarea') === false, 'Script fields hidden without permission');
check_gtm(tk_general_clean_import_options(array('google_tag_manager_head' => $head, 'google_tag_manager_body' => $body, 'other' => 1)) === array('other' => 1), 'Import cannot bypass script permissions');
$unfiltered = true; $manage = false;
save_gtm(array('google_tag_manager_head' => $head), 'Forbidden'); $manage = true; $valid_nonce = false;
save_gtm(array('google_tag_manager_head' => $head), 'Bad nonce'); $valid_nonce = true;
save_gtm(array('google_tag_manager_head' => $head, 'google_tag_manager_body' => array('bad')), 'Invalid tracking setting');
check_gtm($options === $before, 'Invalid nonce, access or value causes no partial updates');
check_gtm(tk_general_clean_import_options(array('google_tag_manager_head' => $head)) === array('google_tag_manager_head' => $head), 'Authorized backup preserves raw snippet');
$options['google_tag_manager_head'] = '</textarea><script>window.escaped=false</script>';
$form = capture_gtm('tk_analytics_render_gtm_form');
check_gtm(strpos($form, '&lt;/textarea&gt;&lt;script&gt;') !== false && strpos($form, '<script>window.escaped') === false, 'Stored code escaped in editor');
save_gtm(array('google_tag_manager_head' => '', 'google_tag_manager_body' => ''));
unset($GLOBALS['tk_analytics_gtm_rendered']);
check_gtm(capture_gtm('tk_analytics_render_gtm_head') === '' && capture_gtm('tk_analytics_render_gtm_body') === '', 'Clearing fields removes output');
tk_analytics_enqueue_editor('post.php'); check_gtm(!$assets, 'Editor assets scoped to Overview');
tk_analytics_enqueue_editor('toplevel_page_tool-kits');
check_gtm($assets['editor']['type'] === 'text/html' && $assets['scripts'][0][2] === array('code-editor'), 'WordPress HTML CodeMirror loaded');
$assets = array(); $editor_settings = false; tk_analytics_enqueue_editor('toplevel_page_tool-kits');
check_gtm($assets['localized'][0][2]['settings'] === false && $assets['scripts'][0][2] === array(), 'Plain textarea fallback for disabled highlighting');
$assets = array(); $unfiltered = false; tk_analytics_enqueue_editor('toplevel_page_tool-kits');
check_gtm(!$assets, 'No script editor assets without permission');
echo 'PASS: ', $checks, ' GTM save, permission, nonce, import, output, CSP and editor checks', PHP_EOL;
