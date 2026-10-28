<?php
// Standalone checks for automatic editor signals; no WordPress or database writes.
define('ABSPATH', '/tmp/');
$meta = array();
$options = array('seo_geo_post_types' => array('post'));
$posts = array(1 => (object) array('ID' => 1, 'post_type' => 'post', 'post_status' => 'auto-draft', 'post_content' => ''));
$terms = array('category' => array(), 'post_tag' => array(7));
$queries = array();
$boxes = array();
$can_edit = true;
$valid_nonce = true;
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_type($id) { return get_post($id)->post_type ?? ''; }
function get_post_meta($id, $key, $single = false) {
    $value = $GLOBALS['meta'][$id][$key] ?? null;
    return $single ? ($value ?? '') : ($value === null ? array() : array($value));
}
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function tk_get_option($key, $fallback = null) { return $GLOBALS['options'][$key] ?? $fallback; }
function get_post_types($args, $output) { return array('post' => (object) array('name' => 'post')); }
function is_post_type_viewable($type) { return true; }
function get_object_taxonomies($type) { return array('category', 'post_tag', 'post_format'); }
function wp_get_post_terms($id, $taxonomy, $args) { return $GLOBALS['terms'][$taxonomy] ?? array(); }
function get_posts($args) { $GLOBALS['queries'][] = $args; return array(2, 3); }
function get_permalink($id) { return 'https://site.example/post-' . $id; }
function is_wp_error($value) { return false; }
function home_url($path) { return 'https://site.example' . $path; }
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function esc_url_raw($value) { return trim($value); }
function maybe_unserialize($value) { return $value; }
function wp_is_post_revision($id) { return false; }
function wp_is_post_autosave($id) { return false; }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['valid_nonce']; }
function current_user_can($capability, $id) { return $GLOBALS['can_edit']; }
function sanitize_text_field($value) { return strip_tags($value); }
function wp_unslash($value) { return stripslashes($value); }
function tk_is_admin_user() { return true; }
function add_meta_box(...$args) { $GLOBALS['boxes'][] = $args; }
function wp_nonce_field(...$args) {}
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function checked($value) { if ($value) echo ' checked'; }
function get_bloginfo($key) { return 'en-US'; }
function wp_json_encode($value) { return json_encode($value); }
function tk_geo_normalize_faq_items($value) { return is_array($value) ? $value : array(); }
function tk_toolkits_can_manage() { return false; }
require dirname(__DIR__) . '/includes/seo-optimization.php';
function verify_auto($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}

$meta[1] = array(
    '_tk_schema_type' => 'NewsArticle',
    '_tk_geo_reference_urls' => array('https://old.example/manual'),
    '_tk_geo_related_posts' => array(99),
    'sources' => array('https://research.example/source', 'javascript:alert(1)'),
);
verify_auto(tk_seo_schema_type_for_post(1) === 'BlogPosting', 'Legacy override must not prevent automatic schema selection');
foreach (array('page' => 'none', 'service' => 'Service', 'product' => 'Product', 'portfolio' => 'CreativeWork') as $type => $expected) {
    verify_auto(tk_seo_schema_type_for_post((object) array('ID' => 1, 'post_type' => $type)) === $expected, 'Automatic schema for ' . $type);
}
$options['seo_schema_post_type_map'] = array('post' => 'Article');
verify_auto(tk_seo_schema_type_for_post(1) === 'BlogPosting', 'Legacy global mappings must not override automatic schema detection');
unset($options['seo_schema_post_type_map']);

$posts[1]->post_content = <<<'HTML'
<p><a href="https://research.example/source">Source</a>
<a href='https://journal.example/paper?a=1&amp;b=2'>Paper</a>
<a href=//docs.example/reference>Documentation</a>
<a href="https://site.example/internal">Internal</a><a href="/relative">Relative</a>
<a href="mailto:hello@example.com">Email</a><a href="javascript:alert(1)">Invalid</a>
<a rel="sponsored" href="https://ads.example/offer">Ad</a>
<a rel="ugc nofollow" href="https://comments.example/link">Comment</a></p>
<!-- <a href="https://fake.example/comment">Hidden</a> -->
<script>var text = '<a href="https://fake.example/script">Hidden</a>';</script>
<img src="https://images.example/image.jpg">
HTML;
verify_auto(tk_seo_reference_urls(1) === array(
    'https://research.example/source',
    'https://journal.example/paper?a=1&b=2',
    'https://docs.example/reference',
), 'Sources must be derived from real metadata and external editorial links, deduplicated and decoded');
$posts[1]->post_content = '';
unset($meta[1]['sources']);
verify_auto(tk_seo_reference_urls(1) === array(), 'Do not fabricate references or reuse hidden manual values');
verify_auto(tk_seo_reference_urls(0) === array(), 'Unsaved content has no references');

verify_auto(tk_seo_related_urls(1) === array('https://site.example/post-2', 'https://site.example/post-3'), 'Related content must use taxonomy matches instead of manual IDs');
$query = end($queries);
verify_auto($query['tax_query'][0]['taxonomy'] === 'post_tag', 'Tags must work when the category is empty');
verify_auto($query['post_status'] === 'publish' && $query['has_password'] === false && $query['post__not_in'] === array(1), 'Only public, unprotected related content may be selected');
$terms['category'] = array(4);
tk_seo_related_urls(1);
verify_auto(count(end($queries)['tax_query']) === 3, 'All matching content taxonomies are considered');
$terms = array();
verify_auto(tk_seo_related_urls(1) === array(), 'No taxonomy match must not invent related content');
$count = count($queries);
verify_auto(tk_seo_related_urls(1, 0) === array() && count($queries) === $count, 'Zero limit must not query all posts');

$legacy = $meta[1];
$_POST = array(
    'tk_seo_geo_meta_nonce' => 'valid', 'tk_seo_enabled' => '1', 'tk_geo_enabled' => '0',
    'tk_seo_focus_keyword' => 'Automatic SEO', 'tk_schema_type' => 'Product',
    'tk_geo_reference_urls' => 'https://submitted.example/', 'tk_geo_related_posts' => array(88),
);
tk_seo_save_geo_meta_box(1, $posts[1]);
foreach ($legacy as $key => $value) {
    verify_auto($meta[1][$key] === $value, 'Saving must preserve legacy data without accepting hidden overrides: ' . $key);
}
verify_auto($meta[1]['_tk_seo_enabled'] === '1' && $meta[1]['_tk_geo_enabled'] === '0' && $meta[1]['_tk_seo_focus_keyword'] === 'Automatic SEO', 'Remaining editor controls must save');
$saved = $meta;
$valid_nonce = false;
$_POST['tk_seo_focus_keyword'] = 'Rejected';
tk_seo_save_geo_meta_box(1, $posts[1]);
verify_auto($meta === $saved, 'Invalid nonce cannot save changes');
$valid_nonce = true;
$can_edit = false;
tk_seo_save_geo_meta_box(1, $posts[1]);
verify_auto($meta === $saved, 'Missing edit permission cannot save changes');

tk_seo_add_geo_meta_boxes();
verify_auto($boxes[0][4] === 'side' && $boxes[0][0] !== 'tk-seo-geo-signals', 'Panel must use sidebar without inheriting legacy bottom placement');
verify_auto($boxes[1][0] === 'tk-seo-content' && $boxes[1][4] === 'normal' && $boxes[1][5] === 'low', 'SEO content must have a separate panel below the editor');
verify_auto($boxes[1][1] === 'Tool Kits SEO Content', 'The standalone panel must use the Tool Kits SEO Content name');
ob_start();
tk_seo_render_geo_meta_box($posts[1]);
$html = ob_get_clean();
foreach (array('tk_schema_type', 'tk_geo_reference_urls', 'tk_geo_related_posts', 'Schema Type', 'References / Sources', 'Related Content') as $removed) {
    verify_auto(strpos($html, $removed) === false, 'Removed field still rendered: ' . $removed);
}
verify_auto(strpos($html, 'tk_seo_focus_keyword') === false && strpos($html, 'tk-seo-content-open') === false && strpos($html, 'tk-faq-dialog') === false, 'Sidebar must leave FAQ and content editing to separate panels');
verify_auto($boxes[2][0] === 'tk-seo-faq' && $boxes[2][4] === 'side' && $boxes[2][1] === 'Tool Kits FAQ GEO', 'FAQ GEO must have its own sidebar panel');
ob_start();
tk_seo_render_faq_meta_box($posts[1]);
$html = ob_get_clean();
verify_auto(strpos($html, 'tk-faq-dialog') !== false && strpos($html, 'name="tk_geo_post_faq"') !== false, 'FAQ panel must render its popup');
echo 'PASS: ' . $checks . " automatic SEO/GEO signal checks\n";
