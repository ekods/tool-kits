<?php
if (!defined('ABSPATH')) { exit; }
/**
 * @var int $score
 * @var string $score_color
 * @var array $opts
 */
$seo_score_data = isset($seo_score_data) && is_array($seo_score_data) ? $seo_score_data : array('score' => 0, 'color' => '#e74c3c', 'checks' => array(), 'link' => '#');
$geo_score_data = isset($geo_score_data) && is_array($geo_score_data) ? $geo_score_data : array('score' => 0, 'color' => '#e74c3c', 'checks' => array(), 'link' => '#');
$seo_score = (int) ($seo_score_data['score'] ?? 0);
$geo_score = (int) ($geo_score_data['score'] ?? 0);
?>
<div class="wrap tk-wrap tk-overview-wrap">
    <?php tk_render_header_branding(); ?>
    <div class="tk-overview-external-notices is-empty" data-tk-overview-external-notices></div>

    <div class="tk-overview-hero">
        <div class="tk-overview-hero-main">
            <div class="tk-page-icon">
                <span class="dashicons dashicons-tool-kits" aria-hidden="true"></span>
            </div>
            <div class="tk-overview-hero-content">
                <h1 class="tk-overview-hero-title"><?php _e('Site overview', 'tool-kits'); ?></h1>
                <p class="tk-overview-hero-subtitle"><?php _e('Review your protection, search visibility, and site performance.', 'tool-kits'); ?></p>
            </div>
        </div>
        <div class="tk-overview-hero-actions">
            <a href="<?php echo esc_url(tk_admin_url('tool-kits-monitoring') . '#realtime'); ?>" class="button button-primary">
                <?php _e('Open live monitor', 'tool-kits'); ?>
            </a>
            <a href="<?php echo esc_url(tk_admin_url(tk_hardening_page_slug())); ?>" class="button">
                <?php _e('Review security settings', 'tool-kits'); ?>
            </a>
        </div>
    </div>

    <section class="tk-readiness-board" aria-label="<?php echo esc_attr(__('Site readiness scores', 'tool-kits')); ?>">
        <?php
        $readiness = array(
            array('label' => __('Security score', 'tool-kits'), 'description' => __('Login protection, firewall, and hardening checks.', 'tool-kits'), 'score' => $score, 'color' => $score_color, 'icon' => 'dashicons-shield', 'link' => tk_admin_url(tk_hardening_page_slug())),
            array('label' => __('SEO score', 'tool-kits'), 'description' => __('Metadata, sitemap, canonical URLs, and indexability.', 'tool-kits'), 'score' => $seo_score, 'color' => $seo_score_data['color'] ?? '#e74c3c', 'icon' => 'dashicons-search', 'link' => $seo_score_data['link'] ?? '#'),
            array('label' => __('GEO score', 'tool-kits'), 'description' => __('Structured content and access for AI search crawlers.', 'tool-kits'), 'score' => $geo_score, 'color' => $geo_score_data['color'] ?? '#e74c3c', 'icon' => 'dashicons-editor-code', 'link' => $geo_score_data['link'] ?? '#'),
        );
        foreach ($readiness as $item) :
            $value = max(0, min(100, (int) $item['score']));
        ?>
            <a class="tk-readiness-item" href="<?php echo esc_url((string) $item['link']); ?>" style="--tk-icon-color:<?php echo esc_attr((string) $item['color']); ?>;">
                <span class="tk-readiness-heading"><span class="dashicons <?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span><?php echo esc_html($item['label']); ?></span>
                <strong class="tk-readiness-value"><?php echo esc_html((string) $value); ?><small>%</small></strong>
                <p><?php echo esc_html($item['description']); ?></p>
                <span class="tk-readiness-meter" aria-hidden="true" style="--tk-score:<?php echo esc_attr((string) $value); ?>%;--tk-score-color:<?php echo esc_attr((string) $item['color']); ?>;"><span></span></span>
            </a>
        <?php endforeach; ?>
    </section>

    <?php if (isset($_GET['tk_waf_reset']) && sanitize_key((string) $_GET['tk_waf_reset']) === '1') : ?>
        <?php tk_notice(__('WAF settings reset to safe defaults.', 'tool-kits'), 'success'); ?>
    <?php endif; ?>

    <?php if (isset($_GET['tk_analytics_saved']) && $_GET['tk_analytics_saved'] === '1') : ?>
        <?php tk_notice(__('Tracking settings updated.', 'tool-kits'), 'success'); ?>
    <?php endif; ?>

    <div class="tk-overview-grid">
        <div class="tk-overview-main">
            <h2 class="tk-overview-section-title"><?php _e('Protection settings', 'tool-kits'); ?></h2>
            <p class="tk-overview-section-desc"><?php _e('Quick overview of your active protection layers.', 'tool-kits'); ?></p>
            
            <?php tk_render_security_table(); ?>

            <?php tk_analytics_render_gtm_form(); ?>

            <div class="tk-card">
                <h2><?php _e('Recommended actions', 'tool-kits'); ?></h2>
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
                                <div class="tk-reco-icon" style="color:<?php echo $reco['color']; ?>;"><span class="dashicons <?php echo $reco['icon']; ?>" aria-hidden="true"></span></div>
                                <div class="tk-reco-text">
                                    <h4><?php echo $reco['title']; ?></h4>
                                    <span class="tk-badge <?php echo $reco['badge']; ?>"><?php echo $reco['risk']; ?></span>
                                </div>
                            </div>
                            <div class="tk-reco-action">
                                <a href="<?php echo esc_url($reco['link']); ?>" class="button" aria-label="<?php echo esc_attr(sprintf(__('Configure: %s', 'tool-kits'), $reco['title'])); ?>"><?php _e('Configure', 'tool-kits'); ?></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="tk-overview-sidebar">
            <div class="tk-sidebar-widget">
                <?php 
                $gtag_id = (string) tk_get_option('google_analytics_gtag_id', '');
                $is_connected = !empty($gtag_id);
                ?>
                <h2>
                    <span class="dashicons dashicons-google" aria-hidden="true" style="color:#4285F4;"></span>
                    <?php _e('Google Analytics', 'tool-kits'); ?>
                    <?php if ($is_connected) : ?>
                        <span class="tk-badge tk-on" style="font-size:13px; margin-left: auto;"><?php _e('Connected', 'tool-kits'); ?></span>
                    <?php else : ?>
                        <span class="tk-badge" style="font-size:13px; margin-left: auto; background:#f1f5f9; color:#64748b;"><?php _e('Not Set', 'tool-kits'); ?></span>
                    <?php endif; ?>
                </h2>
                <p class="description" id="tk-overview-ga-help"><?php _e('Enter your Measurement ID to enable tracking.', 'tool-kits'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php tk_nonce_field('tk_analytics_save'); ?>
                    <input type="hidden" name="action" value="tk_analytics_save">
                    <label class="tk-field-label" for="tk-overview-ga-id"><?php echo esc_html__('Measurement ID', 'tool-kits'); ?></label>
                    <div class="tk-measurement-control">
                        <input type="text" id="tk-overview-ga-id" name="google_analytics_gtag_id" aria-describedby="tk-overview-ga-help" spellcheck="false" autocomplete="off"
                               value="<?php echo esc_attr($gtag_id); ?>" 
                               style="flex-grow:1; border-radius:8px;" 
                               placeholder="G-XXXXXXXXXX">
                        <button class="button button-primary"><?php _e('Save', 'tool-kits'); ?></button>
                    </div>
                </form>
            </div>

            <div class="tk-sidebar-widget">
                <h2 style="display:flex; align-items:center;">
                    <?php _e('Security Audit', 'tool-kits'); ?> 
                    <span class="tk-badge" style="background: <?php echo $score_color; ?>; color: #fff; margin-left: auto; font-size: 13px; padding: 2px 8px; border-radius: 12px;"><?php echo $score; ?>%</span>
                </h2>
                <div class="tk-audit-list" style="margin-top: 15px;">
                    <?php 
                    $labels = isset($score_data['labels']) ? $score_data['labels'] : array();
                    $active = isset($score_data['active_rules']) ? $score_data['active_rules'] : array();
                    $all_rules = isset($score_data['all_rules']) ? $score_data['all_rules'] : array();
                    $links = isset($score_data['links']) ? $score_data['links'] : array();
                    foreach ($all_rules as $key => $weight) : 
                        $is_active = in_array($key, $active);
                        $label = isset($labels[$key]) ? $labels[$key] : $key;
                        $link = isset($links[$key]) ? $links[$key] : '#';
                    ?>
                        <a href="<?php echo esc_url($link); ?>" class="tk-stat-row" style="border-bottom: 1px solid #f1f5f9; padding: 8px 0; display: flex; justify-content: space-between; align-items: center; gap: 10px; text-decoration: none; transition: all 0.2s ease;" onmouseover="this.style.background='#f8fafc';" onmouseout="this.style.background='transparent';">
                            <span style="font-size: 13px; color: <?php echo $is_active ? '#1e293b' : '#646970'; ?>; display: flex; align-items: center;">
                                <?php if ($is_active) : ?>
                                    <span class="dashicons dashicons-yes" aria-hidden="true" style="color: #17623d; font-size: 16px; width: 16px; height: 16px; margin-right: 5px;"></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-no-alt" aria-hidden="true" style="color: #e2e8f0; font-size: 16px; width: 16px; height: 16px; margin-right: 5px;"></span>
                                <?php endif; ?>
                                <?php echo esc_html($label); ?>
                            </span>
                            <span style="font-size: 13px; font-weight: 700; color: <?php echo $is_active ? '#17623d' : '#646970'; ?>;">
                                <?php echo $is_active ? '+' . $weight . '%' : '0%'; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tk-sidebar-widget">
                <h2 style="display:flex; align-items:center;">
                    <?php _e('SEO & GEO Audit', 'tool-kits'); ?>
                    <span class="tk-badge" style="background:#f1f5f9; color:#475569; margin-left:auto; font-size:13px;"><?php echo esc_html((string) round(($seo_score + $geo_score) / 2)); ?>%</span>
                </h2>
                <div class="tk-audit-list" style="margin-top: 15px;">
                    <?php foreach (array_merge((array) ($seo_score_data['checks'] ?? array()), (array) ($geo_score_data['checks'] ?? array())) as $check) : ?>
                        <?php $is_active = !empty($check['ok']); ?>
                        <a href="<?php echo esc_url((string) ($check['link'] ?? '#')); ?>" class="tk-stat-row" style="border-bottom: 1px solid #f1f5f9; padding: 8px 0; display: flex; justify-content: space-between; align-items: center; gap: 10px; text-decoration: none; transition: all 0.2s ease;" onmouseover="this.style.background='#f8fafc';" onmouseout="this.style.background='transparent';">
                            <span style="font-size: 13px; color: <?php echo $is_active ? '#1e293b' : '#646970'; ?>; display: flex; align-items: center;">
                                <?php if ($is_active) : ?>
                                    <span class="dashicons dashicons-yes" aria-hidden="true" style="color: #17623d; font-size: 16px; width: 16px; height: 16px; margin-right: 5px;"></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-no-alt" aria-hidden="true" style="color: #e2e8f0; font-size: 16px; width: 16px; height: 16px; margin-right: 5px;"></span>
                                <?php endif; ?>
                                <?php echo esc_html((string) ($check['label'] ?? '')); ?>
                            </span>
                            <span style="font-size: 13px; font-weight: 700; color: <?php echo $is_active ? '#17623d' : '#646970'; ?>;">
                                <?php echo $is_active ? '+' . esc_html((string) ((int) ($check['weight'] ?? 0))) . '%' : '0%'; ?>
                            </span>
                        </a>
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
                        <span class="tk-stat-value" style="color: <?php echo tk_get_option('waf_enabled',0) ? '#17623d' : '#646970'; ?>;">
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

    <script>
    (function(){
        var target = document.querySelector('[data-tk-overview-external-notices]');
        var hero = document.querySelector('.tk-overview-hero');
        if (!target || !hero) { return; }

        function moveHeroNotices() {
            hero.querySelectorAll('.notice, .updated, .error, .update-nag').forEach(function(notice){
                target.appendChild(notice);
            });
            target.classList.toggle('is-empty', target.children.length === 0);
        }

        moveHeroNotices();
        document.addEventListener('DOMContentLoaded', moveHeroNotices);
        window.setTimeout(moveHeroNotices, 250);
        window.setTimeout(moveHeroNotices, 1000);
    })();
    </script>
</div>
