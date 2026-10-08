<?php
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/seo-optimization.php';
function get_post_types($args, $output) { return $output === 'objects' ? array('work' => (object) array('public' => true)) : array('work' => 'work'); }
function tk_get_option($key, $default = null) { return $key === 'seo_geo_post_types' ? array('work') : $default; }
function get_posts($args) { $GLOBALS['queries'][] = $args; return array_keys($GLOBALS['posts']); }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_type($id) { return get_post($id)->post_type; }
function get_post_meta($id, $key, $single) { return $single ? ($GLOBALS['meta'][$id][$key] ?? '') : array(); }
function get_the_title($id) { return get_post($id)->post_title; }
function get_permalink($id) { return 'https://site.example/' . get_post($id)->post_name . '/'; }
function get_edit_post_link($id) { return 'https://site.example/wp-admin/post.php?post=' . $id; }
function home_url($path) { return 'https://site.example' . $path; }
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function strip_shortcodes($text) { return preg_replace('/\[[^\]]+\]/', '', $text); }
function get_bloginfo($key) { return 'Example'; }
function get_object_taxonomies($type) { return array(); }
function esc_url_raw($value) { return $value; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function tk_admin_url($slug) { return 'https://site.example/wp-admin/admin.php?page=' . $slug; }
function sanitize_key($value) { return $value; }
function verify_validation($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$post = (object) array('ID' => 1, 'post_type' => 'work', 'post_title' => 'Design project', 'post_name' => 'design-project',
    'post_content' => '<p>Client design result</p>', 'post_excerpt' => '', 'post_author' => 0,
    'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00');
$peer = clone $post; $peer->ID = 2;
$GLOBALS['posts'] = array(1 => $post, 2 => $peer);
$GLOBALS['meta'] = array(1 => array('_tk_seo_focus_keyword' => 'design'));
$GLOBALS['queries'] = array();
$full = tk_seo_run_content_audit();
$targeted = tk_seo_run_content_audit(1, true);
verify_validation(count($targeted['items']) === 1, 'Validation audited more than the selected page');
verify_validation($targeted['items'][0] === array_column($full['items'], null, 'post_id')[1], 'Validation differs from full audit findings/score/checks');
$checks = array_column($targeted['items'][0]['strategy_checks'], null, 'category');
verify_validation(count($checks) === 10 && $checks['Keyword targeting']['status'] === 'pass', 'Full optimization checklist missing');
verify_validation($checks['Duplicate content']['status'] === 'warning' && strpos($checks['Duplicate content']['finding'], '2') !== false, 'Cross-page duplicates not detected');
verify_validation($checks['Structured data/schema']['status'] === 'review', 'Stored content marked live schema as verified');
verify_validation($checks['Keyword targeting']['score'] === 100 && $checks['Portfolio SEO']['score'] === 100 && $checks['Duplicate content']['score'] === 0, 'Check scores do not reflect stored evidence');
verify_validation($checks['Content SEO']['score'] === $targeted['items'][0]['score'] + 5 * count(array_filter($checks, function($check) { return $check['status'] === 'warning'; })), 'Content score does not match core audit rules');
foreach (array('GEO / AI readability', 'Entity signals', 'Structured data/schema') as $category) {
    verify_validation($checks[$category]['score'] === null, 'Unverified live check received a numeric score');
}
verify_validation($checks['Local SEO Jakarta/Singapore']['score'] === null && $checks['Local SEO Jakarta/Singapore']['status'] === 'not-applicable', 'Unselected location scored as a failure');
ob_start(); tk_seo_content_checks_render($targeted['items'][0]); $markup = ob_get_clean();
verify_validation(strpos($markup, 'Focus keyword') !== false && strpos($markup, '100/100') !== false && strpos($markup, 'Pending') !== false && strpos($markup, '>Details</summary>') !== false, 'Audit rows do not show compact scores/details');
$legacy = $targeted['items'][0];
foreach ($legacy['strategy_checks'] as &$legacy_check) { unset($legacy_check['score'], $legacy_check['label'], $legacy_check['summary']); } unset($legacy_check);
ob_start(); tk_seo_content_checks_render($legacy); $legacy_markup = ob_get_clean();
verify_validation(strpos($legacy_markup, 'Run Content Audit to refresh individual check scores.') !== false, 'Legacy saved checks invented scores');
$GLOBALS['meta'][1]['_tk_seo_focus_keyword'] = 'client';
$partial = array_column(tk_seo_run_content_audit(1, true)['items'][0]['strategy_checks'], null, 'category');
verify_validation($partial['Keyword targeting']['score'] === 50, 'Partial keyword alignment not scored proportionally');
$GLOBALS['meta'][1]['_tk_seo_focus_keyword'] = '';
verify_validation(array_column(tk_seo_run_content_audit(1, true)['items'][0]['strategy_checks'], null, 'category')['Keyword targeting']['score'] === 0, 'Missing focus keyword received credit');
$GLOBALS['meta'][1]['_tk_seo_focus_keyword'] = 'design';
$sample = clone $post; $sample->post_content = 'Client design';
verify_validation(array_column(tk_seo_content_strategy_checks($sample, array()), null, 'category')['Portfolio SEO']['score'] === 50, 'Partial portfolio context not scored proportionally');
$sample->post_content = 'Coming soon';
verify_validation(array_column(tk_seo_content_strategy_checks($sample, array()), null, 'category')['Placeholder content']['score'] === 0, 'Placeholder copy received a passing score');
verify_validation(end($GLOBALS['queries'])['numberposts'] === 200, 'Duplicate comparison is not bounded');
$GLOBALS['posts'][2]->post_content = 'Different original content';
$fresh = tk_seo_run_content_audit(1, true);
verify_validation(array_column($fresh['items'][0]['strategy_checks'], null, 'category')['Duplicate content']['status'] === 'review', 'Validation reused stale duplicate findings');
verify_validation(array_column(tk_seo_run_content_audit(1)['items'][0]['strategy_checks'], null, 'category')['Duplicate content']['finding'] === 'Not checked in editor; run Content Audit for cross-page comparison.', 'Existing editor audit behavior changed');
verify_validation(array_column(tk_seo_run_content_audit(1)['items'][0]['strategy_checks'], null, 'category')['Duplicate content']['score'] === null, 'Unperformed duplicate comparison received a numeric score');
echo "PASS: audit/modal check-score consistency, proportional keyword/portfolio checks, pending live checks, compact rendering, legacy reports and fresh bounded duplicate comparison\n";
