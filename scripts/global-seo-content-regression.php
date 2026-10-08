<?php
define('ABSPATH', '/tmp/');
$options = array('seo_enabled' => 1, 'seo_global_enabled' => 1, 'seo_geo_post_types' => array('page'));
$theme_options = array(); $theme_mods = array(); $managed = false; $licensed = true; $authorized = true; $purges = 0;
$front = false; $singular = false; $admin = false; $preview = false; $protected = false; $post_status = 'publish'; $post_enabled = '1'; $excerpt = ''; $post_content = ''; $featured = '';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function tk_require_admin_post($action) { check($action === 'tk_seo_global_save', 'Wrong nonce action'); if (!$GLOBALS['authorized']) { throw new RuntimeException('Unauthorized'); } }
function tk_post($key, $default = '') { return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default; }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function sanitize_textarea_field($s) { return trim(strip_tags($s)); }
function sanitize_email($s) { return filter_var($s, FILTER_SANITIZE_EMAIL); }
function is_email($s) { return filter_var($s, FILTER_VALIDATE_EMAIL); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function strip_shortcodes($s) { return preg_replace('/\[[^\]]+\]/', '', $s); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_textarea($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_url_raw($s, $protocols = null) { return preg_match('~^https?://~i', $s) ? $s : ''; }
function checked($a, $b = true) { if ($a == $b) { echo 'checked'; } }
function tk_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_die($s) { throw new RuntimeException($s); }
class Redirected extends RuntimeException {}
function wp_safe_redirect($url) { throw new Redirected($url); }
function admin_url($path) { return 'https://example.com/wp-admin/' . $path; }
function home_url($path = '/') { return 'https://example.com' . $path; }
function get_bloginfo($key) { return $key === 'name' ? 'Site Default' : 'WordPress Tagline'; }
function get_option($key) { return $key === 'admin_email' ? 'editor@example.com' : ''; }
function myprefix_get_theme_option($key) { return $GLOBALS['theme_options'][$key] ?? ''; }
function myprefix_get_theme_option_lang($key) { return $GLOBALS['localized'][$key] ?? ''; }
function get_theme_mod($key, $default = '') { return $GLOBALS['theme_mods'][$key] ?? $default; }
function wp_get_attachment_image_url($id, $size) { return 'https://example.com/logo.png'; }
function wp_get_theme() { return null; }
function apply_filters($hook, $value) { return $hook === 'tk_seo_theme_managed_seo' ? $GLOBALS['managed'] : $value; }
function tk_page_cache_purge() { $GLOBALS['purges']++; }
function is_admin() { return $GLOBALS['admin']; }
function is_front_page() { return $GLOBALS['front']; }
function is_singular() { return $GLOBALS['singular']; }
function is_home() { return false; }
function is_category() { return false; }
function is_tag() { return false; }
function is_tax() { return false; }
function wp_doing_ajax() { return false; }
function is_feed() { return false; }
function is_preview() { return $GLOBALS['preview']; }
function is_search() { return false; }
function is_404() { return false; }
function post_password_required() { return $GLOBALS['protected']; }
function get_post_status() { return $GLOBALS['post_status']; }
function get_queried_object_id() { return 42; }
function get_post_type($id) { return 'page'; }
function get_post_meta($id, $key, $single) { return $key === '_tk_seo_enabled' ? $GLOBALS['post_enabled'] : ''; }
function get_post_types($args, $format) { return array('page' => (object) array('name' => 'page')); }
function is_post_type_viewable($type) { return true; }
function get_post() { return (object) array('post_author' => 7, 'post_content' => $GLOBALS['post_content']); }
function get_the_excerpt() { return $GLOBALS['excerpt']; }
function get_the_author_meta($field, $id) { return 'Page Author'; }
function get_the_post_thumbnail_url($id, $size) { return $GLOBALS['featured']; }
require dirname(__DIR__) . '/includes/seo-optimization.php';
require dirname(__DIR__) . '/includes/geo-crawler-fixes.php';
function save_global() { try { tk_seo_global_save(); } catch (Redirected $e) { check(strpos($e->getMessage(), '#global-content') !== false, 'Wrong save redirect'); } }

// Inherited fields are live references and are neither submitted nor persisted.
$managed = true;
$theme_options = array('themes_description' => 'Theme description', 'themes_author' => 'Theme Author', 'themes_keyword' => 'Branding, Design', 'themes_og_image' => 'https://example.com/theme.jpg');
$options['seo_global_content'] = array('description' => 'Old duplicate', 'author' => 'Old Author', 'service_description' => 'Local service description');
$resolved = tk_seo_global_content();
check($resolved['description']['value'] === 'Theme description' && $resolved['description']['inherited'], 'Theme description not linked');
check($resolved['author']['value'] === 'Theme Author' && $resolved['publisher']['value'] === 'Site Default' && $resolved['publisher']['inherited'], 'Theme identity precedence incorrect');
$localized = array('themes_description' => 'Translated theme description');
check(tk_seo_global_content()['description']['value'] === 'Translated theme description', 'Localized theme description ignored');
$localized = array(); $theme_options['themes_description'] = 'Updated theme description';
check(tk_seo_global_content()['description']['value'] === 'Updated theme description', 'Theme changes require a copied value to be refreshed');
ob_start(); tk_seo_global_render_panel(); $panel = ob_get_clean();
check(strpos($panel, 'Updated theme description') !== false && strpos($panel, 'Linked') !== false && strpos($panel, 'Source: Theme Settings') !== false, 'Theme value/source not rendered');
check(strpos($panel, 'name="seo_global_content[description]"') === false && strpos($panel, 'name="seo_global_content[author]"') === false, 'Inherited fields submitted redundant values');
check(strpos($panel, 'name="seo_global_content[service_name]"') !== false, 'Missing theme field cannot be entered');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('seo_global_enabled' => '1', 'seo_global_content' => array('description' => 'Spoofed description', 'author' => 'Spoofed author', 'service_name' => '<b>Brand Strategy</b>', 'service_description' => 'Service copy'));
$before_theme = $theme_options; save_global();
check(!isset($options['seo_global_content']['description']) && !isset($options['seo_global_content']['author']), 'Inherited values duplicated in Tool Kits storage');
check($options['seo_global_content']['service_name'] === 'Brand Strategy' && $theme_options === $before_theme && $options['seo_enabled'] === 1 && $purges === 1, 'Save modified source/settings or failed to sanitize/purge');
check(tk_seo_organization_data()['service_name'] === 'Brand Strategy' && tk_seo_organization_data()['service_description'] === 'Service copy', 'GEO did not reuse global service content');
$before = $options; $authorized = false;
try { tk_seo_global_save(); throw new LogicException('Unauthorized save accepted'); } catch (RuntimeException $e) { check($e->getMessage() === 'Unauthorized', 'Unexpected auth error'); }
check($options === $before, 'Unauthorized global mutation'); $authorized = true;
$_POST['seo_global_content']['image'] = 'javascript:alert(1)';
unset($theme_options['themes_og_image']);
try { tk_seo_global_save(); throw new LogicException('Invalid image URL accepted'); } catch (RuntimeException $e) { check(strpos($e->getMessage(), 'image URL') !== false, 'Wrong URL validation'); }
check($options === $before, 'Invalid URL partially saved');
$_POST['seo_global_content']['image'] = array('bad');
try { tk_seo_global_save(); throw new LogicException('Array value accepted'); } catch (RuntimeException $e) { check(strpos($e->getMessage(), 'social image') !== false, 'Wrong input validation'); }
check($options === $before, 'Invalid array partially saved');

// Generic themes can supply known Customizer values. Malformed options are not
// cast to strings, and global fallbacks remain available if the source clears.
$managed = false; $theme_options = array();
$theme_mods = array('meta_description' => 'Customizer description');
check(tk_seo_global_content()['description']['value'] === 'Customizer description' && tk_seo_global_content()['description']['inherited'], 'Customizer source ignored');
$theme_mods['meta_description'] = array('bad');
check(!tk_seo_global_content()['description']['inherited'], 'Malformed theme setting inherited');
$theme_mods = array();
$options['seo_global_content'] = array('title' => 'Global homepage title', 'description' => 'Global description', 'author' => 'Global Author', 'publisher' => 'Global Publisher', 'keywords' => 'Strategy, Design', 'image' => 'https://example.com/global.jpg');
check(tk_seo_global_content()['description']['value'] === 'Global description', 'Local fallback not resolved');
$front = true; $singular = true;
check(tk_seo_generate_description() === 'Global description' && tk_seo_global_document_title('Original') === 'Global homepage title', 'Homepage defaults not applied');
$excerpt = 'Homepage-specific excerpt';
check(tk_seo_generate_description() === $excerpt, 'Global default overwrote a manual homepage excerpt');
$front = false; $excerpt = 'Page-specific excerpt'; $featured = 'https://example.com/page.jpg';
check(tk_seo_generate_description() === $excerpt && tk_seo_og_image_url() === $featured && tk_seo_meta_identity()['author'] === 'Page Author', 'Global defaults overwrote page signals');
check(tk_seo_global_document_title('Page title') === 'Page title', 'Global homepage title leaked to a child page');
$excerpt = ''; $featured = ''; $singular = false;
check(tk_seo_generate_description() === 'Global description' && tk_seo_og_image_url() === 'https://example.com/global.jpg' && tk_seo_meta_identity()['author'] === 'Global Author', 'Global fallbacks unavailable');
$managed = true; $front = true;
check(tk_seo_global_document_title('Theme formatted title') === 'Theme formatted title', 'Theme title overridden');
$managed = false;

// Final-HTML completion sees theme tags regardless of hook order. Keep valid
// content and JSON-LD unchanged, repair empty tags, ignore comment/script demos.
$values = array('title' => 'A < B', 'description' => 'Reviewed "description"', 'author' => 'Global Author', 'publisher' => 'Global Publisher', 'keywords' => 'Strategy, Design', 'image' => 'https://example.com/global.jpg');
$html = '<html><head><title>Theme title</title><meta name="description" content="Theme description"><meta content=ThemeAuthor name=author><meta content="https://example.com/theme.jpg" property=og:image><meta name=keywords content="Theme keywords"><script type="application/ld+json">{"name":"Untouched"}</script></head><body><h1>Original content</h1></body></html>';
$fixed = tk_seo_global_fill_html($html, $values); $metadata = tk_geo_crawler_metadata($fixed);
check($metadata['title'] === 'Theme title' && $metadata['description'] === 'Theme description' && $metadata['author'] === 'ThemeAuthor' && $metadata['image'] === 'https://example.com/theme.jpg' && $metadata['keywords'] === 'Theme keywords' && $metadata['publisher'] === 'Global Publisher', 'Valid theme tags overwritten');
check(substr_count($fixed, 'og:image') === 1 && substr_count($fixed, 'name=keywords') === 1 && strpos($fixed, '<script type="application/ld+json">{"name":"Untouched"}</script>') !== false, 'Redundant tags or modified schema');
check(tk_seo_global_fill_html($fixed, $values) === $fixed, 'Global output not idempotent');
$empty = '<html><head><title> </title><meta name="description" content=""><meta name=keywords content=""><meta property="og:image" content=""><!-- <meta name="publisher" content="Fake"> --><script>var demo = "</head><meta name=author content=Fake>";</script></head><body>Preserved</body></html>';
$fixed = tk_seo_global_fill_html($empty, $values); $metadata = tk_geo_crawler_metadata($fixed);
check($metadata['title'] === 'A < B' && $metadata['description'] === $values['description'] && $metadata['image'] === $values['image'] && $metadata['keywords'] === $values['keywords'] && $metadata['author'] === 'Global Author', 'Empty fields not repaired or examples mistaken for tags');
check(substr_count($fixed, '<title>') === 1 && substr_count($fixed, 'og:image') === 1 && strpos($fixed, '<body>Preserved</body>') !== false, 'Empty tags duplicated or body changed');
check(tk_seo_global_fill_html('{"data":1}', $values) === '{"data":1}', 'Non-HTML response changed');

// Public output is opt-in and respects licensing, SEO plugins, post controls,
// private/preview responses and HTTP failures.
$_SERVER['REQUEST_METHOD'] = 'GET'; $front = false; $singular = true;
$level = ob_get_level();
foreach (array('admin', 'preview', 'protected') as $flag) {
    $GLOBALS[$flag] = true; tk_seo_global_start(); check(ob_get_level() === $level, 'Unsafe response buffered: ' . $flag); $GLOBALS[$flag] = false;
}
$post_status = 'private'; tk_seo_global_start(); check(ob_get_level() === $level, 'Private post buffered'); $post_status = 'publish';
$licensed = false; tk_seo_global_start(); check(ob_get_level() === $level, 'Unlicensed global output'); $licensed = true;
$post_enabled = '0'; tk_seo_global_start(); check(ob_get_level() === $level, 'Post opt-out ignored'); $post_enabled = '1';
$options['seo_global_enabled'] = 0; tk_seo_global_start(); check(ob_get_level() === $level, 'Global opt-in ignored'); $options['seo_global_enabled'] = 1;
foreach (array(200, 500) as $status) {
    http_response_code($status); ob_start(); tk_seo_global_start(); echo '<html><head></head><body>Public</body></html>'; ob_end_flush(); $output = ob_get_clean();
    check($status === 200 ? tk_geo_crawler_metadata($output)['author'] === 'Page Author' : $output === '<html><head></head><body>Public</body></html>', 'HTTP response guard or output failed');
}
http_response_code(200); define('WPSEO_VERSION', 'test');
tk_seo_global_start(); check(ob_get_level() === $level && tk_seo_global_document_title('SEO plugin title') === 'SEO plugin title', 'SEO plugin ownership ignored');
echo "PASS: live theme/Customizer inheritance, source display, no copied settings, secure saving, page precedence, global fallbacks, no duplicate tags, unchanged schema and public output guards\n";
