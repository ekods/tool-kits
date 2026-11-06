<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Opt-in Redis object cache. Installs drop-ins/object-cache.php as wp-content/object-cache.php only after a live
 * connection test, never overwrites another plugin's drop-in, and removes its own drop-in on disable/deactivation.
 * Settings live in tk_options (object_cache_*); the drop-in reads a config file under tk_cache_dir().
 */

function tk_object_cache_init() {
    add_action('admin_post_tk_object_cache_save', 'tk_object_cache_handle_save');
    add_action('admin_post_tk_object_cache_enable', 'tk_object_cache_handle_enable');
    add_action('admin_post_tk_object_cache_disable', 'tk_object_cache_handle_disable');
    add_action('admin_post_tk_object_cache_flush', 'tk_object_cache_handle_flush');
}

/** phpredis class; TK_REDIS_CLASS lets the regression tests substitute a fake. */
function tk_object_cache_redis_class(): string {
    return defined('TK_REDIS_CLASS') ? (string) TK_REDIS_CLASS : 'Redis';
}

function tk_object_cache_dropin_path(): string {
    return rtrim(WP_CONTENT_DIR, '/\\') . '/object-cache.php';
}

function tk_object_cache_config_path(): string {
    return tk_cache_dir() . '/object-cache-config.php';
}

function tk_object_cache_owns_dropin(string $contents): bool {
    return strpos($contents, "<?php\n// Tool Kits object-cache v1.") === 0;
}

/** 'none', 'toolkits' or 'foreign'. */
function tk_object_cache_dropin_state(): string {
    $path = tk_object_cache_dropin_path();
    if (!is_file($path)) { return 'none'; }
    return tk_object_cache_owns_dropin((string) @file_get_contents($path)) ? 'toolkits' : 'foreign';
}

/** Saved connection settings; TK_REDIS_* constants in wp-config.php win, as in the drop-in. */
function tk_object_cache_settings(): array {
    $settings = array(
        'host' => (string) tk_get_option('object_cache_host', '127.0.0.1'),
        'port' => (int) tk_get_option('object_cache_port', 6379),
        'password' => (string) tk_get_option('object_cache_password', ''),
        'database' => (int) tk_get_option('object_cache_database', 0),
    );
    foreach (array('host' => 'TK_REDIS_HOST', 'port' => 'TK_REDIS_PORT', 'password' => 'TK_REDIS_PASSWORD', 'database' => 'TK_REDIS_DATABASE') as $field => $constant) {
        if (defined($constant)) { $settings[$field] = is_int($settings[$field]) ? (int) constant($constant) : (string) constant($constant); }
    }
    if ($settings['host'] === '') { $settings['host'] = '127.0.0.1'; }
    return $settings;
}

function tk_object_cache_fingerprint(array $settings): string {
    return md5($settings['host'] . '|' . $settings['port'] . '|' . $settings['password'] . '|' . $settings['database']);
}

/** Key prefix: WP_CACHE_KEY_SALT when defined, else a random per-site prefix created once and kept. */
function tk_object_cache_prefix(): string {
    $prefix = defined('WP_CACHE_KEY_SALT') ? preg_replace('/[\s\x00-\x1f]+/', '', (string) WP_CACHE_KEY_SALT) : '';
    if ($prefix === '') {
        $prefix = (string) tk_get_option('object_cache_prefix', '');
        if ($prefix === '') {
            $prefix = 'tk_' . strtolower(wp_generate_password(10, false, false));
            tk_update_option('object_cache_prefix', $prefix);
        }
    }
    return substr($prefix, -1) === ':' ? $prefix : $prefix . ':';
}

/** Deletes every key under $prefix in SCAN batches. Never FLUSHDB: other sites may share the database. */
function tk_object_cache_flush_prefix($redis, string $prefix): void {
    if ($prefix === '') { return; }
    $pattern = preg_replace('/([*?\[\]\\\\^])/', '\\\\$1', $prefix) . '*';
    $iterator = null;
    do {
        $keys = $redis->scan($iterator, $pattern, 500);
        if (is_array($keys) && $keys) { $redis->del($keys); }
    } while (!empty($iterator));
}

/**
 * Live test: connect, authenticate, select, write/read/delete a probe key. With $flush the site's keys are cleared
 * so values cached before a disable period are not served again.
 * @return array{ok:bool,message:string}
 */
function tk_object_cache_test(array $settings, bool $flush = false): array {
    $class = tk_object_cache_redis_class();
    if (!class_exists($class)) {
        return array('ok' => false, 'message' => 'The PHP Redis extension (phpredis) is not installed.');
    }
    $redis = null;
    try {
        $redis = new $class();
        $host = (string) $settings['host'];
        $socket = $host[0] === '/' || strpos($host, 'unix://') === 0;
        $connected = $socket ? @$redis->connect($host, 0, 1.0, null, 0, 1.0) : @$redis->connect($host, (int) $settings['port'], 1.0, null, 0, 1.0);
        if (!$connected) { return array('ok' => false, 'message' => 'Could not connect to Redis at ' . ($socket ? $host : $host . ':' . (int) $settings['port']) . '.'); }
        if ((string) $settings['password'] !== '' && !@$redis->auth((string) $settings['password'])) { return array('ok' => false, 'message' => 'Redis rejected the password.'); }
        if ((int) $settings['database'] !== 0 && !@$redis->select((int) $settings['database'])) { return array('ok' => false, 'message' => 'Redis database ' . (int) $settings['database'] . ' could not be selected.'); }
        $prefix = tk_object_cache_prefix();
        $probe = $prefix . 'tk-probe:' . bin2hex(random_bytes(6));
        $written = $redis->set($probe, 'ok', array('ex' => 30));
        $read = $redis->get($probe);
        $redis->del($probe);
        if (!$written || $read !== 'ok') { return array('ok' => false, 'message' => 'Redis connected but a test write/read failed.'); }
        if ($flush) { tk_object_cache_flush_prefix($redis, $prefix); }
        return array('ok' => true, 'message' => 'Connected to Redis' . ($socket ? '' : ' at ' . $host . ':' . (int) $settings['port']) . ', database ' . (int) $settings['database'] . '.');
    } catch (Throwable $error) {
        return array('ok' => false, 'message' => 'Redis error: ' . $error->getMessage());
    } finally {
        if ($redis !== null) { try { @$redis->close(); } catch (Throwable $ignored) {} }
    }
}

/** Removes our drop-in (never a foreign one) and the config file. */
function tk_object_cache_remove(): void {
    $dropin = tk_object_cache_dropin_path();
    if (is_file($dropin) && tk_object_cache_owns_dropin((string) @file_get_contents($dropin))) {
        @unlink($dropin);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($dropin, true); }
    }
    $config = tk_object_cache_config_path();
    if (is_file($config)) {
        @unlink($config);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($config, true); }
    }
    @unlink(tk_cache_dir() . '/object-cache-down');
}

/** @return array{ok:bool,message:string} */
function tk_object_cache_enable(): array {
    if (!tk_license_features_enabled()) { return array('ok' => false, 'message' => 'Redis object cache requires an active Tool Kits license.'); }
    if (tk_object_cache_dropin_state() === 'foreign') {
        return array('ok' => false, 'message' => 'wp-content/object-cache.php belongs to another plugin. Tool Kits will not overwrite it.');
    }
    $settings = tk_object_cache_settings();
    $last = tk_get_option('object_cache_last_test', array());
    if (!is_array($last) || empty($last['ok']) || ($last['fp'] ?? '') !== tk_object_cache_fingerprint($settings)) {
        return array('ok' => false, 'message' => 'Save and test the connection successfully before enabling.');
    }
    $test = tk_object_cache_test($settings, true);
    if (!$test['ok']) {
        tk_update_option('object_cache_last_test', array('fp' => tk_object_cache_fingerprint($settings), 'ok' => false, 'message' => $test['message'], 'time' => time()));
        return array('ok' => false, 'message' => 'Not enabled: ' . $test['message']);
    }
    $config = array(
        'enabled' => true, 'host' => $settings['host'], 'port' => $settings['port'], 'password' => $settings['password'],
        'database' => $settings['database'], 'prefix' => tk_object_cache_prefix(), 'timeout' => 1.0,
    );
    $source = (string) @file_get_contents(dirname(__DIR__) . '/drop-ins/object-cache.php');
    if (!tk_object_cache_owns_dropin($source)
        || !tk_page_cache_early_atomic_write(tk_object_cache_config_path(), "<?php\nif (!defined('ABSPATH')) { return array(); }\nreturn " . var_export($config, true) . ";\n")) {
        tk_object_cache_remove();
        return array('ok' => false, 'message' => 'Could not write the object cache config. Check wp-content/cache/tool-kits permissions.');
    }
    @unlink(tk_cache_dir() . '/object-cache-down'); // The test just passed: do not let an old failure delay the first request.
    // Re-check right before writing: another plugin may have installed its drop-in meanwhile.
    if (tk_object_cache_dropin_state() === 'foreign' || !tk_page_cache_early_atomic_write(tk_object_cache_dropin_path(), $source)) {
        tk_object_cache_remove();
        return array('ok' => false, 'message' => 'Could not install wp-content/object-cache.php. Check wp-content permissions.');
    }
    return array('ok' => true, 'message' => 'Redis object cache enabled. It takes effect from the next request.');
}

function tk_object_cache_notice(bool $ok, string $message): void {
    set_transient('tk_object_cache_notice', array('type' => $ok ? 'success' : 'error', 'message' => $message), 60);
    wp_redirect(tk_admin_url('tool-kits-cache') . '#object');
    exit;
}

function tk_object_cache_require_license(): void {
    if (!tk_license_features_enabled()) {
        tk_object_cache_notice(false, 'Redis object cache requires an active Tool Kits license.');
    }
}

function tk_object_cache_handle_save() {
    tk_require_admin_post('tk_object_cache_save');
    tk_object_cache_require_license();
    $values = array(
        'object_cache_host' => sanitize_text_field((string) tk_post('object_cache_host', '127.0.0.1')),
        'object_cache_port' => max(0, min(65535, (int) tk_post('object_cache_port', 6379))),
        'object_cache_database' => max(0, min(1024, (int) tk_post('object_cache_database', 0))),
    );
    if ($values['object_cache_host'] === '') { $values['object_cache_host'] = '127.0.0.1'; }
    $password = (string) tk_post('object_cache_password', '');
    if (!empty($_POST['object_cache_password_clear'])) {
        $values['object_cache_password'] = '';
    } elseif ($password !== '') {
        $values['object_cache_password'] = $password;
    }
    tk_update_options($values);
    $settings = tk_object_cache_settings();
    $test = tk_object_cache_test($settings);
    tk_update_option('object_cache_last_test', array('fp' => tk_object_cache_fingerprint($settings), 'ok' => $test['ok'], 'message' => $test['message'], 'time' => time()));
    tk_object_cache_notice($test['ok'], ($test['ok'] ? 'Settings saved. ' : 'Settings saved, but the connection test failed: ') . $test['message']);
}

function tk_object_cache_handle_enable() {
    tk_require_admin_post('tk_object_cache_enable');
    tk_object_cache_require_license();
    $result = tk_object_cache_enable();
    tk_object_cache_notice($result['ok'], $result['message']);
}

/** Disabling is never license-gated, so a lapsed license can always remove the drop-in. */
function tk_object_cache_handle_disable() {
    tk_require_admin_post('tk_object_cache_disable');
    if (tk_object_cache_dropin_state() === 'foreign') {
        tk_object_cache_notice(false, 'wp-content/object-cache.php belongs to another plugin; Tool Kits did not change it.');
    }
    tk_object_cache_remove();
    tk_object_cache_notice(tk_object_cache_dropin_state() === 'none', tk_object_cache_dropin_state() === 'none'
        ? 'Redis object cache disabled.' : 'Could not remove wp-content/object-cache.php. Check file permissions.');
}

function tk_object_cache_handle_flush() {
    tk_require_admin_post('tk_object_cache_flush');
    tk_object_cache_require_license();
    $ok = function_exists('wp_cache_flush') && wp_cache_flush();
    tk_object_cache_notice($ok, $ok ? 'Object cache flushed.' : 'Object cache flush failed.');
}

/** Live status of our drop-in in this request, or null when it is not the active object cache. */
function tk_object_cache_runtime_status() {
    global $wp_object_cache;
    return is_object($wp_object_cache) && method_exists($wp_object_cache, 'tk_status') ? $wp_object_cache->tk_status() : null;
}

function tk_object_cache_render_section(): void {
    $notice = get_transient('tk_object_cache_notice');
    if ($notice !== false) { delete_transient('tk_object_cache_notice'); }
    $ext = class_exists(tk_object_cache_redis_class());
    $state = tk_object_cache_dropin_state();
    $runtime = tk_object_cache_runtime_status();
    $settings = tk_object_cache_settings();
    $last = tk_get_option('object_cache_last_test', array());
    $tested = is_array($last) && !empty($last['ok']) && ($last['fp'] ?? '') === tk_object_cache_fingerprint($settings);
    $licensed = tk_license_features_enabled();
    $password_saved = (string) tk_get_option('object_cache_password', '') !== '';
    $constants = array_filter(array('TK_REDIS_HOST', 'TK_REDIS_PORT', 'TK_REDIS_PASSWORD', 'TK_REDIS_DATABASE'), 'defined');
    $action = esc_url(admin_url('admin-post.php'));
    ?>
    <div id="tk-object-cache-redis" style="margin-top:28px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
        <h2 style="margin:0;">Object Cache (Redis)</h2>
        <p class="description">Keeps WordPress query and option caches in Redis between requests. Off by default; if Redis becomes unreachable the site falls back to the normal in-memory cache.</p>
        <?php if (is_array($notice) && !empty($notice['message'])) { tk_notice(esc_html((string) $notice['message']), (string) ($notice['type'] ?? 'info')); } ?>
        <?php if (!$licensed) { tk_notice('Redis object cache requires an active Tool Kits license.', 'warning'); } ?>
        <table class="widefat striped" style="margin:16px 0;">
            <tbody>
                <tr><td>PHP Redis extension</td><td><?php echo $ext ? '<span class="tk-badge tk-on">AVAILABLE</span>' : '<span class="tk-badge tk-warn">MISSING</span>'; ?></td></tr>
                <tr><td>object-cache.php drop-in</td><td>
                    <?php if ($state === 'toolkits') : ?><span class="tk-badge tk-on">TOOL KITS</span>
                    <?php elseif ($state === 'foreign') : ?><span class="tk-badge tk-warn">ANOTHER PLUGIN</span> <?php echo esc_html(tk_object_cache_dropin_path()); ?>
                    <?php else : ?><span class="tk-badge">NONE</span><?php endif; ?>
                </td></tr>
                <?php if ($runtime !== null) : ?>
                    <tr><td>Connection (this request)</td><td>
                        <?php echo $runtime['connected'] ? '<span class="tk-badge tk-on">CONNECTED</span> ' . esc_html((string) $runtime['server']) : '<span class="tk-badge tk-warn">FALLBACK</span> ' . esc_html('In-memory only: ' . ($runtime['error'] !== '' ? $runtime['error'] : 'Redis unavailable')); ?>
                    </td></tr>
                    <tr><td>Hit ratio (this request)</td><td><?php echo esc_html($runtime['ratio'] . '% (' . (int) $runtime['hits'] . ' hits, ' . (int) $runtime['misses'] . ' misses)'); ?></td></tr>
                <?php elseif ($state === 'toolkits') : ?>
                    <tr><td>Connection (this request)</td><td><span class="tk-badge tk-warn">NOT CONNECTED</span> WordPress is using its default cache; Redis was unreachable or the config is missing.</td></tr>
                <?php endif; ?>
                <?php if (is_array($last) && !empty($last['message'])) : ?>
                    <tr><td>Last connection test</td><td><?php echo (!empty($last['ok']) ? '<span class="tk-badge tk-on">PASSED</span> ' : '<span class="tk-badge tk-warn">FAILED</span> ') . esc_html((string) $last['message']); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($state === 'foreign') : ?>
            <?php tk_notice('Another plugin manages wp-content/object-cache.php. Disable that plugin\'s object cache first; Tool Kits will not overwrite it.', 'warning'); ?>
        <?php endif; ?>
        <?php if ($constants) : ?>
            <p class="description">Overridden in wp-config.php: <?php echo esc_html(implode(', ', $constants)); ?>.</p>
        <?php endif; ?>
        <form method="post" action="<?php echo $action; ?>">
            <?php tk_nonce_field('tk_object_cache_save'); ?>
            <input type="hidden" name="action" value="tk_object_cache_save">
            <div class="tk-responsive-grid" style="display:grid; grid-template-columns:2fr 1fr; gap:20px;">
                <div class="tk-form-group">
                    <label class="tk-form-label" for="tk-oc-host">Host or socket path</label>
                    <input type="text" id="tk-oc-host" name="object_cache_host" class="tk-input" style="width:100%;" value="<?php echo esc_attr((string) tk_get_option('object_cache_host', '127.0.0.1')); ?>" placeholder="127.0.0.1" autocomplete="off" spellcheck="false">
                </div>
                <div class="tk-form-group">
                    <label class="tk-form-label" for="tk-oc-port">Port</label>
                    <input type="number" id="tk-oc-port" name="object_cache_port" class="tk-input" style="width:100%;" min="0" max="65535" value="<?php echo esc_attr((string) tk_get_option('object_cache_port', 6379)); ?>">
                </div>
                <div class="tk-form-group">
                    <label class="tk-form-label" for="tk-oc-password">Password</label>
                    <input type="password" id="tk-oc-password" name="object_cache_password" class="tk-input" style="width:100%;" autocomplete="new-password" placeholder="<?php echo esc_attr($password_saved ? 'Saved — leave blank to keep' : 'None'); ?>">
                    <?php if ($password_saved) : ?>
                        <label style="display:flex; gap:6px; align-items:center; font-size:12px;"><input type="checkbox" name="object_cache_password_clear" value="1"> Remove saved password</label>
                    <?php endif; ?>
                </div>
                <div class="tk-form-group">
                    <label class="tk-form-label" for="tk-oc-db">Database index</label>
                    <input type="number" id="tk-oc-db" name="object_cache_database" class="tk-input" style="width:100%;" min="0" value="<?php echo esc_attr((string) tk_get_option('object_cache_database', 0)); ?>">
                </div>
            </div>
            <div style="margin-top:16px;">
                <button class="button button-primary" <?php disabled(!$licensed || !$ext); ?>>Save &amp; test connection</button>
            </div>
        </form>
        <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <?php if ($state !== 'toolkits') : ?>
                <form method="post" action="<?php echo $action; ?>" style="margin:0;">
                    <?php tk_nonce_field('tk_object_cache_enable'); ?>
                    <input type="hidden" name="action" value="tk_object_cache_enable">
                    <button class="button button-secondary" <?php disabled(!$licensed || !$ext || !$tested || $state === 'foreign'); ?>>Enable Redis object cache</button>
                </form>
            <?php else : ?>
                <form method="post" action="<?php echo $action; ?>" style="margin:0;">
                    <?php tk_nonce_field('tk_object_cache_disable'); ?>
                    <input type="hidden" name="action" value="tk_object_cache_disable">
                    <button class="button button-secondary" data-confirm="Disable the Redis object cache and remove wp-content/object-cache.php?">Disable Redis object cache</button>
                </form>
                <form method="post" action="<?php echo $action; ?>" style="margin:0;">
                    <?php tk_nonce_field('tk_object_cache_flush'); ?>
                    <input type="hidden" name="action" value="tk_object_cache_flush">
                    <button class="button button-secondary" <?php disabled(!$licensed); ?>>Flush Redis object cache</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($state !== 'toolkits' && !$tested) : ?>
            <p class="description">Enable becomes available after a successful connection test with the current settings.</p>
        <?php endif; ?>
    </div>
    <?php
}
