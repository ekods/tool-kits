<?php
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/geo-schema-modal.php';
function verify_schema($ok, $message) { if (!$ok) throw new RuntimeException($message); }
verify_schema(tk_geo_schema_validate_json('')['valid'], 'Empty setting cannot be removed');
foreach (array('{bad', 'null', '42', '[]', '{"@type":4}', '{"@type":"Organization","@id":4}') as $bad) {
    verify_schema(!tk_geo_schema_validate_json($bad)['valid'], 'Invalid structure accepted: ' . $bad);
}
$different = tk_geo_schema_validate_json('{"@context":"https://schema.org","@graph":[{"@type":"ImageObject","@id":"#a"},{"@type":"ImageObject","@id":"#b"}]}');
verify_schema($different['valid'] && strpos(implode(' ', $different['messages']), 'Repeated entity definition') === false, 'Different images treated as duplicate entities');
$reference = tk_geo_schema_validate_json('{"@type":"Organization","@id":"#org","publisher":{"@id":"#org"}}');
verify_schema($reference['valid'] && strpos(implode(' ', $reference['messages']), 'Repeated entity definition') === false, 'Identity reference treated as definition');
$repeated = tk_geo_schema_validate_json('[{"@type":"WebSite","@id":"#site"},{"@type":"WebSite","@id":"#site"}]');
verify_schema(strpos(implode(' ', $repeated['messages']), 'Repeated entity definition: #site') !== false, 'Repeated identity not found');
verify_schema($repeated['duplicate_types']['WebSite'] === 2 && $repeated['duplicate_ids']['#site'] === 2, 'Structured duplicate markers missing');
echo "PASS: malformed JSON, typed nodes, identity comparisons, references and removable settings\n";

define('MB_IN_BYTES', 1048576);
class SchemaAjaxResponse extends Exception {
    public $data;
    public $success;
    function __construct($data, $success) { $this->data = $data; $this->success = $success; }
}
function wp_send_json_success($data) { throw new SchemaAjaxResponse($data, true); }
function wp_send_json_error($data, $status = 400) { throw new SchemaAjaxResponse($data, false); }
function tk_toolkits_can_manage() { return $GLOBALS['schema_manage']; }
function check_ajax_referer(...$args) {}
function wp_unslash($value) { return stripslashes($value); }
function esc_url_raw($url) { return $url; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path) { return 'https://site.example' . $path; }
function get_bloginfo($key) { return 'Example'; }
function tk_get_option($key, $default) {
    if ($key === 'geo_schema_duplicate_report') return array('items' => array(array('url' => 'https://site.example/')));
    return '{"@type":"Organization","name":"Example"}';
}
function wp_safe_remote_get($url, $options) { return '<script type="application/ld+json">{"@type":"WebSite","@id":"#website"}</script>'; }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($response) { return 200; }
function wp_remote_retrieve_header($response, $key) { return 'text/html'; }
function wp_remote_retrieve_body($response) { return $response; }
function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
function schema_ajax($live = false) {
    try { if ($live) tk_geo_schema_editor_live_ajax(); else tk_geo_schema_editor_ajax(); }
    catch (SchemaAjaxResponse $response) { return $response; }
}
$GLOBALS['schema_manage'] = true;
$_POST = array('url' => 'https://site.example/', 'mode' => 'load');
$editor = schema_ajax();
verify_schema($editor->success && isset($editor->data['json']) && !isset($editor->data['live']), 'Editor route must not fetch live output');
$live = schema_ajax(true);
verify_schema($live->success && $live->data['operation'] === 'live' && count($live->data['live']['documents']) === 1, 'Dedicated live route returned editor payload');
$_POST['url'] = 'https://external.example/';
verify_schema(!schema_ajax(true)->success, 'External live URL accepted');
$_POST['url'] = 'https://site.example/';
$GLOBALS['schema_manage'] = false;
verify_schema(!schema_ajax(true)->success, 'Live route bypassed permissions');
echo "PASS: editor/live AJAX contracts, independent operations, same-site URLs and permissions\n";
