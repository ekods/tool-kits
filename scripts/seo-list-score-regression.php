<?php
// Content-list SEO score and GEO indicator columns. Run: php scripts/seo-list-score-regression.php
define('ABSPATH', __DIR__ . '/');
define('TK_URL', 'https://example.test/wp-content/plugins/tool-kits/');
$meta = array(); $options = array(); $audits = 0; $checks = 0; $geo_off = array(9);
function tk_get_option($key, $fallback = null) { return $GLOBALS['options'][$key] ?? $fallback; }
function tk_is_admin_user() { return true; }
function tk_admin_url($page) { return 'https://example.test/wp-admin/admin.php?page=' . $page; }
function tk_seo_selected_post_types() { return array('post'); }
function tk_seo_post_feature_enabled($feature, $post_id) { return !($feature === 'seo' && $post_id === 8) && !($feature === 'geo' && in_array($post_id, $GLOBALS['geo_off'], true)); }
function tk_seo_content_editor_version($post) { return hash('sha256', $post->post_content); }
function tk_seo_run_content_audit($post_id) { $GLOBALS['audits']++; return array('items' => array(array('score' => 40 + $post_id))); }
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function get_permalink($post) { return 'https://example.test/p/' . $post->ID . '/'; }
function is_post_type_viewable($type) { return $type === 'post'; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function wp_date($format, $time) { return gmdate($format, $time); }
require dirname(__DIR__) . '/includes/seo-list-score.php';

function verify_list($condition, $message) {
    $GLOBALS['checks']++;
    if (!$condition) { throw new RuntimeException($message); }
}
function list_post($id, array $fields = array()) {
    return (object) ($fields + array('ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_content' => 'Body ' . $id));
}
function list_cell($column, $post) {
    ob_start();
    $column === 'tk_seo_score' ? tk_seo_list_score_badges($post) : tk_seo_list_geo_badge($post);
    return ob_get_clean();
}
$base = array('cb' => '', 'title' => 'Title', 'date' => 'Date');

verify_list(array_keys(tk_seo_list_score_column($base)) === array('cb', 'title', 'tk_seo_score', 'tk_geo_score', 'date'), 'SEO and GEO columns default on, before Date');
$options['seo_list_score_enabled'] = 0;
verify_list(array_keys(tk_seo_list_score_column($base)) === array('cb', 'title', 'tk_geo_score', 'date'), 'GEO column stays when SEO column is hidden');
$options['seo_list_geo_enabled'] = 0;
verify_list(array_keys(tk_seo_list_score_column($base)) === array_keys($base), 'Both columns hide independently');
verify_list(!tk_seo_list_score_screen_ok((object) array('base' => 'edit', 'post_type' => 'post')), 'Columns are not registered when both are off');
$options = array();
verify_list(tk_seo_list_score_screen_ok((object) array('base' => 'edit', 'post_type' => 'post')), 'Selected post type list registers columns');
verify_list(!tk_seo_list_score_screen_ok((object) array('base' => 'edit', 'post_type' => 'page')), 'Unselected post type list is ignored');
verify_list(array_keys(tk_seo_list_score_column(array('title' => 'Title'))) === array('title', 'tk_seo_score', 'tk_geo_score'), 'Lists without Date append the columns');

$post = list_post(50);
$html = list_cell('tk_seo_score', $post);
verify_list(strpos($html, '>90<') !== false && strpos($html, 'tk-seo-score-good') !== false, 'SEO score renders a good badge');
list_cell('tk_seo_score', $post);
verify_list($audits === 1, 'Cached SEO score is reused');
$post->post_content = 'Changed';
list_cell('tk_seo_score', $post);
verify_list($audits === 2, 'Changed content recalculates the SEO score');
verify_list(strpos(list_cell('tk_seo_score', list_post(8)), '>Off<') !== false, 'SEO off shows Off');
for ($i = 100; $i < 140; $i++) { $html = list_cell('tk_seo_score', list_post($i)); }
verify_list($audits === TK_SEO_SCORE_LIST_LIMIT && strpos($html, 'tk-seo-score-pending') !== false, 'Uncached SEO scores are limited per request, including earlier calculations');

$options['seo_geo_audit_report'] = array('items' => array(
    array('url' => 'https://example.test/p/60/', 'status' => 200, 'score' => 92, 'grade' => 'A', 'issues' => array()),
    array('url' => 'https://example.test/p/61/', 'status' => 200, 'score' => 64, 'grade' => 'C', 'issues' => array('Missing FAQ', 'Missing <author>')),
    array('url' => 'https://example.test/p/62/', 'status' => 0, 'score' => null, 'grade' => '-', 'issues' => array('Connection timed out "x"')),
    'invalid',
));
$html = list_cell('tk_geo_score', list_post(60));
verify_list(strpos($html, '>92<') !== false && strpos($html, 'tk-seo-score-good') !== false && strpos($html, 'grade A') !== false && strpos($html, '0 issues') !== false, 'GEO score uses the saved audit result');
verify_list(strpos($html, 'page=tool-kits-geo-audit') !== false, 'GEO badge links to GEO Audit & Fixes');
$html = list_cell('tk_geo_score', list_post(61));
verify_list(strpos($html, '>64<') !== false && strpos($html, 'tk-seo-score-fair') !== false && strpos($html, '2 issues') !== false, 'GEO score shows grade level and issue count');
$html = list_cell('tk_geo_score', list_post(62));
verify_list(strpos($html, '>!<') !== false && strpos($html, 'tk-seo-score-poor') !== false && strpos($html, '&quot;x&quot;') !== false, 'Failed GEO audits are flagged with an escaped reason');
verify_list(strpos(list_cell('tk_geo_score', list_post(63)), 'Not audited yet') !== false, 'Unaudited URLs ask for a GEO Audit');
verify_list(strpos(list_cell('tk_geo_score', list_post(9)), '>Off<') !== false, 'GEO off shows Off');
verify_list(strpos(list_cell('tk_geo_score', list_post(60, array('post_status' => 'draft'))), 'published public content only') !== false, 'Drafts are not matched to audit results');
verify_list(strpos(list_cell('tk_geo_score', list_post(60, array('post_password' => 'secret'))), 'published public content only') !== false, 'Protected content is not matched to audit results');
$audits_before = $audits;
list_cell('tk_geo_score', list_post(64));
verify_list($audits === $audits_before, 'GEO column never runs audits');

echo 'PASS: ' . $checks . " content-list SEO score and GEO indicator checks\n";
