<?php
define('ABSPATH', '/tmp/');
define('HOUR_IN_SECONDS', 3600);
$queries = array(); $options = array(); $sessions = array();
function home_url($path) { return 'https://example.test/'; }
function get_post_types($args, $format) { return array('page', 'post', 'portfolio', 'private', 'attachment'); }
function tk_seo_selected_post_types() { return array('page', 'post', 'portfolio', 'private', 'attachment'); }
function is_post_type_viewable($type) { return $type !== 'private'; }
function get_posts($args) {
    $GLOBALS['queries'][] = $args;
    $count = $args['post_type'] === 'page' ? 5 : 10000;
    $limit = $args['posts_per_page'] < 0 ? $count : $args['posts_per_page'];
    $offset = array('page' => 0, 'post' => 100, 'portfolio' => 200)[$args['post_type']];
    $posts = array();
    for ($i = 0; $i < min($count, $limit); $i++) { $posts[] = (object) array('ID' => $offset + $count - $i, 'post_type' => $args['post_type']); }
    return $posts;
}
function get_permalink($id) { return $id === 5 ? home_url('/') : home_url('/') . $id . '/'; }
function get_the_title($post) { return 'Content ' . $post->ID; }
function check_ajax_referer($action) {}
function tk_is_admin_user() { return true; }
function tk_license_features_enabled() { return true; }
function tk_get_option($key, $default) { return $GLOBALS['options'][$key] ?? $default; }
function tk_geo_crawler_fix_url($url) { return strpos($url, home_url('/')) === 0 ? $url : ''; }
function wp_generate_password(...$args) { return str_repeat('A', 24); }
function get_current_user_id() { return 1; }
function set_transient($key, $value, $ttl) { $GLOBALS['sessions'][$key] = $value; }
function absint($value) { return abs((int) $value); }
class SampleResponse extends RuntimeException { public $data; public function __construct($data) { $this->data = $data; } }
function wp_send_json_success($data) { throw new SampleResponse($data); }
function wp_send_json_error($data, $status) { throw new RuntimeException($data['message']); }
function sample_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/includes/geo-audit-batch.php';
$targets = tk_geo_batch_targets();
sample_check(count($targets) === 11 && count($queries) === 3, 'Sampling did not bound two large post types while retaining Pages');
sample_check(array_column($targets, 'id') === array(0, 4, 3, 2, 1, 10100, 10099, 10098, 10200, 10199, 10198), 'Sample is not the latest three per post type or homepage is duplicated');
foreach ($queries as $query) {
    sample_check($query['post_status'] === 'publish' && $query['has_password'] === false && $query['orderby'] === array('date' => 'DESC', 'ID' => 'DESC'), 'Sampling includes drafts/protected content or unstable ordering');
}
$options['seo_geo_audit_report'] = array('requested_urls' => 1000, 'items' => array(
    array('url' => get_permalink(4), 'status' => 200, 'score' => 90),
    array('url' => get_permalink(70), 'status' => 0, 'score' => null),
    array('url' => 'https://foreign.test/', 'status' => 0, 'score' => null),
));
$_POST = array('mode' => 'all');
try { tk_geo_batch_start(); } catch (SampleResponse $response) { sample_check($response->data['total'] === 11, 'All mode uses a full archive'); }
$state = end($sessions);
sample_check(count($state['existing']) === 1 && $state['existing_total'] === 11, 'New sample retained unrelated legacy results or full-site totals');
$queries = array(); $_POST = array('mode' => 'failed');
try { tk_geo_batch_start(); } catch (SampleResponse $response) { sample_check($response->data['total'] === 1, 'Old saved local URLs cannot be retried or foreign URL was allowed'); }
sample_check(!$queries && end($sessions)['targets'][0]['url'] === get_permalink(70), 'Retry enumerates the content library or drops a URL outside the sample');
echo "PASS: GEO latest-three samples, all Pages, homepage deduplication, publish/password guards, sample totals and explicit saved-URL retry\n";
