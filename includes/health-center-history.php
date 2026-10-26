<?php
if (!defined('ABSPATH')) { exit; }

/** A bounded summary only: no probe tokens, HTML, SQL, or full response headers. */
function tk_health_center_history_snapshot(array $report, bool $stored = false): array {
    if (empty($report['completed']) || !is_numeric($report['completed']) || (int) $report['completed'] <= 0 || !isset($report['checks']) || !is_array($report['checks']) || (!$stored && !empty($report['last_recheck']))) { return array(); }
    $checks = array();
    foreach (array_slice($report['checks'], 0, 100) as $check) {
        if (!is_array($check) || !is_string($check['label'] ?? null) || !in_array($check['category'] ?? '', array('Security', 'Performance', 'SEO', 'Compatibility'), true)) { continue; }
        $label = substr($check['label'], 0, 160); $id = tk_health_check_id($label);
        if ($id === '') { continue; }
        $status = in_array($check['status'] ?? '', array('passed', 'warning', 'critical', 'unknown', 'info'), true) ? $check['status'] : 'unknown';
        $checks[$id] = array('id' => $id, 'label' => $label, 'category' => $check['category'], 'status' => $status);
    }
    if (!$checks) { return array(); }
    $performance = array();
    $raw = isset($report['performance']) && is_array($report['performance']) ? $report['performance'] : array();
    foreach (array('ttfb', 'queries', 'slow_http', 'overdue_cron', 'failed_jobs') as $key) {
        $value = $raw[$key] ?? null;
        $performance[$key] = is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 1000000000 ? ($key === 'ttfb' ? (float) $value : (int) $value) : null;
    }
    foreach (array('cron_limited', 'failed_jobs_limited') as $key) { $performance[$key] = !empty($raw[$key]); }
    $performance['cache'] = is_string($raw['cache'] ?? null) ? substr($raw['cache'], 0, 80) : 'Not reported';
    $policy = null;
    if (isset($report['policy']) && is_array($report['policy'])) {
        try { $policy = tk_health_center_validate_policy($report['policy']); } catch (InvalidArgumentException $error) { /* Legacy/invalid policy is not comparable. */ }
    }
    $id = $stored ? ($report['id'] ?? '') : ($report['scan_id'] ?? '');
    if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)) { $id = substr(hash('sha256', serialize(array((int) $report['completed'], $checks, $performance, $policy))), 0, 24); }
    $environment = is_string($report['environment'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $report['environment']) ? $report['environment'] : '';
    return array('id' => $id, 'completed' => (int) $report['completed'], 'checks' => array_values($checks), 'performance' => $performance, 'policy' => $policy, 'environment' => $environment, 'limited' => !empty($report['limited']) || count($report['checks']) > 100);
}

function tk_health_center_history(): array {
    $saved = get_option('tk_health_center_history', array()); $history = array();
    foreach (array_slice(is_array($saved) ? $saved : array(), -10) as $entry) {
        if (!is_array($entry)) { continue; }
        $snapshot = tk_health_center_history_snapshot($entry, true);
        if ($snapshot && !isset($history[$snapshot['id']])) { $history[$snapshot['id']] = $snapshot; }
    }
    return array_values($history);
}

/** Called under the scan lock only after a full scan finishes. Rechecks never call this. */
function tk_health_center_history_record(array $report, array $previous = array()): void {
    $snapshot = tk_health_center_history_snapshot($report);
    if (!$snapshot) { return; }
    $history = tk_health_center_history();
    if (!$history) {
        $baseline = tk_health_center_history_snapshot($previous);
        if ($baseline && $baseline['id'] !== $snapshot['id']) { $history[] = $baseline; }
    }
    foreach ($history as $entry) { if ($entry['id'] === $snapshot['id']) { return; } }
    $history[] = $snapshot;
    update_option('tk_health_center_history', array_slice($history, -10), false);
}

/** Policy changes get neutral transitions; unknown/removed checks never count as resolved. */
function tk_health_center_history_compare(array $older, array $newer): array {
    $policy_changed = $older['policy'] === null || $newer['policy'] === null || $older['policy'] !== $newer['policy'];
    $before = array(); $after = array();
    foreach ($older['checks'] as $check) { $before[$check['id']] = $check; }
    foreach ($newer['checks'] as $check) { $after[$check['id']] = $check; }
    $changes = array(); $counts = array_fill_keys(array('new', 'resolved', 'worsened', 'improved', 'unverified', 'changed'), 0);
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $id) {
        $old = $before[$id]['status'] ?? 'missing'; $new = $after[$id]['status'] ?? 'missing';
        if ($old === $new) { continue; }
        $was_issue = in_array($old, array('warning', 'critical'), true); $is_issue = in_array($new, array('warning', 'critical'), true);
        $kind = 'changed';
        if ($new === 'unknown' || $new === 'missing') { $kind = 'unverified'; }
        elseif (!$policy_changed) {
            if ($was_issue && $new === 'passed') { $kind = 'resolved'; }
            elseif (!$was_issue && $is_issue) { $kind = 'new'; }
            elseif ($old === 'warning' && $new === 'critical') { $kind = 'worsened'; }
            elseif ($old === 'critical' && $new === 'warning') { $kind = 'improved'; }
        }
        $counts[$kind]++;
        $changes[] = array('label' => $after[$id]['label'] ?? $before[$id]['label'], 'before' => $old, 'after' => $new, 'kind' => $kind);
    }
    return array('policy_changed' => $policy_changed, 'environment_changed' => $older['environment'] !== $newer['environment'], 'changes' => $changes, 'counts' => $counts);
}

function tk_health_center_history_metric(array $snapshot, string $key): string {
    if ($key === 'score') { $value = tk_health_scores($snapshot['checks'])['overall']; return $value === null ? 'Not measured' : $value . '/100'; }
    $value = $snapshot['performance'][$key];
    if ($value === null) { return 'Not measured'; }
    $limited = $key === 'overdue_cron' && $snapshot['performance']['cron_limited'] || $key === 'failed_jobs' && $snapshot['performance']['failed_jobs_limited'];
    return ($limited ? '≥ ' : '') . ($key === 'ttfb' ? number_format($value, 3) . ' s' : (string) $value);
}

function tk_health_center_history_delta(array $older, array $newer, string $key): string {
    if ($key === 'score') { $old = tk_health_scores($older['checks'])['overall']; $new = tk_health_scores($newer['checks'])['overall']; }
    else {
        $old = $older['performance'][$key]; $new = $newer['performance'][$key];
        if (($key === 'overdue_cron' && ($older['performance']['cron_limited'] || $newer['performance']['cron_limited'])) || ($key === 'failed_jobs' && ($older['performance']['failed_jobs_limited'] || $newer['performance']['failed_jobs_limited']))) { return 'Not comparable: sample limit'; }
    }
    if ($old === null || $new === null) { return 'Not comparable'; }
    $delta = $new - $old;
    return ($delta > 0 ? '+' : '') . ($key === 'ttfb' ? number_format($delta, 3) . ' s' : (string) $delta . ($key === 'score' ? ' points' : ''));
}

function tk_health_center_history_html(): void {
    $history = tk_health_center_history();
    echo '<details class="tk-health-history"><summary>Scan history <span>' . esc_html(count($history)) . '/10 full scans</span></summary><div class="tk-health-history-body">';
    tk_health_center_history_body($history);
    echo '</div></details>';
}

function tk_health_center_history_body(array $history, string $older_id = '', string $newer_id = ''): void {
    echo '<p>Up to 10 completed full scans are retained. Individual rechecks do not replace these snapshots. Scores depend on measured coverage and the thresholds used at each scan. TTFB is one public homepage sample; compare the reported cache states as well.</p>';
    if (!$history) { echo '<p>No scan history yet. Complete a new full scan to start recording history.</p>'; return; }
    echo '<div class="tk-health-table-wrap" role="region" aria-label="Scan history table" tabindex="0"><table class="widefat striped"><thead><tr><th>Full scan</th><th>Score / coverage</th><th>Critical</th><th>Warnings</th><th>TTFB</th><th>Queries</th></tr></thead><tbody>';
    foreach (array_reverse($history) as $index => $entry) {
        $score = tk_health_scores($entry['checks']);
        echo '<tr><td>Scan ' . esc_html(count($history) - $index) . '<small>' . esc_html(wp_date('Y-m-d H:i:s T', $entry['completed'])) . '</small></td><td>' . esc_html(tk_health_center_history_metric($entry, 'score')) . '<small>' . esc_html($score['counts']['unknown']) . ' not measured</small></td><td>' . esc_html($score['counts']['critical']) . '</td><td>' . esc_html($score['counts']['warning']) . '</td><td>' . esc_html(tk_health_center_history_metric($entry, 'ttfb')) . '<small>Cache: ' . esc_html($entry['performance']['cache']) . '</small></td><td>' . esc_html(tk_health_center_history_metric($entry, 'queries')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (count($history) < 2) { echo '<p>Complete another full scan to compare results.</p>'; return; }
    $older_id = $older_id ?: $history[count($history) - 2]['id']; $newer_id = $newer_id ?: $history[count($history) - 1]['id'];
    $selected = array();
    echo '<form class="tk-health-history-form">';
    foreach (array('older' => array('Earlier scan', $older_id), 'newer' => array('Later scan', $newer_id)) as $name => $selection) {
        echo '<label>' . esc_html($selection[0]) . '<select name="' . esc_attr($name) . '">';
        foreach ($history as $index => $entry) {
            if ($entry['id'] === $selection[1]) { $selected[$name] = $entry; }
            echo '<option value="' . esc_attr($entry['id']) . '"' . ($entry['id'] === $selection[1] ? ' selected' : '') . '>Scan ' . esc_html($index + 1) . ' — ' . esc_html(wp_date('Y-m-d H:i:s T', $entry['completed']) . ' — ' . tk_health_center_history_metric($entry, 'score')) . '</option>';
        }
        echo '</select></label>';
    }
    echo '<button type="submit" class="button">Compare scans</button></form>';
    if (!isset($selected['older'], $selected['newer'])) { return; }
    $older = $selected['older']; $newer = $selected['newer']; $comparison = tk_health_center_history_compare($older, $newer);
    if ($comparison['policy_changed']) { echo '<p class="tk-health-history-note">Thresholds changed or the previous policy was not recorded. Status changes are shown neutrally; they are not counted as verified fixes or worsening.</p>'; }
    if ($comparison['environment_changed']) { echo '<p class="tk-health-history-note">The recorded website configuration changed between these scans. Measurements may have different runtime or plugin conditions.</p>'; }
    if ($older['limited'] || $newer['limited']) { echo '<p class="tk-health-history-note">History summaries are limited to the first 100 checks per scan.</p>'; }
    $labels = array('new' => 'Newly detected', 'resolved' => 'Resolved', 'worsened' => 'Worsened', 'improved' => 'Improved, still open', 'unverified' => 'Needs verification', 'changed' => 'Changed');
    echo '<ul class="tk-health-history-counts">';
    foreach ($labels as $kind => $label) { echo '<li><strong>' . esc_html($comparison['counts'][$kind]) . '</strong> ' . esc_html($label) . '</li>'; }
    echo '</ul><div class="tk-health-table-wrap" role="region" aria-label="Scan metric comparison" tabindex="0"><table class="widefat striped"><thead><tr><th>Metric</th><th>Earlier</th><th>Later</th><th>Difference</th></tr></thead><tbody>';
    foreach (array('score' => 'Overall score', 'ttfb' => 'Homepage TTFB', 'queries' => 'Database queries', 'slow_http' => 'Slow HTTP requests', 'overdue_cron' => 'Overdue cron events', 'failed_jobs' => 'Recorded failed jobs') as $key => $label) {
        $old_cache = $key === 'ttfb' ? '<small>Cache: ' . esc_html($older['performance']['cache']) . '</small>' : '';
        $new_cache = $key === 'ttfb' ? '<small>Cache: ' . esc_html($newer['performance']['cache']) . '</small>' : '';
        echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html(tk_health_center_history_metric($older, $key)) . $old_cache . '</td><td>' . esc_html(tk_health_center_history_metric($newer, $key)) . $new_cache . '</td><td>' . esc_html(tk_health_center_history_delta($older, $newer, $key)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (!$comparison['changes']) { echo '<p>No check-status changes between the selected full scans.</p>'; return; }
    echo '<div class="tk-health-table-wrap" role="region" aria-label="Check status changes" tabindex="0"><table class="widefat striped"><thead><tr><th>Check</th><th>Earlier</th><th>Later</th><th>Change</th></tr></thead><tbody>';
    foreach ($comparison['changes'] as $change) {
        echo '<tr><td>' . esc_html($change['label']) . '</td><td>' . ($change['before'] === 'missing' ? 'Not included' : tk_health_center_status_badge($change['before'])) . '</td><td>' . ($change['after'] === 'missing' ? 'Not included' : tk_health_center_status_badge($change['after'])) . '</td><td>' . esc_html($labels[$change['kind']]) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function tk_health_center_history_ajax(): void {
    tk_health_center_authorize();
    $older = $_POST['older'] ?? ''; $newer = $_POST['newer'] ?? '';
    if (!is_string($older) || !is_string($newer) || !preg_match('/^[a-f0-9]{24}$/D', $older) || !preg_match('/^[a-f0-9]{24}$/D', $newer)) { wp_send_json_error(array('message' => 'Choose two valid saved scans.'), 400); return; }
    $history = tk_health_center_history(); $positions = array_column($history, null, 'id');
    if (!isset($positions[$older], $positions[$newer])) { wp_send_json_error(array('message' => 'A selected scan is no longer retained. Reload the page and choose again.'), 409); return; }
    $ids = array_column($history, 'id');
    if (array_search($older, $ids, true) >= array_search($newer, $ids, true)) { wp_send_json_error(array('message' => 'Choose an earlier scan followed by a later scan.'), 400); return; }
    ob_start(); tk_health_center_history_body($history, $older, $newer); $html = ob_get_clean();
    wp_send_json_success(array('message' => 'Saved full scans compared. No new scan was started.', 'html' => $html));
}
