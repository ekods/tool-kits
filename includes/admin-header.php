<?php
if (!defined('ABSPATH')) { exit; }

function tk_render_page_hero($title, $description, $icon = 'dashicons-tool-kits', $action_html = '') {
    ?>
    <div class="tk-hero tk-page-heading tk-card">
        <div class="tk-hero-content">
            <div class="tk-page-heading-main">
                <div class="tk-page-icon">
                    <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                </div>
                <div>
                    <h1 class="tk-hero-title"><?php echo esc_html($title); ?></h1>
                    <p class="tk-hero-subtitle"><?php echo esc_html($description); ?></p>
                </div>
            </div>
            <?php if ($action_html !== '') : ?>
                <div class="tk-hero-action">
                    <?php echo $action_html; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function tk_render_header_branding() {
    ?>
    <div class="tk-header-branding">
        <div class="tk-header-brand">
            <span class="dashicons dashicons-tool-kits tk-brand-icon" aria-hidden="true"></span>
            <span>Tool Kits Pro</span>
            <span class="tk-header-version">v<?php echo TK_VERSION; ?></span>
        </div>

        <div class="tk-header-statuses">
            <?php
            $ga_id = tk_get_option('google_analytics_gtag_id', '');
            if ($ga_id) : ?>
                <a class="tk-header-status" href="<?php echo esc_url(tk_admin_url('tool-kits') . '#google-analytics'); ?>" title="Open Google Analytics settings">
                    <span class="tk-status-dot" style="background:#22c55e;" aria-hidden="true"></span>
                    <span class="tk-header-status-text">GA Connected: <?php echo esc_html($ga_id); ?></span>
                    <span class="tk-header-status-arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                </a>
            <?php endif; ?>

            <?php if (function_exists('tk_analytics_gtm_status')) :
                $gtm_status = tk_analytics_gtm_status();
                $gtm_dot = $gtm_status['state'] === 'configured' ? '#22c55e' : ($gtm_status['state'] === 'not-set' ? '#94a3b8' : '#f59e0b');
            ?>
                <a class="tk-header-status" href="<?php echo esc_url(tk_admin_url('tool-kits') . '#google-tag-manager'); ?>" title="<?php echo esc_attr('Open Google Tag Manager settings. ' . $gtm_status['message']); ?>" data-tk-gtm-status="<?php echo esc_attr($gtm_status['state']); ?>">
                    <span class="tk-status-dot" style="background:<?php echo esc_attr($gtm_dot); ?>;" aria-hidden="true"></span>
                    <span class="tk-header-status-text">GTM <?php echo esc_html($gtm_status['label'] . ($gtm_status['id'] !== '' ? ': ' . $gtm_status['id'] : '')); ?></span>
                    <span class="tk-header-status-arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                </a>
            <?php endif; ?>

            <?php
            $heartbeat_success = (int) tk_get_option('heartbeat_last_success', 0);
            $heartbeat_fail = (int) tk_get_option('heartbeat_last_failure', 0);
            $is_online = $heartbeat_success > $heartbeat_fail;
            $hb_color = $is_online ? '#22c55e' : ($heartbeat_fail > 0 ? '#e74c3c' : '#94a3b8');
            $hb_text = $is_online ? 'Collector Online' : ($heartbeat_fail > 0 ? 'Collector Offline' : 'Collector Not Connected');
            ?>
            <a class="tk-header-status" href="<?php echo esc_url(tk_admin_url('tool-kits-access') . '#license'); ?>" title="Open Collector connection settings">
                <span class="tk-status-dot" style="background:<?php echo $hb_color; ?>;" aria-hidden="true"></span>
                <span class="tk-header-status-text"><?php echo esc_html($hb_text); ?></span>
                <span class="tk-header-status-arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
            </a>

        </div>
    </div>
    <div class="tk-external-notices is-empty" data-tk-external-notices></div>
    <style>
        .tk-header-branding {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,250,252,0.94));
            gap: 16px;
            padding: 16px 18px;
            border-radius: 14px;
            border: 1px solid rgba(202, 213, 226, 0.8);
            margin-bottom: 24px;
            box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 16px 42px rgba(15,23,42,0.07);
        }
        .tk-header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 760;
            font-size: 16px;
            color: #172033;
            white-space: nowrap;
        }
        .tk-header-brand .dashicons {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: linear-gradient(135deg, #2457e6, #0891b2);
            border-radius: 11px;
            font-size: 18px;
            width: 36px;
            height: 36px;
            box-shadow: 0 10px 22px rgba(36, 87, 230, 0.24);
        }
        .tk-header-version {
            font-weight: 700;
            color: var(--tk-muted);
            font-size: 12px;
            background: #eef2f7;
            padding: 4px 8px;
            border-radius: 999px;
        }
        .tk-header-statuses {
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:10px;
            min-width:0;
            flex-wrap:wrap;
        }
        .tk-header-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--tk-muted);
            background: rgba(255, 255, 255, 0.78);
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .tk-status-dot {
            width: 8px;
            height: 8px;
            background: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2);
            animation: tk-pulse 2s infinite;
        }
        .tk-header-status[data-tk-gtm-status] {
            max-width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        .tk-header-status[data-tk-gtm-status] .tk-status-dot { flex-shrink: 0; }
        .tk-external-notices {
            margin: 0 0 24px;
        }
        .tk-external-notices.is-empty {
            display: none;
        }
        .tk-external-notices .notice,
        .tk-external-notices .updated,
        .tk-external-notices .error,
        .tk-external-notices .update-nag {
            box-sizing: border-box;
            width: 100%;
            margin: 0 0 12px;
            border-radius: 10px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }
        @keyframes tk-pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }
        @media (max-width: 782px) {
            .tk-header-branding {
                align-items: flex-start;
                flex-direction: column;
            }
            .tk-header-statuses {
                justify-content: flex-start;
            }
        }
    </style>
    <script>
    (function(){
        var moving = false;
        var noticeSelector = '.notice, .updated, .error, .update-nag';
        var heroSelector = '.tk-hero, .tk-overview-hero';

        function findTarget(hero) {
            var wrap = hero.closest('.tk-wrap, .tk-overview-wrap') || document;
            return wrap.querySelector('[data-tk-external-notices]') || wrap.querySelector('[data-tk-overview-external-notices]');
        }

        function syncTargetState(target) {
            if (!target) { return; }
            target.classList.toggle('is-empty', target.children.length === 0);
        }

        function moveHeroNotices() {
            if (moving) { return; }
            moving = true;

            document.querySelectorAll(heroSelector).forEach(function(hero){
                var target = findTarget(hero);
                if (!target) { return; }

                hero.querySelectorAll(noticeSelector).forEach(function(notice){
                    if (notice.closest('[data-tk-external-notices], [data-tk-overview-external-notices]')) {
                        return;
                    }
                    target.appendChild(notice);
                });

                syncTargetState(target);
            });

            moving = false;
        }

        moveHeroNotices();
        document.addEventListener('DOMContentLoaded', moveHeroNotices);
        window.setTimeout(moveHeroNotices, 100);
        window.setTimeout(moveHeroNotices, 500);
        window.setTimeout(moveHeroNotices, 1500);

        if (window.MutationObserver && document.body) {
            var observer = new MutationObserver(moveHeroNotices);
            observer.observe(document.body, { childList: true, subtree: true });
            window.setTimeout(function(){
                observer.disconnect();
                moveHeroNotices();
            }, 4000);
        }
    })();
    </script>
    <?php
}
