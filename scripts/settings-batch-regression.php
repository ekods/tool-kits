<?php
// Real save handlers and batch helper, with a recording Option API. No live WordPress writes.
define('ABSPATH', '/tmp/');
$stored = array('tk_options' => array('unrelated' => 'keep', 'assets_js_delay_enabled' => 1, 'smtp_password' => 'saved-password',
    'smtp_gmail_client_id' => 'old-client', 'smtp_gmail_client_secret' => 'saved-secret', 'smtp_gmail_email' => 'old@example.test', 'smtp_gmail_refresh_token' => 'old-token'));
$writes = array(); $audit = array(); $authorized = true;
function get_option($key, $default = false) { return $GLOBALS['stored'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['stored'][$key] = $value; $GLOBALS['writes'][] = $key; }
function tk_is_report_option($key) { return false; }
function tk_killswitch_current_recovery_key($key, $options) { return ''; }
function tk_toolkits_audit_log_option_change($key, $old, $value) { $GLOBALS['audit'][$key] = array($old, $value); }
function get_current_blog_id() { return 1; }
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters($hook, $value) { return $value; }
function tk_require_admin_post($action) { if (!$GLOBALS['authorized']) { wp_die('Forbidden'); } }
function tk_check_nonce($action) { tk_require_admin_post($action); }
function tk_toolkits_can_manage() { return $GLOBALS['authorized']; }
function tk_license_features_enabled() { return true; }
function tk_post($key, $default = '') { return $_POST[$key] ?? $default; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_hex_color($value) { return preg_match('/^#[a-f0-9]{6}$/i', $value) ? $value : ''; }
function sanitize_email($value) { return trim($value); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function absint($value) { return abs((int) $value); }
function is_admin() { return true; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function get_post_types($args, $format) { return array('page' => (object) array('name' => 'page')); }
function is_post_type_viewable($type) { return true; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
class BatchRedirect extends RuntimeException {}
function wp_safe_redirect($url) { throw new BatchRedirect($url); }
function wp_redirect($url) { wp_safe_redirect($url); }
function wp_die($message) { throw new RuntimeException($message); }
$helpers = file_get_contents(dirname(__DIR__) . '/includes/helpers.php');
foreach (array('tk_get_option', 'tk_request_options_cache', 'tk_get_options', 'tk_clear_request_options_cache', 'tk_options_cache_invalidate', 'tk_update_option', 'tk_update_options', 'tk_form_has_field') as $name) {
    preg_match('/^function &?' . $name . '\([^\n]*\).*?^\}/ms', $helpers, $match);
    eval($match[0]);
}
foreach (array('general.php', 'asset-optimization.php', 'lazy-load.php', 'webp.php', 'seo-optimization.php', 'smtp.php', 'cf7-database.php') as $file) {
    require dirname(__DIR__) . '/includes/' . $file;
}
function save_batch($handler, array $posted) {
    $_POST = $posted; $GLOBALS['writes'] = array(); $GLOBALS['audit'] = array();
    try { $handler(); throw new LogicException('No redirect'); } catch (BatchRedirect $redirect) {}
    if ($GLOBALS['writes'] !== array('tk_options') || tk_get_option('unrelated') !== 'keep') { throw new RuntimeException('Save did not use one isolated config write: ' . $handler); }
}
function settings_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
save_batch('tk_assets_opt_save', array('assets_heartbeat_interval' => '120', 'assets_critical_css' => '.hero{color:red}'));
settings_check(tk_get_option('assets_heartbeat_interval') === 120 && tk_get_option('assets_critical_css') === '.hero{color:red}' && tk_get_option('assets_js_delay_enabled') === 1, 'Asset sanitization or absent checkbox preservation changed');
save_batch('tk_assets_opt_save', array('tk_switches' => array('assets_js_delay_enabled')));
settings_check(tk_get_option('assets_js_delay_enabled') === 0 && $audit['assets_js_delay_enabled'] === array(1, 0), 'Rendered unchecked switch or old/new audit values changed');
save_batch('tk_general_save', array('tk_general_section' => 'editing', 'classic_editor_enabled' => '1'));
settings_check(tk_get_option('classic_editor_enabled') === 1 && tk_get_option('classic_widgets_enabled') === 0, 'General settings changed');
save_batch('tk_general_save', array('tk_general_section' => 'xml-sitemap', 'seo_sitemap_path' => '/news.xml', 'seo_sitemap_priority' => '2'));
settings_check(tk_get_option('seo_sitemap_path') === 'news.xml' && tk_get_option('seo_sitemap_priority') === 1, 'Sitemap normalization changed');
save_batch('tk_lazy_load_save', array('lazy_load_eager_images' => '-10'));
settings_check(tk_get_option('lazy_load_eager_images') === 0, 'Lazy-load limits changed');
save_batch('tk_webp_save', array('webp_quality' => '200'));
settings_check(tk_get_option('webp_quality') === 100, 'WebP quality limits changed');
save_batch('tk_seo_opt_save', array('tk_seo_panels_present' => '1', 'seo_content_panel_enabled' => '1', 'seo_enabled' => '1', 'tk_seo_post_types_present' => '1', 'seo_geo_post_types' => array('page', 'invalid')));
settings_check(tk_get_option('seo_content_panel_enabled') === 1 && tk_get_option('seo_geo_post_types') === array('page') && tk_get_option('seo_faq_panel_enabled') === 0, 'SEO panel or post type settings changed');
save_batch('tk_smtp_save', array('smtp_provider' => 'custom', 'smtp_host' => ' mail.example.test ', 'smtp_password' => '', 'smtp_gmail_client_secret' => ''));
settings_check(tk_get_option('smtp_password') === 'saved-password' && tk_get_option('smtp_gmail_client_secret') === 'saved-secret' && tk_get_option('smtp_gmail_refresh_token') === 'old-token', 'Blank SMTP secrets did not preserve saved credentials');
save_batch('tk_smtp_save', array('smtp_provider' => 'gmail', 'smtp_gmail_client_id' => 'new-client', 'smtp_password_clear' => '1'));
settings_check(tk_get_option('smtp_password') === '' && tk_get_option('smtp_gmail_refresh_token') === '' && tk_get_option('smtp_username') === '' && tk_get_option('smtp_gmail_email') === '', 'Changed OAuth credentials did not clear tokens/derived sender consistently');
$_SERVER['REQUEST_METHOD'] = 'POST';
save_batch('tk_cf7_database_save', array());
settings_check(tk_get_option('cf7_database_enabled') === 0 && tk_get_option('cf7_database_managed') === 1, 'CF7 switch save changed');
$authorized = false; $writes = array();
try { tk_assets_opt_save(); throw new LogicException('Unauthorized save'); } catch (RuntimeException $error) { settings_check($error->getMessage() === 'Forbidden' && !$writes, 'Denied save wrote settings'); }
echo "PASS: batched settings writes, unchanged unrelated values, checkbox markers, sanitization, audit old/new values, SMTP secret/token handling and denied saves\n";
