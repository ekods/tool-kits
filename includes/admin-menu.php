<?php
if (!defined('ABSPATH')) { exit; }

function tk_admin_menu_init() {
    add_action('admin_menu', 'tk_register_admin_menus');
    add_action('admin_post_tk_toolkits_access_save', 'tk_toolkits_access_save');
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

function tk_register_admin_menus() {
    if (!tk_toolkits_can_manage()) return;
    $license_key = (string) tk_get_option('license_key', '');
    if ($license_key !== '') {
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
            add_submenu_page('tool-kits', __('Database', 'tool-kits'), __('Database', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-db', 'tk_render_db_tools_page');

            // Security modules now live under the main Tool Kits menu.
            add_submenu_page('tool-kits', __('Optimization', 'tool-kits'), __('Optimization', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-optimization', 'tk_render_optimization_page');
            add_submenu_page('tool-kits', __('Spam Protection', 'tool-kits'), __('Spam Protection', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-security-spam', 'tk_render_spam_protection_page');
            add_submenu_page('tool-kits', __('Rate Limit', 'tool-kits'), __('Rate Limit', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-security-rate-limit', 'tk_render_rate_limit_page');
            add_submenu_page('tool-kits', __('Login Log', 'tool-kits'), __('Login Log', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-security-login-log', 'tk_render_login_log_page');
            add_submenu_page('tool-kits', __('Hardening', 'tool-kits'), __('Hardening', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-security-hardening', 'tk_render_hardening_page');
            add_submenu_page('tool-kits', __('Monitoring', 'tool-kits'), __('Monitoring', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-monitoring', 'tk_render_monitoring_page');
            add_submenu_page('tool-kits', __('Cache', 'tool-kits'), __('Cache', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-cache', 'tk_render_cache_page');
            add_submenu_page('tool-kits', __('Themes Checker', 'tool-kits'), __('Themes Checker', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-theme-checker', 'tk_render_theme_checker_page');
        } else {
            add_submenu_page('tool-kits', __('Database', 'tool-kits'), __('Database', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-db', 'tk_render_db_tools_page');
            add_submenu_page('tool-kits', __('Optimization', 'tool-kits'), __('Optimization', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-optimization', 'tk_render_optimization_page');
            add_submenu_page('tool-kits', __('Monitoring', 'tool-kits'), __('Monitoring', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-monitoring', 'tk_render_monitoring_page');
            add_submenu_page('tool-kits', __('Cache', 'tool-kits'), __('Cache', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-cache', 'tk_render_cache_page');
        }
    }

    add_submenu_page('tools.php', __('Tool Kits Access', 'tool-kits'), __('Tool Kits Access', 'tool-kits'), tk_toolkits_capability(), 'tool-kits-access', 'tk_render_toolkits_access_page');

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
    $monitor_email = tk_get_option('monitoring_alert_email', '');
    $log = tk_get_option('monitoring_404_log', array());
    if (!is_array($log)) {
        $log = array();
    }
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
    ?>
    <div class="wrap tk-wrap">
        <h1>Monitoring</h1>
        <?php
        $wpconfig_status = isset($_GET['tk_wpconfig']) ? sanitize_key($_GET['tk_wpconfig']) : '';
        if ($wpconfig_status === 'ok') {
            tk_notice('wp-config.php permissions updated.', 'success');
        } elseif ($wpconfig_status === 'writable') {
            tk_notice('wp-config.php set to writable.', 'success');
        } elseif ($wpconfig_status === 'fail') {
            tk_notice('Failed to update wp-config.php permissions. Please adjust manually.', 'error');
        } elseif ($wpconfig_status === 'missing') {
            tk_notice('wp-config.php not found.', 'warning');
        }
        $heartbeat_status = isset($_GET['tk_heartbeat']) ? sanitize_key($_GET['tk_heartbeat']) : '';
        if ($heartbeat_status === 'ok') {
            tk_notice('Heartbeat sent.', 'success');
        } elseif ($heartbeat_status === 'fail') {
            $detail = get_transient('tk_heartbeat_last_error');
            $message = 'Heartbeat failed to send.';
            if (is_string($detail) && $detail !== '') {
                $message .= ' ' . $detail;
            }
            tk_notice($message, 'error');
        }
        $cache_status = isset($_GET['tk_cache']) ? sanitize_key($_GET['tk_cache']) : '';
        if ($cache_status === 'ok') {
            $detail = get_transient('tk_cache_last_notice');
            $message = 'Cache cleared.';
            if (is_string($detail) && $detail !== '') {
                $message .= ' ' . $detail;
            }
            tk_notice($message, 'success');
        } elseif ($cache_status === 'fail') {
            $detail = get_transient('tk_cache_last_notice');
            $message = 'Cache clear failed.';
            if (is_string($detail) && $detail !== '') {
                $message .= ' ' . $detail;
            }
            tk_notice($message, 'error');
        }
        $ds_store_status = isset($_GET['tk_ds_store']) ? sanitize_key($_GET['tk_ds_store']) : '';
        if ($ds_store_status === 'ok') {
            $detail = get_transient('tk_ds_store_last_notice');
            $message = '.DS_Store/__MACOSX cleanup completed.';
            if (is_string($detail) && $detail !== '') {
                $message .= ' ' . $detail;
            }
            tk_notice($message, 'success');
        } elseif ($ds_store_status === 'fail') {
            $detail = get_transient('tk_ds_store_last_notice');
            $message = '.DS_Store/__MACOSX cleanup failed.';
            if (is_string($detail) && $detail !== '') {
                $message .= ' ' . $detail;
            }
            tk_notice($message, 'error');
        }
        $log_updated = isset($_GET['tk_404_updated']) ? sanitize_key($_GET['tk_404_updated']) : '';
        if ($log_updated === '1') {
            tk_notice('404 monitor settings saved.', 'success');
        }
        $log_cleared = isset($_GET['tk_404_cleared']) ? sanitize_key($_GET['tk_404_cleared']) : '';
        if ($log_cleared === '1') {
            tk_notice('404 log cleared.', 'success');
        }
        $health_updated = isset($_GET['tk_health_updated']) ? sanitize_key($_GET['tk_health_updated']) : '';
        if ($health_updated === '1') {
            tk_notice('Healthcheck settings saved.', 'success');
        }
        ?>
        <?php
        $core_auto = tk_get_option('hardening_core_auto_updates', 1) ? true : (defined('WP_AUTO_UPDATE_CORE') && WP_AUTO_UPDATE_CORE === true);
        $wp_config_path = tk_hardening_wp_config_path();
        ?>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="checks">Checks</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="actions">Actions</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="server">Server</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="filesystem">Filesystem</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="realtime">Realtime</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="missing">404 Monitor</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="health">Healthcheck</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="checks">
                    <h2>Configuration Checks</h2>
                    <p>Quick scan for common misconfigurations that can expose sensitive files or data.</p>
                    <p><strong>Checklist</strong></p>
                    <ul class="tk-list">
                        <li><?php echo $core_auto ? '&#10003;' : '&#9888;'; ?> Core auto-updates <?php echo $core_auto ? 'enabled' : 'not enabled'; ?></li>
                    </ul>
                    <table class="tk-table">
                        <tbody>
                        <?php foreach ($checks as $check) :
                            $status = isset($check['status']) ? $check['status'] : 'unknown';
                            $badge_class = $status === 'ok' ? 'tk-on' : ($status === 'warn' ? 'tk-warn' : '');
                            $badge_label = $status === 'ok' ? 'OK' : ($status === 'warn' ? 'Warning' : 'Unknown');
                            $action_label = isset($check['action_label']) ? (string) $check['action_label'] : '';
                            $action_url = isset($check['action_url']) ? (string) $check['action_url'] : '';
                        ?>
                            <tr>
                                <th><?php echo esc_html($check['label']); ?></th>
                                <td><span class="tk-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_label); ?></span></td>
                                <td><?php echo esc_html($check['detail']); ?></td>
                                <td>
                                    <?php if ($action_label !== '' && $action_url !== '') : ?>
                                        <a href="<?php echo esc_url($action_url); ?>"><?php echo esc_html($action_label); ?></a>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="actions">
                    <h2>Quick Actions</h2>
                    <p>Apply common maintenance actions quickly.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                        <?php tk_nonce_field('tk_toggle_core_updates'); ?>
                        <input type="hidden" name="action" value="tk_toggle_core_updates">
                        <p><strong>Core auto-updates</strong></p>
                        <button class="button" name="core_updates" value="<?php echo $core_auto ? '0' : '1'; ?>">
                            <?php echo $core_auto ? 'Disable core auto-updates' : 'Enable core auto-updates'; ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                        <?php tk_nonce_field('tk_clear_cache'); ?>
                        <input type="hidden" name="action" value="tk_clear_cache">
                        <p><strong>Cache cleanup</strong></p>
                        <button class="button button-secondary">Clear All Caches</button>
                    </form>
                    <?php if ($wp_config_path !== '' && is_writable($wp_config_path)) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                            <?php tk_nonce_field('tk_set_wpconfig_readonly'); ?>
                            <input type="hidden" name="action" value="tk_set_wpconfig_readonly">
                            <p><strong>wp-config.php permissions</strong></p>
                            <button class="button button-secondary">Set wp-config.php read-only</button>
                        </form>
                    <?php elseif ($wp_config_path !== '') : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                            <?php tk_nonce_field('tk_set_wpconfig_writable'); ?>
                            <input type="hidden" name="action" value="tk_set_wpconfig_writable">
                            <p><strong>wp-config.php permissions</strong></p>
                            <button class="button">Make wp-config.php writable</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="server">
                    <h2>Server Rules</h2>
                    <p><strong>Server detected:</strong> <?php echo esc_html(strtoupper($server)); ?></p>
                    <p><strong>Recommended snippet status:</strong>
                        <?php
                        $badge_class = $server_status['status'] === 'ok' ? 'tk-on' : ($server_status['status'] === 'warn' ? 'tk-warn' : '');
                        $badge_label = $server_status['status'] === 'ok' ? 'Applied' : ($server_status['status'] === 'warn' ? 'Not detected' : 'Unknown');
                        ?>
                        <span class="tk-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_label); ?></span>
                        <span class="description"><?php echo esc_html($server_status['detail']); ?></span>
                    </p>
                    <?php if (tk_get_option('hardening_server_aware_enabled', 1) && !empty($server_rules)) : ?>
                        <p><strong>Server-aware rules</strong></p>
                        <ul class="tk-list">
                            <?php foreach ($server_rules as $rule) : ?>
                                <li>&#10003; <?php echo esc_html($rule); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (tk_get_option('hardening_server_aware_enabled', 1) && $server_snippet !== '') : ?>
                        <p><strong>Recommended snippet</strong></p>
                        <pre><?php echo esc_html($server_snippet); ?></pre>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="filesystem">
                    <h2>Filesystem</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                        <?php tk_nonce_field('tk_monitoring_save'); ?>
                        <input type="hidden" name="action" value="tk_monitoring_save">
                        <p>
                            <label for="tk-monitor-email">Alert email</label><br>
                            <input class="regular-text" type="email" id="tk-monitor-email" name="monitoring_email" value="<?php echo esc_attr((string) $monitor_email); ?>" placeholder="admin@example.com">
                        </p>
                        <p>
                            <button class="button button-primary" name="monitoring_action" value="save">Save email</button>
                            <button class="button button-secondary" name="monitoring_action" value="reset">Reset baseline</button>
                        </p>
                    </form>
                    <p><small>Alert email will receive tamper warnings if plugin files change or critical security settings are turned off.</small></p>
                    <p><strong>Non-core entries in WordPress root</strong></p>
                    <?php if (!empty($noncore_root)) : ?>
                        <ul class="tk-list">
                            <?php foreach (array_slice($noncore_root, 0, 50) as $entry) : ?>
                                <li>&#10003; <?php echo esc_html($entry); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (count($noncore_root) > 50) : ?>
                            <p><small>Showing first 50 items.</small></p>
                        <?php endif; ?>
                    <?php else : ?>
                        <p><small>No non-core entries detected.</small></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                        <?php tk_nonce_field('tk_remove_ds_store'); ?>
                        <input type="hidden" name="action" value="tk_remove_ds_store">
                        <p><strong>.DS_Store cleanup</strong></p>
                        <button class="button button-secondary">Remove .DS_Store and __MACOSX in root</button>
                    </form>
                    <p><small>Deletes macOS .DS_Store files and __MACOSX folders under the WordPress root.</small></p>
                    <hr style="margin:16px 0;">
                    <h3>Heartbeat</h3>
                    <p><small>Send a manual heartbeat to the collector endpoint.</small></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_heartbeat_manual'); ?>
                        <input type="hidden" name="action" value="tk_heartbeat_manual">
                        <button class="button button-secondary">Send Heartbeat Now</button>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="realtime">
                    <h2>Health Monitor Real-Time</h2>
                    <p>Refreshes every 5 seconds.</p>
                    <table class="widefat striped tk-table">
                        <tbody>
                            <tr>
                                <th>Page load time</th>
                                <td><span id="tk-rt-load">-</span></td>
                            </tr>
                            <tr>
                                <th>AJAX response time</th>
                                <td><span id="tk-rt-rtt">-</span></td>
                            </tr>
                            <tr>
                                <th>Memory usage</th>
                                <td><span id="tk-rt-mem">-</span></td>
                            </tr>
                            <tr>
                                <th>Error rate</th>
                                <td><span id="tk-rt-errors">-</span></td>
                            </tr>
                            <tr>
                                <th>Object cache</th>
                                <td><span id="tk-rt-object-cache">-</span></td>
                            </tr>
                            <tr>
                                <th>Redis</th>
                                <td><span id="tk-rt-redis">-</span></td>
                            </tr>
                            <tr>
                                <th>Heaviest plugins</th>
                                <td>
                                    <ul id="tk-rt-plugins" class="tk-list" style="margin:0;"></ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="missing">
                    <h2>404 Monitor</h2>
                    <p>Track missing URLs to fix broken links and spot suspicious scanning activity.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                        <?php tk_nonce_field('tk_404_monitoring_save'); ?>
                        <input type="hidden" name="action" value="tk_404_monitoring_save">
                        <p>
                            <label>
                                <input type="checkbox" name="monitoring_404_enabled" value="1" <?php checked(1, tk_get_option('monitoring_404_enabled', 1)); ?>>
                                Enable 404 monitoring
                            </label>
                        </p>
                        <p>
                            <label>Exclude paths (one per line)</label><br>
                            <textarea name="monitoring_404_exclude_paths" rows="4" class="large-text"><?php echo esc_textarea((string) tk_get_option('monitoring_404_exclude_paths', "/wp-admin\n/wp-login.php\n/wp-cron.php\n")); ?></textarea>
                        </p>
                        <p><button class="button button-primary">Save settings</button></p>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                        <?php tk_nonce_field('tk_404_monitoring_clear'); ?>
                        <input type="hidden" name="action" value="tk_404_monitoring_clear">
                        <button class="button button-secondary">Clear log</button>
                    </form>
                    <h3>Top missing URLs</h3>
                    <?php if (!empty($log_values)) : ?>
                        <table class="widefat striped tk-table">
                            <thead><tr><th>URL</th><th>Count</th><th>Last seen</th><th>Referrer</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($log_values, 0, 50) as $entry) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($entry['path']); ?></code></td>
                                    <td><?php echo esc_html((string) $entry['count']); ?></td>
                                    <td><?php echo esc_html($entry['last'] ? date_i18n('Y-m-d H:i', (int) $entry['last']) : '-'); ?></td>
                                    <td><?php echo esc_html($entry['ref'] !== '' ? $entry['ref'] : '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><small>No 404 logs yet.</small></p>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="health">
                    <h2>Healthcheck</h2>
                    <p>Health endpoint returns JSON for uptime checks and monitoring.</p>
                    <?php if (tk_get_option('monitoring_healthcheck_enabled', 0) && $health_key === '') : ?>
                        <?php tk_notice('Healthcheck is enabled but no key is set. Add a key to activate the endpoint.', 'warning'); ?>
                    <?php endif; ?>
                    <p>
                        <strong>Endpoint</strong><br>
                        <input type="text" class="regular-text" readonly value="<?php echo esc_attr($health_url); ?>" placeholder="<?php echo esc_attr(home_url('/?tk-health=1&key=YOURKEY')); ?>">
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                        <?php tk_nonce_field('tk_healthcheck_save'); ?>
                        <input type="hidden" name="action" value="tk_healthcheck_save">
                        <p>
                            <label>
                                <input type="checkbox" name="monitoring_healthcheck_enabled" value="1" <?php checked(1, tk_get_option('monitoring_healthcheck_enabled', 1)); ?>>
                                Enable healthcheck endpoint
                            </label>
                        </p>
                        <p>
                            <label>Healthcheck key (optional)</label><br>
                            <input type="text" name="monitoring_healthcheck_key" value="<?php echo esc_attr($health_key); ?>" class="regular-text">
                            <span class="description">Set a key to require ?key=... on the endpoint.</span>
                        </p>
                        <p><button class="button button-primary">Save settings</button></p>
                    </form>
                    <h3>Current status</h3>
                    <table class="widefat striped tk-table">
                        <tbody>
                            <tr>
                                <th>WP Cron</th>
                                <td><?php echo $healthcheck['cron']['disabled'] ? 'Disabled' : 'Enabled'; ?></td>
                            </tr>
                            <tr>
                                <th>Next cron</th>
                                <td><?php echo $healthcheck['cron']['next'] ? date_i18n('Y-m-d H:i', (int) $healthcheck['cron']['next']) : '-'; ?></td>
                            </tr>
                            <tr>
                                <th>PHP</th>
                                <td><?php echo esc_html($healthcheck['server']['php']); ?></td>
                            </tr>
                            <tr>
                                <th>Load avg</th>
                                <td><?php echo !empty($healthcheck['server']['load']) ? esc_html(implode(', ', $healthcheck['server']['load'])) : '-'; ?></td>
                            </tr>
                            <tr>
                                <th>Disk free</th>
                                <td><?php echo $healthcheck['server']['disk_free'] ? size_format($healthcheck['server']['disk_free']) : '-'; ?></td>
                            </tr>
                            <tr>
                                <th>Disk total</th>
                                <td><?php echo $healthcheck['server']['disk_total'] ? size_format($healthcheck['server']['disk_total']) : '-'; ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        (function(){
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
        })();
        </script>
        <script>
        (function(){
            var loadEl = document.getElementById('tk-rt-load');
            var rttEl = document.getElementById('tk-rt-rtt');
            var memEl = document.getElementById('tk-rt-mem');
            var errEl = document.getElementById('tk-rt-errors');
            var objectEl = document.getElementById('tk-rt-object-cache');
            var redisEl = document.getElementById('tk-rt-redis');
            var pluginsEl = document.getElementById('tk-rt-plugins');
            if (!loadEl || !rttEl || !memEl || !errEl || !objectEl || !redisEl || !pluginsEl) {
                return;
            }
            var intervalId = null;
            function isRealtimeActive() {
                var panel = document.querySelector('.tk-tab-panel[data-panel-id="realtime"]');
                return panel && panel.classList.contains('is-active');
            }
            function formatBytes(bytes) {
                if (!bytes || bytes <= 0) { return '-'; }
                var units = ['B','KB','MB','GB'];
                var i = 0;
                var val = bytes;
                while (val >= 1024 && i < units.length - 1) {
                    val /= 1024;
                    i++;
                }
                return val.toFixed(1) + ' ' + units[i];
            }
            function setLoadTime() {
                if (!('performance' in window)) { return; }
                var nav = performance.getEntriesByType('navigation');
                if (nav && nav.length) {
                    loadEl.textContent = Math.round(nav[0].duration) + ' ms';
                    return;
                }
                if (performance.timing) {
                    var t = performance.timing;
                    var duration = t.loadEventEnd - t.navigationStart;
                    if (duration > 0) {
                        loadEl.textContent = Math.round(duration) + ' ms';
                    }
                }
            }
            function fetchHealth() {
                if (!isRealtimeActive()) {
                    return;
                }
                var start = Date.now();
                var data = new URLSearchParams();
                data.append('action', 'tk_realtime_health');
                data.append('nonce', '<?php echo esc_js(wp_create_nonce('tk_realtime_health')); ?>');
                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: data.toString()
                }).then(function(resp){ return resp.json(); }).then(function(res){
                    var rtt = Date.now() - start;
                    rttEl.textContent = rtt + ' ms';
                    if (!res || !res.success || !res.data) {
                        return;
                    }
                    var mem = res.data.memory || {};
                    var memText = formatBytes(mem.used);
                    if (mem.percent !== null && mem.percent !== undefined) {
                        memText += ' (' + mem.percent + '%)';
                    }
                    memEl.textContent = memText;
                    var err = res.data.errors || {};
                    if (err.available === false) {
                        errEl.textContent = 'Unavailable (debug.log off)';
                    } else {
                        errEl.textContent = (err.per_min || 0) + '/min (last 5m)';
                    }
                    var cache = res.data.cache || {};
                    objectEl.textContent = cache.object ? 'Enabled' : 'Default';
                    redisEl.textContent = cache.redis ? 'Enabled' : 'Off';
                    pluginsEl.innerHTML = '';
                    var list = res.data.heavy_plugins || [];
                    if (!Array.isArray(list) || list.length === 0) {
                        var fallback = res.data.heavy_plugin || {};
                        if (fallback.name) {
                            var li = document.createElement('li');
                            li.textContent = fallback.name + ' (' + formatBytes(fallback.size) + ')';
                            pluginsEl.appendChild(li);
                        }
                        return;
                    }
                    list.forEach(function(item){
                        if (!item || !item.name) {
                            return;
                        }
                        var li = document.createElement('li');
                        li.textContent = item.name + ' (' + formatBytes(item.size) + ')';
                        pluginsEl.appendChild(li);
                    });
                }).catch(function(){
                    rttEl.textContent = 'Failed';
                });
            }
            function startPolling() {
                if (intervalId !== null) {
                    return;
                }
                setLoadTime();
                fetchHealth();
                intervalId = setInterval(fetchHealth, 5000);
            }
            function stopPolling() {
                if (intervalId === null) {
                    return;
                }
                clearInterval(intervalId);
                intervalId = null;
            }
            document.addEventListener('click', function(e){
                var button = e.target.closest('.tk-tabs-nav-button');
                if (!button) {
                    return;
                }
                var panelId = button.getAttribute('data-panel');
                if (panelId === 'realtime') {
                    startPolling();
                } else {
                    stopPolling();
                }
            });
            if (isRealtimeActive()) {
                startPolling();
            }
        })();
        </script>
    </div>
    <?php
}

function tk_render_overview_page() {
    if (!tk_is_admin_user()) return;
    ?>
    <div class="wrap tk-wrap">
        <h1>Tool Kits</h1>
        <div class="tk-card" style="margin-top:24px;">
            <h2>Security Modules</h2>
            <p class="description">Control each module directly from this page.</p>
            <?php tk_render_security_table(); ?>
        </div>
        <div class="tk-card" style="margin-top:24px;">
            <h2>Recommendation Confidence Level</h2>
            <p class="description">General guidance for enabling common performance and security options. Use the shortcuts to review settings.</p>
            <table class="widefat striped tk-table">
                <thead><tr><th>Recommendation</th><th>Level</th><th>Shortcut</th></tr></thead>
                <tbody>
                    <tr>
                        <td>Enable page cache for anonymous visitors</td>
                        <td><span class="tk-badge tk-on">Safe</span></td>
                        <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-cache') . '#page'); ?>">Cache settings</a></td>
                    </tr>
                    <tr>
                        <td>Enable lazy load for images/iframes below the fold</td>
                        <td><span class="tk-badge tk-on">Safe</span></td>
                        <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#lazy-load'); ?>">Lazy Load settings</a></td>
                    </tr>
                    <tr>
                        <td>Enable Critical CSS and defer non-critical CSS</td>
                        <td><span class="tk-badge tk-warn">Medium risk</span></td>
                        <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#assets'); ?>">Assets settings</a></td>
                    </tr>
                    <tr>
                        <td>Enable HTML minify (frontend)</td>
                        <td><span class="tk-badge tk-warn">Medium risk</span></td>
                        <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#minify'); ?>">Minify settings</a></td>
                    </tr>
                    <tr>
                        <td>Enable Hide Login (custom login slug)</td>
                        <td><span class="tk-badge tk-warn">Medium risk</span></td>
                        <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#hide-login'); ?>">Hide Login settings</a></td>
                    </tr>
                    <tr>
                        <td>Enable WAF or HTTP Auth protections</td>
                        <td><span class="tk-badge tk-adv">Advanced / expert only</span></td>
                        <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-hardening') . '#waf'); ?>">Hardening settings</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function tk_render_security_table() {
    ?>
    <table class="widefat striped tk-table">
        <thead><tr><th>Module</th><th>Status</th><th>Active Items</th><th>Shortcut</th></tr></thead>
        <tbody>
            <tr>
                <td>Hide Login</td>
                <td><?php echo tk_get_option('hide_login_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td>
                    <?php if (tk_get_option('hide_login_enabled',0)) : ?>
                        <ul class="tk-list">
                            <li>&#10003; /<?php echo esc_html((string) tk_get_option('hide_login_slug', 'secure-login')); ?></li>
                        </ul>
                    <?php else : ?>
                        <small>-</small>
                    <?php endif; ?>
                </td>
                <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#hide-login'); ?>">Settings</a></td>
            </tr>
            <tr>
                <td>Captcha</td>
                <td><?php echo tk_get_option('captcha_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td>
                    <?php if (tk_get_option('captcha_enabled',0)) : ?>
                        <ul class="tk-list">
                            <?php if (tk_get_option('captcha_on_login',1)) : ?>
                                <li>&#10003; Login</li>
                            <?php endif; ?>
                            <?php if (tk_get_option('captcha_on_comments',0)) : ?>
                                <li>&#10003; Comments</li>
                            <?php endif; ?>
                            <?php if (!tk_get_option('captcha_on_login',1) && !tk_get_option('captcha_on_comments',0)) : ?>
                                <li>&#10003; Enabled</li>
                            <?php endif; ?>
                        </ul>
                    <?php else : ?>
                        <small>-</small>
                    <?php endif; ?>
                </td>
                <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-spam') . '#captcha'); ?>">Settings</a></td>
            </tr>
            <tr>
                <td>Anti-spam Contact</td>
                <td><?php echo tk_get_option('antispam_cf7_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td>
                    <?php if (tk_get_option('antispam_cf7_enabled',0)) : ?>
                        <ul class="tk-list">
                            <li>&#10003; Min seconds: <?php echo esc_html((string) tk_get_option('antispam_min_seconds', 3)); ?></li>
                        </ul>
                    <?php else : ?>
                        <small>-</small>
                    <?php endif; ?>
                </td>
                <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-spam') . '#antispam'); ?>">Settings</a></td>
            </tr>
            <tr>
                <td>Rate Limit</td>
                <td><?php echo tk_get_option('rate_limit_enabled',0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td>
                    <?php if (tk_get_option('rate_limit_enabled',0)) :
                        $window = (int) tk_get_option('rate_limit_window_minutes', 10);
                        $max = (int) tk_get_option('rate_limit_max_attempts', 5);
                        $lock = (int) tk_get_option('rate_limit_lockout_minutes', 30);
                    ?>
                        <ul class="tk-list">
                            <li>&#10003; Window: <?php echo esc_html((string) $window); ?>m</li>
                            <li>&#10003; Max: <?php echo esc_html((string) $max); ?></li>
                            <li>&#10003; Lock: <?php echo esc_html((string) $lock); ?>m</li>
                        </ul>
                    <?php else : ?>
                        <small>-</small>
                    <?php endif; ?>
                </td>
                <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-rate-limit')); ?>">Settings</a></td>
            </tr>
            <tr>
                <td>Login Log</td>
                <td><?php echo tk_get_option('login_log_enabled',1) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td>
                    <?php if (tk_get_option('login_log_enabled',1)) : ?>
                        <ul class="tk-list">
                            <li>&#10003; Keep days: <?php echo esc_html((string) tk_get_option('login_log_keep_days', 30)); ?></li>
                        </ul>
                    <?php else : ?>
                        <small>-</small>
                    <?php endif; ?>
                </td>
                <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-login-log')); ?>">View</a></td>
            </tr>
            <tr>
                <td>Hardening</td>
                <td><?php echo tk_hardening_active_items() ? '<span class="tk-badge tk-on">ACTIVE</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td>
                    <?php
                    $hardening_items = tk_hardening_active_items();
                    if (!empty($hardening_items)) : ?>
                        <ul class="tk-list">
                            <?php foreach ($hardening_items as $item) : ?>
                                <li>&#10003; <?php echo esc_html($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <small>-</small>
                    <?php endif; ?>
                </td>
                <td><a href="<?php echo esc_url(tk_admin_url('tool-kits-security-hardening')); ?>">Settings</a></td>
            </tr>
        </tbody>
    </table>
    <?php
}

function tk_render_toolkits_access_page() {
    if (!tk_toolkits_can_manage()) return;
    $hidden = (int) tk_get_option('hide_toolkits_menu', 0);
    $hide_cff = (int) tk_get_option('hide_cff_menu', 0);
    $lock = (int) tk_get_option('toolkits_lock_enabled', 0);
    $mask = (int) tk_get_option('toolkits_mask_sensitive_fields', 0);
    $roles = tk_toolkits_allowed_roles();
    $allowlist = (string) tk_get_option('toolkits_ip_allowlist', '');
    $alert_enabled = (int) tk_get_option('toolkits_alert_enabled', 1);
    $alert_email = (string) tk_get_option('toolkits_alert_email', '');
    $alert_admin_created = (int) tk_get_option('toolkits_alert_admin_created', 1);
    $alert_role_change = (int) tk_get_option('toolkits_alert_role_change', 1);
    $alert_admin_ip = (int) tk_get_option('toolkits_alert_admin_login_new_ip', 1);
    $collector_url = (string) tk_get_option('heartbeat_collector_url', '');
    $collector_key = (string) tk_get_option('heartbeat_auth_key', '');
    $license_server_url = (string) tk_get_option('license_server_url', '');
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
    $license_required = isset($_GET['tk_license']) ? sanitize_key($_GET['tk_license']) : '';
    ?>
    <div class="wrap tk-wrap">
        <h1>Tool Kits Access</h1>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('Access settings saved.', 'success'); ?>
        <?php endif; ?>
        <?php if ($cleared === '1') : ?>
            <?php tk_notice('Audit log cleared.', 'success'); ?>
        <?php endif; ?>
        <?php if ($license_required === '1') : ?>
            <?php
            $license_notice = $license_missing
                ? 'License key is required. Please set your license key and license server URL.'
                : ($license_message !== '' ? $license_message : 'License invalid.');
            tk_notice($license_notice, 'warning');
            ?>
        <?php endif; ?>
        <?php if ($collector_key === '' && (!defined('TK_HEARTBEAT_AUTH_KEY') || TK_HEARTBEAT_AUTH_KEY === '')) : ?>
            <?php tk_notice('Collector token is required to access Tool Kits. Please set it below.', 'warning'); ?>
        <?php endif; ?>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="license">License</button>
                <?php if ($show_full_tabs) : ?>
                    <button type="button" class="tk-tabs-nav-button" data-panel="owner">Owner</button>
                    <button type="button" class="tk-tabs-nav-button" data-panel="alerts">Alerts</button>
                    <button type="button" class="tk-tabs-nav-button" data-panel="audit">Audit Log</button>
                <?php endif; ?>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="license">
                    <p>Set license server URL and key to unlock all Tool Kits features.</p>
                    <?php
                    $expires_label = 'Unlimited';
                    $expires_remaining = '';
                    if ($license_expires !== '' && strtotime($license_expires) !== false) {
                        $expires_ts = strtotime($license_expires);
                        $now = time();
                        if ($expires_ts <= $now) {
                            $expires_label = 'Expired';
                            $expires_remaining = '0 days';
                        } else {
                            $days = (int) ceil(($expires_ts - $now) / DAY_IN_SECONDS);
                            $expires_label = gmdate('Y-m-d', $expires_ts);
                            $expires_remaining = $days . ' days left';
                        }
                    }
                    ?>
                    <div class="tk-card" style="margin:12px 0;">
                        <h3 style="margin-top:0;">License Status</h3>
                        <?php
                        $status_class = 'tk-badge';
                        if ($license_status === 'valid') {
                            $status_class = 'tk-badge tk-on';
                        } elseif (in_array($license_status, array('expired','revoked','not_found'), true)) {
                            $status_class = 'tk-badge tk-warn';
                        }
                        ?>
                        <p><strong>Status:</strong> <span class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($license_status); ?></span></p>
                        <?php if ($license_message !== '') : ?>
                            <p><strong>Message:</strong> <?php echo esc_html($license_message); ?></p>
                        <?php endif; ?>
                        <?php if ($license_type !== '') : ?>
                            <p><strong>License type:</strong> <?php echo esc_html($license_type); ?></p>
                        <?php endif; ?>
                        <?php if ($license_env !== '') : ?>
                            <p><strong>Environment:</strong> <?php echo esc_html($license_env); ?></p>
                        <?php endif; ?>
                        <?php if ($license_site !== '') : ?>
                            <p><strong>Bound site:</strong> <?php echo esc_html($license_site); ?></p>
                        <?php endif; ?>
                        <p><strong>Expires:</strong> <?php echo esc_html($expires_label); ?>
                            <?php if ($expires_remaining !== '') : ?>
                                <span>(<?php echo esc_html($expires_remaining); ?>)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_toolkits_access_save'); ?>
                        <input type="hidden" name="action" value="tk_toolkits_access_save">
                        <input type="hidden" name="tk_tab" value="license">
                        <?php if ($license_status !== 'valid') : ?>
                            <input type="hidden" name="heartbeat_collector_url" value="<?php echo esc_attr($collector_url); ?>">
                            <input type="hidden" name="heartbeat_auth_key" value="<?php echo esc_attr($collector_key); ?>">
                            <?php
                            $collector_mask = '';
                            if ($collector_key !== '') {
                                $collector_mask = str_repeat('*', max(0, strlen($collector_key) - 4)) . substr($collector_key, -4);
                            }
                            ?>
                            <p>
                                <label>Collector token</label><br>
                                <input class="regular-text" type="text" name="heartbeat_auth_key_display" value="<?php echo esc_attr($collector_mask); ?>" autocomplete="off">
                            </p>
                        <?php else : ?>
                            <input type="hidden" name="heartbeat_collector_url" value="<?php echo esc_attr($collector_url); ?>">
                            <p><small>Collector token is set.</small></p>
                        <?php endif; ?>
                        <input type="hidden" name="license_server_url" value="<?php echo esc_attr($license_server_url); ?>">
                        <p>
                            <label>License key</label><br>
                            <input type="hidden" name="license_key" value="<?php echo esc_attr($license_key); ?>">
                            <?php
                            $license_mask = '';
                            if ($license_key !== '') {
                                $license_mask = str_repeat('*', max(0, strlen($license_key) - 4)) . substr($license_key, -4);
                            }
                            ?>
                            <input class="regular-text" type="text" name="license_key_display" value="<?php echo esc_attr($license_mask); ?>" autocomplete="off">
                        </p>
                        <p><button class="button button-primary">Save</button></p>
                    </form>
                </div>
                <?php if ($show_full_tabs) : ?>
                <div class="tk-card tk-tab-panel" data-panel-id="owner">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_toolkits_access_save'); ?>
                        <input type="hidden" name="action" value="tk_toolkits_access_save">
                        <input type="hidden" name="tk_tab" value="owner">
                        <h3>Owner-Only Access</h3>
                        <p>
                            <label>
                                <input type="checkbox" name="toolkits_owner_only_enabled" value="1" <?php checked(1, $owner_only); ?>>
                                Restrict Tool Kits access to a single admin
                            </label>
                        </p>
                        <p>
                            <label>Owner admin user</label><br>
                            <select name="toolkits_owner_user_id">
                                <?php foreach ($admins as $admin) : ?>
                                    <option value="<?php echo esc_attr((string) $admin->ID); ?>" <?php selected($admin->ID, $owner_id); ?>>
                                        <?php echo esc_html($admin->user_login . ' (ID ' . $admin->ID . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p><button class="button button-primary">Save</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="alerts">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_toolkits_access_save'); ?>
                        <input type="hidden" name="action" value="tk_toolkits_access_save">
                        <input type="hidden" name="tk_tab" value="alerts">
                        <h3>Security Alerts</h3>
                        <p>
                            <label>
                                <input type="checkbox" name="toolkits_alert_enabled" value="1" <?php checked(1, $alert_enabled); ?>>
                                Enable security alerts
                            </label>
                        </p>
                        <p>
                            <label>Alert email (optional)</label><br>
                            <input class="regular-text" type="email" name="toolkits_alert_email" value="<?php echo esc_attr($alert_email); ?>" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                        </p>
                        <p>
                            <label><input type="checkbox" name="toolkits_alert_admin_created" value="1" <?php checked(1, $alert_admin_created); ?>> Alert on new admin user</label>
                        </p>
                        <p>
                            <label><input type="checkbox" name="toolkits_alert_role_change" value="1" <?php checked(1, $alert_role_change); ?>> Alert on role change to admin</label>
                        </p>
                        <p>
                            <label><input type="checkbox" name="toolkits_alert_admin_login_new_ip" value="1" <?php checked(1, $alert_admin_ip); ?>> Alert on admin login from new IP</label>
                        </p>
                        <p><button class="button button-primary">Save</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="audit">
                    <h2>Audit Log</h2>
                    <?php if (empty($audit_log)) : ?>
                        <p><small>No audit log entries yet.</small></p>
                    <?php else : ?>
                        <table class="widefat striped tk-table">
                            <thead><tr><th>Time</th><th>User</th><th>IP</th><th>Action</th><th>Detail</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($audit_log, 0, 50) as $entry) : ?>
                                    <tr>
                                        <td><?php echo esc_html(date_i18n('Y-m-d H:i', (int) $entry['time'])); ?></td>
                                        <td><?php echo esc_html((string) $entry['user']); ?></td>
                                        <td><?php echo esc_html((string) $entry['ip']); ?></td>
                                        <td><?php echo esc_html((string) $entry['action']); ?></td>
                                        <td><?php echo esc_html(wp_json_encode($entry['detail'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                            <?php tk_nonce_field('tk_toolkits_audit_clear'); ?>
                            <input type="hidden" name="action" value="tk_toolkits_audit_clear">
                            <button class="button button-secondary">Clear audit log</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
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
            var tokenDisplay = document.querySelector('input[name="heartbeat_auth_key_display"]');
            var tokenHidden = document.querySelector('input[name="heartbeat_auth_key"]');
            if (tokenDisplay && tokenHidden) {
                tokenDisplay.addEventListener('input', function() {
                    tokenHidden.value = tokenDisplay.value;
                });
            }
            var licenseDisplay = document.querySelector('input[name="license_key_display"]');
            var licenseHidden = document.querySelector('input[name="license_key"]');
            if (licenseDisplay && licenseHidden) {
                licenseDisplay.addEventListener('input', function() {
                    licenseHidden.value = licenseDisplay.value;
                });
            }
        })();
        </script>
    </div>
    <?php
}

function tk_toolkits_access_save() {
    if (!tk_toolkits_can_manage()) wp_die('Forbidden');
    tk_check_nonce('tk_toolkits_access_save');
    $tab = isset($_POST['tk_tab']) ? sanitize_key($_POST['tk_tab']) : 'license';
    if (!in_array($tab, array('access', 'owner', 'alerts', 'audit', 'license'), true)) {
        $tab = 'license';
    }
    $posted_license_key = isset($_POST['license_key']) ? trim(wp_unslash($_POST['license_key'])) : null;
    if ($posted_license_key !== null) {
        tk_update_option('license_key', $posted_license_key);
        tk_update_option('license_status', 'inactive');
        tk_update_option('license_message', '');
        tk_update_option('license_last_checked', 0);
    }
    if ($tab === 'access') {
        tk_update_option('hide_toolkits_menu', !empty($_POST['hide_menu']) ? 1 : 0);
        tk_update_option('hide_cff_menu', !empty($_POST['hide_cff_menu']) ? 1 : 0);
        $collector_url = isset($_POST['heartbeat_collector_url']) ? esc_url_raw(wp_unslash($_POST['heartbeat_collector_url'])) : '';
        if ($collector_url !== '') {
            tk_update_option('heartbeat_collector_url', $collector_url);
        }
        $collector_key = isset($_POST['heartbeat_auth_key']) ? trim(wp_unslash($_POST['heartbeat_auth_key'])) : '';
        if ($collector_key !== '') {
            tk_update_option('heartbeat_auth_key', $collector_key);
        }
        $roles = isset($_POST['toolkits_allowed_roles']) ? (array) $_POST['toolkits_allowed_roles'] : array();
        $roles = array_filter(array_map('sanitize_key', $roles));
        if (empty($roles)) {
            $roles = array('administrator');
        }
        tk_update_option('toolkits_allowed_roles', $roles);
        tk_update_option('toolkits_ip_allowlist', (string) tk_post('toolkits_ip_allowlist', ''));
        tk_update_option('toolkits_lock_enabled', !empty($_POST['toolkits_lock_enabled']) ? 1 : 0);
        tk_update_option('toolkits_mask_sensitive_fields', !empty($_POST['toolkits_mask_sensitive_fields']) ? 1 : 0);
    } elseif ($tab === 'license') {
        $collector_url = isset($_POST['heartbeat_collector_url']) ? esc_url_raw(wp_unslash($_POST['heartbeat_collector_url'])) : '';
        if ($collector_url !== '') {
            tk_update_option('heartbeat_collector_url', $collector_url);
        }
        $collector_key = isset($_POST['heartbeat_auth_key']) ? trim(wp_unslash($_POST['heartbeat_auth_key'])) : '';
        if ($collector_key !== '') {
            tk_update_option('heartbeat_auth_key', $collector_key);
        }
        $license_server_url = isset($_POST['license_server_url']) ? esc_url_raw(wp_unslash($_POST['license_server_url'])) : '';
        if ($license_server_url === '') {
            $base_url = $collector_url !== '' ? $collector_url : (string) tk_get_option('heartbeat_collector_url', '');
            if (substr($base_url, -13) === 'heartbeat.php') {
                $license_server_url = substr($base_url, 0, -13) . 'license.php';
            } else {
                $license_server_url = rtrim($base_url, '/') . '/license.php';
            }
        }
        tk_update_option('license_server_url', $license_server_url);
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
        tk_update_option('toolkits_alert_email', $alert_email);
        tk_update_option('toolkits_alert_admin_created', !empty($_POST['toolkits_alert_admin_created']) ? 1 : 0);
        tk_update_option('toolkits_alert_role_change', !empty($_POST['toolkits_alert_role_change']) ? 1 : 0);
        tk_update_option('toolkits_alert_admin_login_new_ip', !empty($_POST['toolkits_alert_admin_login_new_ip']) ? 1 : 0);
    }
    tk_toolkits_audit_log('access_update', array('user' => wp_get_current_user()->user_login));
    wp_redirect(admin_url('tools.php?page=tool-kits-access&tk_saved=1#' . $tab));
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
