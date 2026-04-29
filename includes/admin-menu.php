<?php
if (!defined('ABSPATH')) { exit; }

function tk_admin_menu_init() {
    add_action('admin_menu', 'tk_register_admin_menus');
    add_action('admin_enqueue_scripts', 'tk_monitoring_maybe_enqueue_assets');

    add_action('admin_post_tk_toolkits_access_save', 'tk_toolkits_access_save');
    add_action('admin_post_tk_toolkits_license_activate', 'tk_toolkits_license_activate');
    add_action('admin_post_tk_toolkits_license_reset', 'tk_toolkits_license_reset');
    add_action('admin_post_tk_toolkits_audit_clear', 'tk_toolkits_audit_clear');
    add_action('admin_post_tk_set_wpconfig_readonly', 'tk_set_wpconfig_readonly');
    add_action('admin_post_tk_set_wpconfig_writable', 'tk_set_wpconfig_writable');
    add_action('admin_post_tk_toggle_core_updates', 'tk_toggle_core_updates');
    add_action('admin_post_tk_clear_cache', 'tk_clear_cache_handler');
    add_action('admin_post_tk_remove_ds_store', 'tk_remove_ds_store_handler');
    add_action('admin_post_tk_heartbeat_manual', 'tk_heartbeat_manual_send');
    add_action('admin_post_tk_monitoring_save', 'tk_monitoring_save');
    add_action('tk_monitoring_cron', 'tk_monitoring_cron_run');
    add_action('init', 'tk_monitoring_schedule_cron');
}

function tk_monitoring_maybe_enqueue_assets($hook_suffix) {
    if ($hook_suffix !== 'tool-kits_page_tool-kits-monitoring') {
        return;
    }
    $asset_url  = TK_URL . 'assets/monitoring-tabs.js';
    $asset_path = TK_PATH . 'assets/monitoring-tabs.js';
    $ver = file_exists($asset_path) ? filemtime($asset_path) : TK_VERSION;
    wp_enqueue_script('tk-monitoring-tabs', $asset_url, array(), $ver, true);
    wp_localize_script('tk-monitoring-tabs', 'tkMonitoringData', array(
        'nonce'   => wp_create_nonce('tk_realtime_health'),
        'ajaxurl' => admin_url('admin-ajax.php'),
    ));
}

function tk_register_admin_menus() {
    if (!tk_toolkits_can_manage()) return;
    $license_key = (string) tk_get_option('license_key', '');
    $license_reset = isset($_GET['tk_reset_license']) ? sanitize_key($_GET['tk_reset_license']) : '';
    if ($license_key !== '' && $license_reset !== '1' && (int) get_option('tk_license_reset_skip_validate', 0) !== 1) {
        tk_license_validate(true);
    }
    $license_status = (string) tk_get_option('license_status', 'inactive');
    $license_missing = $license_key === '';
    $license_valid = $license_status === 'valid';
    $license_type = (string) tk_get_option('license_type', '');
    $license_limited = $license_type === 'local';
    $allow_full = $license_valid && !$license_limited;

    if ($license_valid) {
        // Parent
        add_menu_page(
            __('Tool Kits', 'tool-kits'),
            __('Tool Kits', 'tool-kits'),
            tk_toolkits_capability(),
            'tool-kits',
            'tk_render_overview_page',
            'dashicons-admin-tools',
            99
        );

        if ($allow_full) {
            // DB
            add_submenu_page('tool-kits', __('General', 'tool-kits'), __('General', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-general', 'tk_render_general_page');
            add_submenu_page('tool-kits', __('Database', 'tool-kits'), __('Database', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-db', 'tk_render_db_tools_page');

            // Security modules now live under the main Tool Kits menu.
            add_submenu_page('tool-kits', __('Optimization', 'tool-kits'), __('Optimization', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-optimization', 'tk_render_optimization_page');
            add_submenu_page('tool-kits', __('Spam Protection', 'tool-kits'), __('Spam Protection', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-security-spam', 'tk_render_spam_protection_page');
            add_submenu_page('tool-kits', __('Rate Limit', 'tool-kits'), __('Rate Limit', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-security-rate-limit', 'tk_render_rate_limit_page');
            add_submenu_page('tool-kits', __('Login Log', 'tool-kits'), __('Login Log', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-security-login-log', 'tk_render_login_log_page');
            add_submenu_page('tool-kits', __('Hardening', 'tool-kits'), __('Hardening', 'tool-kits'), 'manage_options', tk_hardening_page_slug(), 'tk_render_hardening_page');
            add_submenu_page('tool-kits', __('SMTP', 'tool-kits'), __('SMTP', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-smtp', 'tk_render_smtp_page');
            add_submenu_page('tool-kits', __('Monitoring', 'tool-kits'), __('Monitoring', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-monitoring', 'tk_render_monitoring_page');
            add_submenu_page('tool-kits', __('Cache', 'tool-kits'), __('Cache', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-cache', 'tk_render_cache_page');
            add_submenu_page('tool-kits', __('Themes Checker', 'tool-kits'), __('Themes Checker', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-theme-checker', 'tk_render_theme_checker_page');
        } else {
            add_submenu_page('tool-kits', __('General', 'tool-kits'), __('General', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-general', 'tk_render_general_page');
            add_submenu_page('tool-kits', __('Database', 'tool-kits'), __('Database', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-db', 'tk_render_db_tools_page');
            add_submenu_page('tool-kits', __('Optimization', 'tool-kits'), __('Optimization', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-optimization', 'tk_render_optimization_page');
            add_submenu_page('tool-kits', __('SMTP', 'tool-kits'), __('SMTP', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-smtp', 'tk_render_smtp_page');
            add_submenu_page('tool-kits', __('Monitoring', 'tool-kits'), __('Monitoring', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-monitoring', 'tk_render_monitoring_page');
            add_submenu_page('tool-kits', __('Cache', 'tool-kits'), __('Cache', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-cache', 'tk_render_cache_page');
        }
    }

    add_submenu_page('tools.php', __('Tool Kits Access', 'tool-kits'), __('Tool Kits Access', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-access', 'tk_render_toolkits_access_page');
    if (!$license_valid) {
        add_submenu_page('tools.php', __('Database', 'tool-kits'), __('Database', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-db', 'tk_render_db_tools_page');
    }
    // Hidden legacy pages for direct links.
    if ($allow_full) {
        add_submenu_page(null, __('Hide Login', 'tool-kits'), __('Hide Login', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-security-hide-login', 'tk_render_hide_login_page');
        add_submenu_page(null, __('Minify', 'tool-kits'), __('Minify', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-minify', 'tk_render_minify_page');
        add_submenu_page(null, __('Auto WebP', 'tool-kits'), __('Auto WebP', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-webp', 'tk_render_webp_page');
    } elseif ($license_valid && $license_limited) {
        add_submenu_page(null, __('Minify', 'tool-kits'), __('Minify', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-minify', 'tk_render_minify_page');
        add_submenu_page(null, __('Auto WebP', 'tool-kits'), __('Auto WebP', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-webp', 'tk_render_webp_page');
    }

    if (tk_get_option('hide_toolkits_menu', 0) || !$license_valid) {
        remove_menu_page('tool-kits');
    }
    if (tk_get_option('hide_cff_menu', 0)) {
        remove_menu_page('cff');
        remove_submenu_page('cff', 'cff');
    }
}

function tk_render_monitoring_page() {
    if (!tk_is_admin_user()) return;
    
    $checks = tk_hardening_config_checks();
    $server = tk_hardening_detect_server();
    $server_rules = tk_hardening_server_rules();
    $server_snippet = tk_hardening_server_rule_snippet();
    $server_status = tk_hardening_server_rule_status();
    $noncore_root = tk_hardening_noncore_root_entries();
    
    tk_monitoring_maybe_send_alert($noncore_root);
    tk_tamper_maybe_alert();

    $monitor_email = (string) tk_get_option('monitoring_alert_email', '');
    $log = tk_get_option('monitoring_404_log', array());
    if (!is_array($log)) { $log = array(); }
    $log_values = array_values($log);
    
    usort($log_values, function($a, $b) {
        $a_count = isset($a['count']) ? (int) $a['count'] : 0;
        $b_count = isset($b['count']) ? (int) $b['count'] : 0;
        if ($a_count === $b_count) {
            $a_last = isset($a['last']) ? (int) $a['last'] : 0;
            $b_last = isset($b['last']) ? (int) $b['last'] : 0;
            return $b_last <=> $a_last;
        }
        return $b_count <=> $a_count;
    });

    $health_url = add_query_arg('tk-health', '1', home_url('/'));
    $health_key = (string) tk_get_option('monitoring_healthcheck_key', '');
    if ($health_key !== '') {
        $health_url = add_query_arg('key', $health_key, $health_url);
    }

    $healthcheck = tk_healthcheck_data();
    $connection_summary = tk_toolkits_connection_summary();
    $core_auto = tk_get_option('hardening_core_auto_updates', 1) ? true : (defined('WP_AUTO_UPDATE_CORE') && WP_AUTO_UPDATE_CORE === true);
    $wp_config_path = tk_hardening_wp_config_path();

    tk_get_template('monitoring', array(
        'checks' => $checks,
        'server' => $server,
        'server_rules' => $server_rules,
        'server_snippet' => $server_snippet,
        'server_status' => $server_status,
        'noncore_root' => $noncore_root,
        'monitor_email' => $monitor_email,
        'log_values' => $log_values,
        'health_url' => $health_url,
        'health_key' => $health_key,
        'healthcheck' => $healthcheck,
        'connection_summary' => $connection_summary,
        'core_auto' => $core_auto,
        'wp_config_path' => $wp_config_path
    ));
}

function tk_render_overview_page() {
    if (!tk_is_admin_user()) return;
    $opts = tk_get_options();
    $score_data = tk_hardening_calculate_score();
    $score = $score_data['score'];
    $score_color = ($score >= 80) ? '#27ae60' : (($score >= 50) ? '#f39c12' : '#e74c3c');

    tk_get_template('overview', array(
        'score' => $score,
        'score_color' => $score_color,
        'score_data' => $score_data,
        'opts' => $opts
    ));
}

function tk_render_diagnostics_page() {
    if (!tk_is_admin_user()) return;

    $diagnostics = function_exists('tk_github_get_diagnostics') ? tk_github_get_diagnostics() : array();
    $connection_summary = tk_toolkits_connection_summary();
    $status = isset($diagnostics['status']) && is_array($diagnostics['status']) ? $diagnostics['status'] : array();
    $status_label = isset($status['status']) ? (string) $status['status'] : 'idle';
    $status_message = isset($status['message']) ? (string) $status['message'] : 'No updater status recorded yet.';
    $status_context = isset($status['context']) && is_array($status['context']) ? $status['context'] : array();
    $published_at = isset($diagnostics['published_at']) ? (string) $diagnostics['published_at'] : '';
    $checks = isset($diagnostics['checks']) && is_array($diagnostics['checks']) ? $diagnostics['checks'] : array();
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero('System Diagnostics', 'Detailed health reports, connection summaries, and updater logs for advanced troubleshooting.', 'dashicons-analytics'); ?>
        <?php if (isset($_GET['tk_github_status_cleared']) && sanitize_key((string) $_GET['tk_github_status_cleared']) === '1') : ?>
            <?php tk_notice('GitHub updater status cleared.', 'success'); ?>
        <?php endif; ?>

        <div class="tk-card" style="margin-top:24px;">
            <h2>Connection Status</h2>
            <p class="description">Review collector, heartbeat, and license configuration before troubleshooting access or monitoring issues.</p>
            <table class="widefat striped tk-table">
                <tbody>
                    <tr>
                        <th>Collector URL</th>
                        <td><?php echo tk_toolkits_status_badge($connection_summary['collector_status']); ?></td>
                        <td><code><?php echo esc_html($connection_summary['collector_url'] !== '' ? $connection_summary['collector_url'] : 'Missing'); ?></code></td>
                    </tr>
                    <tr>
                        <th>Heartbeat URL</th>
                        <td><?php echo tk_toolkits_status_badge($connection_summary['heartbeat_status']); ?></td>
                        <td><code><?php echo esc_html($connection_summary['heartbeat_url'] !== '' ? $connection_summary['heartbeat_url'] : 'Missing'); ?></code></td>
                    </tr>
                    <tr>
                        <th>License URL</th>
                        <td><?php echo tk_toolkits_status_badge($connection_summary['license_url_status']); ?></td>
                        <td><code><?php echo esc_html($connection_summary['license_url'] !== '' ? $connection_summary['license_url'] : 'Missing'); ?></code></td>
                    </tr>
                    <tr>
                        <th>Token status</th>
                        <td><?php echo tk_toolkits_status_badge($connection_summary['token_status']); ?></td>
                        <td><?php echo $connection_summary['token_status'] === 'configured' ? 'Collector token is set.' : 'Collector token is missing.'; ?></td>
                    </tr>
                    <tr>
                        <th>Last heartbeat</th>
                        <td><?php echo tk_toolkits_status_badge($connection_summary['heartbeat_last_success'] > 0 ? 'configured' : 'missing'); ?></td>
                        <td><?php echo esc_html(tk_toolkits_format_timestamp($connection_summary['heartbeat_last_success'])); ?></td>
                    </tr>
                    <tr>
                        <th>Last heartbeat failure</th>
                        <td><?php echo tk_toolkits_status_badge($connection_summary['heartbeat_last_failure'] > 0 ? 'error' : 'missing'); ?></td>
                        <td><?php echo esc_html(tk_toolkits_format_timestamp($connection_summary['heartbeat_last_failure'])); ?></td>
                    </tr>
                    <tr>
                        <th>Last license check</th>
                        <td><?php echo tk_toolkits_status_badge($connection_summary['license_last_checked'] > 0 ? 'configured' : 'missing'); ?></td>
                        <td><?php echo esc_html(tk_toolkits_format_timestamp($connection_summary['license_last_checked'])); ?></td>
                    </tr>
                    <tr>
                        <th>Last license failure</th>
                        <td><?php echo tk_toolkits_status_badge($connection_summary['license_last_failure'] > 0 ? 'error' : 'missing'); ?></td>
                        <td><?php echo esc_html(tk_toolkits_format_timestamp($connection_summary['license_last_failure'])); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php if ($connection_summary['heartbeat_last_error'] !== '') : ?>
                <p><strong>Last heartbeat error:</strong> <?php echo esc_html($connection_summary['heartbeat_last_error']); ?></p>
            <?php endif; ?>
            <?php if ($connection_summary['license_last_error'] !== '') : ?>
                <p><strong>Last license error:</strong> <?php echo esc_html($connection_summary['license_last_error']); ?></p>
            <?php endif; ?>
        </div>

        <div class="tk-card" style="margin-top:24px;">
            <h2>GitHub Updater</h2>
            <p class="description">Use this page to verify the currently installed version, cached GitHub release data, and the last updater result stored by WordPress.</p>
            <p>
                <?php
                $check_now_url = wp_nonce_url(admin_url('admin-post.php?action=tk_github_check_now'), 'tk_github_check_now');
                $clear_status_url = wp_nonce_url(admin_url('admin-post.php?action=tk_github_clear_status'), 'tk_github_clear_status');
                ?>
                <a class="button button-primary" href="<?php echo esc_url($check_now_url); ?>">Refresh GitHub Release Cache</a>
                <a class="button button-secondary" href="<?php echo esc_url($clear_status_url); ?>">Clear Stored Status</a>
            </p>

            <table class="widefat striped tk-table">
                <tbody>
                    <tr>
                        <th>Installed version</th>
                        <td><?php echo esc_html((string) ($diagnostics['current_version'] ?? '')); ?></td>
                    </tr>
                    <tr>
                        <th>Repository</th>
                        <td><code><?php echo esc_html((string) ($diagnostics['repo'] ?? '')); ?></code></td>
                    </tr>
                    <tr>
                        <th>Cached release</th>
                        <td><?php echo !empty($diagnostics['release_cached']) ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <th>Update available</th>
                        <td><?php echo !empty($diagnostics['update_available']) ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <th>Latest tag</th>
                        <td><?php echo esc_html((string) ($diagnostics['tag_name'] ?? '-')); ?></td>
                    </tr>
                    <tr>
                        <th>Normalized version</th>
                        <td><?php echo esc_html((string) ($diagnostics['normalized_tag'] ?? '-')); ?></td>
                    </tr>
                    <tr>
                        <th>Published at</th>
                        <td><?php echo $published_at !== '' ? esc_html(gmdate('Y-m-d H:i:s', strtotime($published_at)) . ' UTC') : '-'; ?></td>
                    </tr>
                    <tr>
                        <th>Release age</th>
                        <td>
                            <?php
                            if (isset($diagnostics['release_age_hours']) && $diagnostics['release_age_hours'] !== null) {
                                echo esc_html((string) $diagnostics['release_age_hours'] . ' hour(s)');
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Release URL</th>
                        <td>
                            <?php if (!empty($diagnostics['release_url'])) : ?>
                                <a href="<?php echo esc_url((string) $diagnostics['release_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) $diagnostics['release_url']); ?></a>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Package asset</th>
                        <td><?php echo esc_html((string) ($diagnostics['asset_name'] ?? '-')); ?></td>
                    </tr>
                    <tr>
                        <th>Package asset exact match</th>
                        <td><?php echo !empty($diagnostics['package_valid']) ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <th>Package URL</th>
                        <td><code><?php echo esc_html((string) ($diagnostics['package_url'] ?? '-')); ?></code></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="tk-card" style="margin-top:24px;">
            <h2>Readiness Checks</h2>
            <?php if (!empty($checks)) : ?>
                <table class="widefat striped tk-table">
                    <tbody>
                    <?php foreach ($checks as $check) : ?>
                        <?php
                        $check_status = isset($check['status']) ? (string) $check['status'] : 'unknown';
                        $check_badge = 'tk-badge';
                        if ($check_status === 'ok') {
                            $check_badge .= ' tk-on';
                        } elseif ($check_status === 'warn') {
                            $check_badge .= ' tk-warn';
                        }
                        ?>
                        <tr>
                            <th><?php echo esc_html((string) ($check['label'] ?? 'Check')); ?></th>
                            <td><span class="<?php echo esc_attr($check_badge); ?>"><?php echo esc_html(strtoupper($check_status)); ?></span></td>
                            <td><?php echo esc_html((string) ($check['detail'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><small>No readiness checks available yet.</small></p>
            <?php endif; ?>
        </div>

        <div class="tk-card" style="margin-top:24px;">
            <h2>Last Updater Result</h2>
            <?php
            $badge_class = 'tk-badge';
            if ($status_label === 'failed') {
                $badge_class .= ' tk-warn';
            } elseif ($status_label === 'completed' || $status_label === 'installed') {
                $badge_class .= ' tk-on';
            }
            ?>
            <p><span class="<?php echo esc_attr($badge_class); ?>"><?php echo esc_html(strtoupper($status_label)); ?></span></p>
            <p><?php echo esc_html($status_message); ?></p>
            <?php if (!empty($status['timestamp'])) : ?>
                <p><small>Recorded at <?php echo esc_html(date_i18n('Y-m-d H:i:s', (int) $status['timestamp'])); ?></small></p>
            <?php endif; ?>
            <?php if (!empty($status_context)) : ?>
                <table class="widefat striped tk-table">
                    <tbody>
                    <?php foreach ($status_context as $key => $value) : ?>
                        <tr>
                            <th><?php echo esc_html((string) $key); ?></th>
                            <td>
                                <?php
                                if (is_scalar($value) || $value === null) {
                                    echo '<code>' . esc_html((string) $value) . '</code>';
                                } else {
                                    echo '<code>' . esc_html(wp_json_encode($value)) . '</code>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><small>No updater context stored yet.</small></p>
            <?php endif; ?>
        </div>

        <div class="tk-card" style="margin-top:24px;">
            <h2>Release Checklist</h2>
            <ul class="tk-list">
                <li>&#10003; GitHub asset should be named <code>tool-kits.zip</code>.</li>
                <li>&#10003; ZIP root should be exactly <code>tool-kits/</code>.</li>
                <li>&#10003; Main plugin file should exist at <code>tool-kits/tool-kits.php</code>.</li>
                <li>&#10003; <code>Version</code> in <code>tool-kits.php</code>, <code>Stable tag</code> in <code>readme.txt</code>, and the GitHub tag should match.</li>
            </ul>
        </div>
    </div>
    <?php
}

function tk_render_security_table() {
    ?>
    <div class="tk-module-grid">
        <!-- Hide Login -->
        <div class="tk-module-card">
            <div class="tk-module-header">
                <div class="tk-module-title">
                    <span class="dashicons dashicons-lock"></span>
                    <h3>Hide Login</h3>
                </div>
                <?php echo tk_get_option('hide_login_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?>
            </div>
            <div class="tk-module-body">
                <?php if (tk_get_option('hide_login_enabled',0)) : ?>
                    <ul class="tk-list">
                        <li>/<?php echo esc_html((string) tk_get_option('hide_login_slug', 'secure-login')); ?></li>
                    </ul>
                <?php else : ?>
                    <p class="tk-empty">Slug protection inactive.</p>
                <?php endif; ?>
            </div>
            <div class="tk-module-footer">
                <a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#hide-login'); ?>" class="button button-small">Configure</a>
            </div>
        </div>

        <!-- Captcha -->
        <div class="tk-module-card">
            <div class="tk-module-header">
                <div class="tk-module-title">
                    <span class="dashicons dashicons-shield"></span>
                    <h3>Captcha</h3>
                </div>
                <?php echo tk_get_option('captcha_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?>
            </div>
            <div class="tk-module-body">
                <?php if (tk_get_option('captcha_enabled',0)) : ?>
                    <ul class="tk-list">
                        <?php if (tk_get_option('captcha_on_login',1)) : ?><li>Login Protection</li><?php endif; ?>
                        <?php if (tk_get_option('captcha_on_comments',0)) : ?><li>Comment Protection</li><?php endif; ?>
                    </ul>
                <?php else : ?>
                    <p class="tk-empty">Bot protection inactive.</p>
                <?php endif; ?>
            </div>
            <div class="tk-module-footer">
                <a href="<?php echo esc_url(tk_admin_url('tool-kits-security-spam') . '#captcha'); ?>" class="button button-small">Configure</a>
            </div>
        </div>

        <!-- Anti-spam -->
        <div class="tk-module-card">
            <div class="tk-module-header">
                <div class="tk-module-title">
                    <span class="dashicons dashicons-no-alt"></span>
                    <h3>Anti-spam</h3>
                </div>
                <?php echo tk_get_option('antispam_cf7_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?>
            </div>
            <div class="tk-module-body">
                <?php if (tk_get_option('antispam_cf7_enabled',0)) : ?>
                    <ul class="tk-list">
                        <li>Min delay: <?php echo esc_html((string) tk_get_option('antispam_min_seconds', 3)); ?>s</li>
                        <li>CF7 Integration Active</li>
                    </ul>
                <?php else : ?>
                    <p class="tk-empty">Form spam protection inactive.</p>
                <?php endif; ?>
            </div>
            <div class="tk-module-footer">
                <a href="<?php echo esc_url(tk_admin_url('tool-kits-security-spam') . '#antispam'); ?>" class="button button-small">Configure</a>
            </div>
        </div>

        <!-- Rate Limit -->
        <div class="tk-module-card">
            <div class="tk-module-header">
                <div class="tk-module-title">
                    <span class="dashicons dashicons-clock"></span>
                    <h3>Rate Limit</h3>
                </div>
                <?php echo tk_get_option('rate_limit_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?>
            </div>
            <div class="tk-module-body">
                <?php if (tk_get_option('rate_limit_enabled',0)) : 
                    $max = (int) tk_get_option('rate_limit_max_attempts', 5);
                ?>
                    <ul class="tk-list">
                        <li>Max attempts: <?php echo $max; ?></li>
                        <li>Brute-force protection active</li>
                    </ul>
                <?php else : ?>
                    <p class="tk-empty">Traffic throttling inactive.</p>
                <?php endif; ?>
            </div>
            <div class="tk-module-footer">
                <a href="<?php echo esc_url(tk_admin_url('tool-kits-security-rate-limit')); ?>" class="button button-small">Configure</a>
            </div>
        </div>

        <!-- Login Log -->
        <div class="tk-module-card">
            <div class="tk-module-header">
                <div class="tk-module-title">
                    <span class="dashicons dashicons-list-view"></span>
                    <h3>Login Log</h3>
                </div>
                <?php echo tk_get_option('login_log_enabled',1) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?>
            </div>
            <div class="tk-module-body">
                <?php if (tk_get_option('login_log_enabled',1)) : ?>
                    <ul class="tk-list">
                        <li>Retention: <?php echo esc_html((string) tk_get_option('login_log_keep_days', 30)); ?> days</li>
                        <li>Audit trail active</li>
                    </ul>
                <?php else : ?>
                    <p class="tk-empty">Audit logging inactive.</p>
                <?php endif; ?>
            </div>
            <div class="tk-module-footer">
                <a href="<?php echo esc_url(tk_admin_url('tool-kits-security-login-log')); ?>" class="button button-small">View Logs</a>
            </div>
        </div>

        <!-- Hardening -->
        <div class="tk-module-card">
            <div class="tk-module-header">
                <div class="tk-module-title">
                    <span class="dashicons dashicons-hammer"></span>
                    <h3>Hardening</h3>
                </div>
                <?php 
                $hardening_items = tk_hardening_active_items();
                echo !empty($hardening_items) ? '<span class="tk-badge tk-on">ACTIVE</span>' : '<span class="tk-badge">OFF</span>'; 
                ?>
            </div>
            <div class="tk-module-body">
                <?php if (!empty($hardening_items)) : ?>
                    <ul class="tk-list">
                        <li><?php echo count($hardening_items); ?> security rules applied</li>
                        <li>WAF & Server hardening</li>
                    </ul>
                <?php else : ?>
                    <p class="tk-empty">System hardening inactive.</p>
                <?php endif; ?>
            </div>
            <div class="tk-module-footer">
                <a href="<?php echo esc_url(tk_admin_url(tk_hardening_page_slug())); ?>" class="button button-small">Configure</a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <?php tk_nonce_field('tk_hardening_waf_reset'); ?>
                    <input type="hidden" name="action" value="tk_hardening_waf_reset">
                    <button class="button button-link-delete button-small" style="font-size:11px;" data-confirm="Reset WAF settings?">Reset</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function tk_toolkits_default_license_server_url($collector_url) {
    return tk_toolkits_license_server_url_for_collector((string) $collector_url);
}

function tk_toolkits_prepare_license_server_url(): string {
    $license_server_url = tk_license_server_url();
    if ($license_server_url !== '') {
        tk_update_option('license_server_url', $license_server_url);
    }
    return $license_server_url;
}

function tk_toolkits_status_badge(string $status): string {
    $status = strtolower(trim($status));
    $class = 'tk-badge';
    $label = $status === '' ? 'Unknown' : ucfirst($status);
    if (in_array($status, array('configured', 'valid', 'ok', 'enabled'), true)) {
        $class .= ' tk-on';
    } elseif (in_array($status, array('reachable', 'warning', 'degraded'), true)) {
        $class .= ' tk-warn';
        $label = $status === 'reachable' ? 'Reachable' : ucfirst($status);
    } elseif (in_array($status, array('missing', 'error', 'invalid', 'fail', 'expired', 'revoked', 'not_found'), true)) {
        $class .= ' tk-warn';
    }
    return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
}

function tk_toolkits_format_timestamp(int $timestamp): string {
    if ($timestamp <= 0) {
        return 'Never';
    }
    return date_i18n('Y-m-d H:i:s', $timestamp);
}

function tk_toolkits_connection_summary(): array {
    $collector_url = tk_toolkits_collector_url();
    $heartbeat_url = tk_heartbeat_collector_url();
    $license_url = tk_license_server_url();
    $collector_token = tk_heartbeat_auth_key();
    $license_key = trim((string) tk_get_option('license_key', ''));
    return array(
        'collector_url' => $collector_url,
        'heartbeat_url' => $heartbeat_url,
        'license_url' => $license_url,
        'collector_status' => $collector_url !== '' ? 'configured' : 'missing',
        'heartbeat_status' => $heartbeat_url !== '' ? 'configured' : 'missing',
        'license_url_status' => $license_url !== '' ? 'configured' : 'missing',
        'token_status' => $collector_token !== '' ? 'configured' : 'missing',
        'license_key_status' => $license_key !== '' ? 'configured' : 'missing',
        'heartbeat_last_checked' => (int) tk_get_option('heartbeat_last_checked', 0),
        'heartbeat_last_success' => (int) tk_get_option('heartbeat_last_success', 0),
        'heartbeat_last_failure' => (int) tk_get_option('heartbeat_last_failure', 0),
        'heartbeat_last_error' => (string) tk_get_option('heartbeat_last_error_message', ''),
        'heartbeat_last_endpoint' => (string) tk_get_option('heartbeat_last_endpoint', ''),
        'license_last_checked' => (int) tk_get_option('license_last_checked', 0),
        'license_last_success' => (int) tk_get_option('license_last_success', 0),
        'license_last_failure' => (int) tk_get_option('license_last_failure', 0),
        'license_last_error' => (string) tk_get_option('license_last_error_message', ''),
        'license_last_endpoint' => (string) tk_get_option('license_last_endpoint', ''),
    );
}

function tk_render_toolkits_access_page() {
    if (!tk_toolkits_can_manage()) return;
    $hidden = (int) tk_get_option('hide_toolkits_menu', 0);
    $hide_cff = (int) tk_get_option('hide_cff_menu', 0);
    $lock = (int) tk_get_option('toolkits_lock_enabled', 0);
    $mask = (int) tk_get_option('toolkits_mask_sensitive_fields', 0);
    $roles = tk_toolkits_allowed_roles();
    $connection_summary = tk_toolkits_connection_summary();
    $allowlist = (string) tk_get_option('toolkits_ip_allowlist', '');
    $alert_enabled = (int) tk_get_option('toolkits_alert_enabled', 1);
    $alert_email = (string) tk_get_option('toolkits_alert_email', '');
    $alert_webhook = (string) tk_get_option('toolkits_alert_webhook', '');
    $alert_admin_created = (int) tk_get_option('toolkits_alert_admin_created', 1);
    $alert_role_change = (int) tk_get_option('toolkits_alert_role_change', 1);
    $alert_admin_ip = (int) tk_get_option('toolkits_alert_admin_login_new_ip', 1);
    $collector_url = tk_toolkits_collector_url();
    $collector_key = tk_heartbeat_auth_key();
    $license_server_url = tk_license_server_url();
    $license_key = (string) tk_get_option('license_key', '');
    $license_status = (string) tk_get_option('license_status', 'inactive');
    $license_message = (string) tk_get_option('license_message', '');
    $license_type = (string) tk_get_option('license_type', '');
    $license_env = (string) tk_get_option('license_env', '');
    $license_site = (string) tk_get_option('license_site_url', '');
    $license_expires = (string) tk_get_option('license_expires_at', '');
    $license_missing = $license_key === '';
    $license_invalid = $license_status !== 'valid';
    $license_limited = $license_type === 'local';
    $show_full_tabs = !$license_invalid && !$license_limited;
    $owner_only = (int) tk_get_option('toolkits_owner_only_enabled', 0);
    $owner_id = (int) tk_get_option('toolkits_owner_user_id', 1);
    $admins = get_users(array(
        'role' => 'administrator',
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
    $audit_log = tk_get_option('toolkits_audit_log', array());
    if (!is_array($audit_log)) {
        $audit_log = array();
    }
    $audit_log = array_reverse($audit_log);
    $cff_installed = tk_is_cff_installed();
    $saved = isset($_GET['tk_saved']) ? sanitize_key($_GET['tk_saved']) : '';
    $cleared = isset($_GET['tk_cleared']) ? sanitize_key($_GET['tk_cleared']) : '';
    $license_reset = isset($_GET['tk_reset_license']) ? sanitize_key($_GET['tk_reset_license']) : '';
    $license_required = isset($_GET['tk_license']) ? sanitize_key($_GET['tk_license']) : '';
    $collector_url_value = $collector_url;
    $license_server_fixed = defined('TK_LICENSE_SERVER_URL') && TK_LICENSE_SERVER_URL !== '';
    $license_server_value = $license_server_fixed ? (string) TK_LICENSE_SERVER_URL : $license_server_url;
    if ($license_server_value === '') {
        $license_server_value = tk_toolkits_default_license_server_url($collector_url_value);
    }
    $connection_test = isset($_GET['tk_test']) ? sanitize_key($_GET['tk_test']) : '';
    $connection_test_status = isset($_GET['tk_test_status']) ? sanitize_key($_GET['tk_test_status']) : '';
    $connection_summary = tk_toolkits_connection_summary();
    if ($license_server_fixed) {
        $license_server_note = sprintf(__('License server URL is fixed to %s.', 'tool-kits'), $license_server_value);
    } elseif ($license_server_value !== '') {
        $license_server_note = sprintf(__('License server URL is detected automatically as %s.', 'tool-kits'), $license_server_value);
    } else {
        $license_server_note = __('License server URL will be generated automatically from the Collector URL.', 'tool-kits');
    }
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero('Access & Licenses', 'Configure plugin access permissions, audit logs, and manage your Pro license settings.', 'dashicons-admin-network'); ?>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('Access settings saved.', 'success'); ?>
        <?php endif; ?>
        <?php if ($cleared === '1') : ?>
            <?php tk_notice('Audit log cleared.', 'success'); ?>
        <?php endif; ?>
        <?php if ($license_reset === '1') : ?>
            <?php tk_notice('License data reset.', 'success'); ?>
        <?php endif; ?>
        <?php if ($license_required === '1') : ?>
            <?php
            $license_notice = $license_missing
                ? tk_toolkits_missing_config_message('license_key')
                : ($license_message !== '' ? $license_message : tk_toolkits_license_validation_message());
            tk_notice($license_notice, 'warning');
            ?>
        <?php endif; ?>
        <?php if ($collector_key === '' && (!defined('TK_HEARTBEAT_AUTH_KEY') || TK_HEARTBEAT_AUTH_KEY === '')) : ?>
            <?php tk_notice(tk_toolkits_missing_config_message('collector_token') . ' Please set it below.', 'warning'); ?>
        <?php endif; ?>
        <?php if ($connection_test !== '') : ?>
            <?php
            $test_message = '';
            if ($connection_test === 'heartbeat') {
                $test_message = $connection_test_status === 'ok'
                    ? 'Heartbeat test succeeded.'
                    : ((string) tk_get_option('heartbeat_last_error_message', '') !== '' ? (string) tk_get_option('heartbeat_last_error_message', '') : 'Heartbeat test failed.');
            } elseif ($connection_test === 'license') {
                if ($connection_test_status === 'ok') {
                    $test_message = 'License reachability test succeeded.';
                } elseif ($connection_test_status === 'warn') {
                    $test_message = (string) tk_get_option('license_last_error_message', '') !== ''
                        ? (string) tk_get_option('license_last_error_message', '')
                        : 'License server is reachable but returned a non-success HTTP response.';
                } else {
                    $test_message = (string) tk_get_option('license_last_error_message', '') !== ''
                        ? (string) tk_get_option('license_last_error_message', '')
                        : 'License reachability test failed.';
                }
            }
            if ($test_message !== '') {
                $notice_type = 'error';
                if ($connection_test_status === 'ok') {
                    $notice_type = 'success';
                } elseif ($connection_test_status === 'warn') {
                    $notice_type = 'warning';
                }
                tk_notice($test_message, $notice_type);
            }
            ?>
        <?php endif; ?>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <?php if (!$license_invalid) : ?>
                    <button type="button" class="tk-tabs-nav-button is-active" data-panel="access">Access</button>
                <?php endif; ?>
                <button type="button" class="tk-tabs-nav-button <?php echo $license_invalid ? 'is-active' : ''; ?>" data-panel="license-status">License Status</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="license">Connection</button>
                <?php if ($show_full_tabs) : ?>
                    <button type="button" class="tk-tabs-nav-button" data-panel="owner">Owner</button>
                    <button type="button" class="tk-tabs-nav-button" data-panel="alerts">Alerts</button>
                    <button type="button" class="tk-tabs-nav-button" data-panel="audit">Audit Log</button>
                <?php endif; ?>
            </div>
            <div class="tk-tabs-content">
                <?php if (!$license_invalid) : ?>
                <div class="tk-card tk-tab-panel is-active" data-panel-id="access">
                    <h2 style="margin-bottom:8px;">Access Control</h2>
                    <p class="description" style="margin-bottom:24px;">Manage who can access Tool Kits and prevent accidental changes on production.</p>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_toolkits_access_save'); ?>
                        <input type="hidden" name="action" value="tk_toolkits_access_save">
                        <input type="hidden" name="tk_tab" value="access">

                        <?php 
                        tk_render_switch('toolkits_lock_enabled', 'Lock Settings', 'Prevent any modifications to Tool Kits configuration.', $lock);
                        
                        tk_render_switch('toolkits_mask_sensitive_fields', 'Mask Sensitive Data', 'Hide license keys and tokens in the admin UI.', $mask);
                        
                        tk_render_switch('toolkits_owner_only_enabled', 'Owner-Only Mode', 'Restrict access to the primary site owner (UID: ' . $owner_id . ') only.', $owner_only);
                        ?>

                        <div style="margin-top:24px; padding:20px; background:var(--tk-bg-soft); border-radius:12px;">
                            <h3 style="margin:0 0 12px; font-size:14px;">Allowed Administrator Roles</h3>
                            <div style="display:flex; flex-wrap:wrap; gap:12px;">
                                <?php foreach (get_editable_roles() as $role_key => $role) : ?>
                                    <label style="background:#fff; border:1px solid var(--tk-border-soft); padding:8px 12px; border-radius:8px; display:flex; align-items:center; gap:8px; font-size:12px; cursor:pointer;">
                                        <input type="checkbox" name="toolkits_allowed_roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $roles, true)); ?>>
                                        <?php echo esc_html($role['name']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="margin-top:24px;">
                            <label style="display:block; font-weight:600; margin-bottom:8px;">IP Allowlist (One per line)</label>
                            <textarea name="toolkits_ip_allowlist" rows="3" class="large-text" placeholder="203.0.113.10" style="border-radius:10px;"><?php echo esc_textarea($allowlist); ?></textarea>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Access Rules</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <div class="tk-card tk-tab-panel <?php echo $license_invalid ? 'is-active' : ''; ?>" data-panel-id="license-status">
                    <?php
                    $expires_label = 'Unlimited';
                    $expires_remaining = '';
                    $is_expired = false;
                    if ($license_expires !== '' && strtotime($license_expires) !== false) {
                        $expires_ts = strtotime($license_expires);
                        $now = time();
                        if ($expires_ts <= $now) {
                            $is_expired = true;
                            $expires_label = 'Expired';
                            $expires_remaining = '0 days';
                        } else {
                            $days = (int) ceil(($expires_ts - $now) / DAY_IN_SECONDS);
                            $expires_label = gmdate('Y-m-d', $expires_ts);
                            $expires_remaining = $days . ' days left';
                        }
                    }
                    $status_color = ($license_status === 'valid') ? '#27ae60' : ($is_expired ? '#e74c3c' : '#f39c12');
                    ?>

                    <div style="background:var(--tk-bg-soft); border-radius:20px; padding:32px; border:1px solid var(--tk-border-soft); text-align:center;">
                        <div style="width:80px; height:80px; border-radius:40px; background:<?php echo $status_color; ?>; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; box-shadow: 0 10px 15px -3px <?php echo $status_color; ?>44;">
                            <span class="dashicons <?php echo $license_status === 'valid' ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" style="font-size:40px; width:40px; height:40px; color:#fff;"></span>
                        </div>
                        <h2 style="margin:0; font-size:24px;"><?php echo $license_status === 'valid' ? 'License Active' : 'Activation Required'; ?></h2>
                        <p style="color:var(--tk-muted); margin-top:8px;">Current Status: <strong style="color:<?php echo $status_color; ?>; text-transform:uppercase;"><?php echo esc_html($license_status); ?></strong></p>
                        
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:16px; margin-top:32px;">
                            <div class="tk-card" style="padding:15px; background:#fff;">
                                <div style="font-size:10px; text-transform:uppercase; color:var(--tk-muted);">Type</div>
                                <div style="font-size:16px; font-weight:700; margin-top:4px;"><?php echo ucfirst(esc_html($license_type ?: 'Unknown')); ?></div>
                            </div>
                            <div class="tk-card" style="padding:15px; background:#fff;">
                                <div style="font-size:10px; text-transform:uppercase; color:var(--tk-muted);">Expires</div>
                                <div style="font-size:16px; font-weight:700; margin-top:4px;"><?php echo esc_html($expires_label); ?></div>
                                <div style="font-size:10px; color:var(--tk-muted);"><?php echo esc_html($expires_remaining); ?></div>
                            </div>
                            <div class="tk-card" style="padding:15px; background:#fff;">
                                <div style="font-size:10px; text-transform:uppercase; color:var(--tk-muted);">Environment</div>
                                <div style="font-size:16px; font-weight:700; margin-top:4px;"><?php echo ucfirst(esc_html($license_env ?: 'Production')); ?></div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:24px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php tk_nonce_field('tk_license_activate'); ?>
                            <input type="hidden" name="action" value="tk_toolkits_license_activate">
                            <input type="hidden" name="license_key" value="<?php echo esc_attr($license_key); ?>">
                            <button type="submit" class="button button-primary button-hero">Re-activate License</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('Reset license data?');">
                            <?php tk_nonce_field('tk_license_reset'); ?>
                            <input type="hidden" name="action" value="tk_toolkits_license_reset">
                            <button type="submit" class="button button-hero">Reset Key</button>
                        </form>
                        <button type="button" class="button button-hero tk-copy-debug" data-debug="<?php echo esc_attr(tk_get_debug_info()); ?>">Copy Debug Info</button>
                    </div>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="license">
                    <h2 style="margin-bottom:8px;">Connection Settings</h2>
                    <p class="description" style="margin-bottom:24px;">Configure your collector and heartbeat endpoints to unlock cloud features.</p>
                    
                    <div class="tk-rt-grid" style="margin-bottom:24px;">
                        <div class="tk-rt-card" style="padding:15px;">
                            <h4 style="font-size:10px;">Heartbeat</h4>
                            <div class="tk-rt-value" style="font-size:14px;"><?php echo tk_toolkits_status_badge($connection_summary['heartbeat_status']); ?></div>
                            <code style="display:block; font-size:9px; margin-top:8px; overflow:hidden; text-overflow:ellipsis;"><?php echo esc_html($connection_summary['heartbeat_url'] ?: 'Missing'); ?></code>
                        </div>
                        <div class="tk-rt-card" style="padding:15px;">
                            <h4 style="font-size:10px;">License Server</h4>
                            <div class="tk-rt-value" style="font-size:14px;"><?php echo tk_toolkits_status_badge($connection_summary['license_url_status']); ?></div>
                            <code style="display:block; font-size:9px; margin-top:8px; overflow:hidden; text-overflow:ellipsis;"><?php echo esc_html($license_server_value ?: 'Missing'); ?></code>
                        </div>
                        <div class="tk-rt-card" style="padding:15px;">
                            <h4 style="font-size:10px;">Token</h4>
                            <div class="tk-rt-value" style="font-size:14px;"><?php echo tk_toolkits_status_badge($connection_summary['token_status']); ?></div>
                        </div>
                    </div>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_toolkits_access_save'); ?>
                        <input type="hidden" name="action" value="tk_toolkits_access_save">
                        <input type="hidden" name="tk_tab" value="license-status">

                        <div class="tk-card" style="margin-bottom:20px;">
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Collector URL</label>
                            <input class="large-text" type="url" name="heartbeat_collector_url" value="<?php echo esc_attr($collector_url_value); ?>" placeholder="https://collector.example.com/heartbeat.php" style="border-radius:8px;">
                            <p class="description">Main endpoint for health data collection.</p>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:20px;">
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">Collector Token</label>
                                <?php 
                                $collector_mask = $collector_key ? str_repeat('*', max(0, strlen($collector_key) - 4)) . substr($collector_key, -4) : '';
                                ?>
                                <input class="regular-text" type="text" name="heartbeat_auth_key_display" value="<?php echo esc_attr($collector_mask); ?>" placeholder="Enter token..." style="width:100%; border-radius:8px;">
                                <input type="hidden" name="heartbeat_auth_key" value="<?php echo esc_attr($collector_key); ?>">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">License Key</label>
                                <?php 
                                $license_mask = $license_key ? str_repeat('*', max(0, strlen($license_key) - 4)) . substr($license_key, -4) : '';
                                ?>
                                <input class="regular-text" type="text" name="license_key_display" value="<?php echo esc_attr($license_mask); ?>" placeholder="Enter key..." style="width:100%; border-radius:8px;">
                                <input type="hidden" name="license_key" value="<?php echo esc_attr($license_key); ?>">
                            </div>
                        </div>

                        <div style="margin-bottom:20px;">
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Notification Email</label>
                            <input class="regular-text" type="email" name="license_notify_email" value="<?php echo esc_attr(tk_get_option('license_notify_email', '')); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" style="width:100%; border-radius:8px;">
                        </div>

                        <div style="display:flex; gap:12px; margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero" type="submit" name="tk_activate_after_save" value="1">Save & Activate</button>
                            <button class="button button-hero" type="submit" name="tk_connection_test" value="heartbeat">Test Heartbeat</button>
                            <button class="button button-hero" type="submit" name="tk_connection_test" value="license">Test License</button>
                        </div>
                    </form>
                </div>

                <?php if ($show_full_tabs) : ?>
                <div class="tk-card tk-tab-panel" data-panel-id="owner">
                    <h2 style="margin-bottom:8px;">Site Ownership</h2>
                    <p class="description" style="margin-bottom:24px;">Designate the primary administrator for this site.</p>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_toolkits_access_save'); ?>
                        <input type="hidden" name="action" value="tk_toolkits_access_save">
                        <input type="hidden" name="tk_tab" value="owner">

                        <?php 
                        tk_render_switch('toolkits_owner_only_enabled', 'Owner-Only Mode', 'Restrict Tool Kits access to a single admin.', $owner_only);
                        ?>

                        <div style="margin-top:20px;">
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Primary Owner</label>
                            <select name="toolkits_owner_user_id" style="width:100%; max-width:400px; border-radius:8px;">
                                <?php foreach ($admins as $admin) : ?>
                                    <option value="<?php echo (int) $admin->ID; ?>" <?php selected($admin->ID, $owner_id); ?>>
                                        <?php echo esc_html($admin->user_login); ?> (<?php echo esc_html($admin->user_email); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Owner Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="alerts">
                    <h2 style="margin-bottom:8px;">Alert Notifications</h2>
                    <p class="description" style="margin-bottom:24px;">Configure automated alerts for security events and system changes.</p>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_toolkits_access_save'); ?>
                        <input type="hidden" name="action" value="tk_toolkits_access_save">
                        <input type="hidden" name="tk_tab" value="alerts">

                        <?php 
                        tk_render_switch('toolkits_alert_enabled', 'Master Alert Toggle', 'Global switch to enable or disable all local email notifications.', $alert_enabled);
                        
                        tk_render_switch('toolkits_alert_admin_created', 'New Administrator Created', 'Receive an alert whenever a new user with admin privileges is added.', $alert_admin_created);
                        
                        tk_render_switch('toolkits_alert_role_change', 'User Role Escalation', 'Get notified when a user is promoted to a higher capability role.', $alert_role_change);
                        
                        tk_render_switch('toolkits_alert_admin_login_new_ip', 'Admin Login from New IP', 'Detect and alert on administrator logins from unrecognized IP addresses.', $alert_admin_ip);
                        ?>

                        <div style="margin-top:24px;">
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Alert Email Destination</label>
                            <input class="regular-text" type="email" name="toolkits_alert_email" value="<?php echo esc_attr($alert_email); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" style="width:100%; border-radius:8px;">
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Alert Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="audit">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px;">
                        <h2 style="margin:0;">Audit Log</h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('Clear all audit logs?');">
                            <?php tk_nonce_field('tk_toolkits_audit_clear'); ?>
                            <input type="hidden" name="action" value="tk_toolkits_audit_clear">
                            <button class="button button-link-delete">Clear Log</button>
                        </form>
                    </div>

                    <?php if (empty($audit_log)) : ?>
                        <p class="tk-empty">No activity recorded yet.</p>
                    <?php else : ?>
                        <div class="tk-table-scroll">
                            <table class="widefat striped tk-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Action</th>
                                        <th>User</th>
                                        <th>IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($audit_log, 0, 50) as $log) : ?>
                                        <tr>
                                            <td style="white-space:nowrap;"><?php echo esc_html(human_time_diff($log['time'], time())); ?> ago</td>
                                            <td><?php echo esc_html($log['action']); ?></td>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <span class="dashicons dashicons-admin-users" style="color:var(--tk-muted);"></span>
                                                    <?php echo esc_html($log['user']); ?>
                                                </div>
                                            </td>
                                            <td><code><?php echo esc_html($log['ip']); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        tk_csp_print_inline_script(
            "(function(){
            function activateTab(panelId) {
                document.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                });
                document.querySelectorAll('.tk-tabs-nav-button').forEach(function(btn){
                    btn.classList.toggle('is-active', btn.getAttribute('data-panel') === panelId);
                });
            }
            function getPanelFromHash() {
                var hash = window.location.hash || '';
                if (!hash) { return ''; }
                return hash.replace('#', '');
            }
            document.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    if (panelId) {
                        window.location.hash = panelId;
                        activateTab(panelId);
                    }
                });
            });
            var initial = getPanelFromHash();
            if (initial) {
                activateTab(initial);
            }
            var tokenDisplay = document.querySelector('input[name=\"heartbeat_auth_key_display\"]');
            var tokenHidden = document.querySelector('input[name=\"heartbeat_auth_key\"]');
            if (tokenDisplay && tokenHidden) {
                tokenDisplay.addEventListener('input', function() {
                    tokenHidden.value = tokenDisplay.value;
                });
            }
            var licenseDisplay = document.querySelector('input[name=\"license_key_display\"]');
            var licenseHidden = document.querySelector('input[name=\"license_key\"]');
            if (licenseDisplay && licenseHidden) {
                licenseDisplay.addEventListener('input', function() {
                    licenseHidden.value = licenseDisplay.value;
                });
            }
            document.querySelectorAll('.tk-copy-debug').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var text = btn.getAttribute('data-debug');
                    navigator.clipboard.writeText(text).then(function(){
                        var original = btn.textContent;
                        btn.textContent = 'Copied!';
                        btn.classList.add('button-primary');
                        setTimeout(function(){
                            btn.textContent = original;
                            btn.classList.remove('button-primary');
                        }, 2000);
                    });
                });
            });

            // Preflight check
            function initPreflight() {
                var collectorInput = document.querySelector('input[name=\"heartbeat_collector_url\"]');
                if (!collectorInput) return;
                
                var preflightStatus = document.createElement('div');
                preflightStatus.style.marginTop = '5px';
                preflightStatus.style.fontSize = '12px';
                if (!collectorInput.parentNode.querySelector('.tk-preflight-status')) {
                    preflightStatus.className = 'tk-preflight-status';
                    collectorInput.parentNode.appendChild(preflightStatus);
                } else {
                    preflightStatus = collectorInput.parentNode.querySelector('.tk-preflight-status');
                }
                
                var preflightTimer;
                collectorInput.addEventListener('input', function(){
                    clearTimeout(preflightTimer);
                    preflightTimer = setTimeout(function(){
                        var url = collectorInput.value.trim();
                        if (url === '') {
                            preflightStatus.innerHTML = '';
                            return;
                        }
                        preflightStatus.innerHTML = '<span style=\"color:#7f8c8d\">Checking reachability...</span>';
                        var apiTarget = (typeof ajaxurl !== \"undefined\") ? ajaxurl : \"/wp-admin/admin-ajax.php\";
                        fetch(apiTarget + '?action=tk_preflight_check&url=' + encodeURIComponent(url) + '&_wpnonce=' + '" . esc_js(wp_create_nonce('tk_preflight_nonce')) . "')
                            .then(function(r){ return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    preflightStatus.innerHTML = '<span style=\"color:#27ae60\">&#10003; ' + data.data.message + '</span>';
                                } else {
                                    preflightStatus.innerHTML = '<span style=\"color:#e74c3c\">&#10007; ' + data.data.message + '</span>';
                                }
                            })
                            .catch(function(){
                                preflightStatus.innerHTML = '<span style=\"color:#e74c3c\">&#10007; Connection test failed.</span>';
                            });
                    }, 800);
                });
            }
            initPreflight();
        })();",
            array('id' => 'tk-access-tabs')
        );
        ?>
    </div>
    <?php
}

function tk_toolkits_access_save() {
    if (!tk_toolkits_can_manage()) wp_die('Forbidden');
    tk_check_nonce('tk_toolkits_access_save');
    $tab = isset($_POST['tk_tab']) ? sanitize_key($_POST['tk_tab']) : 'access';
    $connection_test = isset($_POST['tk_connection_test']) ? sanitize_key((string) $_POST['tk_connection_test']) : '';
    if (!in_array($tab, array('access', 'owner', 'alerts', 'audit', 'license', 'license-status'), true)) {
        $tab = 'access';
    }
    $posted_license_key = isset($_POST['license_key']) ? trim(wp_unslash($_POST['license_key'])) : null;
    if ($posted_license_key !== null) {
        tk_update_option('license_key', $posted_license_key);
        tk_update_option('license_status', 'inactive');
        tk_update_option('license_message', '');
        tk_update_option('license_last_checked', 0);
    }
    if ($tab === 'access') {
        $collector_url = isset($_POST['heartbeat_collector_url']) ? esc_url_raw(wp_unslash($_POST['heartbeat_collector_url'])) : '';
        tk_update_option('heartbeat_collector_url', $collector_url);
        $collector_key = isset($_POST['heartbeat_auth_key']) ? trim(wp_unslash($_POST['heartbeat_auth_key'])) : '';
        tk_update_option('heartbeat_auth_key', $collector_key);
        $roles = isset($_POST['toolkits_allowed_roles']) ? (array) $_POST['toolkits_allowed_roles'] : array();
        $roles = array_filter(array_map('sanitize_key', $roles));
        if (empty($roles)) {
            $roles = array('administrator');
        }
        tk_update_option('toolkits_allowed_roles', $roles);
        tk_update_option('toolkits_ip_allowlist', (string) tk_post('toolkits_ip_allowlist', ''));
        tk_update_option('toolkits_lock_enabled', !empty($_POST['toolkits_lock_enabled']) ? 1 : 0);
        tk_update_option('toolkits_mask_sensitive_fields', !empty($_POST['toolkits_mask_sensitive_fields']) ? 1 : 0);
    } elseif ($tab === 'license' || $tab === 'license-status') {
        $collector_url = isset($_POST['heartbeat_collector_url']) ? esc_url_raw(wp_unslash($_POST['heartbeat_collector_url'])) : '';
        tk_update_option('heartbeat_collector_url', $collector_url);
        
        $collector_key = '';
        if (isset($_POST['heartbeat_auth_key_display'])) {
            $raw = trim(wp_unslash($_POST['heartbeat_auth_key_display']));
            if ($raw !== '' && strpos($raw, '****') === false) {
                $collector_key = $raw;
                tk_update_option('heartbeat_auth_key', $collector_key);
            } else {
                $collector_key = tk_get_option('heartbeat_auth_key', '');
            }
        } else {
            $collector_key = isset($_POST['heartbeat_auth_key']) ? trim(wp_unslash($_POST['heartbeat_auth_key'])) : '';
            tk_update_option('heartbeat_auth_key', $collector_key);
        }

        if (isset($_POST['license_key_display'])) {
            $raw = trim(wp_unslash($_POST['license_key_display']));
            if ($raw !== '' && strpos($raw, '****') === false) {
                tk_update_option('license_key', $raw);
            }
        }
        
        tk_update_option('license_ssl_verify', isset($_POST['license_ssl_verify']) ? 0 : 1);
        tk_update_option('license_notify_email', isset($_POST['license_notify_email']) ? sanitize_email(wp_unslash($_POST['license_notify_email'])) : '');
        
        $license_server_url = tk_toolkits_license_server_url_for_collector($collector_url);
        tk_update_option('license_server_url', $license_server_url);
        
        if ($connection_test === 'heartbeat') {
            $result = tk_heartbeat_send();
            tk_heartbeat_record_result($result);
            wp_redirect(admin_url('tools.php?page=tool-kits-access&tk_saved=1&tk_test=heartbeat&tk_test_status=' . (!empty($result['ok']) ? 'ok' : 'fail') . '#' . $tab));
            exit;
        }
        if ($connection_test === 'license') {
            $result = tk_license_test_connection();
            $test_status = 'fail';
            if (isset($result['status']) && (string) $result['status'] === 'valid') {
                $test_status = 'ok';
            } elseif (isset($result['status']) && (string) $result['status'] === 'reachable') {
                $test_status = 'warn';
            }
            wp_redirect(admin_url('tools.php?page=tool-kits-access&tk_saved=1&tk_test=license&tk_test_status=' . $test_status . '#' . $tab));
            exit;
        }
        tk_license_validate(true);
    } elseif ($tab === 'owner') {
        tk_update_option('toolkits_owner_only_enabled', !empty($_POST['toolkits_owner_only_enabled']) ? 1 : 0);
        $owner_id = isset($_POST['toolkits_owner_user_id']) ? (int) $_POST['toolkits_owner_user_id'] : 0;
        if ($owner_id <= 0) {
            $owner_id = get_current_user_id();
        }
        $owner_user = get_user_by('id', $owner_id);
        if (!$owner_user || !in_array('administrator', (array) $owner_user->roles, true)) {
            $owner_id = get_current_user_id();
        }
        tk_update_option('toolkits_owner_user_id', $owner_id);
    } elseif ($tab === 'alerts') {
        tk_update_option('toolkits_alert_enabled', !empty($_POST['toolkits_alert_enabled']) ? 1 : 0);
        $alert_email = isset($_POST['toolkits_alert_email']) ? sanitize_email(wp_unslash($_POST['toolkits_alert_email'])) : '';
        $alert_webhook = isset($_POST['toolkits_alert_webhook']) ? esc_url_raw(wp_unslash($_POST['toolkits_alert_webhook'])) : '';
        tk_update_option('toolkits_alert_email', $alert_email);
        tk_update_option('toolkits_alert_webhook', $alert_webhook);
        tk_update_option('toolkits_alert_admin_created', !empty($_POST['toolkits_alert_admin_created']) ? 1 : 0);
        tk_update_option('toolkits_alert_role_change', !empty($_POST['toolkits_alert_role_change']) ? 1 : 0);
        tk_update_option('toolkits_alert_admin_login_new_ip', !empty($_POST['toolkits_alert_admin_login_new_ip']) ? 1 : 0);
    }
    tk_toolkits_audit_log('access_update', array('user' => wp_get_current_user()->user_login));
    wp_redirect(admin_url('tools.php?page=tool-kits-access&tk_saved=1#' . $tab));
    exit;
}

function tk_toolkits_license_activate() {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_license_activate');
    if (isset($_POST['heartbeat_collector_url'])) {
        tk_update_option('heartbeat_collector_url', esc_url_raw(wp_unslash($_POST['heartbeat_collector_url'])));
    }
    if (isset($_POST['heartbeat_auth_key'])) {
        tk_update_option('heartbeat_auth_key', trim(wp_unslash($_POST['heartbeat_auth_key'])));
    }
    if (isset($_POST['license_key'])) {
        tk_update_option('license_key', trim(wp_unslash($_POST['license_key'])));
    }
    tk_toolkits_prepare_license_server_url();
    tk_license_validate(true);
    wp_redirect(admin_url('tools.php?page=tool-kits-access&tk_license=1'));
    exit;
}

function tk_toolkits_license_reset() {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_license_reset');
    tk_license_reset();
    update_option('tk_license_reset_skip_validate', 1, false);
    wp_redirect(admin_url('tools.php?page=tool-kits-access&tk_license=1&tk_reset_license=1'));
    exit;
}

function tk_toolkits_audit_clear() {
    if (!tk_toolkits_can_manage()) wp_die('Forbidden');
    tk_check_nonce('tk_toolkits_audit_clear');
    tk_update_option('toolkits_audit_log', array());
    tk_toolkits_audit_log('audit_clear', array('user' => wp_get_current_user()->user_login));
    wp_redirect(admin_url('tools.php?page=tool-kits-access&tk_cleared=1'));
    exit;
}

function tk_set_wpconfig_readonly() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_set_wpconfig_readonly');
    $path = tk_hardening_wp_config_path();
    if ($path === '' || !file_exists($path)) {
        wp_redirect(admin_url('admin.php?page=tool-kits-monitoring&tk_wpconfig=missing#actions'));
        exit;
    }
    $ok = false;
    foreach (array(0440, 0444) as $perm) {
        if (@chmod($path, $perm)) {
            $ok = true;
            break;
        }
    }
    $status = $ok ? 'ok' : 'fail';
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring&tk_wpconfig=' . $status . '#actions'));
    exit;
}

function tk_set_wpconfig_writable() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_set_wpconfig_writable');
    $path = tk_hardening_wp_config_path();
    if ($path === '' || !file_exists($path)) {
        wp_redirect(admin_url('admin.php?page=tool-kits-monitoring&tk_wpconfig=missing#actions'));
        exit;
    }
    $ok = @chmod($path, 0644);
    $status = $ok ? 'writable' : 'fail';
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring&tk_wpconfig=' . $status . '#actions'));
    exit;
}

function tk_monitoring_schedule_cron() {
    if (!wp_next_scheduled('tk_monitoring_cron')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', 'tk_monitoring_cron');
    }
}

function tk_monitoring_cron_run() {
    $noncore_root = tk_hardening_noncore_root_entries();
    tk_monitoring_maybe_send_alert($noncore_root);
    tk_tamper_maybe_alert();
}

function tk_clear_cache_handler() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_clear_cache');
    $result = tk_clear_all_caches();
    $status = !empty($result['ok']) ? 'ok' : 'fail';
    set_transient('tk_cache_last_notice', (string) $result['message'], MINUTE_IN_SECONDS * 5);
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring&tk_cache=' . $status . '#actions'));
    exit;
}

function tk_remove_ds_store_handler() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_remove_ds_store');
    $result = tk_remove_ds_store_in_root();
    $status = !empty($result['ok']) ? 'ok' : 'fail';
    set_transient('tk_ds_store_last_notice', (string) $result['message'], MINUTE_IN_SECONDS * 5);
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring&tk_ds_store=' . $status . '#filesystem'));
    exit;
}

function tk_monitoring_maybe_send_alert(array $noncore_root): void {
    $email = tk_get_option('monitoring_alert_email', '');
    if (!is_string($email) || $email === '') {
        return;
    }
    $known = tk_get_option('monitoring_noncore_known', array());
    if (!is_array($known) || empty($known)) {
        tk_update_option('monitoring_noncore_known', $noncore_root);
        return;
    }
    $new = array_values(array_diff($noncore_root, $known));
    if (empty($new)) {
        return;
    }
    $subject = 'Tool Kits: New non-core files detected';
    $body = "New entries detected in WordPress root:\n\n" . implode("\n", $new);
    wp_mail($email, $subject, $body);
    tk_update_option('monitoring_noncore_known', $noncore_root);
}

function tk_monitoring_save() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_monitoring_save');
    $action = isset($_POST['monitoring_action']) ? sanitize_key($_POST['monitoring_action']) : 'save';
    if ($action === 'reset') {
        $current = tk_hardening_noncore_root_entries();
        tk_update_option('monitoring_noncore_known', $current);
        tk_update_option('monitoring_tamper_hashes', tk_tamper_collect_hashes());
        wp_redirect(admin_url('admin.php?page=tool-kits-monitoring#filesystem'));
        exit;
    }
    $email = isset($_POST['monitoring_email']) ? sanitize_email(wp_unslash($_POST['monitoring_email'])) : '';
    tk_update_option('monitoring_alert_email', $email);
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring#filesystem'));
    exit;
}

function tk_toggle_core_updates() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_toggle_core_updates');
    tk_killswitch_snapshot('core-updates');
    $value = isset($_POST['core_updates']) && $_POST['core_updates'] === '1' ? 1 : 0;
    tk_update_option('hardening_core_auto_updates', $value);
    wp_redirect(admin_url('admin.php?page=tool-kits-monitoring#actions'));
    exit;
}

function tk_is_cff_installed(): bool {
    $dir = trailingslashit(WP_PLUGIN_DIR) . 'custom-fields-framework-pro';
    return is_dir($dir);
}
function tk_preflight_check() {
    if (!tk_toolkits_can_manage()) {
        wp_send_json_error(array('message' => 'Forbidden'));
    }
    check_ajax_referer('tk_preflight_nonce');
    $url = isset($_GET['url']) ? esc_url_raw(wp_unslash($_GET['url'])) : '';
    if ($url === '') {
        wp_send_json_error(array('message' => 'Invalid URL'));
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, array('http', 'https'), true)) {
        wp_send_json_error(array('message' => 'Only HTTP and HTTPS protocols are allowed.'));
    }

    $host = parse_url($url, PHP_URL_HOST);
    if ($host) {
        $ip = gethostbyname($host);
        // Basic check for private/local IP ranges
        if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
             // Allow if explicitly allowed via constant for development
             if (!defined('TK_ALLOW_LOCAL_PREFLIGHT') || !TK_ALLOW_LOCAL_PREFLIGHT) {
                 wp_send_json_error(array('message' => 'Internal or Local IP addresses are not allowed.'));
             }
        }
    }

    $response = wp_remote_get($url, array(
        'timeout' => 10,
        'sslverify' => (bool) tk_get_option('license_ssl_verify', 1),
    ));
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 400) {
        wp_send_json_success(array('message' => 'Reachable (HTTP ' . $code . ')'));
    } else {
        wp_send_json_error(array('message' => 'HTTP ' . $code));
    }
}
add_action('wp_ajax_tk_preflight_check', 'tk_preflight_check');


