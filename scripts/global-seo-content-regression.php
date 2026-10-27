<?php
define('ABSPATH', '/tmp/');
$options = array('seo_enabled' => 1, 'seo_global_enabled' => 1, 'seo_geo_post_types' => array('page'));
$theme_options = array(); $theme_mods = array(); $managed = false; $licensed = true; $authorized = true; $purges = 0;
$front = false; $singular = false; $admin = false; $preview = false; $protected = false; $post_status = 'publish'; $post_enabled = '1'; $excerpt = ''; $post_content = ''; $featured = '';
$meta = array();
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['global_checks'] = ($GLOBALS['global_checks'] ?? 0) + 1; }
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
function get_post_meta($id, $key, $single) { return $key === '_tk_seo_enabled' ? $GLOBALS['post_enabled'] : ($GLOBALS['meta'][$key] ?? ''); }
function wp_attachment_is_image($id) { return $id === 90; }
function get_post_types($args, $format) { return array('page' => (object) array('name' => 'page')); }
function is_post_type_viewable($type) { return true; }
function get_post() { return (object) array('post_author' => 7, 'post_content' => $GLOBALS['post_content']); }
function get_the_excerpt() { return $GLOBALS['excerpt']; }
function get_the_author_meta($field, $id) { return 'Page Author'; }
function get_the_post_thumbnail_url($id, $size) { return $GLOBALS['featured']; }
require dirname(__DIR__) . '/includes/seo-optimization.php';
require dirname(__DIR__) . '/includes/geo-crawler-fixes.php';
function save_global() { try { tk_seo_global_save(); } catch (Redirected $e) { check(strpos($e->getMessage(), '#global-content') !== false, 'Wrong save redirect'); } }
function render_global(string $html, bool $explicit = false, bool $formula = false): string {
    $level = ob_get_level(); ob_start();
    if ($explicit) { tk_seo_content_output_start(); }
    tk_seo_global_start();
    if ($formula) { tk_seo_title_start(); }
    // A theme can start another output buffer late in template_redirect.
    ob_start(function ($html) { return str_replace('Old theme description', 'Late theme description', $html); });
    echo $html;
    while (ob_get_level() > $level + 1) { ob_end_flush(); }
    return ob_get_clean();
}
function check_head_values(string $html, array $expected): void {
    $dom = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    $dom->loadHTML(preg_replace('~<!--.*?-->|<script\b[^>]*>.*?</script\s*>~is', '', $html));
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    foreach ($expected as $key => $value) {
        if ($key === 'title') { $path = '//head/title'; }
        elseif ($key === 'image_src') { $path = '//head/link[@rel="image_src"]/@href'; }
        elseif ($key === 'schema:image') { $path = '//head/meta[@itemprop="image"]/@content'; }
        else { $path = '//head/meta[@' . (strpos($key, 'og:') === 0 ? 'property' : 'name') . '="' . $key . '"]/@content'; }
        $nodes = $xpath->query($path);
        check($nodes->length === 1 && $nodes->item(0)->nodeValue === $value, 'Incorrect or duplicate public ' . $key);
    }
}

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
check(strpos($panel, 'Enable global metadata override') !== false && strpos($panel, 'Fill missing metadata while preserving') === false, 'Checkbox must describe overwrite behavior');
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
check(tk_seo_generate_description() === 'Global description', 'Global override lost to an automatic homepage excerpt');
$front = false; $excerpt = 'Page-specific excerpt'; $featured = 'https://example.com/page.jpg';
check(tk_seo_generate_description() === 'Global description' && tk_seo_og_image_url() === 'https://example.com/global.jpg' && tk_seo_meta_identity()['author'] === 'Global Author', 'Global overrides lost to automatic page signals');
check(tk_seo_global_document_title('Page title') === 'Page title', 'Global homepage title leaked to a child page');
$excerpt = ''; $featured = ''; $singular = false;
check(tk_seo_generate_description() === 'Global description' && tk_seo_og_image_url() === 'https://example.com/global.jpg' && tk_seo_meta_identity()['author'] === 'Global Author', 'Global fallbacks unavailable');
$managed = true; $front = true;
check(tk_seo_global_document_title('Theme formatted title') === tk_seo_global_content()['title']['value'], 'Theme title defeated the displayed global override');
$managed = false;

// Override real theme tags regardless of hook order; keep visible content,
// canonical, robots and JSON-LD unchanged and ignore comment/script demos.
$values = array('title' => 'A < B', 'description' => 'Reviewed "description"', 'author' => 'Global Author', 'publisher' => 'Global Publisher', 'keywords' => 'Strategy, Design', 'image' => 'https://example.com/global.jpg');
$html = '<html><head><title>Theme title</title><meta name="description" content="Theme description"><meta content=ThemeAuthor name=author><meta content="https://example.com/theme.jpg" property=og:image><meta name=keywords content="Theme keywords"><script type="application/ld+json">{"name":"Untouched"}</script></head><body><h1>Original content</h1></body></html>';
$fixed = tk_seo_global_fill_html($html, $values); $metadata = tk_geo_crawler_metadata($fixed);
check($metadata['title'] === $values['title'] && $metadata['description'] === $values['description'] && $metadata['author'] === $values['author'] && $metadata['image'] === $values['image'] && $metadata['keywords'] === $values['keywords'] && $metadata['publisher'] === $values['publisher'], 'Existing theme metadata was not overwritten');
check(substr_count($fixed, 'og:image') === 1 && substr_count($fixed, 'name="keywords"') === 1 && strpos($fixed, '<script type="application/ld+json">{"name":"Untouched"}</script>') !== false, 'Redundant tags or modified schema');
check(tk_seo_global_fill_html($fixed, $values) === $fixed, 'Global output not idempotent');
$empty = '<html><head><title> </title><meta name="description" content=""><meta name=keywords content=""><meta property="og:image" content=""><!-- <meta name="publisher" content="Fake"> --><script>var demo = "</head><meta name=author content=Fake>";</script></head><body>Preserved</body></html>';
$fixed = tk_seo_global_fill_html($empty, $values); $metadata = tk_geo_crawler_metadata($fixed);
check($metadata['title'] === 'A < B' && $metadata['description'] === $values['description'] && $metadata['image'] === $values['image'] && $metadata['keywords'] === $values['keywords'] && $metadata['author'] === 'Global Author', 'Empty fields not repaired or examples mistaken for tags');
check(substr_count($fixed, '<title>') === 1 && substr_count($fixed, 'og:image') === 1 && strpos($fixed, '<body>Preserved</body>') !== false, 'Empty tags duplicated or body changed');
check(tk_seo_global_fill_html('{"data":1}', $values) === '{"data":1}', 'Non-HTML response changed');

// Public output is opt-in and respects licensing, post controls,
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
foreach (array(200, 204, 301, 404, 500) as $status) {
    http_response_code($status); ob_start(); tk_seo_global_start(); echo '<html><head></head><body>Public</body></html>'; ob_end_flush(); $output = ob_get_clean();
check($status === 200 ? tk_geo_crawler_metadata($output)['author'] === 'Global Author' : $output === '<html><head></head><body>Public</body></html>', 'HTTP response guard or output failed');
}
http_response_code(200); define('WPSEO_VERSION', 'test'); $front = true;
ob_start(); tk_seo_global_start(); echo $html; ob_end_flush(); $plugin_output = ob_get_clean();
check(tk_geo_crawler_metadata($plugin_output)['description'] === 'Global description' && tk_seo_global_document_title('SEO plugin title') === 'Global homepage title', 'Checked global override must replace third-party SEO metadata');

// Reproduce the reported mismatch: configured Tool Kits text vs existing
// theme/plugin descriptions in normal, Open Graph and Twitter metadata.
$options['seo_global_content'] = array('title' => 'Rafida Adventure',
    'description' => 'Rafida Adventure runs sailing trips through Komodo National Park and beyond, on our 27-metre phinisi, with our own Labuan Bajo crew since 2018. Private and shared trips.',
    'author' => 'Rafida Adventure', 'publisher' => 'Rafida Adventure',
    'keywords' => 'Komodo Island tours, Komodo boat tours', 'image' => 'https://example.com/rafida.webp');
$settings = $options['seo_global_content'];
$excerpt = 'Automatic excerpt that must not replace the configured description';
$featured = 'https://example.com/featured.jpg';
$untouched = '<meta name="robots" content="index,follow"><link rel="canonical" href="https://example.com/">'
    . '<script type="application/ld+json">{"@type":"WebPage","name":"Keep schema"}</script>';
$body = '<body><h1>Keep page heading</h1><p>Keep visible content</p></body></html>';
$legacy = '<html><head><title>Old theme title</title><title>Duplicate title</title>'
    . '<meta name="description" content="Old theme description"><meta name="description" content="Duplicate description">'
    . '<meta property="og:description" content="Old social description"><meta name="twitter:description" content="Old social description">'
    . '<meta property="og:title" content="Old social title"><meta name="twitter:title" content="Old social title">'
    . '<meta name="author" content="Old author"><meta name="author" content="Duplicate author">'
    . '<meta name="publisher" content="Old publisher"><meta name="keywords" content="Old keywords">'
    . '<meta property="og:image" content="old.jpg"><meta name="twitter:image" content="old.jpg">'
    . '<link rel="image_src" href="old.jpg"><meta itemprop="image" content="old.jpg">' . $untouched . '</head>' . $body;
$expected = array('title' => $settings['title'], 'og:title' => $settings['title'], 'twitter:title' => $settings['title'],
    'description' => $settings['description'], 'og:description' => $settings['description'], 'twitter:description' => $settings['description'],
    'author' => $settings['author'], 'publisher' => $settings['publisher'], 'keywords' => $settings['keywords'],
    'og:image' => $settings['image'], 'twitter:image' => $settings['image'], 'image_src' => $settings['image'], 'schema:image' => $settings['image']);
$output = render_global($legacy);
check_head_values($output, $expected);
check(strpos($output, $untouched) !== false && substr($output, -strlen($body)) === $body, 'Override changed unrelated metadata/schema or page content');
check(render_global($output) === $output, 'Checked global override is not idempotent');
$options['seo_global_enabled'] = 0;
check(render_global($legacy) === str_replace('Old theme description', 'Late theme description', $legacy), 'Unchecked global override must leave theme/plugin metadata alone');
check(tk_seo_generate_description() === $excerpt && tk_seo_og_image_url() === $featured && tk_seo_meta_identity()['author'] === 'Page Author', 'Unchecked global override must restore automatic page defaults');
$options['seo_global_enabled'] = 1;

// Explicit page SEO entries win over global entries and late theme output.
$meta = array('_tk_seo_title' => 'Saved page title', '_tk_seo_meta_description' => 'Saved page description', '_tk_seo_social_image_id' => 90);
$page_expected = $expected;
foreach (array('title', 'og:title', 'twitter:title') as $field) { $page_expected[$field] = 'Saved page title'; }
foreach (array('description', 'og:description', 'twitter:description') as $field) { $page_expected[$field] = 'Saved page description'; }
foreach (array('og:image', 'twitter:image', 'image_src', 'schema:image') as $field) { $page_expected[$field] = 'https://example.com/logo.png'; }
check_head_values(render_global($legacy, true), $page_expected);
check(tk_seo_global_document_title('Theme title') === 'Saved page title', 'Saved page title lost to global title filter');
$meta = array();
$options['seo_title_override_enabled'] = 1;
$formula = 'Site Default - WordPress Tagline'; $formula_expected = $expected;
foreach (array('title', 'og:title', 'twitter:title') as $field) { $formula_expected[$field] = $formula; }
check_head_values(render_global($legacy, false, true), $formula_expected);
$options['seo_title_override_enabled'] = 0;
$front = false;
$child = render_global('<html><head><title>Child page title</title></head>' . $body);
check_head_values($child, array('title' => 'Child page title', 'description' => $settings['description']));
$options['seo_meta_desc_enabled'] = 0; $options['seo_og_enabled'] = 0;
$disabled = render_global($legacy);
check(strpos($disabled, '<meta name="description" content="Late theme description">') !== false
    && strpos($disabled, '<meta property="og:image" content="old.jpg">') !== false, 'Disabled description/social switches ignored');
define('REST_REQUEST', true); tk_seo_global_start();
check(ob_get_level() === $level, 'REST response buffered');
echo 'PASS: ' . $global_checks . " global SEO checks, including Rafida metadata overwrite, checkbox on/off, late theme/plugin output, per-page overrides, duplicates, request guards and unchanged content/schema\n";
