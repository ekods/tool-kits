<?php
if (!defined('ABSPATH')) { exit; }

function tk_health_center_recheck_target(): array {
    $id = $_POST['check'] ?? '';
    if (!is_string($id) || strlen($id) > 100) { wp_send_json_error(array('message' => 'Invalid check identifier.'), 400); return array(); }
    if ($id === '') { return array(); }
    $group = tk_health_center_check_group($id);
    if ($group === '') { wp_send_json_error(array('message' => 'Unknown check identifier.'), 400); return array(); }
    $saved = get_option('tk_health_center_report', array());
    if (is_array($saved) && !empty($saved['completed'])) {
        foreach (($saved['checks'] ?? array()) as $check) {
            if (tk_health_check_id($check['label']) === $id) { return array('id' => $id, 'group' => $group); }
        }
    }
    wp_send_json_error(array('message' => 'Run a full scan before rechecking this result.'), 409);
    return array();
}

/** Merge one freshly measured row; other results and the full-scan timestamp stay intact. */
function tk_health_center_save_recheck(array &$state): void {
    $saved = get_option('tk_health_center_report', array());
    if (!is_array($saved) || empty($saved['completed'])) { throw new RuntimeException('Saved report unavailable.'); }
    $id = $state['target']['id']; $replacement = null; $found = false;
    foreach ($state['report']['checks'] as $check) { if (tk_health_check_id($check['label']) === $id) { $replacement = $check; break; } }
    if ($replacement === null) { throw new RuntimeException('Selected check was not measured.'); }
    foreach ($saved['checks'] as &$check) {
        if (tk_health_check_id($check['label']) === $id) { $check = $replacement; $found = true; break; }
    }
    unset($check);
    if (!$found) { throw new RuntimeException('Selected result no longer exists.'); }
    $metrics = array(
        'homepage-ttfb' => array('ttfb', 'cache'),
        'homepage-database-query-count' => array('queries'),
        'slow-outbound-http-requests' => array('slow_http'),
        'overdue-cron-events' => array('overdue_cron', 'cron_limited'),
        'recorded-failed-jobs' => array('failed_jobs', 'failed_jobs_limited'),
    );
    foreach (($metrics[$id] ?? array()) as $key) { $saved['performance'][$key] = $state['report']['performance'][$key]; }
    if ($id === 'php2shell-runtime-patch') { $saved['php_security'] = $state['report']['php_security']; }
    $saved['last_recheck'] = time();
    update_option('tk_health_center_report', $saved, false);
    $state['report'] = $saved;
}

function tk_health_center_settings_ajax(): void {
    tk_health_center_authorize();
    try { $policy = tk_health_center_validate_policy($_POST); }
    catch (InvalidArgumentException $error) { wp_send_json_error(array('message' => $error->getMessage()), 400); return; }
    $existing = get_option('tk_health_center_lock', array());
    if ($existing && (int) ($existing['expires'] ?? 0) < time()) { delete_option('tk_health_center_lock'); delete_option('tk_health_center_step_lock'); }
    $lock = array('owner' => get_current_user_id(), 'expires' => time() + 60, 'purpose' => 'settings');
    if (!add_option('tk_health_center_lock', $lock, '', false)) { wp_send_json_error(array('message' => 'Wait for the current scan or settings save to finish.'), 409); return; }
    try {
        update_option('tk_health_center_settings', $policy, false);
        if (get_option('tk_health_center_settings', array()) !== $policy) { wp_send_json_error(array('message' => 'Thresholds could not be saved. Please try again.'), 500); return; }
    }
    finally { delete_option('tk_health_center_lock'); }
    $report = get_option('tk_health_center_report', array());
    ob_start(); tk_health_center_report_html(is_array($report) ? $report : array(), !empty($_POST['full'])); $html = ob_get_clean();
    wp_send_json_success(array('message' => 'Thresholds saved. Run a full scan or recheck individual results to apply the new policy.', 'html' => $html));
}
