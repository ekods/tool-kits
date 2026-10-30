<?php
// Isolated SQL-backed fixture: no wp-config, site database, email or network access.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
error_reporting(E_ALL);
set_error_handler(static function ($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } return false; });
class WP_Error { public function get_error_message() { return 'transport echoed secret'; } }
class CleanupAjaxFixtureResponse extends Exception {
    public $status; public $data;
    public function __construct($status, $data) { parent::__construct('AJAX fixture'); $this->status = $status; $this->data = $data; }
}
function tk_is_admin_user() { return $GLOBALS['can_manage'] ?? false; }
function tk_toolkits_is_locked() { return $GLOBALS['settings_locked'] ?? false; }
function get_current_user_id() { return 7; }
function check_ajax_referer($action, $field) {
    if (($_POST[$field] ?? '') !== 'fixture-nonce') { throw new CleanupAjaxFixtureResponse(403, array()); }
}
function wp_send_json_error($data, $status = 200) { throw new CleanupAjaxFixtureResponse($status, $data); }
function wp_send_json_success($data) { throw new CleanupAjaxFixtureResponse(200, $data); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_unslash($value) { return $value; }
class JobFixtureDB {
    public $options = 'wp_options'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
    public $comments = 'wp_comments'; public $commentmeta = 'wp_commentmeta'; public $prefix = 'wp_'; public $last_error = '';
    public $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (array('options' => 'option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT',
            'posts' => 'ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT',
            'postmeta' => 'meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT',
            'comments' => 'comment_ID INTEGER PRIMARY KEY, comment_approved TEXT',
            'commentmeta' => 'meta_id INTEGER PRIMARY KEY AUTOINCREMENT, comment_id INTEGER, meta_key TEXT, meta_value TEXT') as $table => $columns) {
            $this->pdo->exec('CREATE TABLE wp_' . $table . ' (' . $columns . ')');
        }
    }
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function prepare($sql, ...$args) {
        return preg_replace_callback('/%[sd]/', function ($m) use (&$args) { $value = array_shift($args); return $m[0] === '%d' ? (string) (int) $value : $this->pdo->quote((string) $value); }, $sql);
    }
    private function run($sql) {
        $this->last_error = '';
        if (!empty($GLOBALS['fail_read']) && strpos($sql, ' r WHERE ') !== false) { $this->last_error = 'fixture read error'; return false; }
        $sql = str_replace(' AS UNSIGNED)', ' AS INTEGER)', $sql);
        $sql = preg_replace("/LIKE ('(?:[^']|'')*')/", "$0 ESCAPE '\\'", $sql);
        try { return $this->pdo->query($sql); } catch (Throwable $error) { $this->last_error = $error->getMessage(); throw $error; }
    }
    public function query($sql) { $result = $this->run($sql); return $result ? $result->rowCount() : false; }
    public function get_var($sql) { $result = $this->run($sql); return $result ? $result->fetchColumn() : null; }
    public function get_col($sql) {
        if (strpos($sql, 'SHOW TABLES') === 0) { return array('wp_posts'); }
        return $this->run($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
    public function get_results($sql, $output = null) {
        if (strpos($sql, 'OPTIMIZE TABLE') === 0) { return array(array('Msg_type' => !empty($GLOBALS['fail_optimize']) ? 'error' : 'status', 'Msg_text' => 'fixture')); }
        $result = $this->run($sql); return $result ? $result->fetchAll(PDO::FETCH_ASSOC) : null;
    }
}
$wpdb = new JobFixtureDB();
$settings = array('heartbeat_enabled' => 1, 'license_key' => 'SYNTHETIC-SECRET', 'toolkits_alert_webhook' => 'https://hooks.example/secret');
$events = array(); $audit = array(); $mail = array(); $http = array(); $heartbeats = array(); $mail_results = array(); $http_results = array(); $hb_results = array();
$checks = 0; $wp_version = '6.6';
function check_job($condition, $message) { if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } $GLOBALS['checks']++; }
function maybe_serialize($value) { return is_array($value) ? serialize($value) : (string) $value; }
function get_option($key, $default = false) {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM wp_options WHERE option_name = %s', $key));
    if ($value === false) { return $default; }
    if (strpos($value, 'a:') === 0) { return unserialize($value, array('allowed_classes' => false)); }
    return $value;
}
function add_option($key, $value, $unused = '', $autoload = null) {
    global $wpdb;
    check_job($autoload === false, 'job state autoloads');
    return $wpdb->query($wpdb->prepare('INSERT OR IGNORE INTO wp_options (option_name, option_value) VALUES (%s, %s)', $key, maybe_serialize($value))) === 1;
}
function update_option($key, $value, $autoload = null) {
    global $wpdb;
    check_job($autoload === false, 'job update autoloads');
    $wpdb->query($wpdb->prepare('INSERT INTO wp_options (option_name, option_value) VALUES (%s, %s) ON CONFLICT(option_name) DO UPDATE SET option_value = excluded.option_value', $key, maybe_serialize($value)));
    return true;
}
function delete_option($key) { global $wpdb; return $wpdb->query($wpdb->prepare('DELETE FROM wp_options WHERE option_name = %s', $key)) > 0; }
function wp_cache_delete($key, $group) {}
function get_transient($key) { return get_option('_transient_' . $key); }
function set_transient($key, $value, $ttl) { update_option('_transient_timeout_' . $key, time() + $ttl, false); return update_option('_transient_' . $key, $value, false); }
function delete_transient($key) { delete_option('_transient_timeout_' . $key); return delete_option('_transient_' . $key); }
function delete_site_transient($key) { delete_option('_site_transient_timeout_' . $key); return delete_option('_site_transient_' . $key); }
function is_multisite() { return false; }
function get_site_option($key, $default = false) { return $default; }
function tk_get_option($key, $default = null) { return $GLOBALS['settings'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['settings'][$key] = $value; }
function tk_update_options($values) { $GLOBALS['settings'] = array_merge($GLOBALS['settings'], $values); }
function add_action($hook, $callback, $priority = 10, $args = 1) {}
function add_filter($hook, $callback, $priority = 10, $args = 1) {}
function wp_next_scheduled($hook) { return $GLOBALS['events'][$hook]['timestamp'] ?? false; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['events'][$hook]); }
function wp_schedule_single_event($timestamp, $hook) { $GLOBALS['events'][$hook] = array('timestamp' => $timestamp); return true; }
function wp_schedule_event($timestamp, $schedule, $hook) { $GLOBALS['events'][$hook] = array('timestamp' => $timestamp, 'interval' => tk_heartbeat_interval()); return true; }
function wp_get_scheduled_event($hook) { return isset($GLOBALS['events'][$hook]) ? (object) $GLOBALS['events'][$hook] : false; }
function wp_rand($min, $max) { return $min; }
function wp_mail($email, $subject, $message) {
    if (!empty($GLOBALS['throw_mail'])) { throw new RuntimeException('private smtp details'); }
    $GLOBALS['mail'][] = compact('email', 'subject', 'message'); return array_shift($GLOBALS['mail_results']) ?? true;
}
function wp_safe_remote_post($url, $args) { $GLOBALS['http'][] = compact('url', 'args'); return array_shift($GLOBALS['http_results']) ?? array('code' => 200, 'headers' => array()); }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_json_encode($value) { return json_encode($value); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function tk_toolkits_audit_log($action, $data = array()) { $GLOBALS['audit'][] = compact('action', 'data'); }
function tk_heartbeat_collector_url() { return 'https://collector.example/api/toolkits/heartbeat'; }
function tk_heartbeat_auth_key() { return 'SYNTHETIC-AUTH'; }
function tk_heartbeat_record_diagnostic_result($result, $url) { $GLOBALS['diagnostic'] = $result; }
function tk_toolkits_signed_post($url, $body, $timestamp, $args) { $GLOBALS['heartbeats'][] = json_decode($body, true); return array_shift($GLOBALS['hb_results']) ?? array('code' => 200, 'body' => '{"ok":true}'); }
function home_url($path = '/') { return 'https://site.example' . $path; }
function get_bloginfo($key) { return $key === 'version' ? '6.6' : 'Fixture site'; }
function tk_toolkits_install_id() { return 'fixture'; }
function tk_license_env() { return 'test'; }
function tk_db_cleanup_effective_revision_limit() { return 5; }
function tk_db_cleanup_acf_usage_scan($force = false) { return array('safe_to_remove' => false); }
if (!in_array('--inactive-acf', $argv, true)) { function acf_get_field($key) { return $key === 'field_live'; } }
function wp_delete_post($id, $force) {
    global $wpdb;
    if (($GLOBALS['fail_post'] ?? 0) === $id) { return false; }
    $wpdb->query('DELETE FROM wp_postmeta WHERE post_id = ' . (int) $id);
    return $wpdb->query('DELETE FROM wp_posts WHERE ID = ' . (int) $id) > 0;
}
function wp_delete_comment($id, $force) {
    global $wpdb; $wpdb->query('DELETE FROM wp_commentmeta WHERE comment_id = ' . (int) $id);
    return $wpdb->query('DELETE FROM wp_comments WHERE comment_ID = ' . (int) $id) > 0;
}
function delete_metadata_by_mid($type, $id) { global $wpdb; return $wpdb->query('DELETE FROM wp_' . ($type === 'post' ? 'postmeta' : 'commentmeta') . ' WHERE meta_id = ' . (int) $id) > 0; }
function delete_post_meta($id, $key) { global $wpdb; return $wpdb->query($wpdb->prepare('DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = %s', $id, $key)) > 0; }
require dirname(__DIR__) . '/includes/db-cleanup-job.php';
require dirname(__DIR__) . '/includes/security-alerts.php';
require dirname(__DIR__) . '/includes/monitoring-heartbeat.php';

function cleanup_command($command, $job, $owner = 7) { return tk_db_cleanup_job_command($command, array('job_id' => $job['id']), $owner); }
function finish_cleanup($job) {
    for ($step = 0; $step < 100 && $job['status'] === 'running'; $step++) { $job = cleanup_command('step', $job); }
    check_job($job['status'] === 'completed', 'cleanup did not finish: ' . ($job['error'] ?? ''));
    return $job;
}

// Lease contention, stale takeover, and a late release from a former owner.
$lease = tk_job_lock('fixture', 10);
check_job($lease && tk_job_lock('fixture') === null, 'concurrent worker acquired lock');
update_option('tk_jobs_lock_fixture', array('token' => 'expired', 'expires' => time() - 1), false);
$successor = tk_job_lock('fixture'); tk_job_unlock('fixture', $lease);
check_job(get_option('tk_jobs_lock_fixture') === $successor, 'old worker removed a newer lease'); tk_job_unlock('fixture', $successor);

// Primary-key batching, pause/resume across reloads, owner checks, and high-water mark.
for ($id = 1; $id <= 125; $id++) { $wpdb->query("INSERT INTO wp_posts VALUES ($id, 'revision', 'inherit')"); }
$wpdb->query("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, 'dependent', 'remove')");
$job = tk_db_cleanup_job_command('start', array('do_revisions' => 1), 7);
$wpdb->query("INSERT INTO wp_posts VALUES (126, 'revision', 'inherit')");
$job = cleanup_command('step', $job);
check_job($job['counts']['revisions'] === 50 && (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_posts') === 76, 'cleanup batch not capped at 50');
$job = cleanup_command('pause', $job); $paused = cleanup_command('step', $job);
check_job($paused === $job, 'paused job performed deletions');
try { cleanup_command('resume', $job, 8); check_job(false, 'another user resumed cleanup'); } catch (RuntimeException $error) { check_job(strpos($error->getMessage(), 'another administrator') !== false, 'wrong owner error'); }
try { tk_db_cleanup_job_command('step', array('job_id' => 'obsolete'), 7); check_job(false, 'stale tab accepted'); } catch (RuntimeException $error) {}
$job = finish_cleanup(cleanup_command('resume', $job));
check_job($job['counts']['revisions'] === 125 && (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_posts') === 1, 'new revision was deleted or old revisions skipped');
check_job((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_postmeta') === 0, 'dependent metadata not removed');

// Read and delete failures pause and preserve a retryable cursor instead of reporting false success.
$job = tk_db_cleanup_job_command('start', array('do_revisions' => 1), 7);
$GLOBALS['fail_read'] = true; $job = cleanup_command('step', $job); unset($GLOBALS['fail_read']);
check_job($job['status'] === 'paused' && $job['cursor'] === 0 && $job['error'], 'read failure reported as completion');
$job = cleanup_command('resume', $job); $GLOBALS['fail_post'] = 126; $job = cleanup_command('step', $job); unset($GLOBALS['fail_post']);
check_job($job['status'] === 'paused' && $job['cursor'] === 0, 'delete failure advanced cursor');
finish_cleanup(cleanup_command('resume', $job));

$job = tk_db_cleanup_job_command('start', array('do_optimize' => 1), 7);
$GLOBALS['fail_optimize'] = true; $job = cleanup_command('step', $job); unset($GLOBALS['fail_optimize']);
check_job($job['status'] === 'paused' && $job['cursor'] === 0, 'table optimization error was reported as success');
$job = finish_cleanup(cleanup_command('resume', $job));
check_job($job['counts']['optimized_tables'] === 1, 'table optimization was not resumed');

// Expired-only preserves live and non-expiring transients and Tool Kits nonce/lock state.
set_transient('old', 'expired', -60); set_transient('live', 'valid', 3600); update_option('_transient_forever', 'valid', false);
set_transient('tk_nonce', 'security state', -60); update_option('xtransient_timeout_old', '1', false);
$job = finish_cleanup(tk_db_cleanup_job_command('start', array('do_transients' => 1), 7));
check_job(get_transient('old') === false && get_transient('live') === 'valid' && get_transient('forever') === 'valid', 'expired-only removed a valid cache');
check_job(get_transient('tk_nonce') === 'security state' && get_option('xtransient_timeout_old') === '1', 'prefix wildcard deleted security or unrelated data');
finish_cleanup(tk_db_cleanup_job_command('start', array('do_transients' => 1, 'transients_all' => 1), 7));
check_job(get_transient('live') === false && get_transient('forever') === false && get_transient('tk_nonce') !== false, 'explicit clear-all or exclusions failed');

// ACF reference/value pairing, registered-field protection, and inactive-ACF fail-closed behavior.
$wpdb->query("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, '_old', 'field_orphan'), (1, 'old', 'remove'), (1, '_live', 'field_live'), (1, 'live', 'keep')");
update_option('_acf_old', 'field_orphan', false); update_option('acf_old', 'remove', false);
$job = tk_db_cleanup_job_command('start', array('do_orphaned_acf' => 1), 7);
if (in_array('--inactive-acf', $argv, true)) {
    $job = cleanup_command('step', $job);
    check_job($job['status'] === 'paused' && get_option('acf_old') === 'remove', 'inactive ACF was deleted despite incomplete usage evidence');
    cleanup_command('cancel', $job);
} else {
    $job = finish_cleanup($job);
    check_job(($job['counts']['acf_values'] ?? 0) === 1 && get_option('acf_old') === false, 'orphaned ACF values not removed or counted');
    check_job((int) $wpdb->get_var("SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = 'live'") === 1, 'registered ACF value deleted');
}

// Alerts enqueue without sending, group timestamp-only duplicates, and checkpoint channels independently.
$settings['toolkits_alert_email'] = 'admin@example.test';
tk_security_alert_send('Security event', "User: admin\nTime: 12:00\n");
tk_security_alert_send('Security event', "User: admin\nTime: 12:01\n");
check_job(!$mail && !$http, 'enqueue performed synchronous delivery');
$keys = $wpdb->get_col("SELECT option_name FROM wp_options WHERE option_name LIKE 'tk_jobs_alert_%'");
check_job(count($keys) === 1 && get_option($keys[0])['duplicates'] === 1, 'duplicates were not grouped');
$http_results[] = array('code' => 429, 'headers' => array('retry-after' => '300'));
tk_security_alert_dispatch(); $alert = get_option($keys[0]);
check_job(count($mail) === 1 && count($http) === 1 && $alert['channels']['email']['status'] === 'sent', 'channel delivery failed');
check_job($alert['channels']['webhook']['next'] >= time() + 299 && $alert['status'] === 'pending', 'webhook Retry-After ignored');
tk_security_alert_dispatch(); check_job(count($mail) === 1 && count($http) === 1, 'worker retried before due');
$alert['channels']['webhook']['next'] = time() - 1; update_option($keys[0], $alert, false); tk_security_alert_dispatch();
check_job(count($mail) === 1 && count($http) === 2 && get_option($keys[0])['status'] === 'sent', 'webhook retry resent successful email');
check_job(!isset(get_option($keys[0])['message']) && !isset(get_option($keys[0])['channels']['webhook']['target']), 'terminal alert retained sensitive content');

tk_security_alert_send('Permanent failure', 'message'); $http_results[] = array('code' => 403, 'headers' => array()); tk_security_alert_dispatch();
$failed = array_filter($wpdb->get_col("SELECT option_name FROM wp_options WHERE option_name LIKE 'tk_jobs_alert_%'"), function ($key) { return get_option($key)['status'] === 'failed'; });
check_job(count($failed) === 1, 'permanent webhook rejection retried forever');
tk_security_alert_send('Disabled later', 'message'); $settings['toolkits_alert_enabled'] = 0; $sent_count = count($mail); tk_security_alert_dispatch();
check_job(count($mail) === $sent_count, 'disabled alert was delivered'); $settings['toolkits_alert_enabled'] = 1;

tk_security_alert_send('Retry exhaustion', 'retry fixture');
$retry_key = $wpdb->get_col("SELECT option_name FROM wp_options WHERE option_name LIKE 'tk_jobs_alert_%'")[0];
$http_before = count($http); $mail_before = count($mail);
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $alert = get_option($retry_key);
    foreach ($alert['channels'] as &$channel) { if ($channel['status'] === 'pending') { $channel['next'] = time() - 1; } } unset($channel);
    update_option($retry_key, $alert, false); $mail_results[] = false; tk_security_alert_dispatch();
}
check_job(get_option($retry_key)['status'] === 'failed' && count($mail) === $mail_before + 6 && count($http) === $http_before + 1, 'email retries were unbounded or resent webhook');
$settings['toolkits_alert_enabled'] = 0; tk_security_alert_dispatch(); $settings['toolkits_alert_enabled'] = 1;
for ($entry = 0; $entry < 100; $entry++) {
    add_option('tk_jobs_alert_capacity_' . $entry, array('status' => 'sent', 'created' => time() - 700, 'finished' => time() - 700), '', false);
}
tk_security_alert_send('Full queue', 'must be audited');
check_job(end($audit)['action'] === 'security_alert_queue_full', 'full queue did not report dropped event');
tk_security_alert_dispatch();
check_job((int) $wpdb->get_var("SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'tk_jobs_alert_%'") === 0, 'terminal alerts did not expire');
tk_security_alert_send('Throwing mail hook', 'private event'); $GLOBALS['throw_mail'] = true; tk_security_alert_dispatch(); unset($GLOBALS['throw_mail']);
$exception_key = $wpdb->get_col("SELECT option_name FROM wp_options WHERE option_name LIKE 'tk_jobs_alert_%'")[0];
$exception_job = get_option($exception_key);
check_job($exception_job['channels']['email']['attempts'] === 1 && $exception_job['channels']['email']['next'] >= time() + 59, 'throwing mail hook bypassed attempt/backoff limits');
check_job(strpos(serialize($exception_job), 'private smtp details') === false, 'queue retained private hook exception');

// Heartbeat backoff, regenerated payload, Retry-After, permanent errors, and bounded retries.
$hb_results[] = new WP_Error(); $result = tk_heartbeat_run(); $state = get_option('tk_jobs_heartbeat');
check_job(!$result['ok'] && $state['next'] >= time() + 59 && $state['attempts'] === 1, 'transport failure did not schedule retry');
$count = count($heartbeats); tk_heartbeat_run(true); check_job(count($heartbeats) === $count, 'heartbeat retry ignored due time');
$state['next'] = time() - 1; update_option('tk_jobs_heartbeat', $state, false);
$settings['license_key'] = 'ROTATED-SECRET'; tk_heartbeat_run(true);
check_job(end($heartbeats)['license_key'] === 'ROTATED-SECRET' && !get_option('tk_jobs_heartbeat') && !wp_next_scheduled('tk_heartbeat_retry'), 'retry used stored credentials or did not clear state');
$hb_results[] = array('code' => 429, 'headers' => array('retry-after' => gmdate(DATE_RFC7231, time() + 600)), 'body' => 'SENSITIVE-ECHO');
tk_heartbeat_run(); $state = get_option('tk_jobs_heartbeat');
check_job($state['next'] >= time() + 599 && strpos(json_encode($GLOBALS['diagnostic']), 'SENSITIVE-ECHO') === false, 'Retry-After date or diagnostic redaction failed');
$state['next'] = time() - 1; update_option('tk_jobs_heartbeat', $state, false);
$hb_results[] = array('code' => 401, 'body' => 'echo ROTATED-SECRET'); tk_heartbeat_run(true);
check_job(get_option('tk_jobs_heartbeat')['status'] === 'stopped' && !wp_next_scheduled('tk_heartbeat_retry'), 'authentication error scheduled retry');
$hb_results[] = array('code' => 202, 'body' => '{"ok":false,"license_status":"invalid","message":"echo secret"}'); tk_heartbeat_run();
check_job(get_option('tk_jobs_heartbeat')['status'] === 'stopped', 'license warning scheduled retry');
for ($attempt = 1; $attempt <= 6; $attempt++) {
    $state = get_option('tk_jobs_heartbeat', array()); $state['next'] = 0; update_option('tk_jobs_heartbeat', $state, false);
    $hb_results[] = array('code' => 503, 'headers' => array(), 'body' => 'secret'); tk_heartbeat_run();
}
check_job(get_option('tk_jobs_heartbeat')['attempts'] === 6 && get_option('tk_jobs_heartbeat')['status'] === 'stopped', 'heartbeat retries not bounded');
$lease = tk_job_lock('heartbeat'); $count = count($heartbeats); tk_heartbeat_run();
check_job(count($heartbeats) === $count, 'concurrent heartbeat duplicated send'); tk_job_unlock('heartbeat', $lease);
$settings['heartbeat_enabled'] = 0; tk_heartbeat_run(true);
check_job(!get_option('tk_jobs_heartbeat') && !wp_next_scheduled('tk_heartbeat_retry'), 'disabled heartbeat retained retry');
$settings['heartbeat_enabled'] = 1; $settings['heartbeat_interval'] = 900; tk_heartbeat_schedule();
check_job($events['tk_heartbeat_cron']['interval'] === 900, 'configured heartbeat interval ignored');
$past = time() - 60; update_option('tk_jobs_heartbeat', array('next' => $past, 'status' => 'pending', 'attempts' => 1), false);
$events['tk_heartbeat_retry'] = array('timestamp' => $past); tk_heartbeat_schedule();
check_job($events['tk_heartbeat_retry']['timestamp'] === $past, 'page loads postpone an overdue retry indefinitely');

// Destructive AJAX endpoint refuses missing capability, wrong method, locked settings and missing nonce.
$before = get_option('tk_jobs_cleanup');
foreach (array(array('GET', true, false, 'fixture-nonce'), array('POST', false, false, 'fixture-nonce'),
    array('POST', true, true, 'fixture-nonce'), array('POST', true, false, 'bad-nonce')) as $case) {
    list($_SERVER['REQUEST_METHOD'], $GLOBALS['can_manage'], $GLOBALS['settings_locked'], $nonce) = $case;
    $_POST = array('command' => 'start', 'do_revisions' => 1, '_tk_nonce' => $nonce);
    try { tk_db_cleanup_job_ajax(); check_job(false, 'unauthorized cleanup AJAX returned normally'); }
    catch (CleanupAjaxFixtureResponse $response) { check_job($response->status === 403, 'cleanup AJAX did not reject unsafe request'); }
    check_job(get_option('tk_jobs_cleanup') === $before, 'unsafe AJAX request mutated cleanup state');
}
$_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['can_manage'] = true; $GLOBALS['settings_locked'] = false;
$_POST = array('command' => 'start', 'do_revisions' => 1, '_tk_nonce' => 'fixture-nonce');
try { tk_db_cleanup_job_ajax(); } catch (CleanupAjaxFixtureResponse $response) {
    check_job($response->status === 200 && $response->data['owner'] === 7, 'valid cleanup AJAX could not start');
}
echo 'PASS: ' . $checks . " maintenance job checks (" . (in_array('--inactive-acf', $argv, true) ? 'inactive ACF' : 'active ACF') . ")\n";
