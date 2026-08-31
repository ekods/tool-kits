<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Register Dashboard Widget
 */
function tk_dashboard_widget_init() {
    add_action('wp_dashboard_setup', 'tk_dashboard_widget_register');
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
}

/**
 * Render Dashboard Widget
 */
function tk_render_dashboard_widget() {
    $score_data = tk_hardening_calculate_score();
    $score = $score_data['score'];
    $score_color = ($score >= 80) ? '#27ae60' : (($score >= 50) ? '#f39c12' : '#e74c3c');
    
    $active_hardening = tk_hardening_active_items();
    $waf_enabled = tk_get_option('waf_enabled', 0);
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
