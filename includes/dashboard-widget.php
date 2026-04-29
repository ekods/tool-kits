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
