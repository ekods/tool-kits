<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Initialize Analytics module
 */
function tk_analytics_init(): void {
    add_action('wp_head', 'tk_analytics_render_gtm_head', -100);
    add_action('wp_body_open', 'tk_analytics_render_gtm_body', 1);
    add_action('wp_head', 'tk_analytics_render_gtag', 1);
    add_action('admin_post_tk_analytics_save', 'tk_analytics_save');
    add_action('admin_enqueue_scripts', 'tk_analytics_enqueue_editor');
}

function tk_analytics_enqueue_editor($hook): void {
    if ($hook !== 'toplevel_page_tool-kits' || !tk_toolkits_can_manage() || !current_user_can('unfiltered_html')) {
        return;
    }
    $settings = wp_enqueue_code_editor(array(
        'type' => 'text/html',
        'codemirror' => array('lineNumbers' => true, 'lineWrapping' => true, 'indentUnit' => 2, 'tabSize' => 2, 'lint' => false),
    ));
    wp_enqueue_style('tk-analytics-editor', TK_URL . 'assets/analytics-editor.css', array(), tk_asset_version('assets/analytics-editor.css'));
    wp_enqueue_script('tk-analytics-editor', TK_URL . 'assets/analytics-editor.js', $settings === false ? array() : array('code-editor'), tk_asset_version('assets/analytics-editor.js'), true);
    wp_localize_script('tk-analytics-editor', 'tkAnalyticsEditor', array('settings' => $settings));
}

function tk_analytics_render_gtm_form(): void {
    ?>
    <section class="tk-card tk-analytics-settings" id="google-tag-manager" aria-labelledby="tk-gtm-heading">
        <h2 id="tk-gtm-heading">Google Tag Manager</h2>
        <p class="description">Paste the two installation snippets from your Google Tag Manager container. Leave a field empty to remove that snippet.</p>
        <?php if (current_user_can('unfiltered_html')) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tk-analytics-form">
                <?php tk_nonce_field('tk_analytics_save'); ?>
                <input type="hidden" name="action" value="tk_analytics_save">
                <div class="tk-analytics-field">
                    <label for="tk-google-tag-manager-head"><strong>Head code</strong> <code>&lt;head&gt;</code></label>
                    <p class="description" id="tk-gtm-head-help">Paste the complete script snippet, including its &lt;script&gt; tags. It will appear near the start of the page head.</p>
                    <textarea id="tk-google-tag-manager-head" name="google_tag_manager_head" class="large-text code" rows="12" spellcheck="false" aria-describedby="tk-gtm-head-help" data-tk-analytics-code><?php echo esc_textarea((string) tk_get_option('google_tag_manager_head', '')); ?></textarea>
                </div>
                <div class="tk-analytics-field">
                    <label for="tk-google-tag-manager-body"><strong>Body code</strong> <code>&lt;body&gt;</code></label>
                    <p class="description" id="tk-gtm-body-help">Paste the complete noscript snippet. It will appear immediately after the opening body tag on supported themes.</p>
                    <textarea id="tk-google-tag-manager-body" name="google_tag_manager_body" class="large-text code" rows="7" spellcheck="false" aria-describedby="tk-gtm-body-help" data-tk-analytics-code><?php echo esc_textarea((string) tk_get_option('google_tag_manager_body', '')); ?></textarea>
                </div>
                <p class="description">If this container also installs Google Analytics, use it as your single Analytics installation to avoid duplicate tracking.</p>
                <p><button type="submit" class="button button-primary">Save Google Tag Manager</button> <a href="https://support.google.com/tagmanager/answer/14847097" target="_blank" rel="noopener noreferrer">Installation guide</a></p>
            </form>
        <?php else : ?>
            <p class="description">Your WordPress account does not have permission to edit tracking scripts.</p>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * Save tracking settings. Raw snippets require WordPress's script-editing capability.
 */
function tk_analytics_save(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }

    tk_check_nonce('tk_analytics_save');

    $updates = array();
    foreach (array('google_tag_manager_head', 'google_tag_manager_body', 'google_analytics_gtag_id') as $key) {
        if (!array_key_exists($key, $_POST)) { continue; }
        if ($key !== 'google_analytics_gtag_id' && !current_user_can('unfiltered_html')) { wp_die('Forbidden'); }
        if (!is_string($_POST[$key])) { wp_die('Invalid tracking setting'); }
        $value = trim(tk_post($key));
        $updates[$key] = $key === 'google_analytics_gtag_id' ? sanitize_text_field($value) : $value;
    }
    foreach ($updates as $key => $value) { tk_update_option($key, $value); }

    $redirect = (string) tk_post('_wp_http_referer', admin_url('admin.php?page=tool-kits'));
    wp_safe_redirect(add_query_arg('tk_analytics_saved', '1', $redirect));
    exit;
}

function tk_analytics_render_gtm_head(): void {
    tk_analytics_render_gtm('head');
}

function tk_analytics_render_gtm_body(): void {
    tk_analytics_render_gtm('body');
}

function tk_analytics_render_gtm(string $placement): void {
    if (!in_array($placement, array('head', 'body'), true) || is_admin() || wp_doing_ajax() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
    if (!empty($GLOBALS['tk_analytics_gtm_rendered'][$placement])) { return; }
    $snippet = tk_get_option('google_tag_manager_' . $placement, '');
    if (!is_string($snippet) || trim($snippet) === '') { return; }
    $GLOBALS['tk_analytics_gtm_rendered'][$placement] = true;
    // Raw HTML is intentional: only users with unfiltered_html can save these snippets.
    // Add the current CSP nonce without rewriting the vendor's JavaScript or iframe URL.
    $snippet = preg_replace_callback('/<script\b[^>]*>/i', function ($match) {
        return tk_csp_add_nonce_to_tag($match[0], 'script');
    }, $snippet);
    echo "\n" . $snippet . "\n";
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
            var timer = 0;
            var events = ['mousedown', 'mousemove', 'touchstart', 'scroll', 'keydown'];

            window.__tkGoogleAnalyticsIds = window.__tkGoogleAnalyticsIds || {};

            function cleanup() {
                events.forEach(function(event) {
                    window.removeEventListener(event, loadGA);
                });
                if (timer) {
                    window.clearTimeout(timer);
                    timer = 0;
                }
            }
            
            function loadGA() {
                if (loaded) return;
                loaded = true;
                cleanup();

                // Protect against duplicate execution after partial navigation,
                // cached fragments, or another render of the document head.
                if (window.__tkGoogleAnalyticsIds[gtagId]) return;
                window.__tkGoogleAnalyticsIds[gtagId] = true;
                
                var script = document.createElement('script');
                script.async = true;
                script.dataset.tkGoogleAnalytics = gtagId;
                script.src = 'https://www.googletagmanager.com/gtag/js?id=' + gtagId;
                document.head.appendChild(script);
                
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                window.gtag = gtag;
                gtag('js', new Date());
                gtag('config', gtagId);
                
            }

            // Load on first interaction or after 3.5s delay
            events.forEach(function(event) {
                window.addEventListener(event, loadGA, { passive: true });
            });

            // Fallback for PageSpeed (some crawlers might not trigger events, but we want to stay clean)
            // 3.5s is usually enough to clear the initial LCP/FID window
            timer = window.setTimeout(loadGA, 3500);
        })();
    </script>
    <?php
}
