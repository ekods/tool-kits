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
    echo '<div class="tk-health-center' . ($full === true ? ' tk-health-full' : '') . '" data-nonce="' . esc_attr(wp_create_nonce('tk_health_center')) . '">';
    echo '<p>Latest manual scan results. Opening the dashboard does not start a scan.</p><div class="tk-health-result">';
    tk_health_center_report_html(is_array($report) ? $report : array(), $full === true);
    echo '</div><p><button type="button" class="button button-primary tk-health-run">Run Manual Scan</button> ';
    if ($full !== true) { echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=tool-kits-health-center')) . '">View Details</a>'; }
    echo '</p><p class="tk-health-progress" role="status" aria-live="polite"></p></div>';
}

function tk_health_center_report_html(array $report, bool $full): void {
    if (empty($report['completed'])) { echo '<p class="tk-health-empty">No scan results yet. Scores and metrics will appear after a manual scan completes.</p>'; return; }
    $scores = tk_health_scores($report['checks']); $counts = $scores['counts'];
    echo '<p class="tk-health-date">Last scan: ' . esc_html(wp_date('Y-m-d H:i:s T', $report['completed'])) . '</p>';
    echo '<p class="tk-health-overall">Overall Health Score <strong>' . esc_html($scores['overall'] === null ? '—' : $scores['overall'] . '/100') . '</strong></p>';
    foreach ($scores['categories'] as $label => $value) {
        echo '<div class="tk-health-score"><span>' . esc_html($label) . '</span><progress max="100" value="' . esc_attr($value ?? 0) . '" aria-label="' . esc_attr($label) . '"></progress><strong>' . esc_html($value === null ? 'N/A' : $value . '%') . '</strong></div>';
    }
    echo '<h3>Issues detected</h3><ul class="tk-health-counts">';
    foreach (array('critical' => 'Critical issues', 'warning' => 'Warnings', 'passed' => 'Checks passed', 'unknown' => 'Not measured') as $key => $label) { echo '<li class="tk-health-' . esc_attr($key) . '"><strong>' . esc_html($counts[$key]) . '</strong> ' . esc_html($label) . '</li>'; }
    echo '</ul><h3>Server</h3><dl class="tk-health-metrics">';
    foreach ($report['server'] as $label => $value) { echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>'; }
    $p = $report['performance'];
    echo '</dl><h3>Performance</h3><dl class="tk-health-metrics">';
    $metrics = array('Homepage TTFB' => $p['ttfb'] === null ? 'Not measured' : number_format($p['ttfb'], 3) . ' s', 'Slow HTTP requests' => $p['slow_http'] === null ? 'Not measured' : (string) $p['slow_http'], 'Recorded failed jobs' => $p['failed_jobs'] === null ? 'Unavailable' : ($p['failed_jobs_limited'] ? '≥ ' : '') . $p['failed_jobs'], 'Overdue cron (>15m)' => $p['overdue_cron'] === null ? 'Not measured' : ($p['cron_limited'] ? '≥ ' : '') . $p['overdue_cron'], 'Database queries' => $p['queries'] === null ? 'Not measured' : (string) $p['queries'], 'Cache response' => $p['cache']);
    foreach ($metrics as $label => $value) { echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>'; }
    echo '</dl><p class="description">' . ($full ? 'TTFB: one public homepage request. Queries and slow HTTP requests (≥1 second): an uncached homepage profile, measured after Tool Kits loads. Failed jobs: retained Action Scheduler records, when available. Overdue WP-Cron events are counted separately.' : 'TTFB: public homepage. Queries/HTTP: uncached profile. See details for measurement sources and coverage.') . '</p>';
    if ($counts['unknown']) { echo '<p class="description">Scores use measured checks only. Results are incomplete when values are N/A.</p>'; }
    if ($full) {
        echo '<h3>Check Details</h3><div class="tk-health-table-wrap"><table class="widefat striped"><thead><tr><th>Category</th><th>Check</th><th>Status</th><th>Result</th></tr></thead><tbody>';
        foreach ($report['checks'] as $check) { echo '<tr><td>' . esc_html($check['category']) . '</td><td>' . esc_html($check['label']) . '</td><td class="tk-health-' . esc_attr($check['status']) . '">' . esc_html($check['status']) . '</td><td>' . esc_html($check['detail']) . '</td></tr>'; }
        echo '</tbody></table></div><p class="description">Category scores average measured checks: passed=100, warning=50, critical=0. The overall score averages measured categories. This scan samples the homepage; use the dedicated scanners for malware or broader site coverage.</p>';
    }
}
