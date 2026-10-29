<?php
// Isolated WordPress fixtures; no live options, posts or database writes.
define('ABSPATH', '/tmp/');
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$meta = array();
$options = array('seo_enabled' => 1, 'seo_geo_post_types' => array('page'));
$state = array('can_edit' => true, 'nonce' => true, 'revision' => false, 'autosave' => false, 'admin' => false, 'licensed' => true, 'theme' => false);
$post = (object) array('ID' => 42, 'post_type' => 'page', 'post_name' => 'company', 'post_status' => 'auto-draft', 'post_excerpt' => '', 'post_content' => 'Original page content');
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function get_option($key) { return $GLOBALS['options'][$key] ?? 0; }
function get_post($id = null) { return $GLOBALS['post']; }
function get_post_type($id) { return $GLOBALS['post']->post_type; }
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$key] ?? ''; }
function get_the_terms($id, $taxonomy) { return $GLOBALS['categories'] ?? array(); }
function get_query_var($key) { return $GLOBALS['query'][$key] ?? 0; }
function wp_json_encode($value) { return json_encode($value); }
function myprefix_get_theme_option($key) { return $GLOBALS['theme_options'][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$key] = $value; }
function wp_is_post_revision($id) { return $GLOBALS['state']['revision']; }
function wp_is_post_autosave($id) { return $GLOBALS['state']['autosave']; }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['state']['nonce'] && $nonce === 'fixture' && $action === 'tk_seo_content_meta_box'; }
function wp_nonce_field($action, $field) { echo '<input type="hidden" name="' . $field . '" value="fixture">'; }
function wp_create_nonce($action) { return 'fixture'; }
function admin_url($path) { return 'https://brandkraft.id/wp-admin/' . $path; }
function current_user_can($cap, $id = null) { return $cap === 'upload_files' ? ($GLOBALS['state']['can_upload'] ?? true) : $GLOBALS['state']['can_edit']; }
function wp_unslash($text) { return stripslashes($text); }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function sanitize_textarea_field($text) { return trim(strip_tags($text)); }
function sanitize_title($text) { return strtolower(str_replace(' ', '-', sanitize_text_field($text))); }
function wp_unique_post_slug($slug, $id, $status, $type, $parent) { return $slug === 'existing' ? 'existing-2' : $slug; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function strip_shortcodes($text) { return $text; }
function get_post_types($args, $output) { return array('page' => (object) array('name' => 'page')); }
function is_post_type_viewable($type) { return true; }
function is_admin() { return $GLOBALS['state']['admin']; }
function wp_doing_ajax() { return $GLOBALS['state']['ajax'] ?? false; }
function is_feed() { return $GLOBALS['state']['feed'] ?? false; }
function is_singular() { return true; }
function is_front_page() { return false; }
function is_home() { return false; }
function tk_license_features_enabled() { return $GLOBALS['state']['licensed']; }
function get_queried_object_id() { return 42; }
function get_theme_mod($name) { return $GLOBALS['theme_mods'][$name] ?? ''; }
function apply_filters($name, $value) { return $name === 'tk_seo_theme_managed_seo' ? $GLOBALS['state']['theme'] : $value; }
function get_bloginfo($key) { return $key === 'name' ? 'BRANDKRAFT' : 'Brand strategy and design.'; }
function get_the_title($id) { return 'Company'; }
function get_the_excerpt() { return $GLOBALS['post']->post_excerpt; }
function get_site_icon_url($size) { return $GLOBALS['site_icon'] ?? ''; }
function get_the_post_thumbnail_url($id, $size) { return $GLOBALS['featured'][$id] ?? ''; }
function wp_get_attachment_image_url($id, $size) { return $GLOBALS['attachment_urls'][$id] ?? ''; }
function wp_attachment_is_image($id) { return isset($GLOBALS['attachment_urls'][$id]); }
function cst_get_social_share_image_data($id) { return $GLOBALS['theme_social'][$id] ?? array(); }
function get_permalink($id) { return 'https://brandkraft.id/company/'; }
function home_url($path) { return 'https://brandkraft.id' . $path; }
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function esc_url_raw($value, $protocols = null) { return preg_match('~^https?://~i', $value) ? $value : ''; }
function esc_textarea($value) { return esc_attr($value); }
function tk_toolkits_can_manage() { return true; }
require dirname(__DIR__) . '/includes/seo-optimization.php';

function is_preview() { return $GLOBALS['state']['preview'] ?? false; }
function post_password_required() { return $GLOBALS['state']['protected'] ?? false; }
function get_post_status($id = null) { return $GLOBALS['post']->post_status; }
function add_action($hook, $callback, $priority = 10, $arguments = 1) { $GLOBALS['hooks'][] = array($hook, $callback, $priority); }
function add_filter($hook, $callback, $priority = 10, $arguments = 1) { add_action($hook, $callback, $priority, $arguments); }
