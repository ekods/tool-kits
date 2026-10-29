<?php
if (!defined('ABSPATH')) { exit; }

function tk_page_cache_early_config_path(): string {
    return tk_cache_dir() . '/page-cache-config.php';
}

function tk_page_cache_early_atomic_write(string $path, string $contents): bool {
    if (is_file($path) && @file_get_contents($path) === $contents) { return true; }
    if (!is_dir(dirname($path)) && !wp_mkdir_p(dirname($path))) { return false; }
    $temporary = @tempnam(dirname($path), '.tk-cache-');
    if ($temporary === false) { return false; }
    $mode = is_file($path) ? (fileperms($path) & 0777) : 0644;
    $ok = @file_put_contents($temporary, $contents, LOCK_EX) === strlen($contents)
        && @chmod($temporary, $mode) && @rename($temporary, $path);
    if (is_file($temporary)) { @unlink($temporary); }
    if ($ok && function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
    return $ok;
}

function tk_page_cache_early_owns_dropin(string $contents): bool {
    return strpos($contents, "<?php\n// Tool Kits advanced-cache v1.") === 0;
}

function tk_page_cache_wp_config_path(): string {
    $root = rtrim(ABSPATH, '/\\');
    if (is_file($root . '/wp-config.php')) { return $root . '/wp-config.php'; }
    $parent = dirname($root);
    return !is_file($parent . '/wp-settings.php') && is_file($parent . '/wp-config.php') ? $parent . '/wp-config.php' : '';
}

/** Find real defines, ignoring comments and strings containing example code. */
function tk_page_cache_wp_cache_defined(string $contents): bool {
    $tokens = token_get_all($contents);
    $significant = array_values(array_filter($tokens, function($token) {
        return !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
    }));
    foreach ($significant as $i => $token) {
        if (!is_array($token) || strtolower(ltrim($token[1], '\\')) !== 'define' || ($significant[$i + 1] ?? '') !== '(') { continue; }
        $name = $significant[$i + 2] ?? null;
        if (is_array($name) && $name[0] === T_CONSTANT_ENCAPSED_STRING && trim($name[1], "\"'") === 'WP_CACHE') { return true; }
    }
    return false;
}

function tk_page_cache_early_wp_cache(bool $enable): bool {
    $path = tk_page_cache_wp_config_path();
    if ($path === '') { return false; }
    $contents = @file_get_contents($path);
    if (!is_string($contents)) { return false; }
    $managed = "/* BEGIN Tool Kits WP_CACHE */\ndefine('WP_CACHE', true);\n/* END Tool Kits WP_CACHE */\n";
    if (!$enable) {
        if (strpos($contents, $managed) === false) { return true; }
        return is_writable($path) && tk_page_cache_early_atomic_write($path, str_replace($managed, '', $contents));
    }
    if (strpos($contents, $managed) !== false || (defined('WP_CACHE') && WP_CACHE)) { return true; }
    // WP defines WP_CACHE=false by default; only a real wp-config definition
    // prevents us from adding the managed setting for the next request.
    if (tk_page_cache_wp_cache_defined($contents) || !is_writable($path)) { return false; }
    $tokens = token_get_all($contents);
    $offset = 0;
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $offset += strlen($text);
        if (is_array($token) && $token[0] === T_OPEN_TAG && strpos($text, '<?php') === 0) {
            return tk_page_cache_early_atomic_write($path, substr_replace($contents, "\n" . $managed, $offset, 0));
        }
    }
    return false;
}

function tk_page_cache_early_remove(): void {
    $config = tk_page_cache_early_config_path();
    if (is_file($config)) { @unlink($config); if (function_exists('opcache_invalidate')) { @opcache_invalidate($config, true); } }
    $dropin = WP_CONTENT_DIR . '/advanced-cache.php';
    if (is_file($dropin) && tk_page_cache_early_owns_dropin((string) @file_get_contents($dropin))) {
        @unlink($dropin);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($dropin, true); }
    }
    if (!tk_page_cache_early_wp_cache(false)) {
        $GLOBALS['tk_page_cache_early_notice'] = 'Tool Kits early cache is disabled. Its WP_CACHE marker could not be removed from wp-config.php; check file permissions.';
    }
}

/** Features that need WordPress to run before serving cached HTML. */
function tk_page_cache_early_hook_requirements(): array {
    $requirements = array();
    if (function_exists('is_multisite') && is_multisite()) { $requirements[] = 'Multisite'; }
    if (tk_get_option('hardening_httpauth_enabled', 0)) { $requirements[] = 'HTTP authentication'; }
    if (tk_get_option('hardening_waf_enabled', 0)) { $requirements[] = 'PHP firewall'; }
    return $requirements;
}

// Everything the early-cache snapshot depends on that can be read without touching files.
function tk_page_cache_early_fingerprint(): string {
    $claims = tk_license_features_enabled() ? tk_license_verified_claims() : false;
    return md5((string) wp_json_encode(array(
        !empty($GLOBALS['tk_page_cache_early_deactivated']), tk_cache_page_enabled(), tk_license_features_enabled(),
        tk_page_cache_early_hook_requirements(), (int) tk_get_option('page_cache_ttl', 3600),
        (string) tk_get_option('page_cache_exclude_paths', ''), tk_page_cache_dir(), defined('TK_VERSION') ? TK_VERSION : '',
        is_array($claims) ? (int) ($claims['expires_at'] ?? 0) : 0, home_url('/'),
    )));
}

// wp_loaded entry point. A full sync reads wp-config.php, the drop-in and the config file, so public requests
// reuse the last result until an input changes or the signed snapshot nears expiry; admin and cron always sync.
function tk_page_cache_early_sync_request(): void {
    $fingerprint = tk_page_cache_early_fingerprint();
    if (!is_admin() && !wp_doing_cron()) {
        $state = get_option('tk_page_cache_early_state', array());
        if (is_array($state) && ($state['fp'] ?? '') === $fingerprint
            && ((int) ($state['until'] ?? 0) === 0 || (int) $state['until'] > time() + 300)) {
            $GLOBALS['tk_page_cache_early_notice'] = (string) ($state['notice'] ?? '');
            return;
        }
    }
    tk_page_cache_early_sync();
    $path = tk_page_cache_early_config_path();
    $snapshot = is_file($path) ? include $path : array();
    $state = array('fp' => $fingerprint, 'until' => is_array($snapshot) ? (int) ($snapshot['valid_until'] ?? 0) : 0,
        'notice' => (string) ($GLOBALS['tk_page_cache_early_notice'] ?? ''));
    if (get_option('tk_page_cache_early_state', array()) !== $state) {
        update_option('tk_page_cache_early_state', $state, true);
    }
}

function tk_page_cache_early_sync(): void {
    $GLOBALS['tk_page_cache_early_notice'] = '';
    if (!empty($GLOBALS['tk_page_cache_early_deactivated']) || !tk_cache_page_enabled() || !tk_license_features_enabled()) { tk_page_cache_early_remove(); return; }
    // These protections require PHP hooks. A cache hit must not bypass them.
    if (tk_page_cache_early_hook_requirements()) {
        tk_page_cache_early_remove();
        // This is an expected mode selection, shown on Cache Status. Keep any
        // actionable cleanup error set by early_remove() instead of overwriting it.
        return;
    }
    $dropin = WP_CONTENT_DIR . '/advanced-cache.php';
    if (is_file($dropin) && !tk_page_cache_early_owns_dropin((string) @file_get_contents($dropin))) {
        // Remove our config only; never overwrite another plugin\'s drop-in.
        $config = tk_page_cache_early_config_path();
        if (is_file($config)) { @unlink($config); if (function_exists('opcache_invalidate')) { @opcache_invalidate($config, true); } }
        $GLOBALS['tk_page_cache_early_notice'] = 'Tool Kits early cache could not start: advanced-cache.php belongs to another plugin. Normal page caching remains available.';
        return;
    }
    $config = array(
        'format' => TK_Page_Cache_Runtime::FORMAT,
        'version' => defined('TK_VERSION') ? TK_VERSION : '',
        'enabled' => true,
        'ttl' => max(0, (int) tk_get_option('page_cache_ttl', 3600)),
        'directory' => tk_page_cache_dir(),
        'runtime' => __DIR__ . '/cache-runtime.php',
        'plugin_file' => dirname(__DIR__) . '/tool-kits.php',
        'plugin_mtime' => (int) filemtime(dirname(__DIR__) . '/tool-kits.php'),
        'exclude_paths' => array_values(array_filter(array_map('trim', explode("\n", (string) tk_get_option('page_cache_exclude_paths', "/wp-login.php\n/wp-admin\n"))), 'strlen')),
        'dynamic_paths' => tk_cache_dynamic_path_patterns(),
        'allowed_query_keys' => TK_Page_Cache_Runtime::allowed_query_keys(),
        'cookie_markers' => TK_Page_Cache_Runtime::cookie_markers(),
    );
    $config = apply_filters('tk_page_cache_early_config', $config);
    if (!is_array($config) || empty($config['enabled'])) { tk_page_cache_early_remove(); return; }
    $path = tk_page_cache_early_config_path();
    $existing = is_file($path) ? include $path : array();
    $previous = is_array($existing) ? $existing : array();
    unset($previous['valid_until']);
    if (isset($previous['version']) && $previous['version'] !== $config['version']) { tk_page_cache_purge(); }
    $claims = tk_license_verified_claims();
    if ($claims === false) { tk_page_cache_early_remove(); return; }
    $license_until = $claims['expires_at'];
    if ($license_until <= time()) { tk_page_cache_early_remove(); return; }
    // Bound the lifetime of the signed-license snapshot; expired snapshots fall
    // through to WP, which refreshes this file without an admin visit.
    if ($previous !== $config || (int) ($existing['valid_until'] ?? 0) <= time() + 300 || (int) ($existing['valid_until'] ?? 0) > $license_until) {
        $config['valid_until'] = min(time() + 21600, $license_until);
        if (!tk_page_cache_early_atomic_write($path, "<?php\nif (!defined('ABSPATH')) { return array(); }\nreturn " . var_export($config, true) . ";\n")) {
            tk_page_cache_early_remove();
            $GLOBALS['tk_page_cache_early_notice'] = 'Tool Kits cannot write its early-cache config. Check wp-content/cache/tool-kits permissions. Normal page caching remains available.';
            return;
        }
    }
    $source = (string) @file_get_contents(dirname(__DIR__) . '/drop-ins/advanced-cache.php');
    if ($source === '' || !tk_page_cache_early_atomic_write($dropin, $source)) {
        tk_page_cache_early_remove();
        $GLOBALS['tk_page_cache_early_notice'] = 'Tool Kits cannot install advanced-cache.php. Check wp-content permissions. Normal page caching remains available.';
        return;
    }
    if (!tk_page_cache_early_wp_cache(true)) {
        $GLOBALS['tk_page_cache_early_notice'] = "Tool Kits early cache needs define('WP_CACHE', true) before wp-settings.php in wp-config.php. An existing definition or file permissions prevented automatic setup. Normal page caching remains available.";
    }
}

function tk_page_cache_early_option_changed($option, $old = null, $new = null): void {
    if ($option !== 'tk_options' && $option !== 'active_plugins' && $option !== 'home' && $option !== 'siteurl') { return; }
    if ($option === 'tk_options' && is_array($old) && is_array($new)) {
        $keys = array('page_cache_enabled', 'page_cache_ttl', 'page_cache_exclude_paths', 'license_status', 'license_key', 'license_signature', 'license_receipt', 'license_site_url', 'license_expires_at', 'toolkits_install_id', 'hardening_waf_enabled', 'hardening_httpauth_enabled');
        $changed = false;
        foreach ($keys as $key) { if (($old[$key] ?? null) !== ($new[$key] ?? null)) { $changed = true; break; } }
        if (!$changed) { return; }
    }
    // Disable immediately after an option/license change, then publish one
    // consistent snapshot after all fields in the settings form have saved.
    delete_option('tk_page_cache_early_state');
    $config = tk_page_cache_early_config_path();
    if (is_file($config)) { @unlink($config); if (function_exists('opcache_invalidate')) { @opcache_invalidate($config, true); } }
    if (empty($GLOBALS['tk_page_cache_early_sync_pending'])) {
        $GLOBALS['tk_page_cache_early_sync_pending'] = true;
        add_action('shutdown', 'tk_page_cache_early_sync', PHP_INT_MAX);
    }
}

function tk_page_cache_early_admin_notice(): void {
    if (!tk_is_admin_user() || empty($GLOBALS['tk_page_cache_early_notice'])) { return; }
    tk_notice(esc_html($GLOBALS['tk_page_cache_early_notice']), 'warning');
}
