<?php
if (!defined('ABSPATH')) { exit; }

function tk_cf7_assets_init(): void {
    add_action('admin_post_tk_cf7_assets_save', 'tk_cf7_assets_save');
    add_action('template_redirect', 'tk_cf7_assets_preview_headers', -1);
    // Let CF7 register/localize its scripts first. Never deregister shared dependencies.
    add_action('wp_enqueue_scripts', 'tk_cf7_assets_apply', PHP_INT_MAX);
    add_action('admin_bar_menu', 'tk_cf7_assets_preview_bar', 100);
}

function tk_cf7_assets_mode(): string {
    $mode = tk_get_option('cf7_assets_mode', 'off');
    return in_array($mode, array('off', 'preview', 'live'), true) ? $mode : 'off';
}

function tk_cf7_assets_preview_user(): bool {
    return is_user_logged_in() && tk_is_admin_user();
}

function tk_cf7_assets_active(): bool {
    $mode = tk_cf7_assets_mode();
    if ($mode === 'off' || ($mode === 'preview' && !tk_cf7_assets_preview_user())) { return false; }
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
        || is_feed() || is_trackback() || is_robots() || !function_exists('wpcf7')
        || !tk_license_features_enabled()) {
        return false;
    }
    return true;
}

function tk_cf7_assets_preview_headers(): void {
    if (tk_cf7_assets_mode() !== 'preview' || !tk_cf7_assets_active()) { return; }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();
}

/** Accept exact URL paths, post IDs, or * for a sitewide template/popup form. */
function tk_cf7_assets_parse_rules(string $raw) {
    if (strlen($raw) > 32768) {
        return new WP_Error('cf7_rules', 'The always-load list is too large.');
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $rules = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        if ($line === '*') {
            $rules[] = '*';
        } elseif (preg_match('/^[1-9][0-9]{0,9}$/D', $line)) {
            $rules[] = $line;
        } elseif (strlen($line) <= 512 && substr($line, 0, 1) === '/'
            && substr($line, 0, 2) !== '//'
            && !preg_match('/[\s\x00-\x1f\x7f?#*<>"\'\\\\]/', $line)) {
            $rules[] = rtrim($line, '/') ?: '/';
        } else {
            return new WP_Error('cf7_rules', 'Use one post/page ID or exact path per line (for example /contact/). Use * to always load sitewide. URLs, query strings and path wildcards are not supported.');
        }
        if (count($rules) > 200) {
            return new WP_Error('cf7_rules', 'Use no more than 200 always-load entries.');
        }
    }
    return array_values(array_unique($rules));
}

/** Bounded inspection of queried content and reusable blocks; never render shortcodes. */
function tk_cf7_assets_content_needs_assets(string $content, array &$seen, int &$budget, int $depth = 0): bool {
    if (--$budget < 0 || $depth > 8 || strlen($content) > 262144) { return true; }
    if (has_shortcode($content, 'contact-form-7')
        || has_block('contact-form-7/contact-form-selector', $content)) {
        return true;
    }
    if (strpos($content, '<!-- wp:') === false) { return false; }
    $blocks = parse_blocks($content);
    while ($blocks) {
        if (--$budget < 0) { return true; }
        $block = array_pop($blocks);
        if (($block['blockName'] ?? '') === 'core/block' && !empty($block['attrs']['ref'])) {
            $id = (int) $block['attrs']['ref'];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $post = get_post($id);
                if (!$post || tk_cf7_assets_content_needs_assets((string) $post->post_content, $seen, $budget, $depth + 1)) {
                    return true;
                }
            }
        }
        foreach (($block['innerBlocks'] ?? array()) as $inner) { $blocks[] = $inner; }
    }
    return false;
}

function tk_cf7_assets_should_load(): bool {
    $rules = tk_cf7_assets_parse_rules((string) tk_get_option('cf7_assets_always_load', ''));
    // Invalid imported settings should preserve working forms.
    if (is_wp_error($rules) || in_array('*', $rules, true)) { return true; }
    if (is_singular() && in_array((string) get_queried_object_id(), $rules, true)) { return true; }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = wp_parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || substr($path, 0, 1) !== '/') { return true; }
    if (in_array(rtrim($path, '/') ?: '/', $rules, true)) { return true; }

    global $wp_query;
    if (!isset($wp_query->posts) || !is_array($wp_query->posts) || count($wp_query->posts) > 50) { return true; }
    $seen = array();
    $budget = 200;
    foreach ($wp_query->posts as $post) {
        if (!is_object($post) || !isset($post->post_content)) { return true; }
        if (tk_cf7_assets_content_needs_assets((string) $post->post_content, $seen, $budget)) { return true; }
    }
    // Themes/builders may declare template forms here without changing stored settings.
    return (bool) apply_filters('tk_cf7_assets_should_load', false);
}

/** Keep CF7 handles required, directly or indirectly, by another queued asset. */
function tk_cf7_assets_dequeue($registry, array $targets, string $type): array {
    $stack = array_values(array_diff($registry->queue, $targets));
    $visited = array();
    while ($stack) {
        $handle = array_pop($stack);
        if (isset($visited[$handle])) { continue; }
        $visited[$handle] = true;
        if (isset($registry->registered[$handle])) {
            foreach ((array) $registry->registered[$handle]->deps as $dep) { $stack[] = $dep; }
        }
    }
    $removed = array();
    foreach ($targets as $handle) {
        if (isset($visited[$handle]) || !in_array($handle, $registry->queue, true)) { continue; }
        if ($type === 'script') { wp_dequeue_script($handle); }
        else { wp_dequeue_style($handle); }
        $removed[] = $handle;
    }
    return $removed;
}

function tk_cf7_assets_apply(): void {
    if (!tk_cf7_assets_active()) { return; }
    $GLOBALS['tk_cf7_assets_result'] = array('needed' => true, 'removed' => array());
    if (tk_cf7_assets_should_load()) { return; }
    $scripts = tk_cf7_assets_dequeue(wp_scripts(), array('contact-form-7', 'contact-form-7-html5-fallback', 'wpcf7-recaptcha'), 'script');
    $styles = tk_cf7_assets_dequeue(wp_styles(), array('contact-form-7', 'contact-form-7-rtl'), 'style');
    $GLOBALS['tk_cf7_assets_result'] = array('needed' => false, 'removed' => array_merge($scripts, $styles));
}

function tk_cf7_assets_preview_bar($bar): void {
    if (tk_cf7_assets_mode() !== 'preview' || !tk_cf7_assets_active()) { return; }
    $result = $GLOBALS['tk_cf7_assets_result'] ?? null;
    $status = !$result ? 'waiting for asset loading' : ($result['needed'] ? 'assets kept for this page' : (empty($result['removed']) ? 'assets kept by dependencies or already absent' : 'unused assets skipped'));
    $bar->add_node(array(
        'id' => 'tk-cf7-assets-preview',
        'title' => 'CF7 preview: ' . $status,
        'href' => admin_url('admin.php?page=tool-kits-assets#tk-cf7-assets'),
    ));
}

function tk_cf7_assets_save(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Method not allowed', '', array('response' => 405)); }
    tk_require_admin_post('tk_cf7_assets_save');
    if (!tk_license_features_enabled()) { wp_die('An active Tool Kits license is required.'); }
    $reset = isset($_POST['cf7_assets_reset']);
    $mode = $reset ? 'off' : (isset($_POST['cf7_assets_mode']) && is_string($_POST['cf7_assets_mode']) ? wp_unslash($_POST['cf7_assets_mode']) : '');
    if (!in_array($mode, array('off', 'preview', 'live'), true)) { wp_die('Invalid Contact Form 7 asset mode.'); }
    $raw = $reset ? '' : (isset($_POST['cf7_assets_always_load']) && is_string($_POST['cf7_assets_always_load']) ? wp_unslash($_POST['cf7_assets_always_load']) : '');
    $rules = tk_cf7_assets_parse_rules($raw);
    if (is_wp_error($rules)) { wp_die(esc_html($rules->get_error_message())); }
    tk_update_option('cf7_assets_always_load', implode("\n", $rules));
    tk_update_option('cf7_assets_mode', $mode);
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-assets&tk_cf7_saved=1#tk-cf7-assets'));
    exit;
}

function tk_cf7_assets_render_panel(): void {
    if (!tk_is_admin_user()) { return; }
    $mode = tk_cf7_assets_mode();
    ?>
    <div class="tk-card" id="tk-cf7-assets">
        <div class="tk-section-heading">
            <div>
                <h2>Contact Form 7 Asset Loading</h2>
                <p class="description">Load form styles and scripts only on pages that need them.</p>
            </div>
            <span class="tk-badge <?php echo function_exists('wpcf7') ? 'tk-on' : 'tk-warn'; ?>"><?php echo function_exists('wpcf7') ? 'CF7 detected' : 'CF7 inactive'; ?></span>
        </div>
        <?php if (isset($_GET['tk_cf7_saved']) && $_GET['tk_cf7_saved'] === '1') { tk_notice('Contact Form 7 asset settings saved.', 'success'); } ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_cf7_assets_save'); ?>
            <input type="hidden" name="action" value="tk_cf7_assets_save">
            <div class="tk-setting-section">
                <div><label for="tk-cf7-assets-mode">Loading mode</label><p class="description">Start with Preview to test the changes.</p></div>
                <div>
                <select id="tk-cf7-assets-mode" name="cf7_assets_mode">
                    <option value="off" <?php selected($mode, 'off'); ?>>Default — leave CF7 loading unchanged</option>
                    <option value="preview" <?php selected($mode, 'preview'); ?>>Preview — Tool Kits managers only</option>
                    <option value="live" <?php selected($mode, 'live'); ?>>Live — load only where needed</option>
                </select>
                    <p class="description">Preview applies only to logged-in Tool Kits managers. Public visitors keep the default behavior. Open a frontend page while logged in and check the CF7 preview indicator in the admin toolbar. Preview responses are not cached.</p>
                </div>
            </div>
            <div class="tk-setting-section">
                <div><label for="tk-cf7-assets-always">Always-load pages</label><p class="description">Keep assets available for popup, widget or template forms.</p></div>
                <div>
                <textarea id="tk-cf7-assets-always" name="cf7_assets_always_load" rows="5" class="large-text code" aria-describedby="tk-cf7-assets-help" placeholder="/contact/&#10;/products/&#10;123"><?php echo esc_textarea((string) tk_get_option('cf7_assets_always_load', '')); ?></textarea>
            <p id="tk-cf7-assets-help" class="description">One exact URL path or post/page ID per line, up to 200 entries. Use / for the homepage, include the site subfolder if any, or * for forms shown sitewide. Query strings and trailing slashes do not affect path matching.</p>
            <p class="description">Shortcodes, CF7 blocks and reusable blocks in queried content are detected automatically. Add pages with popup, widget, page-builder or theme-template forms here before using Live mode. This does not inspect every template or dynamically injected form.</p>
                </div>
            </div>
            <details class="tk-cf7-notes">
                <summary>Testing and cache notes</summary>
                <p class="description">Shared dependencies and CF7 assets required by other queued scripts/styles are preserved. Form editing, submissions, anti-spam and SMTP settings stay in their existing modules. Test validation, captcha and successful submission in Preview before switching to Live.</p>
                <p class="description">After saving Live settings or resetting, purge any external page/CDN cache so visitors receive the updated asset list.</p>
            </details>
            <p class="tk-action-row">
                <button class="button button-primary" type="submit">Save CF7 Settings</button>
                <button class="button button-secondary" type="submit" name="cf7_assets_reset" value="1">Reset to CF7 Defaults</button>
                <a class="button button-secondary" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener">Open Website</a>
            </p>
        </form>
    </div>
    <?php
}
