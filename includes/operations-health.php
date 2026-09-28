<?php
if (!defined('ABSPATH')) { exit; }

function tk_operations_health_init(): void {
    add_action('admin_post_tk_operations_health_refresh', 'tk_operations_health_refresh_handler');
    add_action('admin_post_tk_operations_health_repair', 'tk_operations_health_repair_handler');
}

function tk_operations_health_queue_summary(): array {
    $indexnow = tk_get_option('indexnow_queue', array());
    $indexnow = is_array($indexnow) ? $indexnow : array();
    $images = tk_get_option('image_opt_queue', array());
    $images = is_array($images) ? $images : array();

    return array(
        array(
            'label' => 'IndexNow',
            'status' => count($indexnow) > 0 ? 'queued' : 'idle',
            'pending' => count($indexnow),
            'detail' => count($indexnow) > 0 ? 'Waiting for background delivery.' : 'No changed URLs waiting.',
        ),
        array(
            'label' => 'Image Optimizer',
            'status' => sanitize_key((string) ($images['status'] ?? 'idle')),
            'pending' => max(0, (int) ($images['total'] ?? 0) - (int) ($images['offset'] ?? 0)),
            'detail' => !empty($images) ? sprintf('%d of %d processed.', (int) ($images['offset'] ?? 0), (int) ($images['total'] ?? 0)) : 'No image queue started.',
        ),
    );
}

function tk_operations_health_report(bool $force = false): array {
    $saved = tk_get_option('operations_health_report', array());
    $saved = is_array($saved) ? $saved : array();
    if (!$force && !empty($saved['scanned_at']) && (int) $saved['scanned_at'] > time() - 5 * MINUTE_IN_SECONDS) {
        $cached_events = get_transient('tk_operations_health_events');
        $saved['events'] = is_array($cached_events) ? $cached_events : array();
        return $saved;
    }

    $events = array();
    $overdue = 0;
    $toolkits = 0;
    $cron = function_exists('_get_cron_array') ? _get_cron_array() : array();
    $cron = is_array($cron) ? $cron : array();
    $schedules = wp_get_schedules();
    foreach ($cron as $timestamp => $hooks) {
        if (!is_array($hooks)) { continue; }
        foreach ($hooks as $hook => $instances) {
            if (!is_array($instances)) { continue; }
            foreach ($instances as $instance) {
                $timestamp = (int) $timestamp;
                $is_overdue = $timestamp < time() - 5 * MINUTE_IN_SECONDS;
                $is_toolkits = strpos((string) $hook, 'tk_') === 0;
                $schedule = is_array($instance) ? (string) ($instance['schedule'] ?? '') : '';
                $interval = $schedule !== '' && isset($schedules[$schedule]['interval']) ? (int) $schedules[$schedule]['interval'] : 0;
                $overdue += $is_overdue ? 1 : 0;
                $toolkits += $is_toolkits ? 1 : 0;
                $events[] = array(
                    'hook' => sanitize_text_field((string) $hook),
                    'timestamp' => $timestamp,
                    'schedule' => $schedule !== '' ? $schedule : 'single',
                    'interval' => $interval,
                    'overdue' => $is_overdue,
                    'toolkits' => $is_toolkits,
                );
            }
        }
    }
    usort($events, function($a, $b) {
        if (!empty($a['overdue']) !== !empty($b['overdue'])) {
            return !empty($a['overdue']) ? -1 : 1;
        }
        return (int) $a['timestamp'] <=> (int) $b['timestamp'];
    });

    $queues = tk_operations_health_queue_summary();
    $missing_workers = array();
    if ((int) ($queues[0]['pending'] ?? 0) > 0 && !wp_next_scheduled('tk_indexnow_process_queue')) {
        $missing_workers[] = 'IndexNow queue has no scheduled worker.';
    }
    if (($queues[1]['status'] ?? '') === 'running' && !wp_next_scheduled('tk_image_opt_queue_process')) {
        $missing_workers[] = 'Image optimizer queue has no scheduled worker.';
    }
    if (function_exists('tk_heartbeat_enabled') && tk_heartbeat_enabled() && !wp_next_scheduled('tk_heartbeat_cron')) {
        $missing_workers[] = 'Heartbeat is enabled but not scheduled.';
    }
    $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    $score = max(0, 100 - min(40, $overdue * 10) - min(40, count($missing_workers) * 20) - ($cron_disabled ? 20 : 0));
    $report = array(
        'scanned_at' => time(),
        'score' => $score,
        'cron_disabled' => $cron_disabled,
        'event_count' => count($events),
        'toolkits_event_count' => $toolkits,
        'overdue_count' => $overdue,
        'missing_workers' => $missing_workers,
        'queues' => $queues,
        'events' => array_slice($events, 0, 50),
    );
    set_transient('tk_operations_health_events', $report['events'], 5 * MINUTE_IN_SECONDS);
    $stored_report = $report;
    unset($stored_report['events']);
    tk_update_option('operations_health_report', $stored_report);
    return $report;
}

function tk_operations_health_refresh_handler(): void {
    tk_require_admin_post('tk_operations_health_refresh');
    tk_operations_health_report(true);
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-monitoring', 'tk_operations' => 'refreshed'), admin_url('admin.php')) . '#operations');
    exit;
}

function tk_operations_health_repair_handler(): void {
    tk_require_admin_post('tk_operations_health_repair');
    if (function_exists('tk_heartbeat_schedule')) {
        tk_heartbeat_schedule();
    }
    $indexnow = tk_get_option('indexnow_queue', array());
    if (is_array($indexnow) && !empty($indexnow) && !wp_next_scheduled('tk_indexnow_process_queue')) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'tk_indexnow_process_queue');
    }
    $images = tk_get_option('image_opt_queue', array());
    if (is_array($images) && ($images['status'] ?? '') === 'running' && !wp_next_scheduled('tk_image_opt_queue_process')) {
        wp_schedule_single_event(time() + 10, 'tk_image_opt_queue_process');
    }
    if (function_exists('tk_security_events_schedule_maintenance')) {
        tk_security_events_schedule_maintenance();
    }
    tk_operations_health_report(true);
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-monitoring', 'tk_operations' => 'repaired'), admin_url('admin.php')) . '#operations');
    exit;
}

function tk_operations_health_render_panel(array $report): void {
    $events = isset($report['events']) && is_array($report['events']) ? $report['events'] : array();
    $queues = isset($report['queues']) && is_array($report['queues']) ? $report['queues'] : array();
    ?>
    <div class="tk-card tk-tab-panel" data-panel-id="operations">
        <h2>Background Operations</h2>
        <p class="description">Review scheduled tasks and bounded queues. This report is cached for five minutes and does not run on frontend requests.</p>
        <div class="tk-grid tk-grid-3" style="margin:18px 0;">
            <div><strong>Health</strong><br><span class="tk-badge <?php echo (int) ($report['score'] ?? 0) >= 80 ? 'tk-on' : 'tk-warn'; ?>"><?php echo esc_html((string) ((int) ($report['score'] ?? 0))); ?>%</span></div>
            <div><strong>Scheduled events</strong><br><?php echo esc_html((string) ((int) ($report['event_count'] ?? 0))); ?></div>
            <div><strong>Overdue</strong><br><?php echo esc_html((string) ((int) ($report['overdue_count'] ?? 0))); ?></div>
        </div>
        <?php if (!empty($report['cron_disabled'])) : ?><p class="tk-alert tk-alert-info">WP-Cron is disabled. Confirm a server cron invokes <code>wp-cron.php</code>.</p><?php endif; ?>
        <?php foreach (($report['missing_workers'] ?? array()) as $warning) : ?><p class="tk-alert tk-alert-info"><?php echo esc_html((string) $warning); ?></p><?php endforeach; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php tk_nonce_field('tk_operations_health_refresh'); ?><input type="hidden" name="action" value="tk_operations_health_refresh"><button class="button">Refresh report</button></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php tk_nonce_field('tk_operations_health_repair'); ?><input type="hidden" name="action" value="tk_operations_health_repair"><button class="button button-primary">Repair Tool Kits schedules</button></form>
        </div>
        <h3>Queues</h3>
        <table class="widefat striped"><thead><tr><th>Queue</th><th>Status</th><th>Pending</th><th>Detail</th></tr></thead><tbody>
        <?php foreach ($queues as $queue) : ?><tr><td><?php echo esc_html((string) ($queue['label'] ?? '')); ?></td><td><?php echo esc_html((string) ($queue['status'] ?? '')); ?></td><td><?php echo esc_html((string) ((int) ($queue['pending'] ?? 0))); ?></td><td><?php echo esc_html((string) ($queue['detail'] ?? '')); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <h3 style="margin-top:20px;">Next scheduled events</h3>
        <div class="tk-table-scroll"><table class="widefat striped"><thead><tr><th>Hook</th><th>Next run</th><th>Schedule</th><th>Status</th></tr></thead><tbody>
        <?php foreach (array_slice($events, 0, 50) as $event) : ?><tr><td><code><?php echo esc_html((string) ($event['hook'] ?? '')); ?></code></td><td><?php echo esc_html(wp_date('Y-m-d H:i:s', (int) ($event['timestamp'] ?? 0))); ?></td><td><?php echo esc_html((string) ($event['schedule'] ?? '')); ?></td><td><span class="tk-badge <?php echo !empty($event['overdue']) ? 'tk-warn' : 'tk-on'; ?>"><?php echo !empty($event['overdue']) ? 'Overdue' : 'Scheduled'; ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php
}
