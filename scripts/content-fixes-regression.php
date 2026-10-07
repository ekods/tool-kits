<?php
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/seo-content-fixes.php';
function get_post($id) { return $GLOBALS['post']; }
function current_user_can(...$args) { return $GLOBALS['editable']; }
function is_post_type_viewable($type) { return true; }
function tk_seo_selected_post_types() { return array('post'); }
function strip_shortcodes($text) { return preg_replace('/\[[^\]]+\]/', '', $text); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_trim_words($text, $limit, $more) { return implode(' ', array_slice(explode(' ', $text), 0, $limit)); }
function wp_slash($data) { return array_map('addslashes', $data); }
function wp_update_post($data, $error) {
    $GLOBALS['writes'][] = $data;
    foreach ($data as $key => $value) if ($key !== 'ID') $GLOBALS['post']->$key = stripslashes($value);
    return 1;
}
function is_wp_error($value) { return false; }
function get_permalink($id) { return 'https://other.example/post'; }
function home_url($path) { return 'https://site.example/'; }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function verify($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$GLOBALS['editable'] = true;
$GLOBALS['writes'] = array();
$GLOBALS['post'] = (object) array('post_status' => 'publish', 'post_password' => '', 'post_type' => 'post', 'post_excerpt' => '', 'post_content' => '<p>Actual project copy with a literal \\ path.</p>');
$result = tk_seo_content_fix_post(1);
verify($result['changed'] && count($GLOBALS['writes']) === 1, 'Missing excerpt not filled');
verify(strpos($GLOBALS['post']->post_excerpt, '\\') !== false, 'Backslashes lost');
verify(!isset($GLOBALS['writes'][0]['post_content']), 'Source content overwritten');
verify(!tk_seo_content_fix_post(1)['changed'], 'Repeated fix must be idempotent');
$GLOBALS['post']->post_excerpt = 'Manual excerpt';
verify(!tk_seo_content_fix_post(1)['changed'] && $GLOBALS['post']->post_excerpt === 'Manual excerpt', 'Manual excerpt overwritten');
$GLOBALS['post']->post_excerpt = '';
$GLOBALS['post']->post_content = '';
verify(!tk_seo_content_fix_post(1)['changed'], 'Empty source created fake content');
$GLOBALS['editable'] = false;
verify(!tk_seo_content_fix_post(1)['changed'], 'Unauthorized content changed');
$GLOBALS['editable'] = true;
$GLOBALS['post']->post_password = 'secret';
verify(!tk_seo_content_fix_post(1)['changed'], 'Protected content changed');
$GLOBALS['post']->post_password = '';
$GLOBALS['post']->post_status = 'draft';
verify(!tk_seo_content_fix_post(1)['changed'], 'Draft changed');
echo "PASS: excerpt generation, source preservation, idempotence, escaping, empty source and edit/public guards\n";
