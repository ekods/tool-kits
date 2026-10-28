<?php
// Isolated Polylang and WordPress fixtures. No live content or database writes.
define('ABSPATH', '/tmp/');
define('TK_URL', 'https://example.com/wp-content/plugins/tool-kits/');
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$options = array('seo_geo_post_types' => array('page'));
$posts = array(42 => (object) array('ID' => 42, 'post_type' => 'page', 'post_status' => 'publish'),
    43 => (object) array('ID' => 43, 'post_type' => 'page', 'post_status' => 'draft'));
$meta = array(); $denied = array(); $admin = true; $valid_nonce = true; $translated = true;
$translations = array('id' => 42, 'en' => 43); $languages = array(42 => 'id', 43 => 'en');
$configured_languages = array('slug' => array('id', 'en'), 'name' => array('Indonesian', 'English'), 'locale' => array('id_ID', 'en_US'));
$boxes = array(); $assets = array(); $writes = array(); $fail_key = '';
function tk_get_option($key, $fallback = null) { return $GLOBALS['options'][$key] ?? $fallback; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_type($id) { return get_post($id)->post_type ?? ''; }
function get_post_types($args, $format) { return array('page' => (object) array('name' => 'page')); }
function is_post_type_viewable($type) { return true; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) {
    if ($GLOBALS['fail_key'] === $key) { return false; }
    $GLOBALS['writes'][] = array($id, $key);
    $GLOBALS['meta'][$id][$key] = wp_unslash($value);
    return true;
}
function current_user_can($cap, $id = null) { return !in_array($id, $GLOBALS['denied'], true); }
function tk_is_admin_user() { return $GLOBALS['admin']; }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['valid_nonce'] && $nonce === 'fixture' && $action === 'tk_seo_copy_locale_42'; }
function wp_create_nonce($action) { return 'fixture'; }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function wp_slash($value) { return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function esc_url_raw($value) { return $value; }
function admin_url($path) { return 'https://example.com/wp-admin/' . $path; }
function get_edit_post_link($id, $context) { return admin_url('post.php?post=' . $id . '&action=edit'); }
function add_meta_box(...$args) { $GLOBALS['boxes'][] = $args; }
function get_current_screen() { return (object) array('base' => 'post', 'post_type' => 'page'); }
function tk_asset_version($path) { return 'fixture'; }
function wp_enqueue_style($handle, ...$args) { $GLOBALS['assets'][] = $handle; }
function wp_enqueue_script($handle, ...$args) { $GLOBALS['assets'][] = $handle; }
function wp_enqueue_media() { $GLOBALS['assets'][] = 'media-editor'; }
class WP_Error {
    private $code; private $message;
    function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
class JsonResult extends RuntimeException {
    public $data; public $status;
    function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
function wp_send_json_error($data, $status) { throw new JsonResult($data, $status); }
function wp_send_json_success($data) { throw new JsonResult($data, 200); }
require dirname(__DIR__) . '/includes/seo-optimization.php';
function verify_locale($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}
verify_locale(!tk_seo_locale_available() && tk_seo_locale_targets(42) === array(), 'Inactive Polylang must be a safe no-op');
ob_start(); tk_seo_render_locale_copy(42); $html = ob_get_clean();
verify_locale($html === '', 'Do not render copy controls without Polylang');
if (!function_exists('pll_get_post')) {
    function pll_is_translated_post_type($type) { return $GLOBALS['translated']; }
    function pll_languages_list($args) { return $GLOBALS['configured_languages'][$args['fields']]; }
    function pll_get_post_language($id, $field) { return $GLOBALS['languages'][$id] ?? ''; }
    function pll_get_post_translations($id) { return $GLOBALS['translations']; }
    function pll_get_post($id, $slug) { return $GLOBALS['translations'][$slug] ?? 0; }
}
verify_locale(tk_seo_locale_targets(42) === array('en' => array('id' => 43, 'name' => 'English', 'locale' => 'en_US')), 'List the editable linked locale, excluding the source language');
ob_start(); tk_seo_render_locale_copy(42); $html = ob_get_clean();
verify_locale(strpos($html, 'English (en_US)') !== false && strpos($html, 'value="id"') === false, 'Render configured locale names and codes');
verify_locale(strpos($html, 'class="tk-seo-copy-replace" value="1" checked') === false, 'Replacing existing data must require opting in');
if (in_array('--preview', $argv, true)) { echo $html; exit; }
verify_locale(tk_seo_editor_panel_visible('content') && tk_seo_editor_panel_visible('geo'), 'Existing installations show both panels by default');
tk_seo_save_panel_visibility(array('tk_seo_panels_present' => '1', 'seo_content_panel_enabled' => '1'));
tk_seo_add_geo_meta_boxes();
verify_locale(count($boxes) === 1 && $boxes[0][0] === 'tk-seo-content', 'Only SEO Content must be registered when GEO panel is hidden');
tk_seo_content_panel_enqueue(); tk_seo_enqueue_faq_editor();
verify_locale(in_array('tk-seo-content-panel', $assets, true) && !in_array('tk-post-faq', $assets, true), 'Skip hidden GEO assets');
verify_locale(in_array('media-editor', $assets, true), 'Load the WordPress media picker for Open Graph uploads');
tk_seo_save_panel_visibility(array('tk_seo_panels_present' => '1', 'seo_geo_panel_enabled' => '1', 'seo_faq_panel_enabled' => '1'));
$boxes = array(); $assets = array(); tk_seo_add_geo_meta_boxes();
verify_locale(count($boxes) === 2 && $boxes[0][0] === 'tk-seo-geo-sidebar' && $boxes[1][0] === 'tk-seo-faq', 'Only GEO and FAQ must be registered when Content panel is hidden');
tk_seo_content_panel_enqueue(); tk_seo_enqueue_faq_editor();
verify_locale(!in_array('tk-seo-content-panel', $assets, true) && in_array('tk-post-faq', $assets, true), 'Skip hidden Content assets');
tk_seo_save_panel_visibility(array('tk_seo_panels_present' => '1', 'seo_geo_panel_enabled' => '1'));
$boxes = array(); $assets = array(); tk_seo_add_geo_meta_boxes(); tk_seo_enqueue_faq_editor();
verify_locale(count($boxes) === 1 && $boxes[0][0] === 'tk-seo-geo-sidebar' && !in_array('tk-post-faq', $assets, true), 'FAQ GEO panel and assets hide independently of SEO / GEO');
verify_locale(tk_seo_post_feature_enabled('seo', 42) && tk_seo_post_feature_enabled('geo', 42), 'Panel visibility must not disable public per-page features');
$before = $options; tk_seo_save_panel_visibility(array());
verify_locale($before === $options, 'Legacy settings submissions without the presence marker preserve visibility');
tk_seo_save_panel_visibility(array('tk_seo_panels_present' => '1'));
$boxes = array(); tk_seo_add_geo_meta_boxes();
verify_locale(!$boxes, 'Unchecking both panels must hide both');
$options['seo_content_panel_enabled'] = 1; $options['seo_geo_panel_enabled'] = 1;
$input = array('nonce' => 'fixture', 'locale' => 'en', 'tk_seo_focus_keyword' => 'Design', 'tk_seo_title' => '%%title%% %%sep%% %%sitename%%',
    'tk_seo_meta_description' => rtrim(str_repeat("Brand's complete description 🧭 ", 12)));
$meta[42] = array('_tk_seo_title' => 'Saved source', '_tk_geo_faq_items' => array('preserved'));
$meta[43] = array('_tk_seo_title' => 'Existing English title', '_tk_geo_enabled' => '0', '_tk_seo_rendered_audit' => array('old'), '_unrelated' => 'Keep');
$source_meta = $meta[42]; $target_extra = array_diff_key($meta[43], array('_tk_seo_title' => true));
$result = tk_seo_copy_locale(42, $input);
verify_locale($result['copied'] === 2 && $result['preserved'] === 1, 'Default copy fills only empty target fields');
verify_locale($meta[43]['_tk_seo_title'] === 'Existing English title', 'Keep existing translated text without explicit replacement');
verify_locale($meta[43]['_tk_seo_meta_description'] === $input['tk_seo_meta_description'], 'Preserve complete Unicode text and apostrophes across WordPress meta slashing');
verify_locale($meta[42] === $source_meta && array_intersect_key($meta[43], $target_extra) === $target_extra, 'Do not modify source, audit or unrelated target metadata');
verify_locale($posts[43]->post_status === 'draft', 'Keep the target translation publication status');
$input['replace'] = '1'; $result = tk_seo_copy_locale(42, $input);
verify_locale($meta[43]['_tk_seo_title'] === $input['tk_seo_title'] && $result['copied'] === 1, 'Explicit replacement must retain dynamic variable templates');
$count = count($writes); tk_seo_copy_locale(42, $input);
verify_locale(count($writes) === $count, 'Repeated copy must not rewrite matching values');
$input['tk_seo_title'] = ''; tk_seo_copy_locale(42, $input);
verify_locale($meta[43]['_tk_seo_title'] !== '', 'A blank source must not erase translated data even when replacing');
unset($meta[42]['_tk_seo_title']);
$input['tk_seo_title'] = 'Automatic title'; $input['initial'] = array('tk_seo_title' => 'Automatic title');
tk_seo_copy_locale(42, $input);
verify_locale($meta[43]['_tk_seo_title'] !== 'Automatic title', 'Do not freeze inherited defaults into another locale');
$input['tk_seo_title'] = 'Edited automatic title'; tk_seo_copy_locale(42, $input);
verify_locale($meta[43]['_tk_seo_title'] === 'Edited automatic title', 'Copy edited values even when the source originally used automatic defaults');
$input['tk_seo_title'] = '<b>Safe</b> %%title%%'; tk_seo_copy_locale(42, $input);
verify_locale($meta[43]['_tk_seo_title'] === 'Safe %%title%%', 'Sanitize current editor text while retaining variables');
$count = count($writes);
foreach (array('id', 'fr') as $locale) {
    $bad = $input; $bad['locale'] = $locale;
    verify_locale(is_wp_error(tk_seo_copy_locale(42, $bad)), 'Reject source or unconfigured locale ' . $locale);
}
$bad = $input; $bad['tk_seo_title'] = array('injected');
verify_locale(is_wp_error(tk_seo_copy_locale(42, $bad)), 'Reject malformed field values before writing');
$valid_nonce = false; verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Reject expired or invalid nonce'); $valid_nonce = true;
$admin = false; verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Reject users outside Tool Kits permissions'); $admin = true;
foreach (array(42, 43) as $id) {
    $denied = array($id); verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Enforce edit permission for post ' . $id);
}
$denied = array(); $options['seo_content_panel_enabled'] = 0;
verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Reject direct copies when the Content panel is disabled');
$options['seo_content_panel_enabled'] = 1;
$posts[43]->post_status = 'trash'; verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Reject a trashed target'); $posts[43]->post_status = 'draft';
$posts[43]->post_type = 'post'; verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Reject a target of a different content type'); $posts[43]->post_type = 'page';
$languages[43] = 'id'; verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Reject stale or mismatched translation language'); $languages[43] = 'en';
$translated = false; verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Reject post types not translated by Polylang'); $translated = true;
$options['seo_geo_post_types'] = array(); verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'Reject excluded content types'); $options['seo_geo_post_types'] = array('page');
verify_locale(count($writes) === $count, 'Rejected requests must perform no writes');
$input['tk_seo_title'] = 'Write failure'; $fail_key = '_tk_seo_title';
verify_locale(is_wp_error(tk_seo_copy_locale(42, $input)), 'A failed meta write must not report success'); $fail_key = '';
foreach (array('42' => 200, '0' => 400, '42x' => 400) as $id => $expected) {
    $_POST = wp_slash(array('post_id' => (string) $id) + $input);
    try { tk_seo_copy_locale_ajax(); throw new RuntimeException('Missing AJAX response'); }
    catch (JsonResult $response) { verify_locale($response->status === $expected, 'AJAX status for source ' . $id); }
}
$valid_nonce = false;
try { tk_seo_copy_locale_ajax(); } catch (JsonResult $response) { verify_locale($response->status === 400, 'Malformed source IDs fail before authorization'); }
$_POST['post_id'] = '42';
try { tk_seo_copy_locale_ajax(); } catch (JsonResult $response) { verify_locale($response->status === 403, 'AJAX returns an authorization error for invalid nonce'); }
$valid_nonce = true;
$configured_languages = array('slug' => array('id', 'en', 'ja', 'de'),
    'name' => array('Indonesian', 'English', 'Japanese', 'German'), 'locale' => array('id_ID', 'en_US', 'ja', 'de_DE'));
$posts[44] = (object) array('ID' => 44, 'post_type' => 'page', 'post_status' => 'publish');
$translations['ja'] = 44; $languages[44] = 'ja';
verify_locale(array_keys(tk_seo_locale_targets(42)) === array('en', 'ja'), 'More than two locales must list every linked destination, excluding source and unlinked locales');
ob_start(); tk_seo_render_locale_copy(42); $html = ob_get_clean();
verify_locale(strpos($html, 'English (en_US)') !== false && strpos($html, 'Japanese (ja)') !== false,
    'The dropdown must show names and locale codes for multiple destinations');
verify_locale(strpos($html, 'value="id"') === false && strpos($html, 'value="de"') === false,
    'The source and unlinked translations must not be offered');
$other_locale = $meta[43]; $source_meta = $meta[42];
$input['locale'] = 'ja'; $result = tk_seo_copy_locale(42, $input);
verify_locale($result['copied'] === 3 && $meta[44]['_tk_seo_title'] === $input['tk_seo_title'], 'Copy to the selected third locale');
verify_locale($meta[43] === $other_locale && $meta[42] === $source_meta, 'Copying to Japanese must preserve both English and the source');
$japanese = $meta[44]; $input['locale'] = 'en'; $input['tk_seo_title'] = 'English destination only';
tk_seo_copy_locale(42, $input);
verify_locale($meta[43]['_tk_seo_title'] === 'English destination only' && $meta[44] === $japanese,
    'Changing the destination must write only to the selected locale');
$denied = array(44);
verify_locale(array_keys(tk_seo_locale_targets(42)) === array('en'), 'An inaccessible translation must not remove other eligible destinations');
echo 'PASS: ' . $checks . " Polylang locale-copy and panel visibility checks\n";
