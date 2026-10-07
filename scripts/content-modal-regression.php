<?php
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/includes/seo-content-modal.php';
class ModalResponse extends Exception {
    public $data;
    public $success;
    function __construct($data, $success) { $this->data = $data; $this->success = $success; }
}
function wp_send_json_error($data, $status = 400) { throw new ModalResponse($data, false); }
function wp_send_json_success($data) { throw new ModalResponse($data, true); }
function tk_toolkits_can_manage() { return $GLOBALS['manage']; }
function check_ajax_referer(...$args) { if (!$GLOBALS['nonce']) throw new ModalResponse(array(), false); }
function current_user_can(...$args) { return $GLOBALS['edit']; }
function absint($id) { return abs((int) $id); }
function get_post($id) { return $GLOBALS['post']; }
function get_post_meta($id, $key, $single) { return $key === '_tk_seo_target_location' ? $GLOBALS['location'] : $GLOBALS['keyword']; }
function is_post_type_viewable($type) { return true; }
function tk_seo_selected_post_types() { return array('work'); }
function sanitize_textarea_field($text) { return trim(strip_tags($text)); }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function wp_unslash($text) { return stripslashes($text); }
function wp_slash($data) { return array_map('addslashes', $data); }
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function home_url($path) { return 'https://site.example' . $path; }
function wpautop($text) { return '<p>' . $text . '</p>'; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_url($url) { return esc_html($url); }
function wp_update_post($updates, $error) {
    $GLOBALS['writes']++;
    foreach ($updates as $key => $value) if ($key !== 'ID') $GLOBALS['post']->$key = stripslashes($value);
    return 1;
}
function is_wp_error($value) { return false; }
function update_post_meta($id, $key, $value) { $GLOBALS[$key === '_tk_seo_target_location' ? 'location' : 'keyword'] = $value; }
function tk_update_option($key, $value) { $GLOBALS['audit'] = $value; $GLOBALS['option_writes']++; }
function tk_seo_run_content_audit($post_id = 0, $compare_duplicates = false) {
    $GLOBALS['validation_calls'][] = array($post_id, $compare_duplicates);
    return array('refreshed' => true, 'scanned_at' => 123, 'items' => array(array('post_id' => 1, 'words' => 1,
        'score' => 42, 'priority' => 'critical', 'issues' => array('Low word count (<300)'),
        'strategy_checks' => array(array('category' => 'Keyword targeting', 'status' => $GLOBALS['keyword'] === '' ? 'warning' : 'pass',
            'finding' => $GLOBALS['keyword'], 'action' => 'Review keyword', 'target' => '')))));
}
function wp_strip_all_tags($text) { return strip_tags($text); }
function get_the_title($id) { return 'Project'; }
function verify_modal($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function call_modal() { try { tk_seo_content_editor_ajax(); } catch (ModalResponse $result) { return $result; } }
$GLOBALS['manage'] = $GLOBALS['edit'] = $GLOBALS['nonce'] = true;
$GLOBALS['writes'] = 0;
$GLOBALS['option_writes'] = 0;
$GLOBALS['keyword'] = '';
$GLOBALS['location'] = 'singapore';
$GLOBALS['post'] = (object) array('ID' => 1, 'post_status' => 'publish', 'post_password' => '', 'post_type' => 'work', 'post_content' => '<!-- builder -->Original', 'post_excerpt' => 'Original excerpt');
$_POST = array('post_id' => 1, 'mode' => 'load');
$loaded = call_modal();
verify_modal($loaded->success && $loaded->data['excerpt'] === 'Original excerpt', 'Load failed');
verify_modal($loaded->data['location'] === 'singapore', 'Saved location not loaded');
verify_modal($loaded->data['validation']['score'] === 42 && $loaded->data['checked_at'] === 123, 'Validation payload missing');
verify_modal(end($GLOBALS['validation_calls']) === array(1, true), 'Modal skipped cross-page duplicate comparison');
$_POST['mode'] = 'validate';
$_POST['content'] = 'Unsaved content';
$validated = call_modal();
verify_modal($validated->success && $validated->data['validation'] === $loaded->data['validation'], 'Validation differs from audit checks');
verify_modal($GLOBALS['writes'] === 0 && $GLOBALS['option_writes'] === 0 && $GLOBALS['post']->post_content === '<!-- builder -->Original', 'Validate wrote content or audit options');
unset($_POST['content']);
$_POST['mode'] = 'invalid';
verify_modal(!call_modal()->success, 'Unknown operation accepted');
$_POST['mode'] = 'load';
$_POST += array('version' => $loaded->data['version'], 'content' => 'Useful content', 'links' => '/services/ | Our services', 'excerpt' => 'New excerpt', 'keyword' => 'design', 'client' => 'Example client', 'outcome' => 'Verified results');
$_POST['mode'] = 'save';
$_POST['location'] = 'invalid';
verify_modal(!call_modal()->success && $GLOBALS['writes'] === 0, 'Invalid location accepted');
$_POST['location'] = 'jakarta';
$_POST['links'] = 'https://external.example/ | External';
verify_modal(!call_modal()->success && $GLOBALS['writes'] === 0, 'External link accepted or partial save occurred');
$_POST['links'] = '/services/ | Our services';
$_POST['version'] = 'stale';
verify_modal(!call_modal()->success && $GLOBALS['writes'] === 0, 'Stale input accepted');
$_POST['version'] = $loaded->data['version'];
$GLOBALS['edit'] = false;
verify_modal(!call_modal()->success, 'Edit permission bypassed');
$GLOBALS['edit'] = true;
$GLOBALS['nonce'] = false;
verify_modal(!call_modal()->success, 'Nonce bypassed');
$GLOBALS['nonce'] = true;
$saved = call_modal();
verify_modal($saved->success && strpos($GLOBALS['post']->post_content, '<!-- builder -->Original') === 0, 'Builder content replaced');
verify_modal(strpos($GLOBALS['post']->post_content, 'Project Outcome') !== false && strpos($GLOBALS['post']->post_content, 'https://site.example/services/') !== false, 'Sections or links missing');
verify_modal($GLOBALS['keyword'] === 'design' && $GLOBALS['audit']['refreshed'], 'Keyword or audit not updated');
verify_modal($GLOBALS['location'] === 'jakarta', 'Location not saved');
verify_modal($saved->data['validation']['strategy_checks'][0]['finding'] === 'design', 'Save returned stale validation');
verify_modal(!call_modal()->success && $GLOBALS['writes'] === 1, 'Retry duplicated content');
echo "PASS: modal load/validate/save, read-only validation, current audit checks, permissions, nonce, internal links, stale edits, content preservation and fresh audit\n";
