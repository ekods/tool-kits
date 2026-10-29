<?php
/** Isolated saved-score endpoint and hook regressions; no live database access. */
define('ABSPATH', __DIR__ . '/');
define('TK_URL', 'https://example.test/wp-content/plugins/tool-kits/');
$checks = 0; $audits = 0; $writes = array(); $hooks = array(); $meta = array();
$manager = true; $can_edit = true; $valid_nonce = true; $revision = false; $autosave = false;
$post = (object) array('ID' => 42, 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'Saved body', 'post_excerpt' => 'Saved excerpt');
$audit_item = array('score' => 72, 'priority' => 'medium', 'issues' => array('<script>Unsafe issue</script>'), 'strategy_checks' => array());
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][] = array($hook, $callback, $priority, $args); }
function add_filter($hook, $callback, $priority = 10, $args = 1) { add_action($hook, $callback, $priority, $args); }
function tk_toolkits_can_manage() { return $GLOBALS['manager']; }
function tk_is_admin_user() { return $GLOBALS['manager']; }
function get_current_screen() { return $GLOBALS['score_screen']; }
function tk_seo_editor_panel_visible($panel) { return in_array($panel, $GLOBALS['visible_panels'], true); }
function tk_get_option($key, $default = null) { return $default; }
function tk_asset_version($path) { return 'fixture'; }
function wp_enqueue_style($handle, ...$args) { $GLOBALS['enqueued_styles'][] = $handle; }
function wp_enqueue_script($handle, ...$args) { $GLOBALS['enqueued_scripts'][] = $handle; }
function current_user_can($cap, $id) { return $GLOBALS['can_edit'] && $id === 42; }
function tk_seo_selected_post_types() { return array('page'); }
function tk_seo_post_feature_enabled($feature, $id) { return true; }
function wp_is_post_revision($id) { return $GLOBALS['revision']; }
function wp_is_post_autosave($id) { return $GLOBALS['autosave']; }
function get_post($id) { return $id === 42 ? $GLOBALS['post'] : null; }
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$key] = $value; $GLOBALS['writes'][] = $key; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$key]); }
function is_admin() { return false; }
function tk_seo_run_content_audit($id) { $GLOBALS['audits']++; return array('items' => $GLOBALS['audit_item'] === null ? array() : array($GLOBALS['audit_item'])); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function wp_create_nonce($action) { return $action; }
class ScoreResponse extends RuntimeException {
    public $data; public $success; public $status;
    public function __construct($success, $data, $status) { $this->data = $data; $this->success = $success; $this->status = $status; }
}
function wp_send_json_error($data, $status = 400) { throw new ScoreResponse(false, $data, $status); }
function wp_send_json_success($data) { throw new ScoreResponse(true, $data, 200); }
function check_ajax_referer($action, $key) {
    if (!$GLOBALS['valid_nonce'] || ($_POST[$key] ?? '') !== $action) wp_send_json_error(array('message' => 'Invalid nonce'), 403);
}
require dirname(__DIR__) . '/includes/seo-content-modal.php';
require dirname(__DIR__) . '/includes/seo-content-panel.php';
require dirname(__DIR__) . '/includes/seo-list-score.php';
function check_score($ok, $message) { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($message); }
function score_request($input = null) {
    $_POST = $input ?? array('post_id' => '42', 'nonce' => 'tk_seo_content_score_42');
    try { tk_seo_content_score_ajax(); } catch (ScoreResponse $response) { return $response; }
    throw new RuntimeException('Missing JSON response');
}
tk_seo_list_score_init();
check_score(in_array(array('wp_after_insert_post', 'tk_seo_refresh_content_score', 20, 2), $hooks, true), 'Scores invalidate after metadata is saved, including REST');
check_score(!in_array('save_post', array_column($hooks, 0), true), 'Do not compute scores before REST metadata saves');
check_score(in_array('wp_ajax_tk_seo_content_score', array_column($hooks, 0), true), 'Authenticated score endpoint is registered');
$score_screen = (object) array('base' => 'post', 'post_type' => 'page');
foreach (array(array('content'), array('geo')) as $visible_panels) {
    $enqueued_scripts = $enqueued_styles = array(); tk_seo_list_score_enqueue();
    check_score($enqueued_scripts === array('tk-seo-score-refresh'), 'Refresh loads for either visible score panel independently');
}
$visible_panels = array(); $enqueued_scripts = $enqueued_styles = array(); tk_seo_list_score_enqueue();
check_score(!$enqueued_scripts && !$enqueued_styles, 'Hidden editor panels do not load score assets');
$score_screen->base = 'edit'; tk_seo_list_score_enqueue();
check_score(!$enqueued_scripts && $enqueued_styles === array('tk-seo-score'), 'Content lists keep badges without editor refresh scripts');
ob_start(); tk_seo_score_refresh_attributes(42); $attributes = ob_get_clean();
check_score(strpos($attributes, 'data-tk-seo-score-post="42"') !== false && strpos($attributes, 'tk_seo_content_score_42') !== false, 'Refresh configuration is bound to the current post');
$response = score_request();
check_score($response->success && $response->data['score'] === 72 && $response->data['post_id'] === 42, 'Draft score response uses saved content');
check_score($audits === 1, 'Analysis and sidebar share one audit');
check_score(strpos($response->data['badge_html'], '>72</span>') !== false && strpos($response->data['analysis_html'], 'SEO Score: 72/100') !== false, 'Both score views refresh together');
check_score(strpos($response->data['analysis_html'], '<script>') === false && strpos($response->data['analysis_html'], '&lt;script&gt;') !== false, 'Analysis escapes content-derived issues');
check_score($writes === array(TK_SEO_SCORE_META), 'Score request only writes the derived score cache');
$post->post_status = 'publish'; $post->post_content = 'Updated saved content'; $meta['_tk_seo_focus_keyword'] = 'saved keyword';
$audit_item['score'] = 94;
$response = score_request(array('post_id' => '42', 'nonce' => 'tk_seo_content_score_42', 'tk_seo_focus_keyword' => 'unsaved keyword', 'post_content' => 'unsaved body'));
check_score($response->success && $response->data['score'] === 94, 'Saved updates refresh the score');
check_score($meta[TK_SEO_SCORE_META]['version'] === tk_seo_content_editor_version($post) && $meta['_tk_seo_focus_keyword'] === 'saved keyword' && $post->post_content === 'Updated saved content', 'Do not save or score submitted unsaved form fields');
$before = $audits;
$manager = false; check_score(score_request()->status === 403, 'Require plugin management permission'); $manager = true;
$can_edit = false; check_score(score_request()->status === 403, 'Require permission for this post'); $can_edit = true;
$valid_nonce = false; check_score(score_request()->status === 403, 'Reject invalid nonce'); $valid_nonce = true;
check_score(score_request(array('post_id' => '42', 'nonce' => 'tk_seo_content_score_43'))->status === 403, 'Nonce cannot be reused for another post');
foreach (array(array('42'), '0', '42bad', '-42') as $invalid) check_score(score_request(array('post_id' => $invalid))->status === 400, 'Reject malformed post IDs');
check_score(score_request(array('post_id' => '43', 'nonce' => 'tk_seo_content_score_43'))->status === 403, 'Reject missing posts');
$post->post_type = 'attachment'; check_score(score_request()->status === 403, 'Reject unselected post types'); $post->post_type = 'page';
$revision = true; check_score(score_request()->status === 403, 'Reject revisions'); $revision = false;
$autosave = true; check_score(score_request()->status === 403, 'Reject autosaves'); $autosave = false;
foreach (array('auto-draft', 'trash') as $status) { $post->post_status = $status; check_score(score_request()->status === 400, 'Reject unsaved or trashed posts'); }
check_score($audits === $before, 'Rejected requests do not run audits');
$post->post_status = 'draft'; $audit_item = null;
check_score(score_request()->status === 500, 'Unavailable score reports an error');
$meta[TK_SEO_SCORE_META] = array('score' => 20, 'version' => 'old');
tk_seo_refresh_content_score(42, $post);
check_score(!isset($meta[TK_SEO_SCORE_META]), 'REST post-save hook invalidates an outdated cached score');
echo 'PASS: ' . $checks . " saved-score refresh, permissions, escaping and post-save cache checks\n";
