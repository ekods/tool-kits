<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Register Dashboard Widget
 */
function tk_dashboard_widget_init() {
    add_action('wp_dashboard_setup', 'tk_dashboard_widget_register');
    add_action('admin_menu', 'tk_dashboard_widget_register_attack_details_page', 30);
    add_action('admin_post_tk_security_events_settings', 'tk_security_events_settings_save');
    add_action('admin_post_tk_security_events_run_maintenance', 'tk_security_events_run_maintenance_handler');
}

function tk_dashboard_widget_register_attack_details_page(): void {
    if (!tk_toolkits_can_manage()) return;
    add_submenu_page(
        'tool-kits',
        __('Attack Details', 'tool-kits'),
        __('Attack Details', 'tool-kits'),
        'manage_options',
        'tool-kits-security-events',
        'tk_render_security_events_page'
    );
}

function tk_dashboard_widget_register() {
    if (!tk_toolkits_can_manage()) return;
    
    wp_add_dashboard_widget(
        'tk_security_status_widget',
        'Tool Kits: Security Status',
        'tk_render_dashboard_widget'
    );

    wp_add_dashboard_widget(
        'tk_cache_status_widget',
        'Tool Kits: Cache Status',
        'tk_render_cache_dashboard_widget'
    );

    wp_add_dashboard_widget(
        'tk_attacks_blocked_widget',
        'Total Attacks Blocked: Tool Kits Firewall',
        'tk_render_attacks_blocked_dashboard_widget'
    );
}

function tk_dashboard_widget_login_table_exists(): bool {
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    global $wpdb;
    $table = function_exists('tk_login_log_table') ? tk_login_log_table() : $wpdb->prefix . 'tk_login_log';
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $exists = is_string($found) && $found === $table;
    return $exists;
}

function tk_dashboard_widget_login_failed_count(int $since): int {
    static $counts = array();
    if (isset($counts[$since])) {
        return $counts[$since];
    }

    if (!tk_dashboard_widget_login_table_exists()) {
        $counts[$since] = 0;
        return 0;
    }

    global $wpdb;
    $table = tk_login_log_table();
    $since_mysql = gmdate('Y-m-d H:i:s', $since);
    $counts[$since] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s AND time >= %s", 'failed', $since_mysql));
    return $counts[$since];
}

function tk_dashboard_widget_login_failed_ip_rows(int $since): array {
    static $rows_by_since = array();
    if (isset($rows_by_since[$since])) {
        return $rows_by_since[$since];
    }

    if (!tk_dashboard_widget_login_table_exists()) {
        $rows_by_since[$since] = array();
        return array();
    }

    global $wpdb;
    $table = tk_login_log_table();
    $since_mysql = gmdate('Y-m-d H:i:s', $since);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ip, MAX(location) AS location, COUNT(*) AS count FROM {$table} WHERE status = %s AND time >= %s GROUP BY ip",
        'failed',
        $since_mysql
    ));
    $rows_by_since[$since] = is_array($rows) ? $rows : array();
    return $rows_by_since[$since];
}

function tk_dashboard_widget_firewall_events(): array {
    $events = tk_get_option('firewall_event_log', array());
    return is_array($events) ? $events : array();
}

function tk_dashboard_widget_firewall_count(int $since, string $kind = 'all'): int {
    $count = 0;
    foreach (tk_dashboard_widget_firewall_events() as $event) {
        $time = isset($event['time']) ? (int) $event['time'] : 0;
        if ($time < $since) {
            continue;
        }
        $reason = isset($event['reason']) ? (string) $event['reason'] : '';
        $is_blocklist = stripos($reason, 'Blocked IP/CIDR') !== false || stripos($reason, 'Blocked user agent') !== false;
        if ($kind === 'blocklist' && !$is_blocklist) {
            continue;
        }
        if ($kind === 'complex' && $is_blocklist) {
            continue;
        }
        $count++;
    }
    return $count;
}

function tk_dashboard_widget_attacks_count(int $since, string $kind = 'total'): int {
    if (tk_dashboard_widget_use_security_events()) {
        return tk_dashboard_widget_security_events_count($since, $kind);
    }
    if ($kind === 'brute') {
        return tk_dashboard_widget_login_failed_count($since);
    }
    if ($kind === 'blocklist') {
        return tk_dashboard_widget_firewall_count($since, 'blocklist');
    }
    if ($kind === 'complex') {
        return tk_dashboard_widget_firewall_count($since, 'complex');
    }
    return tk_dashboard_widget_firewall_count($since, 'all') + tk_dashboard_widget_login_failed_count($since);
}

function tk_dashboard_widget_use_security_events(): bool {
    static $use = null;
    if ($use !== null) {
        return $use;
    }
    if (!function_exists('tk_security_events_table_exists') || !tk_security_events_table_exists()) {
        $use = false;
        return false;
    }
    global $wpdb;
    $use = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . tk_security_events_table() . ' LIMIT 1') > 0;
    return $use;
}

function tk_dashboard_widget_security_events_count(int $since, string $kind = 'total'): int {
    global $wpdb;
    $table = tk_security_events_table();
    $since_mysql = gmdate('Y-m-d H:i:s', $since);
    $categories = array();
    if ($kind === 'brute') {
        $categories = array('brute_force');
    } elseif ($kind === 'blocklist') {
        $categories = array('blocklist', 'auto_block');
    } elseif ($kind === 'complex') {
        $categories = array('complex');
    }

    if (empty($categories)) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND time >= %s",
            'blocked',
            $since_mysql
        ));
    }

    $placeholders = implode(', ', array_fill(0, count($categories), '%s'));
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND category IN ({$placeholders}) AND time >= %s",
        array_merge(array('blocked'), $categories, array($since_mysql))
    ));
}

function tk_dashboard_widget_country_from_location(string $location): string {
    $location = trim($location);
    if ($location === '' || $location === 'Unknown' || $location === 'Private/local IP') {
        return '';
    }

    $parts = array_values(array_filter(array_map('trim', explode(',', $location))));
    return !empty($parts) ? (string) end($parts) : '';
}

function tk_dashboard_widget_country_code(string $country): string {
    $codes = array(
        'Australia' => 'AU',
        'Brazil' => 'BR',
        'Canada' => 'CA',
        'China' => 'CN',
        'France' => 'FR',
        'Germany' => 'DE',
        'Hong Kong' => 'HK',
        'India' => 'IN',
        'Indonesia' => 'ID',
        'Japan' => 'JP',
        'Malaysia' => 'MY',
        'Netherlands' => 'NL',
        'Russia' => 'RU',
        'Singapore' => 'SG',
        'United Kingdom' => 'GB',
        'United States' => 'US',
        'Vietnam' => 'VN',
    );
    return isset($codes[$country]) ? $codes[$country] : '';
}

function tk_dashboard_widget_country_flag(string $country): string {
    $code = tk_dashboard_widget_country_code($country);
    if ($code === '' || strlen($code) !== 2) {
        return '';
    }

    $flag = '';
    foreach (str_split($code) as $letter) {
        $flag .= html_entity_decode('&#' . (string) (127397 + ord($letter)) . ';', ENT_NOQUOTES, 'UTF-8');
    }
    return $flag;
}

function tk_dashboard_widget_top_countries(int $since, int $limit = 7): array {
    if (tk_dashboard_widget_use_security_events()) {
        global $wpdb;
        $table = tk_security_events_table();
        $since_mysql = gmdate('Y-m-d H:i:s', $since);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT country, COUNT(*) AS count FROM {$table} WHERE event_type = %s AND time >= %s AND country <> '' GROUP BY country ORDER BY count DESC LIMIT %d",
            'blocked',
            $since_mysql,
            $limit
        ));
        $top = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $country = (string) ($row->country ?? '');
            $top[] = array(
                'country' => $country,
                'flag' => tk_dashboard_widget_country_flag($country),
                'count' => (int) ($row->count ?? 0),
            );
        }
        return $top;
    }

    $countries = array();
    $ip_countries = array();
    foreach (tk_dashboard_widget_login_failed_ip_rows($since) as $row) {
        $ip = isset($row->ip) ? trim((string) $row->ip) : '';
        $country = tk_dashboard_widget_country_from_location((string) ($row->location ?? ''));
        if ($country === '') {
            continue;
        }
        if ($ip !== '') {
            $ip_countries[$ip] = $country;
        }
        if (!isset($countries[$country])) {
            $countries[$country] = 0;
        }
        $countries[$country] += max(0, (int) ($row->count ?? 0));
    }

    foreach (tk_dashboard_widget_firewall_events() as $event) {
        $time = isset($event['time']) ? (int) $event['time'] : 0;
        $ip = isset($event['ip']) ? trim((string) $event['ip']) : '';
        if ($time < $since || $ip === '' || empty($ip_countries[$ip])) {
            continue;
        }
        $country = $ip_countries[$ip];
        if (!isset($countries[$country])) {
            $countries[$country] = 0;
        }
        $countries[$country]++;
    }

    arsort($countries);
    $top = array();
    foreach (array_slice($countries, 0, $limit, true) as $country => $count) {
        $top[] = array(
            'country' => $country,
            'flag' => tk_dashboard_widget_country_flag((string) $country),
            'count' => (int) $count,
        );
    }
    return $top;
}

function tk_dashboard_widget_top_ips(int $since, int $limit = 10): array {
    if (tk_dashboard_widget_use_security_events()) {
        global $wpdb;
        $table = tk_security_events_table();
        $since_mysql = gmdate('Y-m-d H:i:s', $since);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ip, COUNT(*) AS count FROM {$table} WHERE event_type = %s AND time >= %s AND ip <> '' GROUP BY ip ORDER BY count DESC LIMIT %d",
            'blocked',
            $since_mysql,
            $limit
        ));
        $top = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $top[] = array(
                'ip' => (string) ($row->ip ?? ''),
                'count' => (int) ($row->count ?? 0),
            );
        }
        return $top;
    }

    $ips = array();
    foreach (tk_dashboard_widget_firewall_events() as $event) {
        $time = isset($event['time']) ? (int) $event['time'] : 0;
        $ip = isset($event['ip']) ? trim((string) $event['ip']) : '';
        if ($time < $since || $ip === '') {
            continue;
        }
        if (!isset($ips[$ip])) {
            $ips[$ip] = 0;
        }
        $ips[$ip]++;
    }

    foreach (tk_dashboard_widget_login_failed_ip_rows($since) as $row) {
        $ip = isset($row->ip) ? trim((string) $row->ip) : '';
        if ($ip === '') {
            continue;
        }
        if (!isset($ips[$ip])) {
            $ips[$ip] = 0;
        }
        $ips[$ip] += max(0, (int) ($row->count ?? 0));
    }

    arsort($ips);
    $top = array();
    foreach (array_slice($ips, 0, $limit, true) as $ip => $count) {
        $top[] = array(
            'ip' => $ip,
            'count' => (int) $count,
        );
    }
    return $top;
}

function tk_dashboard_widget_attack_chart_series(int $days): array {
    $now = time();
    $points = array();
    $bucket_count = $days === 1 ? 12 : 30;
    $bucket_seconds = $days === 1 ? 2 * HOUR_IN_SECONDS : DAY_IN_SECONDS;
    $start = $now - ($bucket_seconds * ($bucket_count - 1));

    for ($i = 0; $i < $bucket_count; $i++) {
        $bucket_start = $start + ($i * $bucket_seconds);
        $bucket_end = $bucket_start + $bucket_seconds;
        if ($i === $bucket_count - 1) {
            $bucket_end = $now + 1;
        }
        $count = tk_dashboard_widget_attacks_count($bucket_start, 'total') - tk_dashboard_widget_attacks_count($bucket_end, 'total');
        $label = $days === 1 ? wp_date('g a', $bucket_start) : wp_date('M j', $bucket_start);
        $points[] = array(
            'label' => $label,
            'value' => max(0, $count),
        );
    }

    return $points;
}

function tk_dashboard_widget_render_attack_chart(array $points): void {
    $max = 1;
    foreach ($points as $point) {
        $max = max($max, (int) $point['value']);
    }
    $width = 640;
    $height = 220;
    $padding_left = 44;
    $padding_right = 14;
    $padding_top = 14;
    $padding_bottom = 42;
    $plot_width = $width - $padding_left - $padding_right;
    $plot_height = $height - $padding_top - $padding_bottom;
    $count = max(1, count($points));
    $step = $count > 1 ? $plot_width / ($count - 1) : $plot_width;
    $path = '';
    $circles = '';
    $labels = '';

    foreach ($points as $index => $point) {
        $value = (int) $point['value'];
        $x = $padding_left + ($step * $index);
        $y = $padding_top + ($plot_height - (($value / $max) * $plot_height));
        $path .= ($index === 0 ? 'M' : ' L') . round($x, 2) . ' ' . round($y, 2);
        $circles .= '<circle cx="' . esc_attr((string) round($x, 2)) . '" cy="' . esc_attr((string) round($y, 2)) . '" r="2.5"></circle>';
        if ($index % ($count > 14 ? 3 : 2) === 0 || $index === $count - 1) {
            $labels .= '<text x="' . esc_attr((string) round($x, 2)) . '" y="' . esc_attr((string) ($height - 10)) . '" text-anchor="middle">' . esc_html((string) $point['label']) . '</text>';
        }
    }

    for ($i = 0; $i <= 4; $i++) {
        $grid_y = $padding_top + (($plot_height / 4) * $i);
        echo '<line class="tk-attack-grid" x1="' . esc_attr((string) $padding_left) . '" y1="' . esc_attr((string) round($grid_y, 2)) . '" x2="' . esc_attr((string) ($width - $padding_right)) . '" y2="' . esc_attr((string) round($grid_y, 2)) . '"></line>';
    }
    ?>
    <path class="tk-attack-line" d="<?php echo esc_attr($path); ?>"></path>
    <g class="tk-attack-points"><?php echo $circles; ?></g>
    <g class="tk-attack-labels"><?php echo $labels; ?></g>
    <?php
}

/**
 * Render Dashboard Widget
 */
function tk_render_dashboard_widget() {
    $score_data = tk_hardening_calculate_score();
    $score = $score_data['score'];
    $score_color = ($score >= 80) ? '#27ae60' : (($score >= 50) ? '#f39c12' : '#e74c3c');
    
    $active_hardening = tk_hardening_active_items();
    $waf_enabled = tk_get_option('hardening_waf_enabled', 0);
    $hide_login = tk_get_option('hide_login_enabled', 0);
    ?>
    <div class="tk-dashboard-widget">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <div style="text-align: center; flex: 1;">
                <div style="font-size: 28px; font-weight: bold; color: <?php echo $score_color; ?>;">
                    <?php echo $score; ?>%
                </div>
                <div style="font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px;">Security Score</div>
            </div>
            <div style="flex: 2; padding-left: 20px; border-left: 1px solid #e2e8f0;">
                <div style="margin-bottom: 8px; display: flex; justify-content: space-between; font-size: 13px;">
                    <span>WAF Protection</span>
                    <span class="tk-badge <?php echo $waf_enabled ? 'tk-on' : ''; ?>" style="font-size: 10px;"><?php echo $waf_enabled ? 'Active' : 'Disabled'; ?></span>
                </div>
                <div style="margin-bottom: 8px; display: flex; justify-content: space-between; font-size: 13px;">
                    <span>Hide Login</span>
                    <span class="tk-badge <?php echo $hide_login ? 'tk-on' : ''; ?>" style="font-size: 10px;"><?php echo $hide_login ? 'Active' : 'Disabled'; ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px;">
                    <span>Active Features</span>
                    <span style="font-weight: 600; color: #1e293b;"><?php echo count($active_hardening); ?></span>
                </div>
            </div>
        </div>
        
        <div style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=tool-kits')); ?>" class="button button-primary" style="width: 100%; text-align: center;">Open Tool Kits Dashboard</a>
        </div>
    </div>
    <style>
        .tk-dashboard-widget .tk-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            background: #f1f5f9;
            color: #64748b;
            font-weight: 600;
        }
        .tk-dashboard-widget .tk-badge.tk-on {
            background: #dcfce7;
            color: #166534;
        }
    </style>
    <?php
}

/**
 * Render Cache Dashboard Widget
 */
function tk_render_cache_dashboard_widget() {
    $stats = function_exists('tk_page_cache_stats') ? tk_page_cache_stats() : array('files' => 0, 'bytes' => 0);
    $files = isset($stats['files']) ? max(0, (int) $stats['files']) : 0;
    $bytes = isset($stats['bytes']) ? max(0, (int) $stats['bytes']) : 0;
    $summary = function_exists('tk_page_cache_summary_text') ? tk_page_cache_summary_text($stats) : $files . ' files';
    $page_cache_enabled = (int) tk_get_option('page_cache_enabled', 0) === 1;
    $status_label = $page_cache_enabled ? 'Active' : 'Disabled';
    $server_cache = function_exists('tk_server_cache_status') ? tk_server_cache_status() : array('detected' => false, 'label' => 'UNKNOWN', 'detail' => 'Server cache detector is unavailable.');
    $server_cache_detected = !empty($server_cache['detected']);
    $server_cache_label = $server_cache_detected ? 'Detected' : 'Not detected';
    $server_cache_detail = isset($server_cache['detail']) ? (string) $server_cache['detail'] : '';
    $cache_url = function_exists('tk_admin_url') ? tk_admin_url('tool-kits-cache') : admin_url('admin.php?page=tool-kits-cache');
    ?>
    <div class="tk-dashboard-widget tk-cache-dashboard-widget">
        <div style="display: flex; align-items: stretch; gap: 12px; margin-bottom: 16px;">
            <div style="flex: 1; min-width: 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                <div style="font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 6px;">Cached Files</div>
                <div style="font-size: 26px; font-weight: 700; color: #1e293b; line-height: 1;"><?php echo esc_html(number_format_i18n($files)); ?></div>
            </div>
            <div style="flex: 1; min-width: 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                <div style="font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 6px;">Cache Size</div>
                <div style="font-size: 26px; font-weight: 700; color: #1e293b; line-height: 1;"><?php echo esc_html(size_format($bytes)); ?></div>
            </div>
        </div>

        <div style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 13px;">
            <span>Page cache</span>
            <span class="tk-badge <?php echo $page_cache_enabled ? 'tk-on' : ''; ?>" style="font-size: 10px;"><?php echo esc_html($status_label); ?></span>
        </div>
        <div style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 13px;">
            <span>Server/CDN cache</span>
            <span class="tk-badge <?php echo $server_cache_detected ? 'tk-on' : ''; ?>" style="font-size: 10px;" title="<?php echo esc_attr($server_cache_detail); ?>"><?php echo esc_html($server_cache_label); ?></span>
        </div>
        <div style="margin-bottom: 16px; color: #64748b; font-size: 12px;">
            Current usage: <?php echo esc_html($summary); ?>.
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0 0 10px;">
            <?php tk_nonce_field('tk_cache_purge'); ?>
            <input type="hidden" name="action" value="tk_cache_purge">
            <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('index.php')); ?>">
            <button type="submit" class="button button-primary" style="width: 100%; text-align: center;"<?php disabled($files, 0); ?>>Clear Page Cache</button>
        </form>

        <a href="<?php echo esc_url($cache_url); ?>" class="button button-secondary" style="width: 100%; text-align: center;">Open Cache Settings</a>
    </div>
    <style>
        .tk-cache-dashboard-widget .tk-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            background: #f1f5f9;
            color: #64748b;
            font-weight: 600;
        }
        .tk-cache-dashboard-widget .tk-badge.tk-on {
            background: #dcfce7;
            color: #166534;
        }
        @media (max-width: 782px) {
            .tk-cache-dashboard-widget > div:first-child {
                flex-direction: column;
            }
        }
    </style>
    <?php
}

function tk_render_attacks_blocked_dashboard_widget() {
    $now = time();
    $today_start = strtotime(wp_date('Y-m-d 00:00:00', $now));
    $today_start = $today_start ? (int) $today_start : $now - DAY_IN_SECONDS;
    $ranges = array(
        'today' => $today_start,
        'week' => $now - (7 * DAY_IN_SECONDS),
        'month' => $now - (30 * DAY_IN_SECONDS),
    );
    $summary = array();
    foreach ($ranges as $key => $since) {
        $complex = tk_dashboard_widget_attacks_count($since, 'complex');
        $brute = tk_dashboard_widget_attacks_count($since, 'brute');
        $blocklist = tk_dashboard_widget_attacks_count($since, 'blocklist');
        $summary[$key] = array(
            'complex' => $complex,
            'brute' => $brute,
            'blocklist' => $blocklist,
            'total' => $complex + $brute + $blocklist,
        );
    }

    $series_24h = tk_dashboard_widget_attack_chart_series(1);
    $series_30d = tk_dashboard_widget_attack_chart_series(30);
    $top_countries = tk_dashboard_widget_top_countries($ranges['week']);
    $top_ips = array(
        '24h' => tk_dashboard_widget_top_ips($now - DAY_IN_SECONDS),
        '7d' => tk_dashboard_widget_top_ips($ranges['week']),
        '30d' => tk_dashboard_widget_top_ips($ranges['month']),
    );
    $last_event_time = 0;
    foreach (tk_dashboard_widget_firewall_events() as $event) {
        $last_event_time = max($last_event_time, isset($event['time']) ? (int) $event['time'] : 0);
    }
    if (tk_dashboard_widget_login_table_exists()) {
        global $wpdb;
        $table = tk_login_log_table();
        $last_login_time = $wpdb->get_var($wpdb->prepare("SELECT time FROM {$table} WHERE status = %s ORDER BY time DESC LIMIT 1", 'failed'));
        if (is_string($last_login_time) && $last_login_time !== '') {
            $last_event_time = max($last_event_time, strtotime($last_login_time . ' GMT') ?: 0);
        }
    }
    $updated_label = $last_event_time > 0 ? human_time_diff($last_event_time, $now) . ' ago' : 'No blocked attacks yet';
    $firewall_url = function_exists('tk_admin_url') ? tk_admin_url('tool-kits-firewall') : admin_url('admin.php?page=tool-kits-firewall');
    $login_log_url = function_exists('tk_admin_url') ? tk_admin_url('tool-kits-security-login-log') : admin_url('admin.php?page=tool-kits-security-login-log');
    $details_url = admin_url('admin.php?page=tool-kits-security-events');
    ?>
    <div class="tk-dashboard-widget tk-attacks-dashboard-widget">
        <div class="tk-attacks-chart-head">
            <div class="tk-attacks-tabs" role="tablist" aria-label="Attack chart range">
                <button type="button" class="is-active" data-tk-attack-range="24h">24 Hours</button>
                <button type="button" data-tk-attack-range="30d">30 Days</button>
            </div>
            <div class="tk-attacks-legend"><span></span>Total Attacks</div>
        </div>

        <div class="tk-attacks-chart is-active" data-tk-attack-chart="24h">
            <svg viewBox="0 0 640 220" role="img" aria-label="Total attacks blocked in the last 24 hours">
                <?php tk_dashboard_widget_render_attack_chart($series_24h); ?>
            </svg>
        </div>
        <div class="tk-attacks-chart" data-tk-attack-chart="30d">
            <svg viewBox="0 0 640 220" role="img" aria-label="Total attacks blocked in the last 30 days">
                <?php tk_dashboard_widget_render_attack_chart($series_30d); ?>
            </svg>
        </div>
        <div class="tk-attacks-updated">Last Updated: <?php echo esc_html($updated_label); ?></div>

        <div class="tk-attacks-summary-title">
            <strong>Firewall Summary:</strong> Attacks Blocked for <?php echo esc_html(wp_parse_url(home_url('/'), PHP_URL_HOST) ?: get_bloginfo('name')); ?>
        </div>
        <table class="tk-attacks-summary">
            <thead>
                <tr>
                    <th>Block Type</th>
                    <th>Complex</th>
                    <th>Brute Force</th>
                    <th>Blocklist</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array('today' => 'Today', 'week' => 'Week', 'month' => 'Month') as $key => $label) : ?>
                    <tr>
                        <th><?php echo esc_html($label); ?></th>
                        <td><?php echo esc_html(number_format_i18n($summary[$key]['complex'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($summary[$key]['brute'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($summary[$key]['blocklist'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($summary[$key]['total'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="tk-attacks-country-title">Top Countries by Number of Attacks - Last 7 Days</div>
        <table class="tk-attacks-country-table">
            <thead>
                <tr>
                    <th>Country</th>
                    <th></th>
                    <th>Block Count</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($top_countries)) : ?>
                    <tr><td colspan="3" class="tk-attacks-empty">No country data has been recorded.</td></tr>
                <?php else : ?>
                    <?php foreach ($top_countries as $country) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url(add_query_arg('country', (string) $country['country'], $details_url)); ?>"><?php echo esc_html($country['country']); ?></a></td>
                            <td class="tk-attacks-flag"><?php echo esc_html($country['flag']); ?></td>
                            <td><?php echo esc_html(number_format_i18n($country['count'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="tk-attacks-top-ip-title">Top IPs Blocked</div>
        <div class="tk-attacks-tabs tk-attacks-ip-tabs" role="tablist" aria-label="Top blocked IP range">
            <button type="button" class="is-active" data-tk-top-ip-range="24h">24 Hours</button>
            <button type="button" data-tk-top-ip-range="7d">7 Days</button>
            <button type="button" data-tk-top-ip-range="30d">30 Days</button>
        </div>
        <?php foreach ($top_ips as $range => $rows) : ?>
            <div class="tk-attacks-ip-panel <?php echo $range === '24h' ? 'is-active' : ''; ?>" data-tk-top-ip-panel="<?php echo esc_attr($range); ?>">
                <?php if (empty($rows)) : ?>
                    <p class="tk-attacks-empty">No blocks have been recorded.</p>
                <?php else : ?>
                    <table class="tk-attacks-ip-table">
                        <thead><tr><th>IP</th><th>Block Count</th></tr></thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td><a href="<?php echo esc_url(add_query_arg('ip', (string) $row['ip'], $details_url)); ?>"><code><?php echo esc_html($row['ip']); ?></code></a></td>
                                    <td><?php echo esc_html(number_format_i18n($row['count'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="tk-attacks-actions">
            <a href="<?php echo esc_url($firewall_url); ?>" class="button button-primary">Open Firewall</a>
            <a href="<?php echo esc_url($login_log_url); ?>" class="button button-secondary">View Login Log</a>
        </div>
    </div>
    <style>
        .tk-attacks-dashboard-widget {
            color: #2f343b;
        }
        .tk-attacks-chart-head {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
            margin: 4px 0 10px;
        }
        .tk-attacks-tabs {
            display: inline-flex;
            border: 1px solid #cfd7df;
            border-radius: 4px;
            overflow: hidden;
            background: #fff;
        }
        .tk-attacks-tabs button {
            border: 0;
            border-right: 1px solid #cfd7df;
            background: #fff;
            color: #0f6597;
            padding: 8px 14px;
            min-height: 38px;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
        }
        .tk-attacks-tabs button:last-child {
            border-right: 0;
        }
        .tk-attacks-tabs button.is-active {
            background: #1d7fa9;
            color: #fff;
        }
        .tk-attacks-legend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 13px;
            color: #555;
        }
        .tk-attacks-legend span {
            width: 42px;
            height: 16px;
            border: 4px solid #14b8a6;
            background: rgba(20, 184, 166, 0.28);
            box-sizing: border-box;
        }
        .tk-attacks-chart {
            display: none;
        }
        .tk-attacks-chart.is-active {
            display: block;
        }
        .tk-attacks-chart svg {
            display: block;
            width: 100%;
            height: auto;
            max-height: 260px;
        }
        .tk-attack-grid {
            stroke: rgba(0, 0, 0, 0.13);
            stroke-width: 1;
        }
        .tk-attack-line {
            fill: none;
            stroke: #14b8a6;
            stroke-width: 4;
            stroke-linejoin: round;
            stroke-linecap: round;
        }
        .tk-attack-points circle {
            fill: #fff;
            stroke: #14b8a6;
            stroke-width: 2;
        }
        .tk-attack-labels text {
            fill: #666;
            font-size: 13px;
        }
        .tk-attacks-updated {
            text-align: center;
            font-style: italic;
            font-weight: 600;
            margin: 4px 0 18px;
            color: #2f343b;
        }
        .tk-attacks-summary-title {
            border-top: 1px solid #e2e8f0;
            padding-top: 14px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .tk-attacks-summary {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            text-align: center;
            margin-bottom: 14px;
        }
        .tk-attacks-summary th,
        .tk-attacks-summary td {
            padding: 6px 5px;
            font-size: 14px;
            border: 0;
        }
        .tk-attacks-summary thead th {
            font-weight: 700;
        }
        .tk-attacks-summary tbody th {
            font-weight: 700;
            text-align: right;
        }
        .tk-attacks-summary td {
            font-size: 16px;
        }
        .tk-attacks-summary th:nth-child(4),
        .tk-attacks-summary td:nth-child(4) {
            border-left: 3px solid #1d7fa9;
            border-right: 3px solid #1d7fa9;
            color: #999;
        }
        .tk-attacks-summary thead th:nth-child(4) {
            border-top: 3px solid #1d7fa9;
            border-radius: 8px 8px 0 0;
        }
        .tk-attacks-summary tbody tr:last-child td:nth-child(4) {
            border-bottom: 3px solid #1d7fa9;
        }
        .tk-attacks-country-title,
        .tk-attacks-top-ip-title {
            border-top: 1px solid #e2e8f0;
            color: #343a42;
            font-size: 16px;
            font-weight: 700;
            margin-top: 16px;
            padding-top: 16px;
        }
        .tk-attacks-country-table,
        .tk-attacks-ip-table {
            border-collapse: collapse;
            margin: 12px 0 16px;
            width: 100%;
        }
        .tk-attacks-country-table th,
        .tk-attacks-country-table td,
        .tk-attacks-ip-table th,
        .tk-attacks-ip-table td {
            border-bottom: 1px solid #d7d7d7;
            color: #2f343b;
            font-size: 14px;
            padding: 9px 10px;
            text-align: left;
        }
        .tk-attacks-country-table thead th,
        .tk-attacks-ip-table thead th {
            border-bottom: 2px solid #d0d0d0;
            font-weight: 700;
        }
        .tk-attacks-country-table th:last-child,
        .tk-attacks-country-table td:last-child,
        .tk-attacks-ip-table th:last-child,
        .tk-attacks-ip-table td:last-child {
            text-align: right;
        }
        .tk-attacks-flag {
            text-align: center !important;
            width: 52px;
        }
        .tk-attacks-ip-tabs {
            margin: 18px auto 14px;
            width: max-content;
        }
        .tk-attacks-ip-panel {
            display: none;
        }
        .tk-attacks-ip-panel.is-active {
            display: block;
        }
        .tk-attacks-empty {
            color: #2f343b;
            font-style: italic;
            margin: 16px 0;
        }
        .tk-attacks-actions {
            display: flex;
            gap: 8px;
            margin-top: 14px;
        }
        .tk-attacks-actions .button {
            flex: 1;
            text-align: center;
        }
        @media (max-width: 782px) {
            .tk-attacks-summary th,
            .tk-attacks-summary td {
                font-size: 12px;
                padding-inline: 3px;
            }
            .tk-attacks-actions {
                flex-direction: column;
            }
        }
    </style>
    <script>
    (function(){
        var widget = document.querySelector('.tk-attacks-dashboard-widget');
        if (!widget) { return; }
        widget.querySelectorAll('[data-tk-attack-range]').forEach(function(button){
            button.addEventListener('click', function(){
                var range = button.getAttribute('data-tk-attack-range');
                widget.querySelectorAll('[data-tk-attack-range]').forEach(function(item){
                    item.classList.toggle('is-active', item === button);
                });
                widget.querySelectorAll('[data-tk-attack-chart]').forEach(function(chart){
                    chart.classList.toggle('is-active', chart.getAttribute('data-tk-attack-chart') === range);
                });
            });
        });
        widget.querySelectorAll('[data-tk-top-ip-range]').forEach(function(button){
            button.addEventListener('click', function(){
                var range = button.getAttribute('data-tk-top-ip-range');
                widget.querySelectorAll('[data-tk-top-ip-range]').forEach(function(item){
                    item.classList.toggle('is-active', item === button);
                });
                widget.querySelectorAll('[data-tk-top-ip-panel]').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-tk-top-ip-panel') === range);
                });
            });
        });
    })();
    </script>
    <?php
}

function tk_render_security_events_page(): void {
    if (!tk_toolkits_can_manage()) return;

    if (function_exists('tk_security_events_install_table')) {
        tk_security_events_install_table();
    }

    $country = isset($_GET['country']) ? sanitize_text_field(wp_unslash((string) $_GET['country'])) : '';
    $ip = isset($_GET['ip']) ? sanitize_text_field(wp_unslash((string) $_GET['ip'])) : '';
    $category = isset($_GET['category']) ? sanitize_key(wp_unslash((string) $_GET['category'])) : '';
    if (!in_array($category, array('', 'complex', 'brute_force', 'blocklist', 'auto_block'), true)) {
        $category = '';
    }

    $rows = array();
    $total = 0;
    if (function_exists('tk_security_events_table_exists') && tk_security_events_table_exists()) {
        global $wpdb;
        $table = tk_security_events_table();
        $where = array('event_type = %s');
        $params = array('blocked');
        if ($country !== '') {
            $where[] = 'country = %s';
            $params[] = $country;
        }
        if ($ip !== '') {
            $where[] = 'ip = %s';
            $params[] = $ip;
        }
        if ($category !== '') {
            $where[] = 'category = %s';
            $params[] = $category;
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where_sql}", $params));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} {$where_sql} ORDER BY time DESC LIMIT %d",
            array_merge($params, array(100))
        ));
        $rows = is_array($rows) ? $rows : array();
    }

    $base_url = admin_url('admin.php?page=tool-kits-security-events');
    $retention_days = max(7, (int) tk_get_option('security_events_retention_days', 90));
    $backfill_time = (int) tk_get_option('security_events_last_backfill_time', 0);
    $backfill_count = (int) tk_get_option('security_events_last_backfill_count', 0);
    $notice = isset($_GET['tk_security_events_status']) ? sanitize_key(wp_unslash((string) $_GET['tk_security_events_status'])) : '';
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Attack Details', 'tool-kits'), __('Drill into blocked requests by country, IP, reason, and request target.', 'tool-kits'), 'dashicons-search'); ?>
        <?php if ($notice === 'saved') : ?><?php tk_notice(__('Security event settings saved.', 'tool-kits'), 'success'); ?><?php endif; ?>
        <?php if ($notice === 'maintenance') : ?><?php tk_notice(__('Security event maintenance completed.', 'tool-kits'), 'success'); ?><?php endif; ?>

        <div class="tk-card" style="margin-bottom:24px;">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; align-items:end;">
                <input type="hidden" name="page" value="tool-kits-security-events">
                <label>
                    <strong><?php esc_html_e('Country', 'tool-kits'); ?></strong><br>
                    <input class="regular-text" type="text" name="country" value="<?php echo esc_attr($country); ?>" style="width:100%;">
                </label>
                <label>
                    <strong><?php esc_html_e('IP', 'tool-kits'); ?></strong><br>
                    <input class="regular-text" type="text" name="ip" value="<?php echo esc_attr($ip); ?>" style="width:100%;">
                </label>
                <label>
                    <strong><?php esc_html_e('Category', 'tool-kits'); ?></strong><br>
                    <select name="category" style="width:100%;">
                        <option value="" <?php selected($category, ''); ?>><?php esc_html_e('All', 'tool-kits'); ?></option>
                        <option value="complex" <?php selected($category, 'complex'); ?>>Complex</option>
                        <option value="brute_force" <?php selected($category, 'brute_force'); ?>>Brute Force</option>
                        <option value="blocklist" <?php selected($category, 'blocklist'); ?>>Blocklist</option>
                        <option value="auto_block" <?php selected($category, 'auto_block'); ?>>Auto Block</option>
                    </select>
                </label>
                <div style="display:flex; gap:8px;">
                    <button class="button button-primary"><?php esc_html_e('Filter', 'tool-kits'); ?></button>
                    <a class="button" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Reset', 'tool-kits'); ?></a>
                </div>
            </form>
        </div>

        <div class="tk-card" style="margin-bottom:24px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Event Storage', 'tool-kits'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('Last backfill: %s. Imported events: %d.', 'tool-kits'), $backfill_time > 0 ? wp_date('Y-m-d H:i:s', $backfill_time) : __('Never', 'tool-kits'), $backfill_count)); ?></p>
            <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:10px; align-items:flex-end; margin:0;">
                    <?php tk_nonce_field('tk_security_events_settings'); ?>
                    <input type="hidden" name="action" value="tk_security_events_settings">
                    <label>
                        <strong><?php esc_html_e('Retention days', 'tool-kits'); ?></strong><br>
                        <input type="number" name="retention_days" min="7" value="<?php echo esc_attr((string) $retention_days); ?>" class="small-text">
                    </label>
                    <button class="button button-primary"><?php esc_html_e('Save Retention', 'tool-kits'); ?></button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                    <?php tk_nonce_field('tk_security_events_run_maintenance'); ?>
                    <input type="hidden" name="action" value="tk_security_events_run_maintenance">
                    <button class="button"><?php esc_html_e('Run Maintenance Now', 'tool-kits'); ?></button>
                </form>
            </div>
        </div>

        <div class="tk-card">
            <div style="display:flex; justify-content:space-between; gap:16px; align-items:center; margin-bottom:12px;">
                <div>
                    <h2 style="margin:0;"><?php esc_html_e('Blocked Request Events', 'tool-kits'); ?></h2>
                    <p class="description" style="margin:4px 0 0;"><?php echo esc_html(sprintf(_n('%d event found.', '%d events found.', $total, 'tool-kits'), $total)); ?></p>
                </div>
            </div>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'tool-kits'); ?></th>
                        <th><?php esc_html_e('Category', 'tool-kits'); ?></th>
                        <th><?php esc_html_e('IP / Country', 'tool-kits'); ?></th>
                        <th><?php esc_html_e('Reason', 'tool-kits'); ?></th>
                        <th><?php esc_html_e('Request', 'tool-kits'); ?></th>
                        <th><?php esc_html_e('Agent', 'tool-kits'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="6"><?php esc_html_e('No persistent attack events have been recorded yet.', 'tool-kits'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html(wp_date('Y-m-d H:i:s', strtotime((string) $row->time) ?: time())); ?></td>
                            <td><span class="tk-badge tk-warn"><?php echo esc_html((string) $row->category); ?></span></td>
                            <td><code><?php echo esc_html((string) $row->ip); ?></code><br><?php echo esc_html((string) ($row->country ?: $row->location)); ?></td>
                            <td><?php echo esc_html((string) $row->reason); ?></td>
                            <td><code><?php echo esc_html(strtoupper((string) $row->request_method) . ' ' . (string) $row->request_uri); ?></code></td>
                            <td style="max-width:260px; overflow-wrap:anywhere;"><?php echo esc_html((string) $row->user_agent); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function tk_security_events_settings_save(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_security_events_settings');
    $retention_days = isset($_POST['retention_days']) ? max(7, (int) $_POST['retention_days']) : 90;
    tk_update_option('security_events_retention_days', $retention_days);
    if (function_exists('tk_security_events_cleanup')) {
        tk_security_events_cleanup();
    }
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-security-events', 'tk_security_events_status' => 'saved'), admin_url('admin.php')));
    exit;
}

function tk_security_events_run_maintenance_handler(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_security_events_run_maintenance');
    if (function_exists('tk_security_events_maintenance')) {
        tk_security_events_maintenance();
    }
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-security-events', 'tk_security_events_status' => 'maintenance'), admin_url('admin.php')));
    exit;
}
