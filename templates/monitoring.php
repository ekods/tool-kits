<?php
if (!defined('ABSPATH')) { exit; }
/**
 * @var array $checks
 * @var string $server
 * @var array $server_rules
 * @var string $server_snippet
 * @var array $server_status
 * @var array $noncore_root
 * @var string $monitor_email
 * @var array $log_values
 * @var string $health_url
 * @var string $health_key
 * @var array $healthcheck
 * @var array $connection_summary
 * @var bool $core_auto
 * @var string $wp_config_path
 */
?>
<div class="wrap tk-wrap">
    <?php tk_render_header_branding(); ?>
    <?php 
    $heartbeat_action = '
    <div style="background:rgba(255,255,255,0.1); padding:8px 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.2); backdrop-filter: blur(10px);">
        <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">
            ' . wp_nonce_field('tk_heartbeat_manual', '_wpnonce', true, false) . '
            <input type="hidden" name="action" value="tk_heartbeat_manual">
            <button class="button" style="background:#fff; color:var(--tk-primary); border:none; padding:6px 16px; font-weight:600; border-radius:6px; cursor:pointer;">' . __('Send Heartbeat Now', 'tool-kits') . '</button>
        </form>
    </div>';
    tk_render_page_hero(__('System Monitoring', 'tool-kits'), __('Real-time health tracking and security audits for your WordPress site.', 'tool-kits'), 'dashicons-visibility', $heartbeat_action); 
    ?>
    <?php
    $notices = array(
        'tk_wpconfig' => array(
            'ok' => array(__('wp-config.php permissions updated.', 'tool-kits'), 'success'),
            'writable' => array(__('wp-config.php set to writable.', 'tool-kits'), 'success'),
            'fail' => array(__('Failed to update wp-config.php permissions. Please adjust manually.', 'tool-kits'), 'error'),
            'missing' => array(__('wp-config.php not found.', 'tool-kits'), 'warning'),
        ),
        'tk_heartbeat' => array(
            'ok' => array(__('Heartbeat sent.', 'tool-kits'), 'success'),
            'fail' => array(__('Heartbeat failed to send.', 'tool-kits') . ' ' . get_transient('tk_heartbeat_last_error'), 'error'),
        ),
        'tk_cache' => array(
            'ok' => array(__('Cache cleared.', 'tool-kits') . ' ' . get_transient('tk_cache_last_notice'), 'success'),
            'fail' => array(__('Cache clear failed.', 'tool-kits') . ' ' . get_transient('tk_cache_last_notice'), 'error'),
        ),
        'tk_ds_store' => array(
            'ok' => array(__('.DS_Store/__MACOSX cleanup completed.', 'tool-kits') . ' ' . get_transient('tk_ds_store_last_notice'), 'success'),
            'fail' => array(__('.DS_Store/__MACOSX cleanup failed.', 'tool-kits') . ' ' . get_transient('tk_ds_store_last_notice'), 'error'),
        ),
    );

    foreach ($notices as $get_key => $states) {
        $status = isset($_GET[$get_key]) ? sanitize_key($_GET[$get_key]) : '';
        if (isset($states[$status])) {
            tk_notice($states[$status][0], $states[$status][1]);
        }
    }

    if (isset($_GET['tk_404_updated']) && $_GET['tk_404_updated'] === '1') tk_notice(__('404 monitor settings saved.', 'tool-kits'), 'success');
    if (isset($_GET['tk_404_cleared']) && $_GET['tk_404_cleared'] === '1') tk_notice(__('404 log cleared.', 'tool-kits'), 'success');
    if (isset($_GET['tk_health_updated']) && $_GET['tk_health_updated'] === '1') tk_notice(__('Healthcheck settings saved.', 'tool-kits'), 'success');
    ?>

    <div class="tk-tabs" id="tk-monitoring-tabs">
        <div class="tk-tabs-nav">
            <button type="button" class="tk-tabs-nav-button is-active" data-panel="overview"><?php _e('Security Overview', 'tool-kits'); ?></button>
            <button type="button" class="tk-tabs-nav-button" data-panel="checks"><?php _e('Checks', 'tool-kits'); ?></button>
            <button type="button" class="tk-tabs-nav-button" data-panel="actions"><?php _e('Actions', 'tool-kits'); ?></button>
            <button type="button" class="tk-tabs-nav-button" data-panel="server"><?php _e('Server', 'tool-kits'); ?></button>
            <button type="button" class="tk-tabs-nav-button" data-panel="filesystem"><?php _e('Filesystem', 'tool-kits'); ?></button>
            <button type="button" class="tk-tabs-nav-button" data-panel="realtime"><?php _e('Realtime', 'tool-kits'); ?></button>
            <button type="button" class="tk-tabs-nav-button" data-panel="missing"><?php _e('404 Monitor', 'tool-kits'); ?></button>
            <button type="button" class="tk-tabs-nav-button" data-panel="health"><?php _e('Healthcheck', 'tool-kits'); ?></button>
            <button type="button" class="tk-tabs-nav-button" data-panel="integrity"><?php _e('Integrity', 'tool-kits'); ?></button>
        </div>
        <div class="tk-tabs-content" id="tk-monitoring-tabs-content">
            <!-- Panel: Overview -->
            <div class="tk-card tk-tab-panel is-active" data-panel-id="overview">
                <div class="tk-grid tk-grid-3" style="gap:24px;">
                    <div class="tk-card" style="text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:30px 20px;">
                        <?php 
                        $active_hardening = tk_hardening_active_items();
                        $total_possible = 20; 
                        $score = count($active_hardening);
                        $percent = min(100, round(($score / $total_possible) * 100));
                        $score_color = $percent > 70 ? '#27ae60' : ($percent > 40 ? '#f39c12' : '#e74c3c');
                        $dash = round(2 * pi() * 45); 
                        $offset = $dash - ($dash * ($percent / 100));
                        ?>
                        <div style="position:relative; width:100px; height:100px; margin-bottom:20px;">
                            <svg viewBox="0 0 100 100" style="width:100px; height:100px; transform: rotate(-90deg);">
                                <circle cx="50" cy="50" r="45" fill="none" stroke="var(--tk-bg-soft)" stroke-width="8" />
                                <circle cx="50" cy="50" r="45" fill="none" stroke="<?php echo $score_color; ?>" stroke-width="8" stroke-dasharray="<?php echo $dash; ?>" stroke-dashoffset="<?php echo $offset; ?>" stroke-linecap="round" />
                            </svg>
                            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); font-size:20px; font-weight:800; color:var(--tk-text);">
                                <?php echo $percent; ?>%
                            </div>
                        </div>
                        <h3 style="margin:0 0 4px; font-size:16px;">Hardening Score</h3>
                        <p class="description" style="margin-bottom:16px;"><?php printf(__('%d features active', 'tool-kits'), $score); ?></p>
                        <a href="<?php echo esc_url(tk_admin_url(tk_hardening_page_slug())); ?>" class="button button-primary button-small" style="width:100%; border-radius:8px;"><?php _e('Optimize Now', 'tool-kits'); ?></a>
                    </div>
                    
                    <div class="tk-card" style="text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:30px 20px;">
                        <div style="width:60px; height:60px; border-radius:50%; background:var(--tk-bg-soft); display:flex; align-items:center; justify-content:center; margin-bottom:20px; color:var(--tk-primary);">
                            <span class="dashicons dashicons-shield-alt" style="font-size:32px; width:32px; height:32px;"></span>
                        </div>
                        <?php 
                        $fim_last = (int) tk_get_option('tk_fim_last_scan_time', 0);
                        $fim_result = tk_get_option('tk_fim_last_scan_result', array());
                        $fim_status = __('Never Scanned', 'tool-kits');
                        $fim_badge = '';
                        if ($fim_last > 0) {
                            if (empty($fim_result)) {
                                $fim_status = __('Safe', 'tool-kits');
                                $fim_badge = 'tk-on';
                            } else {
                                $fim_status = sprintf(__('%d Altered', 'tool-kits'), count($fim_result));
                                $fim_badge = 'tk-warn';
                            }
                        }
                        ?>
                        <h3 style="margin:0 0 4px; font-size:16px;">File Integrity</h3>
                        <p class="description" style="margin-bottom:16px;"><?php echo $fim_last > 0 ? __('Last scan:', 'tool-kits') . ' ' . date_i18n('Y-m-d H:i', $fim_last) : __('Not scanned yet', 'tool-kits'); ?></p>
                        <div style="display:flex; gap:8px; width:100%;">
                            <span class="tk-badge <?php echo $fim_badge; ?>" style="flex:1; display:flex; align-items:center; justify-content:center;"><?php echo esc_html($fim_status); ?></span>
                            <button type="button" class="button button-small" style="border-radius:8px;" onclick="document.querySelector('[data-panel=integrity]').click();"><?php _e('Scan', 'tool-kits'); ?></button>
                        </div>
                    </div>

                    <div class="tk-card" style="text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:30px 20px;">
                        <div style="width:60px; height:60px; border-radius:50%; background:var(--tk-bg-soft); display:flex; align-items:center; justify-content:center; margin-bottom:20px; color:#e74c3c;">
                            <span class="dashicons dashicons-warning" style="font-size:32px; width:32px; height:32px;"></span>
                        </div>
                        <?php 
                        $spam_log = tk_get_option('antispam_log', array());
                        $spam_count = count($spam_log);
                        ?>
                        <h3 style="margin:0 0 4px; font-size:16px;">Spam Blocked</h3>
                        <p class="description" style="margin-bottom:16px;"><?php printf(__('%d requests caught', 'tool-kits'), $spam_count); ?></p>
                        <a href="<?php echo esc_url(tk_admin_url('tool-kits-security-spam')); ?>" class="button button-small" style="width:100%; border-radius:8px;"><?php _e('View Audit Logs', 'tool-kits'); ?></a>
                    </div>
                </div>
            </div>

            <!-- Panel: Checks -->
            <div class="tk-card tk-tab-panel" data-panel-id="checks">
                <h2><?php _e('Configuration Checks', 'tool-kits'); ?></h2>
                <p><?php _e('Quick scan for common misconfigurations that can expose sensitive files or data.', 'tool-kits'); ?></p>
                <table class="tk-table">
                    <tbody>
                    <?php foreach ($checks as $check) :
                        $status = isset($check['status']) ? $check['status'] : 'unknown';
                        $badge_class = $status === 'ok' ? 'tk-on' : ($status === 'warn' ? 'tk-warn' : '');
                        $badge_label = $status === 'ok' ? __('OK', 'tool-kits') : ($status === 'warn' ? __('Warning', 'tool-kits') : __('Unknown', 'tool-kits'));
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
                <h2><?php _e('Quick Actions', 'tool-kits'); ?></h2>
                <p class="description"><?php _e('Perform critical maintenance and system adjustments instantly.', 'tool-kits'); ?></p>
                
                <div class="tk-grid tk-grid-3" style="gap:24px; margin-top:24px;">
                    <div class="tk-card" style="background:var(--tk-bg-soft); border-radius:16px;">
                        <h4 style="margin:0 0 12px;"><?php _e('System Updates', 'tool-kits'); ?></h4>
                        <p class="description" style="margin-bottom:20px;"><?php _e('Toggle automatic WordPress core background updates.', 'tool-kits'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php tk_nonce_field('tk_toggle_core_updates'); ?>
                            <input type="hidden" name="action" value="tk_toggle_core_updates">
                            <button class="button button-hero <?php echo $core_auto ? '' : 'button-primary'; ?>" name="core_updates" value="<?php echo $core_auto ? '0' : '1'; ?>" style="width: 100%; border-radius:10px;">
                                <?php echo $core_auto ? __('Disable Core Updates', 'tool-kits') : __('Enable Core Updates', 'tool-kits'); ?>
                            </button>
                        </form>
                    </div>

                    <div class="tk-card" style="background:var(--tk-bg-soft); border-radius:16px;">
                        <h4 style="margin:0 0 12px;"><?php _e('Cache Purge', 'tool-kits'); ?></h4>
                        <p class="description" style="margin-bottom:20px;"><?php _e('Flush all registered page and object caches immediately.', 'tool-kits'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php tk_nonce_field('tk_clear_cache'); ?>
                            <input type="hidden" name="action" value="tk_clear_cache">
                            <button class="button button-primary button-hero" style="width: 100%; border-radius:10px;"><?php _e('Clear All Caches', 'tool-kits'); ?></button>
                        </form>
                    </div>

                    <?php if ($wp_config_path !== '') : ?>
                        <div class="tk-card" style="background:var(--tk-bg-soft); border-radius:16px;">
                            <h4 style="margin:0 0 12px;"><?php _e('Wp-Config Guard', 'tool-kits'); ?></h4>
                            <p class="description" style="margin-bottom:20px;"><?php _e('Modify file permissions to prevent unauthorized editing.', 'tool-kits'); ?></p>
                            <?php if (is_writable($wp_config_path)) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php tk_nonce_field('tk_set_wpconfig_readonly'); ?>
                                    <input type="hidden" name="action" value="tk_set_wpconfig_readonly">
                                    <button class="button button-primary button-hero" style="width: 100%; border-radius:10px;"><?php _e('Set to Read-Only', 'tool-kits'); ?></button>
                                </form>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php tk_nonce_field('tk_set_wpconfig_writable'); ?>
                                    <input type="hidden" name="action" value="tk_set_wpconfig_writable">
                                    <button class="button button-hero" style="width: 100%; border-radius:10px;"><?php _e('Make Writable', 'tool-kits'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Panel: Server -->
            <div class="tk-card tk-tab-panel" data-panel-id="server">
                <h2><?php _e('Server Rules', 'tool-kits'); ?></h2>
                <p><strong><?php _e('Server detected:', 'tool-kits'); ?></strong> <?php echo esc_html(strtoupper($server)); ?></p>
                <p><strong><?php _e('Recommended snippet status:', 'tool-kits'); ?></strong>
                    <?php
                    $badge_class = $server_status['status'] === 'ok' ? 'tk-on' : ($server_status['status'] === 'warn' ? 'tk-warn' : '');
                    $badge_label = $server_status['status'] === 'ok' ? __('Applied', 'tool-kits') : ($server_status['status'] === 'warn' ? __('Not detected', 'tool-kits') : __('Unknown', 'tool-kits'));
                    ?>
                    <span class="tk-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_label); ?></span>
                    <span class="description"><?php echo esc_html($server_status['detail']); ?></span>
                </p>
                <?php if (tk_get_option('hardening_server_aware_enabled', 1) && !empty($server_rules)) : ?>
                    <p><strong><?php _e('Server-aware rules', 'tool-kits'); ?></strong></p>
                    <ul class="tk-list">
                        <?php foreach ($server_rules as $rule) : ?>
                            <li>&#10003; <?php echo esc_html($rule); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (tk_get_option('hardening_server_aware_enabled', 1) && $server_snippet !== '') : ?>
                    <p><strong><?php _e('Recommended snippet', 'tool-kits'); ?></strong></p>
                    <pre style="background: #f1f5f9; padding: 15px; border-radius: 8px; font-size: 12px;"><?php echo esc_html($server_snippet); ?></pre>
                <?php endif; ?>
            </div>

            <!-- Panel: Filesystem -->
            <div class="tk-card tk-tab-panel" data-panel-id="filesystem">
                <?php 
                $largest_files = tk_monitoring_get_largest_files(10);
                $total_largest_size = 0;
                $absolute_largest = 0;
                foreach ($largest_files as $f) {
                    $total_largest_size += $f['size'];
                    if ($f['size'] > $absolute_largest) $absolute_largest = $f['size'];
                }
                ?>

                <div class="tk-rt-grid" style="margin-bottom:24px;">
                    <div class="tk-rt-card" style="padding:15px;">
                        <span class="dashicons dashicons-email-alt" style="font-size:20px;"></span>
                        <h4 style="font-size:10px;"><?php _e('Alert Email', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" style="font-size:14px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr((string) $monitor_email); ?>">
                            <?php echo !empty($monitor_email) ? esc_html($monitor_email) : __('Not Set', 'tool-kits'); ?>
                        </div>
                    </div>
                    <div class="tk-rt-card" style="padding:15px;">
                        <span class="dashicons dashicons-cloud" style="font-size:20px;"></span>
                        <h4 style="font-size:10px;"><?php _e('Connection', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" style="font-size:16px;">
                            <?php echo $connection_summary['collector_status'] === 'configured' ? '<span style="color:#27ae60;">Active</span>' : '<span style="color:#94a3b8;">Missing</span>'; ?>
                        </div>
                    </div>
                    <div class="tk-rt-card" style="padding:15px;">
                        <span class="dashicons dashicons-database-export" style="font-size:20px;"></span>
                        <h4 style="font-size:10px;"><?php _e('Root Files Size', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" style="font-size:18px;"><?php echo size_format($total_largest_size); ?></div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px;">
                    <div class="tk-card">
                        <h3 style="display:flex; align-items:center; gap:8px;"><span class="dashicons dashicons-media-archive"></span> <?php _e('Largest Files in Root', 'tool-kits'); ?></h3>
                        <?php if (empty($largest_files)) : ?>
                            <p><em><?php _e('No files detected or scan failed.', 'tool-kits'); ?></em></p>
                        <?php else : ?>
                            <div style="margin-top:15px;">
                                <?php foreach ($largest_files as $f) : 
                                    $p = $absolute_largest > 0 ? round(($f['size'] / $absolute_largest) * 100) : 0;
                                ?>
                                    <div style="margin-bottom:12px;">
                                        <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                                            <code style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70%;"><?php echo esc_html($f['path']); ?></code>
                                            <span style="font-weight:600;"><?php echo size_format($f['size']); ?></span>
                                        </div>
                                        <div class="tk-progress"><div class="tk-progress-bar" style="width:<?php echo $p; ?>%; opacity:<?php echo 0.4 + ($p/200); ?>;"></div></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tk-card">
                        <h3 style="display:flex; align-items:center; gap:8px;"><span class="dashicons dashicons-admin-settings"></span> <?php _e('Settings & Tools', 'tool-kits'); ?></h3>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                            <?php tk_nonce_field('tk_monitoring_save'); ?>
                            <input type="hidden" name="action" value="tk_monitoring_save">
                            <label style="font-size:12px; color:var(--tk-muted);"><?php _e('Alert Email', 'tool-kits'); ?></label>
                            <div style="display:flex; gap:8px; margin-top:4px;">
                                <input style="flex-grow:1;" type="email" name="monitoring_email" value="<?php echo esc_attr((string) $monitor_email); ?>" placeholder="admin@example.com">
                                <button class="button button-primary" name="monitoring_action" value="save"><?php _e('Save', 'tool-kits'); ?></button>
                            </div>
                        </form>

                        <div style="background:var(--tk-bg-soft); padding:12px; border-radius:8px; border:1px solid var(--tk-border-soft); margin-bottom: 16px;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php tk_nonce_field('tk_remove_ds_store'); ?>
                                <input type="hidden" name="action" value="tk_remove_ds_store">
                                <button class="button button-secondary button-small" style="width:100%;"><?php _e('Remove .DS_Store & __MACOSX', 'tool-kits'); ?></button>
                            </form>
                        </div>
                    </div>
                </div>

                <?php if (!empty($noncore_root)) : ?>
                    <div class="tk-card" style="margin-top:20px;">
                        <h3 style="display:flex; align-items:center; gap:8px;"><span class="dashicons dashicons-warning"></span> <?php _e('Non-core Root Entries', 'tool-kits'); ?></h3>
                        <p class="description"><?php _e('Suspicious or extra files detected in WordPress root directory.', 'tool-kits'); ?></p>
                        <ul class="tk-list" style="columns: 2; -webkit-columns: 2; -moz-columns: 2; margin-top:10px;">
                            <?php foreach (array_slice($noncore_root, 0, 50) as $entry) : ?>
                                <li style="font-size:12px;"><code><?php echo esc_html($entry); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Panel: Realtime -->
            <div class="tk-card tk-tab-panel" data-panel-id="realtime">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                    <h2 style="margin:0;"><?php _e('Health Monitor Real-Time', 'tool-kits'); ?></h2>
                    <div id="tk-rt-pulse" class="tk-pulse" title="Live Heartbeat"></div>
                </div>
                <p class="description"><?php _e('Live system metrics refreshed every 5 seconds.', 'tool-kits'); ?></p>

                <div class="tk-rt-grid">
                    <div class="tk-rt-card">
                        <span class="dashicons dashicons-dashboard"></span>
                        <h4><?php _e('CPU Load (1m)', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" id="tk-rt-cpu">-</div>
                        <div class="tk-progress"><div id="tk-rt-cpu-bar" class="tk-progress-bar" style="width:0%; transition: all 0.5s ease-in-out;"></div></div>
                    </div>
                    <div class="tk-rt-card">
                        <span class="dashicons dashicons-performance"></span>
                        <h4><?php _e('Load Time', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" id="tk-rt-load">-</div>
                    </div>
                    <div class="tk-rt-card">
                        <span class="dashicons dashicons-rest-api"></span>
                        <h4><?php _e('AJAX RTT', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" id="tk-rt-rtt">-</div>
                    </div>
                    <div class="tk-rt-card">
                        <span class="dashicons dashicons-database"></span>
                        <h4><?php _e('Memory', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" id="tk-rt-mem">-</div>
                        <div class="tk-progress"><div id="tk-rt-mem-bar" class="tk-progress-bar" style="width:0%; transition: all 0.5s ease-in-out;"></div></div>
                    </div>
                    <div class="tk-rt-card">
                        <span class="dashicons dashicons-warning"></span>
                        <h4><?php _e('Error Rate', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" id="tk-rt-errors">-</div>
                    </div>
                </div>
                
                <div class="tk-card" style="margin-top:20px; border-radius:12px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                        <h3 style="display:flex; align-items:center; gap:8px; margin:0;">
                            <span class="dashicons dashicons-chart-line"></span>
                            <?php _e('CPU Load History (Live)', 'tool-kits'); ?>
                        </h3>
                        <div style="display:flex; align-items:center; gap:16px; font-size:12px; flex-wrap:wrap;">
                            <span style="display:flex;align-items:center;gap:5px;"><span style="display:inline-block;width:12px;height:4px;border-radius:2px;background:#27ae60;"></span><?php _e('Normal (&lt; 2.0)', 'tool-kits'); ?></span>
                            <span style="display:flex;align-items:center;gap:5px;"><span style="display:inline-block;width:12px;height:4px;border-radius:2px;background:#f39c12;"></span><?php _e('Moderate (2–4)', 'tool-kits'); ?></span>
                            <span style="display:flex;align-items:center;gap:5px;"><span style="display:inline-block;width:12px;height:4px;border-radius:2px;background:#e74c3c;"></span><?php _e('High (&gt; 4.0)', 'tool-kits'); ?></span>
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; align-items:stretch;">
                        <!-- Y-Axis Labels -->
                        <div id="tk-rt-cpu-yaxis" style="display:flex; flex-direction:column; justify-content:space-between; align-items:flex-end; font-size:10px; line-height:1; color:var(--tk-muted); width:34px; flex-shrink:0; padding:2px 0 22px;">
                            <span id="tk-rt-cpu-y-max">4.0</span>
                            <span id="tk-rt-cpu-y-mid">2.0</span>
                            <span id="tk-rt-cpu-y-zero">0.0</span>
                        </div>
                        <!-- Chart Area -->
                        <div style="flex:1; min-width:0; position:relative;">
                            <div style="height:150px; width:100%; position:relative; overflow:hidden; border-bottom:1px solid var(--tk-border-soft); border-left:1px solid var(--tk-border-soft);">
                                <!-- Zone bands -->
                                <div id="tk-rt-cpu-zone-red" style="position:absolute; bottom:0; left:0; right:0; background:rgba(231,76,60,0.06); border-top:1px dashed rgba(231,76,60,0.3);"></div>
                                <div id="tk-rt-cpu-zone-yellow" style="position:absolute; bottom:0; left:0; right:0; background:rgba(243,156,18,0.06); border-top:1px dashed rgba(243,156,18,0.3);"></div>
                                <div id="tk-rt-cpu-zone-green" style="position:absolute; bottom:0; left:0; right:0; background:rgba(39,174,96,0.06);"></div>
                                <svg id="tk-rt-cpu-chart" width="100%" height="100%" preserveAspectRatio="none" style="overflow:hidden; position:relative; z-index:1;">
                                    <defs>
                                        <linearGradient id="tk-cpu-area-grad" x1="0" y1="0" x2="0" y2="1">
                                            <stop id="tk-cpu-grad-top" offset="0%" stop-color="#3498db" stop-opacity="0.25"/>
                                            <stop offset="100%" stop-color="#3498db" stop-opacity="0.02"/>
                                        </linearGradient>
                                    </defs>
                                    <polygon id="tk-rt-cpu-area" fill="url(#tk-cpu-area-grad)" points=""></polygon>
                                    <polyline id="tk-rt-cpu-line" fill="none" stroke="#27ae60" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" points=""></polyline>
                                </svg>
                                <!-- CSS dot overlay: not inside SVG to avoid aspect ratio distortion -->
                                <div id="tk-rt-cpu-dot" style="position:absolute; width:10px; height:10px; border-radius:50%; background:#27ae60; border:2px solid #fff; transform:translate(-50%,-50%); pointer-events:none; z-index:2; transition:left 0.4s ease, top 0.4s ease, background 0.4s ease; left:-20px; top:-20px;"></div>
                            </div>
                            <!-- X-Axis time labels -->
                            <div style="display:flex; justify-content:space-between; gap:8px; font-size:10px; line-height:1.2; color:var(--tk-muted); margin-top:6px; padding:0 2px;">
                                <span id="tk-rt-cpu-x-old">&mdash;</span>
                                <span id="tk-rt-cpu-x-mid">&mdash;</span>
                                <span id="tk-rt-cpu-x-now">&mdash;</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel: 404 Monitor -->
            <div class="tk-card tk-tab-panel" data-panel-id="missing">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                    <h2 style="margin:0;"><?php _e('404 Monitor', 'tool-kits'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                        <?php tk_nonce_field('tk_404_clear'); ?>
                        <input type="hidden" name="action" value="tk_404_clear">
                        <button class="button button-secondary button-small" data-confirm="Clear 404 log?"><?php _e('Clear Log', 'tool-kits'); ?></button>
                    </form>
                </div>
                <p class="description"><?php _e('Track missing URLs to fix broken links and spot suspicious scanning activity.', 'tool-kits'); ?></p>
                
                <?php 
                $total_hits = 0;
                $unique_paths = count($log_values);
                $top_path = '-';
                $max_hits = 0;
                foreach ($log_values as $entry) {
                    $hits = (int) $entry['count'];
                    $total_hits += $hits;
                    if ($hits > $max_hits) {
                        $max_hits = $hits;
                        $top_path = $entry['path'];
                    }
                }
                ?>

                <div class="tk-rt-grid" style="margin-bottom:20px;">
                    <div class="tk-rt-card" style="padding:15px;">
                        <span class="dashicons dashicons-chart-bar" style="font-size:20px;"></span>
                        <h4 style="font-size:10px;"><?php _e('Total Hits', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" style="font-size:18px;"><?php echo number_format($total_hits); ?></div>
                    </div>
                    <div class="tk-rt-card" style="padding:15px;">
                        <span class="dashicons dashicons-admin-links" style="font-size:20px;"></span>
                        <h4 style="font-size:10px;"><?php _e('Unique Paths', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" style="font-size:18px;"><?php echo number_format($unique_paths); ?></div>
                    </div>
                    <div class="tk-rt-card" style="padding:15px; grid-column: span 2;">
                        <span class="dashicons dashicons-warning" style="font-size:20px; color:#e74c3c;"></span>
                        <h4 style="font-size:10px;"><?php _e('Top Hotspot', 'tool-kits'); ?></h4>
                        <div class="tk-rt-value" style="font-size:14px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:5px;" title="<?php echo esc_attr($top_path); ?>">
                            <code><?php echo esc_html($top_path); ?></code>
                        </div>
                    </div>
                </div>

                <?php if (empty($log_values)) : ?>
                    <p><em><?php _e('No 404 errors recorded yet.', 'tool-kits'); ?></em></p>
                <?php else : ?>
                    <div class="tk-table-scroll">
                        <table class="widefat striped tk-table">
                            <thead>
                                <tr>
                                    <th>Path</th>
                                    <th style="width:80px;">Hits</th>
                                    <th style="width:150px;">Last Hit</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($log_values, 0, 50) as $entry) : 
                                    $is_high = (int) $entry['count'] > 10;
                                ?>
                                    <tr>
                                        <td><code><?php echo esc_html($entry['path']); ?></code></td>
                                        <td><span class="<?php echo $is_high ? 'tk-badge tk-adv' : ''; ?>"><?php echo (int) $entry['count']; ?></span></td>
                                        <td><small><?php echo date_i18n('Y-m-d H:i', (int) $entry['last']); ?></small></td>
                                        <td>
                                            <div class="tk-log-details">
                                                <table class="tk-mini-table" style="width:100%; border-collapse:collapse; font-size:11px;">
                                                    <?php if (!empty($entry['ref'])) : ?>
                                                        <tr>
                                                            <td style="font-weight:700; width:90px; padding:4px 0; color:#1e293b;">Referer:</td>
                                                            <td style="padding:4px 0; color:#64748b; word-break:break-all;"><?php echo esc_html($entry['ref']); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (!empty($entry['ua'])) : ?>
                                                        <tr>
                                                            <td style="font-weight:700; width:90px; padding:4px 0; color:#1e293b;">Agent:</td>
                                                            <td style="padding:4px 0; color:#64748b; font-size:10px;"><?php echo esc_html($entry['ua']); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php if (empty($entry['ref']) && empty($entry['ua'])) : ?>
                                                        <tr><td style="color:#94a3b8;">&mdash;</td></tr>
                                                    <?php endif; ?>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Panel: Healthcheck -->
            <div class="tk-card tk-tab-panel" data-panel-id="health">
                <?php
                $health_enabled = (int) tk_get_option('monitoring_healthcheck_enabled', 0) === 1;
                $health_time = isset($healthcheck['time']) ? (int) $healthcheck['time'] : 0;
                $health_load = isset($healthcheck['server']['load']) && is_array($healthcheck['server']['load']) ? $healthcheck['server']['load'] : null;
                $health_disk_free = isset($healthcheck['server']['disk_free']) ? $healthcheck['server']['disk_free'] : null;
                $health_disk_total = isset($healthcheck['server']['disk_total']) ? $healthcheck['server']['disk_total'] : null;
                $health_cron_next = isset($healthcheck['cron']['next']) ? $healthcheck['cron']['next'] : null;
                $health_cron_disabled = !empty($healthcheck['cron']['disabled']);
                $health_disk_label = '-';
                if (is_numeric($health_disk_free) && is_numeric($health_disk_total) && (int) $health_disk_total > 0) {
                    $health_disk_used_pct = round((1 - ((int) $health_disk_free / (int) $health_disk_total)) * 100, 1);
                    $health_disk_label = size_format((int) $health_disk_free) . ' free / ' . size_format((int) $health_disk_total) . ' total (' . $health_disk_used_pct . '% used)';
                }
                ?>
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
                    <div>
                        <h2 style="margin:0 0 6px;"><?php _e('Healthcheck', 'tool-kits'); ?></h2>
                        <p class="description" style="margin:0;"><?php _e('Expose a protected JSON endpoint for uptime monitors and collector checks.', 'tool-kits'); ?></p>
                    </div>
                    <span class="tk-badge <?php echo $health_enabled ? 'tk-on' : ''; ?>"><?php echo $health_enabled ? esc_html__('Enabled', 'tool-kits') : esc_html__('Disabled', 'tool-kits'); ?></span>
                </div>

                <div class="tk-grid tk-grid-3" style="gap:20px; margin-bottom:22px;">
                    <div class="tk-card" style="background:var(--tk-bg-soft); border-radius:12px;">
                        <h4 style="margin:0 0 8px;"><?php _e('Endpoint Status', 'tool-kits'); ?></h4>
                        <p style="margin:0; font-size:18px; font-weight:800; color:<?php echo $health_enabled && $health_key !== '' ? '#27ae60' : '#f39c12'; ?>;">
                            <?php echo $health_enabled && $health_key !== '' ? esc_html__('Ready', 'tool-kits') : esc_html__('Needs Setup', 'tool-kits'); ?>
                        </p>
                        <p class="description" style="margin:8px 0 0;"><?php echo $health_time > 0 ? esc_html(date_i18n('Y-m-d H:i:s', $health_time)) : esc_html__('No snapshot yet', 'tool-kits'); ?></p>
                    </div>
                    <div class="tk-card" style="background:var(--tk-bg-soft); border-radius:12px;">
                        <h4 style="margin:0 0 8px;"><?php _e('Load Average', 'tool-kits'); ?></h4>
                        <p style="margin:0; font-size:18px; font-weight:800;">
                            <?php
                            echo is_array($health_load) && isset($health_load[0])
                                ? esc_html(implode(' / ', array_map(static function($value) { return number_format((float) $value, 2); }, array_slice($health_load, 0, 3))))
                                : esc_html__('Unavailable', 'tool-kits');
                            ?>
                        </p>
                        <p class="description" style="margin:8px 0 0;"><?php _e('1m / 5m / 15m', 'tool-kits'); ?></p>
                    </div>
                    <div class="tk-card" style="background:var(--tk-bg-soft); border-radius:12px;">
                        <h4 style="margin:0 0 8px;"><?php _e('WP-Cron', 'tool-kits'); ?></h4>
                        <p style="margin:0; font-size:18px; font-weight:800; color:<?php echo $health_cron_disabled ? '#e74c3c' : 'inherit'; ?>;">
                            <?php echo $health_cron_disabled ? esc_html__('Disabled', 'tool-kits') : esc_html__('Enabled', 'tool-kits'); ?>
                        </p>
                        <p class="description" style="margin:8px 0 0;">
                            <?php echo is_numeric($health_cron_next) && (int) $health_cron_next > 0 ? esc_html__('Next:', 'tool-kits') . ' ' . esc_html(date_i18n('Y-m-d H:i', (int) $health_cron_next)) : esc_html__('Next run unavailable', 'tool-kits'); ?>
                        </p>
                    </div>
                </div>

                <div class="tk-card" style="background:var(--tk-bg-soft); border-radius:12px; margin-bottom:22px;">
                    <h4 style="margin:0 0 12px;"><?php _e('Endpoint URL', 'tool-kits'); ?></h4>
                    <?php if ($health_key === '') : ?>
                        <p class="description" style="margin-top:0;"><?php _e('Set a secret key below before using the external healthcheck endpoint.', 'tool-kits'); ?></p>
                    <?php endif; ?>
                    <code style="display:block; padding:12px; background:#fff; border:1px solid var(--tk-border-soft); border-radius:8px; word-break:break-all;"><?php echo esc_html($health_url); ?></code>
                    <p class="description" style="margin-bottom:0;"><?php echo esc_html__('Disk:', 'tool-kits') . ' ' . esc_html($health_disk_label); ?></p>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tk-card" style="background:var(--tk-bg-soft); border-radius:12px;">
                    <?php tk_nonce_field('tk_healthcheck_save'); ?>
                    <input type="hidden" name="action" value="tk_healthcheck_save">
                    <h4 style="margin:0 0 16px;"><?php _e('Healthcheck Settings', 'tool-kits'); ?></h4>
                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
                        <input type="checkbox" name="monitoring_healthcheck_enabled" value="1" <?php checked($health_enabled); ?>>
                        <span><?php _e('Enable public healthcheck endpoint with secret key protection', 'tool-kits'); ?></span>
                    </label>
                    <label style="display:block; margin-bottom:14px;">
                        <span style="display:block; font-weight:700; margin-bottom:6px;"><?php _e('Secret Key', 'tool-kits'); ?></span>
                        <input class="regular-text" type="text" name="monitoring_healthcheck_key" value="<?php echo esc_attr($health_key); ?>" placeholder="<?php esc_attr_e('Enter a strong random key', 'tool-kits'); ?>" style="width:100%; max-width:520px;">
                    </label>
                    <button class="button button-primary"><?php _e('Save Healthcheck Settings', 'tool-kits'); ?></button>
                </form>
            </div>

            <!-- Panel: Integrity -->
            <div class="tk-card tk-tab-panel" data-panel-id="integrity">
                <h2><?php _e('File Integrity Monitoring (FIM)', 'tool-kits'); ?></h2>
                <p><?php _e('Compare local WordPress core files against official WordPress.org checksums.', 'tool-kits'); ?></p>
                
                <div style="display:flex; gap:10px; margin-bottom:20px;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_fim_scan'); ?>
                        <input type="hidden" name="action" value="tk_fim_scan">
                        <button class="button button-primary"><?php _e('Scan Core Files Now', 'tool-kits'); ?></button>
                    </form>
                </div>
                
                <?php 
                $last_scan = (int) tk_get_option('tk_fim_last_scan_time', 0);
                if ($last_scan > 0) : 
                    $altered_files = tk_get_option('tk_fim_last_scan_result', array());
                ?>
                    <div style="background:var(--tk-bg-soft); padding:20px; border-radius:12px; border:1px solid var(--tk-border-soft);">
                        <p><strong><?php _e('Last Scan:', 'tool-kits'); ?></strong> <?php echo date_i18n('Y-m-d H:i:s', $last_scan); ?></p>
                        <?php if (empty($altered_files)) : ?>
                            <div class="tk-alert tk-alert-success">&#10003; <?php _e('All core files matched WordPress.org checksums.', 'tool-kits'); ?></div>
                        <?php else : ?>
                            <div class="tk-alert tk-alert-error">
                                <h3><?php printf(__('%d altered files detected!', 'tool-kits'), count($altered_files)); ?></h3>
                                <ul style="margin-top: 10px;">
                                    <?php foreach (array_slice($altered_files, 0, 20) as $file) : ?>
                                        <li><code><?php echo esc_html($file); ?></code></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    (function() {
        var c = document.getElementById('tk-monitoring-tabs');
        if (!c) { return; }
        var panels  = c.querySelectorAll('[data-panel-id]');
        var buttons = c.querySelectorAll('[data-panel]');
        function activate(id) {
            panels.forEach(function(p) { p.classList.toggle('is-active', p.getAttribute('data-panel-id') === id); });
            buttons.forEach(function(b) { b.classList.toggle('is-active', b.getAttribute('data-panel') === id); });
            try { history.replaceState(null, null, '#' + id); } catch(e) {}
        }
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var id = btn.getAttribute('data-panel');
                if (id) { activate(id); }
            });
        });
        var hash = window.location.hash.replace('#', '');
        if (hash && c.querySelector('[data-panel-id="' + hash + '"]')) { activate(hash); }
    })();
    </script>
</div>
