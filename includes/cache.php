<?php
if (!defined('ABSPATH')) { exit; }

function tk_cache_init() {
    add_action('admin_post_tk_cache_save', 'tk_cache_save');
    add_action('admin_post_tk_cache_purge', 'tk_cache_purge');
    add_action('admin_post_tk_cache_object_flush', 'tk_cache_object_flush');
    add_action('admin_post_tk_cache_opcache_reset', 'tk_cache_opcache_reset');
    add_action('admin_post_tk_fragment_cache_flush', 'tk_fragment_cache_flush');

    add_action('template_redirect', 'tk_page_cache_maybe_serve', 0);
    add_action('template_redirect', 'tk_page_cache_start_buffer', 1);

    add_action('save_post', 'tk_page_cache_purge');
    add_action('deleted_post', 'tk_page_cache_purge');
    add_action('transition_comment_status', 'tk_page_cache_purge');
}

function tk_cache_dir() {
    return trailingslashit(WP_CONTENT_DIR) . 'cache/tool-kits';
}

function tk_page_cache_dir() {
    return trailingslashit(tk_cache_dir()) . 'page';
}

function tk_page_cache_key() {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $scheme = is_ssl() ? 'https' : 'http';
    return md5($scheme . '|' . $host . '|' . $uri);
}

function tk_page_cache_path() {
    $dir = tk_page_cache_dir();
    return trailingslashit($dir) . tk_page_cache_key() . '.html';
}

function tk_cache_is_cacheable_request() {
    if (!tk_get_option('page_cache_enabled', 0)) {
        return false;
    }
    if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
        return false;
    }
    if (is_admin() || wp_doing_ajax() || is_feed() || is_preview()) {
        return false;
    }
    if (is_user_logged_in()) {
        return false;
    }
    if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'GET') {
        return false;
    }
    if (is_404()) {
        return false;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = strtok($path, '?');
    if ($path === '') {
        return false;
    }
    $excludes = tk_get_option('page_cache_exclude_paths', "/wp-login.php\n/wp-admin\n");
    $list = array_filter(array_map('trim', explode("\n", (string) $excludes)));
    foreach ($list as $item) {
        if ($item === '') {
            continue;
        }
        if (strpos($path, $item) === 0) {
            return false;
        }
    }
    return true;
}

function tk_page_cache_maybe_serve() {
    if (!tk_cache_is_cacheable_request()) {
        return;
    }
    $path = tk_page_cache_path();
    if (!file_exists($path)) {
        return;
    }
    $ttl = (int) tk_get_option('page_cache_ttl', 3600);
    if ($ttl > 0 && (time() - filemtime($path)) > $ttl) {
        @unlink($path);
        return;
    }
    if (!headers_sent()) {
        header('X-Tool-Kits-Cache: HIT');
    }
    readfile($path);
    exit;
}

function tk_page_cache_start_buffer() {
    if (!tk_cache_is_cacheable_request()) {
        return;
    }
    ob_start('tk_page_cache_callback');
}

function tk_page_cache_callback($html) {
    if (!tk_cache_is_cacheable_request()) {
        return $html;
    }
    $code = function_exists('http_response_code') ? http_response_code() : 200;
    if ($code !== 200) {
        return $html;
    }
    $dir = tk_page_cache_dir();
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    if (is_dir($dir)) {
        $path = tk_page_cache_path();
        $tmp = $path . '.tmp';
        $written = @file_put_contents($tmp, $html);
        if ($written !== false) {
            @rename($tmp, $path);
        }
    }
    if (!headers_sent()) {
        header('X-Tool-Kits-Cache: MISS');
    }
    return $html;
}

function tk_page_cache_purge() {
    $dir = tk_page_cache_dir();
    if (!is_dir($dir)) {
        return;
    }
    $files = glob($dir . '/*.html');
    if (is_array($files)) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

function tk_cache_save() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_save');

    tk_update_option('page_cache_enabled', !empty($_POST['page_cache_enabled']) ? 1 : 0);
    tk_update_option('page_cache_ttl', max(0, (int) tk_post('page_cache_ttl', 3600)));
    tk_update_option('page_cache_exclude_paths', (string) tk_post('page_cache_exclude_paths', "/wp-login.php\n/wp-admin\n"));

    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_updated=1'));
    exit;
}

function tk_cache_purge() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_purge');
    tk_page_cache_purge();
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_purged=1'));
    exit;
}

function tk_cache_object_flush() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_object_flush');
    $ok = function_exists('wp_cache_flush') ? wp_cache_flush() : false;
    set_transient('tk_cache_object_flush', $ok ? 'ok' : 'fail', 30);
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_object=' . ($ok ? 'ok' : 'fail')));
    exit;
}

function tk_cache_opcache_reset() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_cache_opcache_reset');
    $ok = false;
    if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
        $ok = @opcache_reset();
    }
    set_transient('tk_cache_opcache_reset', $ok ? 'ok' : 'fail', 30);
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_opcache=' . ($ok ? 'ok' : 'fail')));
    exit;
}

function tk_fragment_cache_get($key) {
    $cache_key = 'tk_frag_' . md5((string) $key);
    return get_transient($cache_key);
}

function tk_fragment_cache_set($key, $value, $ttl = 300) {
    $cache_key = 'tk_frag_' . md5((string) $key);
    $ttl = max(1, (int) $ttl);
    set_transient($cache_key, $value, $ttl);
    $keys = tk_get_option('fragment_cache_keys', array());
    if (!is_array($keys)) {
        $keys = array();
    }
    if (!in_array($cache_key, $keys, true)) {
        $keys[] = $cache_key;
        if (count($keys) > 200) {
            $keys = array_slice($keys, -200);
        }
        tk_update_option('fragment_cache_keys', $keys);
    }
}

function tk_fragment_cache_flush() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_fragment_cache_flush');
    $keys = tk_get_option('fragment_cache_keys', array());
    if (is_array($keys)) {
        foreach ($keys as $cache_key) {
            delete_transient($cache_key);
        }
    }
    tk_update_option('fragment_cache_keys', array());
    wp_redirect(admin_url('admin.php?page=tool-kits-cache&tk_fragment=ok'));
    exit;
}

function tk_cache_render_status_rows() {
    $object = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    $redis = function_exists('wp_cache_get') && defined('WP_REDIS_VERSION');
    $opcache = function_exists('opcache_get_status') && ini_get('opcache.enable');
    ?>
    <table class="widefat striped tk-table">
        <thead><tr><th>Cache</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody>
            <tr>
                <td>Page cache</td>
                <td><?php echo tk_get_option('page_cache_enabled', 0) ? '<span class="tk-badge tk-on">ON</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td>File-based HTML cache for anonymous GET requests.</td>
            </tr>
            <tr>
                <td>Object cache</td>
                <td><?php echo $object ? '<span class="tk-badge tk-on">PERSISTENT</span>' : '<span class="tk-badge">DEFAULT</span>'; ?></td>
                <td><?php echo $object ? 'External object cache enabled.' : 'Using default non-persistent cache.'; ?></td>
            </tr>
            <tr>
                <td>Redis</td>
                <td><?php echo $redis ? '<span class="tk-badge tk-on">ENABLED</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td><?php echo $redis ? 'Redis object cache detected.' : 'Redis not detected.'; ?></td>
            </tr>
            <tr>
                <td>Opcode cache</td>
                <td><?php echo $opcache ? '<span class="tk-badge tk-on">ENABLED</span>' : '<span class="tk-badge">OFF</span>'; ?></td>
                <td><?php echo $opcache ? 'OPcache available in PHP.' : 'OPcache not enabled.'; ?></td>
            </tr>
            <tr>
                <td>Fragment cache</td>
                <td><span class="tk-badge">ON DEMAND</span></td>
                <td>Use helper functions for specific blocks.</td>
            </tr>
        </tbody>
    </table>
    <?php
}

function tk_render_cache_page() {
    if (!tk_is_admin_user()) return;
    $updated = isset($_GET['tk_updated']) ? sanitize_key($_GET['tk_updated']) : '';
    $purged = isset($_GET['tk_purged']) ? sanitize_key($_GET['tk_purged']) : '';
    $object = isset($_GET['tk_object']) ? sanitize_key($_GET['tk_object']) : '';
    $opcache = isset($_GET['tk_opcache']) ? sanitize_key($_GET['tk_opcache']) : '';
    $fragment = isset($_GET['tk_fragment']) ? sanitize_key($_GET['tk_fragment']) : '';
    ?>
    <div class="wrap tk-wrap">
        <h1>Cache</h1>
        <?php if ($updated === '1') : ?>
            <?php tk_notice('Cache settings saved.', 'success'); ?>
        <?php endif; ?>
        <?php if ($purged === '1') : ?>
            <?php tk_notice('Page cache cleared.', 'success'); ?>
        <?php endif; ?>
        <?php if ($object === 'ok') : ?>
            <?php tk_notice('Object cache flushed.', 'success'); ?>
        <?php elseif ($object === 'fail') : ?>
            <?php tk_notice('Object cache flush failed.', 'error'); ?>
        <?php endif; ?>
        <?php if ($opcache === 'ok') : ?>
            <?php tk_notice('Opcode cache reset.', 'success'); ?>
        <?php elseif ($opcache === 'fail') : ?>
            <?php tk_notice('Opcode cache reset failed or unavailable.', 'error'); ?>
        <?php endif; ?>
        <?php if ($fragment === 'ok') : ?>
            <?php tk_notice('Fragment cache cleared.', 'success'); ?>
        <?php endif; ?>

        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="status">Status</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="page">Page Cache</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="object">Object Cache</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="opcode">Opcode Cache</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="fragment">Fragment Cache</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="status">
                    <h2>Cache Status</h2>
                    <?php tk_cache_render_status_rows(); ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="page">
                    <h2>Page Cache (HTML statis)</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_cache_save'); ?>
                        <input type="hidden" name="action" value="tk_cache_save">
                        <p>
                            <label>
                                <input type="checkbox" name="page_cache_enabled" value="1" <?php checked(1, tk_get_option('page_cache_enabled', 0)); ?>>
                                Enable page cache for anonymous visitors
                            </label>
                        </p>
                        <p>
                            <label>TTL (seconds)</label><br>
                            <input type="number" name="page_cache_ttl" value="<?php echo esc_attr((string) tk_get_option('page_cache_ttl', 3600)); ?>" min="0">
                            <small>Use 0 for no expiry.</small>
                        </p>
                        <p>
                            <label>Exclude paths (one per line)</label><br>
                            <textarea name="page_cache_exclude_paths" rows="4" class="large-text"><?php echo esc_textarea((string) tk_get_option('page_cache_exclude_paths', "/wp-login.php\n/wp-admin\n")); ?></textarea>
                        </p>
                        <p><button class="button button-primary">Save</button></p>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                        <?php tk_nonce_field('tk_cache_purge'); ?>
                        <input type="hidden" name="action" value="tk_cache_purge">
                        <button class="button button-secondary">Purge page cache</button>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="object">
                    <h2>Object Cache (query DB)</h2>
                    <p>Flushes the object cache pool if available.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_cache_object_flush'); ?>
                        <input type="hidden" name="action" value="tk_cache_object_flush">
                        <button class="button button-secondary">Flush object cache</button>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="opcode">
                    <h2>Opcode Cache (PHP)</h2>
                    <p>Reset OPcache when available.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_cache_opcache_reset'); ?>
                        <input type="hidden" name="action" value="tk_cache_opcache_reset">
                        <button class="button button-secondary">Reset opcode cache</button>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="fragment">
                    <h2>Fragment Cache</h2>
                    <p>Use helpers for parts of templates. Example:</p>
                    <pre>if (($block = tk_fragment_cache_get('home:hero')) === false) {
    ob_start();
    // render block
    $block = ob_get_clean();
    tk_fragment_cache_set('home:hero', $block, 300);
}
echo $block;</pre>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_fragment_cache_flush'); ?>
                        <input type="hidden" name="action" value="tk_fragment_cache_flush">
                        <button class="button button-secondary">Clear fragment cache</button>
                    </form>
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
    </div>
    <?php
}
