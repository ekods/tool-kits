<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', '/tmp/'); define('TK_VERSION', 'fixture'); define('TK_MINIMUM_PHP', '7.4');
$options = array(); $writes = 0; $checks = 0; $capable = true; $manageable = true; $licensed = true; $nonce = true;
error_reporting(E_ALL);
set_error_handler(static function ($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
function verify_history($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { verify_history($key === 'tk_health_center_history' && $autoload === false, 'History wrote an unrelated/autoloaded option'); $GLOBALS['writes']++; $GLOBALS['options'][$key] = $value; return true; }
function current_user_can($capability) { return $GLOBALS['capable']; }
function tk_toolkits_can_manage() { return $GLOBALS['manageable']; }
function tk_license_features_enabled() { return $GLOBALS['licensed']; }
function check_ajax_referer($action) { verify_history($action === 'tk_health_center', 'Wrong history nonce'); if (!$GLOBALS['nonce']) { wp_send_json_error(array('message' => 'Bad nonce'), 403); } }
class HistoryReply extends RuntimeException { public $data; public $status; public $success; public function __construct($data, $status, $success) { $this->data = $data; $this->status = $status; $this->success = $success; } }
function wp_send_json_success($data) { throw new HistoryReply($data, 200, true); }
function wp_send_json_error($data, $status = 400) { throw new HistoryReply($data, $status, false); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
require dirname(__DIR__) . '/includes/health-center.php';
function history_request($data, $method = 'POST') {
    $_POST = $data; $_SERVER['REQUEST_METHOD'] = $method;
    try { tk_health_center_history_ajax(); throw new RuntimeException('Missing JSON reply'); }
    catch (HistoryReply $reply) { return $reply; }
}
function history_report(int $number, array $statuses = array('A' => 'passed')): array {
    $checks = array();
    foreach ($statuses as $label => $status) { $checks[] = array('label' => $label, 'category' => 'Security', 'status' => $status, 'detail' => 'secret-raw-detail', 'evidence' => array('current' => 'secret-full-header'), 'checked_at' => time()); }
    return array('scan_id' => sprintf('%024x', $number), 'completed' => 1700000000, 'checks' => $checks, 'policy' => tk_health_center_policy_defaults(), 'environment' => str_repeat('a', 64), 'performance' => array('ttfb' => 1.2, 'queries' => 200, 'slow_http' => 3, 'overdue_cron' => 2, 'failed_jobs' => 1000, 'failed_jobs_limited' => true, 'cron_limited' => false), 'probe' => 'secret-probe', 'token' => 'secret-token', 'headers' => array('private' => 'secret-header'));
}
verify_history($options === array() && $writes === 0, 'Loading history starts work or reads/writes state');
$snapshot = tk_health_center_history_snapshot(history_report(1));
verify_history($snapshot['id'] === sprintf('%024x', 1) && count($snapshot['checks']) === 1, 'Full snapshot metadata missing');
verify_history(strpos(serialize($snapshot), 'secret-') === false, 'History retained raw report/probe data');
verify_history(tk_health_center_history_snapshot(array('completed' => -1, 'checks' => array())) === array(), 'Invalid completed snapshot accepted');
for ($i = 1; $i <= 12; $i++) { tk_health_center_history_record(history_report($i)); }
$history = tk_health_center_history();
verify_history(count($history) === 10 && $history[0]['id'] === sprintf('%024x', 3) && $history[9]['id'] === sprintf('%024x', 12), 'History retention did not keep the latest 10 scans');
verify_history(count(array_unique(array_column($history, 'id'))) === 10, 'Scans in the same second were collapsed');
$before = $options; $before_writes = $writes;
tk_health_center_history_record(history_report(12, array('A' => 'critical')));
verify_history($options === $before && $writes === $before_writes, 'Existing full snapshot was overwritten by duplicate ID');
$partial = history_report(13); $partial['last_recheck'] = time();
tk_health_center_history_record($partial);
verify_history($options === $before && $writes === $before_writes, 'Partial recheck was archived as a full scan');
$options['tk_health_center_history'] = array();
$legacy = history_report(1); unset($legacy['scan_id'], $legacy['policy']);
tk_health_center_history_record(history_report(2), $legacy);
verify_history(count(tk_health_center_history()) === 2 && tk_health_center_history()[0]['policy'] === null, 'Intact legacy report was not retained as baseline');
$options['tk_health_center_history'] = array(); $legacy['last_recheck'] = time();
tk_health_center_history_record(history_report(2), $legacy);
verify_history(count(tk_health_center_history()) === 1, 'Mixed legacy report was presented as an intact full scan');
$large = history_report(3, array_combine(array_map(static function ($n) { return 'Check ' . $n; }, range(1, 120)), array_fill(0, 120, 'passed')));
$bounded = tk_health_center_history_snapshot($large);
verify_history(count($bounded['checks']) === 100 && $bounded['limited'], 'Per-snapshot storage is unbounded');
$options['tk_health_center_history'] = 'corrupt'; $before_writes = $writes;
verify_history(tk_health_center_history() === array() && $writes === $before_writes, 'Reading corrupt history wrote to storage');

$old_statuses = array('Resolved' => 'warning', 'New' => 'passed', 'Worsening' => 'warning', 'Improving' => 'critical', 'Unverified' => 'critical', 'Removed' => 'warning', 'Optional' => 'warning', 'New coverage' => 'unknown', 'Same' => 'passed');
$new_statuses = array('Resolved' => 'passed', 'New' => 'warning', 'Worsening' => 'critical', 'Improving' => 'warning', 'Unverified' => 'unknown', 'Optional' => 'info', 'New coverage' => 'passed', 'Same' => 'passed', 'Added failing check' => 'critical');
$older = tk_health_center_history_snapshot(history_report(10, $old_statuses));
$newer = tk_health_center_history_snapshot(history_report(11, $new_statuses));
$comparison = tk_health_center_history_compare($older, $newer);
verify_history($comparison['counts'] === array('new' => 2, 'resolved' => 1, 'worsened' => 1, 'improved' => 1, 'unverified' => 2, 'changed' => 2), 'Status transitions were classified incorrectly');
$changed_policy = $newer; $changed_policy['policy']['ttfb_warning'] = 1.1;
$comparison = tk_health_center_history_compare($older, $changed_policy);
verify_history($comparison['policy_changed'] && $comparison['counts']['resolved'] === 0 && $comparison['counts']['new'] === 0 && $comparison['counts']['worsened'] === 0 && $comparison['counts']['improved'] === 0, 'Policy changes were presented as verified improvements or worsening');
$no_policy = $older; $no_policy['policy'] = null;
verify_history(tk_health_center_history_compare($no_policy, $newer)['counts']['resolved'] === 0, 'Unknown legacy policy generated verified fixes');
$newer['performance']['ttfb'] = 0.9; $newer['performance']['queries'] = 150;
verify_history(tk_health_center_history_delta($older, $newer, 'ttfb') === '-0.300 s' && tk_health_center_history_delta($older, $newer, 'queries') === '-50', 'Measured metric deltas incorrect');
verify_history(tk_health_center_history_delta($older, $newer, 'failed_jobs') === 'Not comparable: sample limit', 'Lower-bound counts generated an exact delta');
$unmeasured = $newer; $unmeasured['performance']['ttfb'] = null;
verify_history(tk_health_center_history_delta($older, $unmeasured, 'ttfb') === 'Not comparable' && tk_health_center_history_metric($unmeasured, 'ttfb') === 'Not measured', 'Unknown metric was shown as zero');
$newer['environment'] = str_repeat('b', 64);
verify_history(tk_health_center_history_compare($older, $newer)['environment_changed'], 'Changed environment context omitted');

$evil = history_report(21, array('<script>unsafe</script>' => 'critical'));
$safe = history_report(22, array('<script>unsafe</script>' => 'passed'));
$options['tk_health_center_history'] = array(tk_health_center_history_snapshot($evil), tk_health_center_history_snapshot($safe));
$before = $options; $before_writes = $writes;
ob_start(); tk_health_center_history_html(); $html = ob_get_clean();
verify_history(strpos($html, '<script>unsafe</script>') === false && strpos($html, '&lt;script&gt;unsafe&lt;/script&gt;') !== false, 'History labels were not escaped');
verify_history(strpos($html, 'Scan 1') !== false && strpos($html, 'Scan 2') !== false && strpos($html, 'Resolved') !== false, 'Comparison/history missing visible scan labels');
$ids = array_column(tk_health_center_history(), 'id');
$reply = history_request(array('older' => $ids[0], 'newer' => $ids[1]));
verify_history($reply->success && strpos($reply->data['message'], 'No new scan') !== false, 'Read-only comparison failed');
verify_history($options === $before && $writes === $before_writes, 'Viewing/comparing history modified saved data');
verify_history(history_request(array('older' => $ids[1], 'newer' => $ids[0]))->status === 400, 'Reversed comparison accepted');
verify_history(history_request(array('older' => $ids[0], 'newer' => $ids[0]))->status === 400, 'Same-scan comparison accepted');
verify_history(history_request(array('older' => str_repeat('f', 24), 'newer' => $ids[1]))->status === 409, 'Evicted scan was not rejected');
verify_history(history_request(array('older' => array(), 'newer' => $ids[1]))->status === 400, 'Malformed selection accepted');
verify_history(history_request(array('older' => $ids[0], 'newer' => $ids[1]), 'GET')->status === 405, 'GET comparison accepted');
foreach (array('capable', 'manageable', 'licensed', 'nonce') as $flag) { $GLOBALS[$flag] = false; verify_history(history_request(array('older' => $ids[0], 'newer' => $ids[1]))->status === 403, 'Unauthorized history access: ' . $flag); $GLOBALS[$flag] = true; }
verify_history($options === $before && $writes === $before_writes, 'Rejected history actions modified stored data');
echo 'PASS: Health Center history (' . $checks . " checks)\n";
