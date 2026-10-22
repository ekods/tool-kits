<?php
if (!defined('ABSPATH')) { exit; }

function tk_html_scan_normalize_finding(array $finding): array {
    $finding += array('rule' => 'legacy_finding', 'severity' => 'low', 'reason' => '', 'line' => 1, 'host' => '', 'snippet' => '', 'source' => '', 'source_url' => '', 'post_id' => 0);
    if ($finding['severity'] === 'review') { $finding['severity'] = 'low'; }
    return $finding + array('rule_id' => strtoupper($finding['rule']), 'action' => in_array($finding['severity'], array('high', 'critical'), true) ? 'REPORT' : 'REVIEW', 'classification' => 'legacy_unverified', 'confidence' => .5, 'occurrences' => 1, 'affected_urls' => $finding['source_url'] !== '' ? array($finding['source_url']) : array(), 'timestamp' => 0, 'fingerprint' => hash('sha256', $finding['source'] . '|' . $finding['line'] . '|' . $finding['rule'] . '|' . $finding['snippet']));
}

function tk_html_scan_statistics(array $report): array {
    $stats = array('critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0, 'needs_review' => 0, 'suppressed' => 0, 'duplicates_grouped' => 0, 'pages_scanned' => (int) ($report['pages_scanned'] ?? 0), 'files_analyzed' => (int) ($report['files_analyzed'] ?? 0));
    foreach (array('findings', 'suppressed') as $bucket) {
        foreach ($report[$bucket] ?? array() as $item) {
            $item = tk_html_scan_normalize_finding($item); $occurrences = max(1, (int) $item['occurrences']);
            if (isset($stats[$item['severity']])) { $stats[$item['severity']]++; }
            if ($item['action'] === 'REVIEW') { $stats['needs_review']++; }
            if ($item['action'] === 'SKIP') { $stats['suppressed'] += $occurrences; }
            $stats['duplicates_grouped'] += $occurrences - 1;
        }
    }
    $stats['suppression_audit_overflow'] = (int) ($report['suppression_audit_overflow'] ?? 0);
    $stats['suppressed'] += $stats['suppression_audit_overflow'];
    return $stats;
}

/** Merge repeated public markup at the same DOM location; never merge independent DB records/files/attributes. */
function tk_html_scan_record(array &$state, array $finding, string $source, string $source_url = '', int $post_id = 0): void {
    $public = strpos($source, 'Public HTML / ') === 0;
    $finding['source'] = $source; $finding['source_url'] = $source_url; $finding['post_id'] = $post_id;
    $identity = $public ? 'public_html' : $source;
    $fingerprint = hash('sha256', implode('|', array($finding['rule_id'] ?? $finding['rule'], $identity, $finding['location'] ?? ('line:' . $finding['line']), $finding['evidence_hash'] ?? hash('sha256', $finding['snippet']), $finding['resource_identifier'] ?? $finding['host'], $finding['severity'], $finding['classification'] ?? '', $finding['action'] ?? '')));
    $finding['fingerprint'] = $fingerprint; $finding['timestamp'] = time(); $finding['occurrences'] = max(1, (int) ($finding['occurrences'] ?? 1)); $finding['affected_urls'] = $source_url !== '' ? array($source_url) : ($finding['affected_urls'] ?? array());
    if (($finding['action'] ?? '') === 'SKIP' && isset($state['restored'][$fingerprint])) { $finding['original_action'] = 'SKIP'; $finding['original_severity'] = $finding['severity']; $finding['action'] = 'REVIEW'; $finding['severity'] = 'low'; $finding['classification'] = 'administrator_restored'; }
    $finding = tk_html_scan_normalize_finding($finding);
    $bucket = $finding['action'] === 'SKIP' ? 'suppressed' : 'findings';
    if (!isset($state['report'][$bucket])) { $state['report'][$bucket] = array(); }
    foreach ($state['report'][$bucket] as &$existing) {
        if (($existing['fingerprint'] ?? '') !== $fingerprint) { continue; }
        $existing['occurrences'] += $finding['occurrences']; $existing['last_seen'] = time();
        $existing['affected_urls'] = array_slice(array_values(array_unique(array_merge($existing['affected_urls'], $finding['affected_urls']))), 0, 50);
        return;
    }
    unset($existing);
    if (count($state['report'][$bucket]) >= 300) {
        tk_html_scan_issue($state, $bucket . ': 300 distinct groups reached; additional evidence was outside the retained report budget.');
        if ($bucket === 'suppressed') { $state['report']['suppression_audit_overflow'] = ($state['report']['suppression_audit_overflow'] ?? 0) + 1; }
        return;
    }
    unset($finding['evidence_hash']);
    $state['report'][$bucket][] = $finding;
}

function tk_html_scan_restore_ajax(): void {
    tk_html_scan_authorize();
    $fingerprint = isset($_POST['fingerprint']) && is_string($_POST['fingerprint']) ? $_POST['fingerprint'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) { wp_send_json_error(array('message' => 'Invalid finding fingerprint.'), 400); return; }
    if ((int) get_option('tk_html_scan_step_lock', 0) < time()) { delete_option('tk_html_scan_step_lock'); }
    if (!add_option('tk_html_scan_step_lock', time() + 60, '', false)) { wp_send_json_error(array('message' => 'A scanner operation is still running.'), 409); return; }
    try { $reply = tk_html_scan_restore_report($fingerprint); }
    catch (Throwable $error) { $reply = array('message' => 'The report could not be restored. Reload the current report.', 'status' => 500); }
    finally { delete_option('tk_html_scan_step_lock'); }
    if (isset($reply['report'])) { wp_send_json_success($reply); }
    else { wp_send_json_error(array('message' => $reply['message']), $reply['status']); }
}

/** Called under the scanner operation lock; no content or filesystem mutations. */
function tk_html_scan_restore_report(string $fingerprint): array {
    $lock = get_option('tk_html_scan_lock', array());
    if ((int) ($lock['expires'] ?? 0) >= time()) { return array('message' => 'Stop the active scan before restoring a suppressed finding.', 'status' => 409); }
    $report = get_option('tk_html_scan_report', array()); $restored = get_option('tk_html_scan_restored', array());
    foreach ($report['suppressed'] ?? array() as $index => $finding) {
        if (($finding['fingerprint'] ?? '') !== $fingerprint) { continue; }
        if (count($restored) >= 300 && !isset($restored[$fingerprint]) || count($report['findings'] ?? array()) >= 300) { return array('message' => 'Review/override budget reached (300 groups). Review stored overrides before adding more.', 'status' => 409); }
        $finding['original_action'] = 'SKIP'; $finding['original_severity'] = $finding['severity']; $finding['action'] = 'REVIEW'; $finding['severity'] = 'low'; $finding['classification'] = 'administrator_restored';
        $finding['restored_by'] = get_current_user_id(); $finding['restored_at'] = time();
        $restored[$fingerprint] = array('user' => $finding['restored_by'], 'time' => $finding['restored_at']);
        $report['findings'][] = $finding; unset($report['suppressed'][$index]); $report['suppressed'] = array_values($report['suppressed']);
        $report['statistics'] = tk_html_scan_statistics($report);
        update_option('tk_html_scan_restored', $restored, false); update_option('tk_html_scan_report', $report, false);
        return array('report' => $report);
    }
    return array('message' => 'Suppressed finding not found. Reload the current report.', 'status' => 404);
}
