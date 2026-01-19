<?php
if (!defined('ABSPATH')) { exit; }

function tk_admin_menu_init() {
    add_action('admin_menu', 'tk_register_admin_menus');
    add_action('admin_post_tk_toolkits_access_save', 'tk_toolkits_access_save');
    add_action('admin_post_tk_set_wpconfig_readonly', 'tk_set_wpconfig_readonly');
    add_action('admin_post_tk_set_wpconfig_writable', 'tk_set_wpconfig_writable');
    add_action('admin_post_tk_toggle_core_updates', 'tk_toggle_core_updates');
    add_action('admin_post_tk_clear_cache', 'tk_clear_cache_handler');
    add_action('admin_post_tk_heartbeat_manual', 'tk_heartbeat_manual_send');
    add_action('admin_post_tk_monitoring_save', 'tk_monitoring_save');
    add_action('tk_monitoring_cron', 'tk_monitoring_cron_run');
    add_action('init', 'tk_monitoring_schedule_cron');
}

function tk_register_admin_menus() {
    if (!current_user_can('manage_options')) return;

    // Parent
    add_menu_page(
        __('Tool Kits', 'tool-kits'),
        __('Tool Kits', 'tool-kits'),
        'manage_options',
        'tool-kits',
        'tk_render_overview_page',
        'dashicons-admin-tools',
        99
    );

    // DB
    add_submenu_page('tool-kits', __('Database', 'tool-kits'), __('Database', 'tool-kits'), 'manage_options', 'tool-kits-db', 'tk_render_db_tools_page');

    // Security modules now live under the main Tool Kits menu.
    add_submenu_page('tool-kits', __('Optimization', 'tool-kits'), __('Optimization', 'tool-kits'), 'manage_options', 'tool-kits-optimization', 'tk_render_optimization_page');
    add_submenu_page('tool-kits', __('Spam Protection', 'tool-kits'), __('Spam Protection', 'tool-kits'), 'manage_options', 'tool-kits-security-spam', 'tk_render_spam_protection_page');
    add_submenu_page('tool-kits', __('Rate Limit', 'tool-kits'), __('Rate Limit', 'tool-kits'), 'manage_options', 'tool-kits-security-rate-limit', 'tk_render_rate_limit_page');
    add_submenu_page('tool-kits', __('Login Log', 'tool-kits'), __('Login Log', 'tool-kits'), 'manage_options', 'tool-kits-security-login-log', 'tk_render_login_log_page');
    add_submenu_page('tool-kits', __('Hardening', 'tool-kits'), __('Hardening', 'tool-kits'), 'manage_options', 'tool-kits-security-hardening', 'tk_render_hardening_page');
    add_submenu_page('tool-kits', __('Monitoring', 'tool-kits'), __('Monitoring', 'tool-kits'), 'manage_options', 'tool-kits-monitoring', 'tk_render_monitoring_page');
    add_submenu_page('tool-kits', __('Cache', 'tool-kits'), __('Cache', 'tool-kits'), 'manage_options', 'tool-kits-cache', 'tk_render_cache_page');

    add_submenu_page('tools.php', __('Tool Kits Access', 'tool-kits'), __('Tool Kits Access', 'tool-kits'), 'manage_options', 'tool-kits-access', 'tk_render_toolkits_access_page');

    // Hidden legacy pages for direct links.
    add_submenu_page(null, __('Hide Login', 'tool-kits'), __('Hide Login', 'tool-kits'), 'manage_options', 'tool-kits-security-hide-login', 'tk_render_hide_login_page');
    add_submenu_page(null, __('Minify', 'tool-kits'), __('Minify', 'tool-kits'), 'manage_options', 'tool-kits-minify', 'tk_render_minify_page');
    add_submenu_page(null, __('Auto WebP', 'tool-kits'), __('Auto WebP', 'tool-kits'), 'manage_options', 'tool-kits-webp', 'tk_render_webp_page');

    if (tk_get_option('hide_toolkits_menu', 0)) {
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
                        ?>
                            <tr>
                                <th><?php echo esc_html($check['label']); ?></th>
                                <td><span class="tk-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_label); ?></span></td>
                                <td><?php echo esc_html($check['detail']); ?></td>
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
                                <th>Plugin paling berat</th>
                                <td><span id="tk-rt-plugin">-</span></td>
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
            var pluginEl = document.getElementById('tk-rt-plugin');
            if (!loadEl || !rttEl || !memEl || !errEl || !pluginEl) {
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
                    var heavy = res.data.heavy_plugin || {};
                    if (heavy.name) {
                        pluginEl.textContent = heavy.name + ' (' + formatBytes(heavy.size) + ')';
                    }
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
    if (!tk_is_admin_user()) return;
    $hidden = (int) tk_get_option('hide_toolkits_menu', 0);
    $hide_cff = (int) tk_get_option('hide_cff_menu', 0);
    $cff_installed = tk_is_cff_installed();
    ?>
    <div class="wrap tk-wrap">
        <h1>Tool Kits Access</h1>
        <div class="tk-card">
            <p>Hide the Tool Kits menu from the admin sidebar on production to prevent changes.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_toolkits_access_save'); ?>
                <input type="hidden" name="action" value="tk_toolkits_access_save">
                <p><label><input type="checkbox" name="hide_menu" value="1" <?php checked(1, $hidden); ?>> Hide Tool Kits menu</label></p>
                <?php if ($cff_installed) : ?>
                    <p><label><input type="checkbox" name="hide_cff_menu" value="1" <?php checked(1, $hide_cff); ?>> Hide CFF menu</label></p>
                <?php endif; ?>
                <p><button class="button button-primary">Save</button></p>
            </form>
        </div>
    </div>
    <?php
}

function tk_toolkits_access_save() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_toolkits_access_save');
    tk_update_option('hide_toolkits_menu', !empty($_POST['hide_menu']) ? 1 : 0);
    tk_update_option('hide_cff_menu', !empty($_POST['hide_cff_menu']) ? 1 : 0);
    wp_redirect(admin_url('tools.php?page=tool-kits-access&tk_saved=1'));
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
