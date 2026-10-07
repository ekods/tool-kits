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
verify_validation(end($GLOBALS['queries'])['numberposts'] === 200, 'Duplicate comparison is not bounded');
$GLOBALS['posts'][2]->post_content = 'Different original content';
$fresh = tk_seo_run_content_audit(1, true);
verify_validation(array_column($fresh['items'][0]['strategy_checks'], null, 'category')['Duplicate content']['status'] === 'review', 'Validation reused stale duplicate findings');
verify_validation(array_column(tk_seo_run_content_audit(1)['items'][0]['strategy_checks'], null, 'category')['Duplicate content']['finding'] === 'Not checked in editor; run Content Audit for cross-page comparison.', 'Existing editor audit behavior changed');
echo "PASS: selected-page validation matches all audit checks, fresh bounded duplicate comparison, scores and rendered-review limits\n";
