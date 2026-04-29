<?php
if (!defined('ABSPATH')) { exit; }
/**
 * @var int $score
 * @var string $score_color
 * @var array $opts
 */
?>
<div class="wrap tk-overview-wrap">
    <?php tk_render_header_branding(); ?>

    <div class="tk-overview-hero">
        <div class="tk-overview-hero-content">
            <h1 class="tk-overview-hero-title"><?php _e('Welcome to Tool Kits', 'tool-kits'); ?></h1>
            <p class="tk-overview-hero-subtitle"><?php _e('Your ultimate suite for WordPress security, optimization, and real-time monitoring.', 'tool-kits'); ?></p>
            
            <div class="tk-overview-hero-actions">
                <a href="<?php echo esc_url(tk_admin_url('tool-kits-monitoring')); ?>" class="button button-primary button-hero">
                    <?php _e('View Live Monitor', 'tool-kits'); ?>
                </a>
                <a href="<?php echo esc_url(tk_admin_url(tk_hardening_page_slug())); ?>" class="button button-hero" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff;">
                    <?php _e('Security Settings', 'tool-kits'); ?>
                </a>
            </div>
        </div>

        <div class="tk-overview-score-box">
            <div class="tk-overview-score-circle">
                <svg viewBox="0 0 36 36">
                    <circle class="bg" cx="18" cy="18" r="15.9155" />
                    <circle class="fg" cx="18" cy="18" r="15.9155" 
                            stroke="<?php echo $score_color; ?>" 
                            stroke-dasharray="<?php echo $score; ?>, 100" />
                </svg>
                <div class="tk-overview-score-value"><?php echo $score; ?>%</div>
            </div>
            <div class="tk-overview-score-label"><?php _e('Security Score', 'tool-kits'); ?></div>
        </div>
    </div>

    <?php if (isset($_GET['tk_waf_reset']) && sanitize_key((string) $_GET['tk_waf_reset']) === '1') : ?>
        <?php tk_notice(__('WAF settings reset to safe defaults.', 'tool-kits'), 'success'); ?>
    <?php endif; ?>

    <?php if (isset($_GET['tk_analytics_saved']) && $_GET['tk_analytics_saved'] === '1') : ?>
        <?php tk_notice(__('Google Analytics settings updated.', 'tool-kits'), 'success'); ?>
    <?php endif; ?>

    <div class="tk-overview-grid">
        <div class="tk-overview-main">
            <h2 class="tk-overview-section-title"><?php _e('Security Modules', 'tool-kits'); ?></h2>
            <p class="tk-overview-section-desc"><?php _e('Quick overview of your active protection layers.', 'tool-kits'); ?></p>
            
            <?php tk_render_security_table(); ?>

            <div class="tk-card" style="margin-top: 40px; border-radius: 16px;">
                <h2 style="margin-top: 0;"><?php _e('Confidence Guide', 'tool-kits'); ?></h2>
                <p class="description" style="margin-bottom: 24px;"><?php _e('Recommended actions to boost your site performance and security score.', 'tool-kits'); ?></p>
                
                <div class="tk-reco-list">
                    <?php
                    $recommendations = array(
                        array(
                            'title' => __('Enable page cache for anonymous visitors', 'tool-kits'),
                            'risk' => 'Safe',
                            'badge' => 'tk-on',
                            'link' => tk_admin_url('tool-kits-cache') . '#page',
                            'icon' => 'dashicons-shield',
                            'color' => 'inherit'
                        ),
                        array(
                            'title' => __('Enable lazy load for images/iframes below the fold', 'tool-kits'),
                            'risk' => 'Safe',
                            'badge' => 'tk-on',
                            'link' => tk_admin_url('tool-kits-optimization') . '#lazy-load',
                            'icon' => 'dashicons-shield',
                            'color' => 'inherit'
                        ),
                        array(
                            'title' => __('Enable Critical CSS and defer non-critical CSS', 'tool-kits'),
                            'risk' => 'Medium risk',
                            'badge' => 'tk-warn',
                            'link' => tk_admin_url('tool-kits-optimization') . '#assets',
                            'icon' => 'dashicons-shield',
                            'color' => '#f39c12'
                        ),
                        array(
                            'title' => __('Enable HTML minify (frontend)', 'tool-kits'),
                            'risk' => 'Medium risk',
                            'badge' => 'tk-warn',
                            'link' => tk_admin_url('tool-kits-optimization') . '#minify',
                            'icon' => 'dashicons-shield',
                            'color' => '#f39c12'
                        ),
                        array(
                            'title' => __('Enable Hide Login (custom login slug)', 'tool-kits'),
                            'risk' => 'Medium risk',
                            'badge' => 'tk-warn',
                            'link' => tk_admin_url('tool-kits-optimization') . '#hide-login',
                            'icon' => 'dashicons-shield',
                            'color' => '#f39c12'
                        ),
                        array(
                            'title' => __('Enable WAF or HTTP Auth protections', 'tool-kits'),
                            'risk' => 'Advanced / expert only',
                            'badge' => 'tk-adv',
                            'link' => tk_admin_url(tk_hardening_page_slug()) . '#waf',
                            'icon' => 'dashicons-shield',
                            'color' => '#e74c3c'
                        ),
                    );

                    foreach ($recommendations as $reco) : ?>
                        <div class="tk-reco-item">
                            <div class="tk-reco-info">
                                <div class="tk-reco-icon" style="color:<?php echo $reco['color']; ?>;"><span class="dashicons <?php echo $reco['icon']; ?>"></span></div>
                                <div class="tk-reco-text">
                                    <h4><?php echo $reco['title']; ?></h4>
                                    <span class="tk-badge <?php echo $reco['badge']; ?>"><?php echo $reco['risk']; ?></span>
                                </div>
                            </div>
                            <div class="tk-reco-action">
                                <a href="<?php echo esc_url($reco['link']); ?>" class="button"><?php _e('Configure', 'tool-kits'); ?></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="tk-overview-sidebar">
            <div class="tk-sidebar-widget" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
                <?php 
                $gtag_id = (string) tk_get_option('google_analytics_gtag_id', '');
                $is_connected = !empty($gtag_id);
                ?>
                <h2>
                    <span class="dashicons dashicons-google" style="color:#4285F4;"></span>
                    <?php _e('Google Analytics', 'tool-kits'); ?>
                    <?php if ($is_connected) : ?>
                        <span class="tk-badge tk-on" style="font-size:10px; margin-left: auto;"><?php _e('Connected', 'tool-kits'); ?></span>
                    <?php else : ?>
                        <span class="tk-badge" style="font-size:10px; margin-left: auto; background:#f1f5f9; color:#64748b;"><?php _e('Not Set', 'tool-kits'); ?></span>
                    <?php endif; ?>
                </h2>
                <p class="description"><?php _e('Enter your Measurement ID to enable tracking.', 'tool-kits'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php tk_nonce_field('tk_analytics_save'); ?>
                    <input type="hidden" name="action" value="tk_analytics_save">
                    <div style="display:flex; gap:8px;">
                        <input type="text" name="google_analytics_gtag_id" 
                               value="<?php echo esc_attr($gtag_id); ?>" 
                               style="flex-grow:1; border-radius:8px;" 
                               placeholder="G-XXXXXXXXXX">
                        <button class="button button-primary" style="border-radius:8px;"><?php _e('Save', 'tool-kits'); ?></button>
                    </div>
                </form>
            </div>

            <div class="tk-sidebar-widget">
                <h2 style="display:flex; align-items:center;">
                    <?php _e('Security Audit', 'tool-kits'); ?> 
                    <span class="tk-badge" style="background: <?php echo $score_color; ?>; color: #fff; margin-left: auto; font-size: 10px; padding: 2px 8px; border-radius: 12px;"><?php echo $score; ?>%</span>
                </h2>
                <div class="tk-audit-list" style="margin-top: 15px;">
                    <?php 
                    $labels = isset($score_data['labels']) ? $score_data['labels'] : array();
                    $active = isset($score_data['active_rules']) ? $score_data['active_rules'] : array();
                    $all_rules = isset($score_data['all_rules']) ? $score_data['all_rules'] : array();
                    foreach ($all_rules as $key => $weight) : 
                        $is_active = in_array($key, $active);
                        $label = isset($labels[$key]) ? $labels[$key] : $key;
                    ?>
                        <div class="tk-stat-row" style="border-bottom: 1px solid #f1f5f9; padding: 8px 0; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                            <span style="font-size: 12px; color: <?php echo $is_active ? '#1e293b' : '#94a3b8'; ?>; display: flex; align-items: center;">
                                <?php if ($is_active) : ?>
                                    <span class="dashicons dashicons-yes" style="color: #22c55e; font-size: 16px; width: 16px; height: 16px; margin-right: 5px;"></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-no-alt" style="color: #e2e8f0; font-size: 16px; width: 16px; height: 16px; margin-right: 5px;"></span>
                                <?php endif; ?>
                                <?php echo esc_html($label); ?>
                            </span>
                            <span style="font-size: 10px; font-weight: 700; color: <?php echo $is_active ? '#22c55e' : '#cbd5e1'; ?>;">
                                <?php echo $is_active ? '+' . $weight . '%' : '0%'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tk-sidebar-widget">
                <h2><?php _e('Quick Stats', 'tool-kits'); ?></h2>
                <div class="tk-stats-content">
                    <?php 
                    $uptime = get_transient('tk_last_heartbeat_time');
                    $uptime_str = $uptime ? human_time_diff($uptime) . ' ' . __('ago', 'tool-kits') : __('Never', 'tool-kits');
                    ?>
                    <div class="tk-stat-row">
                        <span class="tk-stat-label"><?php _e('Last Heartbeat', 'tool-kits'); ?></span>
                        <span class="tk-stat-value"><?php echo esc_html($uptime_str); ?></span>
                    </div>
                    <div class="tk-stat-row">
                        <span class="tk-stat-label"><?php _e('PHP Version', 'tool-kits'); ?></span>
                        <span class="tk-stat-value"><?php echo esc_html(PHP_VERSION); ?></span>
                    </div>
                    <div class="tk-stat-row">
                        <span class="tk-stat-label"><?php _e('WAF Status', 'tool-kits'); ?></span>
                        <span class="tk-stat-value" style="color: <?php echo tk_get_option('waf_enabled',0) ? '#22c55e' : '#94a3b8'; ?>;">
                            <?php echo tk_get_option('waf_enabled',0) ? __('Active', 'tool-kits') : __('Disabled', 'tool-kits'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="tk-tips-widget">
                <h2><?php _e('Expert Tips', 'tool-kits'); ?></h2>
                <div class="tk-tips-content">
                    <?php _e('Enabling <strong>WAF</strong> and <strong>HTTP Auth</strong> together provides powerful dual protection against brute-force attacks and vulnerability exploits.', 'tool-kits'); ?>
                </div>
            </div>
        </div>
    </div>

</div>
