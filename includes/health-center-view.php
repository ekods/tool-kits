<?php
if (!defined('ABSPATH')) { exit; }

function tk_health_center_page(): void {
    if (!tk_health_center_allowed()) { wp_die(esc_html__('Access denied.', 'tool-kits')); }
    echo '<div class="wrap"><h1>WordPress Health Center</h1>';
    tk_health_center_render(true);
    echo '</div>';
}

/** This view reads saved data only. It never starts a scan, HTTP request or cron check. */
function tk_health_center_render($full = false): void {
    if (!tk_health_center_allowed()) { return; }
    $report = get_option('tk_health_center_report', array());
    echo '<div class="tk-health-center' . ($full === true ? ' tk-health-full' : ' tk-health-widget') . '" data-nonce="' . esc_attr(wp_create_nonce('tk_health_center')) . '">';
    echo '<p class="tk-health-intro">Site health at a glance. Run a scan for the latest results.</p><div class="tk-health-result">';
    tk_health_center_report_html(is_array($report) ? $report : array(), $full === true);
    echo '</div><div class="tk-health-actions"><button type="button" class="button button-primary tk-health-run">Run Manual Scan</button>';
    if ($full !== true) { echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=tool-kits-health-center')) . '">View Details</a>'; }
    echo '</div><progress class="tk-health-scan-progress" max="100" value="0" aria-label="Manual scan progress" hidden></progress><p class="tk-health-progress" role="status" aria-live="polite"></p></div>';
}

function tk_health_center_report_html(array $report, bool $full): void {
    if (empty($report['completed'])) { echo '<div class="tk-health-empty"><span class="dashicons dashicons-heart" aria-hidden="true"></span><strong>No scan results yet</strong><p>Run your first manual scan to see health scores, server details, and performance metrics.</p></div>'; return; }
    $scores = tk_health_scores($report['checks']); $counts = $scores['counts'];
    $overall_state = $scores['overall'] === null ? 'unknown' : ($counts['critical'] ? 'critical' : ($counts['warning'] ? 'warning' : ($counts['unknown'] ? 'unknown' : 'passed')));
    $overall_label = $scores['overall'] === null ? 'Not measured' : ($counts['critical'] ? 'Needs attention' : ($counts['warning'] ? 'Review warnings' : ($counts['unknown'] ? 'Partial results' : 'Healthy')));
    echo '<p class="tk-health-date">Last scan: ' . esc_html(wp_date('Y-m-d H:i:s T', $report['completed'])) . '</p>';
    $freshness = tk_health_center_freshness($report);
    if ($freshness['stale']) { echo '<p class="tk-health-stale"><strong>Scan again</strong><span>' . esc_html($freshness['message']) . '</span></p>'; }
    echo '<div class="tk-health-overall"><div><span class="tk-health-score-label">Overall health</span><strong>' . esc_html($scores['overall'] === null ? '—' : $scores['overall']) . '<span class="tk-health-score-total">/100</span></strong></div><span class="tk-health-status tk-health-' . esc_attr($overall_state) . '">' . esc_html($overall_label) . '</span></div>';
    echo '<div class="tk-health-categories">';
    foreach ($scores['categories'] as $label => $value) {
        $state = $value === null ? 'unknown' : ($value < 50 ? 'critical' : ($value < 85 ? 'warning' : 'passed'));
        echo '<div class="tk-health-score tk-health-' . esc_attr($state) . '"><span>' . esc_html($label) . '</span><progress max="100" value="' . esc_attr($value ?? 0) . '" aria-label="' . esc_attr($label) . '" aria-valuetext="' . esc_attr($value === null ? 'Not measured' : $value . '%') . '"></progress><strong>' . esc_html($value === null ? 'N/A' : $value . '%') . '</strong></div>';
    }
    echo '</div><h3 class="tk-health-section-title">Check summary</h3><ul class="tk-health-counts">';
    foreach (array('critical' => 'Critical issues', 'warning' => 'Warnings', 'passed' => 'Checks passed', 'unknown' => 'Not measured') as $key => $label) { echo '<li class="tk-health-' . esc_attr($key) . '"><strong>' . esc_html($counts[$key]) . '</strong><span>' . esc_html($label) . '</span></li>'; }
    echo '</ul>';
    $top = array();
    foreach (array('critical', 'warning') as $priority) {
        foreach ($report['checks'] as $index => $check) {
            if (($check['status'] ?? '') === $priority && count($top) < 3) { $top[$index] = $check; }
        }
    }
    if ($top) {
        echo '<h3 class="tk-health-section-title">Priority actions</h3><ul class="tk-health-findings">';
        foreach ($top as $index => $check) {
            $target = ($full ? '' : admin_url('admin.php?page=tool-kits-health-center')) . '#tk-health-check-' . (int) $index;
            echo '<li><span class="tk-health-finding-status tk-health-' . esc_attr($check['status']) . '">' . esc_html(ucfirst($check['status'])) . '</span><strong>' . esc_html($check['label']) . '</strong><p>' . esc_html(tk_health_center_recommendation($check)) . '</p><a href="' . esc_url($target) . '">View check details</a></li>';
        }
        echo '</ul>';
    }
    $p = $report['performance'];
    echo '<div class="tk-health-panels"><details class="tk-health-panel"' . ($full ? ' open' : '') . '><summary><span>Server</span><span class="tk-health-panel-preview">' . esc_html(isset($report['server']['PHP Version']) ? 'PHP ' . $report['server']['PHP Version'] : 'Environment') . '</span></summary><dl class="tk-health-metrics">';
    foreach ($report['server'] as $label => $value) { echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>'; }
    echo '</dl></details><details class="tk-health-panel"' . ($full ? ' open' : '') . '><summary><span>Performance</span><span class="tk-health-panel-preview">' . esc_html($p['ttfb'] === null ? 'TTFB not measured' : 'TTFB ' . number_format($p['ttfb'], 3) . ' s') . '</span></summary><dl class="tk-health-metrics">';
    $metrics = array('Homepage TTFB' => $p['ttfb'] === null ? 'Not measured' : number_format($p['ttfb'], 3) . ' s', 'Slow HTTP requests' => $p['slow_http'] === null ? 'Not measured' : (string) $p['slow_http'], 'Recorded failed jobs' => $p['failed_jobs'] === null ? 'Unavailable' : ($p['failed_jobs_limited'] ? '≥ ' : '') . $p['failed_jobs'], 'Overdue cron (>15m)' => $p['overdue_cron'] === null ? 'Not measured' : ($p['cron_limited'] ? '≥ ' : '') . $p['overdue_cron'], 'Database queries' => $p['queries'] === null ? 'Not measured' : (string) $p['queries'], 'Cache response' => $p['cache']);
    foreach ($metrics as $label => $value) { echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>'; }
    echo '</dl><p class="tk-health-note">' . ($full ? 'TTFB: one public homepage request. Queries and slow HTTP requests (≥1 second): an uncached homepage profile, measured after Tool Kits loads. Failed jobs: retained Action Scheduler records, when available. Overdue WP-Cron events are counted separately.' : 'TTFB samples the public homepage. Queries and HTTP requests use an uncached profile.') . '</p></details></div>';
    if ($counts['unknown']) { echo '<p class="tk-health-note tk-health-coverage">Scores use measured checks only. N/A values indicate incomplete coverage.</p>'; }
    if ($full) {
        echo '<h3>Check Details</h3><div class="tk-health-table-wrap"><table class="widefat striped"><thead><tr><th>Category</th><th>Check</th><th>Status</th><th>Result</th></tr></thead><tbody>';
        foreach ($report['checks'] as $index => $check) {
            echo '<tr id="tk-health-check-' . (int) $index . '"><td>' . esc_html($check['category']) . '</td><td>' . esc_html($check['label']) . '</td><td class="tk-health-' . esc_attr($check['status']) . '">' . esc_html($check['status']) . '</td><td>' . esc_html($check['detail']);
            if ($check['label'] === 'PHP2Shell runtime patch') { echo '<p><a href="https://www.php.net/archive/2026.php" target="_blank" rel="noopener noreferrer">Official PHP advisory</a></p>'; }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><p class="description">Category scores average measured checks: passed=100, warning=50, critical=0. The overall score averages measured categories. This scan samples the homepage; use the dedicated scanners for malware or broader site coverage.</p>';
    }
}
