<?php
define('ABSPATH', '/tmp/');
define('HOUR_IN_SECONDS', 3600);
$requests = 0;
function tk_get_option($key, $default = null) {
    return $key === 'seo_geo_audit_report' ? array('scanned_at' => 1, 'items' => array(
        array('url' => 'https://example.test/', 'score' => 99, 'status' => 200),
    )) : $default;
}
class WP_Error { public function get_error_message() { return 'Synthetic changed response'; } }
function wp_remote_get($url, $args) {
    $GLOBALS['requests']++;
    if (($args['sslverify'] ?? null) !== true) { throw new RuntimeException('GEO audit must verify TLS'); }
    return new WP_Error();
}
function is_wp_error($value) { return $value instanceof WP_Error; }
require dirname(__DIR__) . '/includes/seo-optimization.php';
$cached = tk_seo_geo_audit_url('https://example.test/');
if ($cached['score'] !== 99 || $requests !== 0) { throw new RuntimeException('Reading saved results must not crawl'); }
for ($i = 0; $i < 2; $i++) {
    $result = tk_seo_geo_audit_url('https://example.test/', 20, true);
    if ($result['score'] !== null || $result['status'] !== 0) { throw new RuntimeException('Explicit audit reused saved results'); }
}
if ($requests !== 2) { throw new RuntimeException('Each explicit audit must measure a new response'); }
function check_ajax_referer($action) {}
function tk_is_admin_user() { return true; }
function tk_license_features_enabled() { return true; }
function get_current_user_id() { return 1; }
function get_transient($key) { return array('targets' => array(array('url' => 'https://example.test/')), 'items' => array(), 'retry' => false); }
function set_transient($key, $state, $ttl) {}
function delete_transient($key) {}
function tk_update_option($key, $value) { $GLOBALS['saved_report'] = $value; }
class GeoResponse extends RuntimeException { public $data; public function __construct($data) { $this->data = $data; } }
function wp_send_json_success($data) { throw new GeoResponse($data); }
require dirname(__DIR__) . '/includes/geo-audit-batch.php';
$_POST = array('token' => str_repeat('A', 24));
try { tk_geo_batch_step(); } catch (GeoResponse $response) {
    if (!$response->data['done'] || $response->data['item']['score'] !== null || $requests !== 3) {
        throw new RuntimeException('Manual batches reused a saved result');
    }
}
echo "PASS: saved reads stay cached; explicit and resumed GEO batches fetch fresh responses with TLS\n";
