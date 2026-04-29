<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Initialize Analytics module
 */
function tk_analytics_init(): void {
    add_action('wp_head', 'tk_analytics_render_gtag', 1);
    add_action('admin_post_tk_analytics_save', 'tk_analytics_save');
}

/**
 * Handle saving the Analytics ID
 */
function tk_analytics_save(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }

    tk_check_nonce('tk_analytics_save');

    $gtag_id = trim(sanitize_text_field((string) tk_post('google_analytics_gtag_id', '')));
    tk_update_option('google_analytics_gtag_id', $gtag_id);

    $redirect = (string) tk_post('_wp_http_referer', admin_url('admin.php?page=tool-kits'));
    wp_safe_redirect(add_query_arg('tk_analytics_saved', '1', $redirect));
    exit;
}

/**
 * Render the Google Analytics (gtag.js) script in the head
 */
function tk_analytics_render_gtag(): void {
    $gtag_id = (string) tk_get_option('google_analytics_gtag_id', '');
    
    if (empty($gtag_id)) {
        return;
    }

    if (!preg_match('/^(G|UA|AW|DC)-[A-Z0-9]+$/i', $gtag_id)) {
        return;
    }

    ?>
    <!-- Tool Kits - Optimized Google Analytics -->
    <script>
        (function() {
            var gtagId = '<?php echo esc_js($gtag_id); ?>';
            var loaded = false;
            
            function loadGA() {
                if (loaded) return;
                loaded = true;
                
                var script = document.createElement('script');
                script.async = true;
                script.src = 'https://www.googletagmanager.com/gtag/js?id=' + gtagId;
                document.head.appendChild(script);
                
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                window.gtag = gtag;
                gtag('js', new Date());
                gtag('config', gtagId);
                
                console.log('ToolKits: Google Analytics Lazy Loaded');
            }

            // Load on first interaction or after 3.5s delay
            var events = ['mousedown', 'mousemove', 'touchstart', 'scroll', 'keydown'];
            events.forEach(function(event) {
                window.addEventListener(event, loadGA, { once: true, passive: true });
            });

            // Fallback for PageSpeed (some crawlers might not trigger events, but we want to stay clean)
            // 3.5s is usually enough to clear the initial LCP/FID window
            setTimeout(loadGA, 3500);
        })();
    </script>
    <?php
}
