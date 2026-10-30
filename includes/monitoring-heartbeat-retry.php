<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/job-lock.php';

function tk_heartbeat_interval(): int {
    return max(900, min(DAY_IN_SECONDS, (int) tk_get_option('heartbeat_interval', DAY_IN_SECONDS)));
}

function tk_heartbeat_cron_schedules(array $schedules): array {
    $schedules['tk_heartbeat_interval'] = array('interval' => tk_heartbeat_interval(), 'display' => 'Tool Kits heartbeat');
    return $schedules;
}

function tk_heartbeat_retry_schedule(int $timestamp): void {
    $next = wp_next_scheduled('tk_heartbeat_retry');
    if (!$next || $next !== $timestamp) {
        wp_clear_scheduled_hook('tk_heartbeat_retry');
        wp_schedule_single_event($timestamp, 'tk_heartbeat_retry');
    }
}

/** A fresh payload/signature is generated on every attempt; credentials are never queued. */
function tk_heartbeat_run(bool $retry = false, bool $manual = false): array {
    if (!$manual && !tk_heartbeat_enabled()) {
        wp_clear_scheduled_hook('tk_heartbeat_retry'); delete_option('tk_jobs_heartbeat');
        return array('ok' => false, 'message' => 'Automatic heartbeat is disabled.');
    }
    $lease = tk_job_lock('heartbeat', 180);
    if (!$lease) { return array('ok' => false, 'message' => 'Another heartbeat is in progress.'); }
    try {
        $state = get_option('tk_jobs_heartbeat', array());
        if ($retry && empty($state['next'])) { return array('ok' => false, 'message' => 'No heartbeat retry is pending.'); }
        if (($state['next'] ?? 0) > time()) {
            tk_heartbeat_retry_schedule((int) $state['next']);
            return array('ok' => false, 'message' => 'Heartbeat retry is waiting until ' . gmdate('Y-m-d H:i:s', $state['next']) . ' UTC.');
        }
        $attempt = ($state['status'] ?? '') === 'pending' ? (int) ($state['attempts'] ?? 0) + 1 : 1;
        try { $result = tk_heartbeat_send(); }
        catch (Throwable $error) {
            $result = array('ok' => false, 'message' => 'Heartbeat could not be prepared or sent. Check collector connectivity.', 'retryable' => true, 'http_status' => 0);
        }
        tk_heartbeat_record_result($result);
        wp_clear_scheduled_hook('tk_heartbeat_retry');
        if (!empty($result['ok'])) { delete_option('tk_jobs_heartbeat'); return $result; }
        $delays = array(60, 300, 900, 3600, 14400);
        $again = tk_heartbeat_enabled() && !empty($result['retryable']) && $attempt <= count($delays);
        $next = $again ? time() + max((int) ($result['retry_after'] ?? 0), $delays[$attempt - 1] + wp_rand(0, 30)) : 0;
        update_option('tk_jobs_heartbeat', array('attempts' => $attempt, 'status' => $again ? 'pending' : 'stopped',
            'next' => $next, 'http_status' => (int) ($result['http_status'] ?? 0), 'checked_at' => time()), false);
        if ($again) { tk_heartbeat_retry_schedule($next); }
        return $result;
    } finally { tk_job_unlock('heartbeat', $lease); }
}

function tk_heartbeat_retry_run(): void { tk_heartbeat_run(true); }

function tk_heartbeat_settings_save(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Forbidden'); }
    tk_require_admin_post('tk_heartbeat_settings');
    $interval = (int) ($_POST['heartbeat_interval'] ?? DAY_IN_SECONDS);
    if (!in_array($interval, array(900, 3600, 21600, 86400), true)) { wp_die('Invalid heartbeat interval.'); }
    tk_update_option('heartbeat_interval', $interval);
    tk_heartbeat_schedule();
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-monitoring'));
    exit;
}
