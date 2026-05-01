<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub-based updater to surface releases for Tool Kits.
 */

add_filter('pre_set_site_transient_update_plugins', 'tk_github_plugin_update_check', 20);
add_filter('plugins_api', 'tk_github_plugin_api', 20, 3);
add_filter('upgrader_source_selection', 'tk_github_upgrader_source_selection', 10, 4);
add_filter('upgrader_pre_download', 'tk_github_upgrader_pre_download', 10, 4);
add_filter('upgrader_package_options', 'tk_github_upgrader_package_options', 10, 1);
add_filter('upgrader_install_package_result', 'tk_github_upgrader_install_package_result', 10, 2);
add_filter('upgrader_post_install', 'tk_github_upgrader_post_install', 10, 3);
add_action('upgrader_process_complete', 'tk_github_upgrader_process_complete', 10, 2);

add_action('admin_post_tk_github_check_now', 'tk_github_check_now_handler');
add_action('admin_post_tk_github_clear_status', 'tk_github_clear_status_handler');
add_filter('plugin_action_links_' . plugin_basename(TK_PATH . 'tool-kits.php'), 'tk_github_plugin_action_links');
add_action('admin_notices', 'tk_github_check_now_notice');

function tk_github_plugin_update_check($transient) {
    if (!is_object($transient)) {
        $transient = new stdClass();
    }

    if (empty($transient->checked) || !is_array($transient->checked)) {
        return $transient;
    }

    $plugin_file = plugin_basename(TK_PATH . 'tool-kits.php');
    $current_version = defined('TK_VERSION') ? (string) TK_VERSION : '';

    if ($current_version === '') {
        tk_github_log('Current plugin version is empty.');
        return $transient;
    }

    $release = tk_github_fetch_latest_release();
    if (is_wp_error($release)) {
        tk_github_log('Update check failed: ' . $release->get_error_message());
        return $transient;
    }

    $latest_version = tk_github_normalize_version($release['tag_name'] ?? '');
    if ($latest_version === '') {
        tk_github_log('Latest version from GitHub release is empty.');
        return $transient;
    }

    if (!version_compare($latest_version, $current_version, '>')) {
        if (isset($transient->response[$plugin_file])) {
            unset($transient->response[$plugin_file]);
        }
        return $transient;
    }

    $package = tk_github_resolve_package_url($release);
    tk_github_log('Resolved package URL: ' . $package);

    if ($package === '') {
        tk_github_log('Package URL could not be resolved.');
        return $transient;
    }

    $update = new stdClass();
    $update->slug = TK_SLUG;
    $update->plugin = $plugin_file;
    $update->new_version = $latest_version;
    $update->url = defined('TK_GITHUB_REPO_URL') ? TK_GITHUB_REPO_URL : '';
    $update->package = $package;
    $update->tested = '6.6';
    $update->requires = '5.8';
    $update->requires_php = '7.4';

    $transient->response[$plugin_file] = $update;

    return $transient;
}

function tk_github_plugin_api($res, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'tool-kits') {
        return $res;
    }

    $release = tk_github_fetch_latest_release();
    if (is_wp_error($release)) {
        tk_github_log('Plugin API fetch failed: ' . $release->get_error_message());
        return $res;
    }

    $version = tk_github_normalize_version($release['tag_name'] ?? '');
    $download_link = tk_github_resolve_package_url($release);
    $changelog = wp_kses_post(wpautop((string) ($release['body'] ?? 'No changelog provided.')));

    $info = new stdClass();
    $info->name = 'Tool Kits';
    $info->slug = 'tool-kits';
    $info->version = $version;
    $info->author = '<a href="' . esc_url(TK_GITHUB_REPO_URL) . '">Tool Kits</a>';
    $info->homepage = TK_GITHUB_REPO_URL;
    $info->requires = '5.8';
    $info->tested = '6.6';
    $info->requires_php = '7.4';
    $info->last_updated = !empty($release['published_at']) ? gmdate('Y-m-d', strtotime($release['published_at'])) : '';
    $info->download_link = $download_link;
    $info->sections = array(
        'description'  => '<p>Fetches the latest Tool Kits release from GitHub.</p>',
        'installation' => '<p>Install and update Tool Kits directly from GitHub Releases.</p>',
        'changelog'    => $changelog,
    );
    $info->banners = array();
    $info->icons = array();

    return $info;
}

function tk_github_fetch_latest_release() {
    $cache_key = 'tk_github_latest_release';
    $cached = get_transient($cache_key);
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    if (!defined('TK_GITHUB_REPO') || !TK_GITHUB_REPO) {
        return new WP_Error('tk_github_repo_missing', 'GitHub repository constant is not defined.');
    }

    $headers = array(
        'User-Agent' => 'Tool Kits Updater',
        'Accept'     => 'application/vnd.github+json',
    );

    $token = tk_github_auth_token();
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $args = array(
        'timeout' => 20,
        'headers' => $headers,
    );

    $url = 'https://api.github.com/repos/' . TK_GITHUB_REPO . '/releases/latest';
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        tk_github_log('GitHub latest release request error: ' . $response->get_error_message());
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $release = null;

    if ($code === 200) {
        $body = wp_remote_retrieve_body($response);
        $parsed = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed) && !empty($parsed['tag_name'])) {
            if (empty($parsed['draft']) && empty($parsed['prerelease'])) {
                $release = $parsed;
            }
        }
    } else {
        tk_github_log('GitHub latest release HTTP ' . $code . '. Trying fallback releases endpoint.');
    }

    if (!is_array($release)) {
        $fallback_url = 'https://api.github.com/repos/' . TK_GITHUB_REPO . '/releases?per_page=10';
        $fallback = wp_remote_get($fallback_url, $args);

        if (is_wp_error($fallback)) {
            tk_github_log('GitHub releases fallback request error: ' . $fallback->get_error_message());
            return $fallback;
        }

        $fallback_code = (int) wp_remote_retrieve_response_code($fallback);
        if ($fallback_code !== 200) {
            tk_github_log('GitHub releases fallback HTTP ' . $fallback_code);
            return new WP_Error('tk_github_http', 'GitHub API returned HTTP ' . $fallback_code);
        }

        $fallback_body = wp_remote_retrieve_body($fallback);
        $releases = json_decode($fallback_body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($releases)) {
            return new WP_Error('tk_github_parse', 'Invalid GitHub releases response.');
        }

        foreach ($releases as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['draft']) || !empty($item['prerelease'])) {
                continue;
            }
            if (!empty($item['tag_name'])) {
                $release = $item;
                break;
            }
        }
    }

    if (!is_array($release) || empty($release['tag_name'])) {
        return new WP_Error('tk_github_empty', 'No published stable release with a valid tag_name was found.');
    }

    set_transient($cache_key, $release, HOUR_IN_SECONDS);

    return $release;
}

function tk_github_auth_token(): string {
    if (defined('TK_GITHUB_TOKEN')) {
        return trim((string) TK_GITHUB_TOKEN);
    }

    return '';
}

function tk_github_normalize_version($tag) {
    $version = trim((string) $tag);

    if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
        $version = substr($version, 1);
    }

    return trim($version);
}

function tk_github_resolve_package_url(array $release) {
    if (empty($release['assets']) || !is_array($release['assets'])) {
        tk_github_log('No release assets found on GitHub release.');
        return '';
    }

    foreach ($release['assets'] as $asset) {
        $asset_name = strtolower((string) ($asset['name'] ?? ''));
        $download_url = (string) ($asset['browser_download_url'] ?? '');

        if ($download_url !== '' && $asset_name === 'tool-kits.zip') {
            tk_github_log('Using exact release asset: ' . $asset_name);
            return $download_url;
        }
    }

    tk_github_log('Exact asset tool-kits.zip not found.');
    return '';
}

function tk_github_asset_api_url_for_package(string $package): string {
    $release = get_transient('tk_github_latest_release');
    if (!is_array($release) || empty($release['assets']) || !is_array($release['assets'])) {
        $release = tk_github_fetch_latest_release();
    }

    if (is_wp_error($release) || !is_array($release) || empty($release['assets']) || !is_array($release['assets'])) {
        return '';
    }

    foreach ($release['assets'] as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $name = strtolower((string) ($asset['name'] ?? ''));
        $browser_url = (string) ($asset['browser_download_url'] ?? '');
        $api_url = (string) ($asset['url'] ?? '');
        if ($name === 'tool-kits.zip' && $browser_url === $package && $api_url !== '') {
            return $api_url;
        }
    }

    return '';
}

function tk_github_response_body_excerpt($body): string {
    $body = is_scalar($body) ? (string) $body : '';
    if ($body === '') {
        return '';
    }

    $body = wp_strip_all_tags($body);
    $body = preg_replace('/\s+/', ' ', $body);
    $body = is_string($body) ? trim($body) : '';

    return substr($body, 0, 220);
}

function tk_github_download_package(string $package) {
    $host = parse_url($package, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';
    $allowed_hosts = array('github.com', 'release-assets.githubusercontent.com');

    if ($package === '' || !in_array($host, $allowed_hosts, true)) {
        return new WP_Error('tk_github_invalid_package_url', 'Invalid GitHub update package URL.');
    }

    $headers = array(
        'User-Agent' => 'Tool Kits Updater',
    );

    $download_url = $package;

    $tmp_file = wp_tempnam('tool-kits.zip');
    if (!$tmp_file) {
        return new WP_Error('tk_github_temp_file', 'Could not create a temporary file for the update package.');
    }

    $response = wp_remote_get($download_url, array(
        'timeout'     => 60,
        'redirection' => 5,
        'stream'      => true,
        'filename'    => $tmp_file,
        'headers'     => $headers,
    ));

    if (is_wp_error($response)) {
        @unlink($tmp_file);
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
    $size = file_exists($tmp_file) ? (int) filesize($tmp_file) : 0;

    if ($code < 200 || $code >= 300) {
        $body = file_exists($tmp_file) ? file_get_contents($tmp_file) : '';
        @unlink($tmp_file);
        $detail = tk_github_response_body_excerpt($body);
        $message = 'GitHub update package returned HTTP ' . $code . '.';
        if ($detail !== '') {
            $message .= ' Response: ' . $detail;
        }
        return new WP_Error('tk_github_download_http', $message);
    }

    if ($size < 128) {
        @unlink($tmp_file);
        return new WP_Error('tk_github_download_empty', 'GitHub update package download was empty or too small.');
    }

    $signature = file_get_contents($tmp_file, false, null, 0, 4);
    $looks_like_zip = is_string($signature) && substr($signature, 0, 2) === 'PK';
    if (!$looks_like_zip) {
        $body = file_exists($tmp_file) ? file_get_contents($tmp_file) : '';
        @unlink($tmp_file);
        $detail = tk_github_response_body_excerpt($body);
        $message = 'GitHub update package did not return a valid ZIP file.';
        if ($content_type !== '') {
            $message .= ' Content-Type: ' . $content_type . '.';
        }
        if ($detail !== '') {
            $message .= ' Response: ' . $detail;
        }
        return new WP_Error('tk_github_download_not_zip', $message);
    }

    return $tmp_file;
}

function tk_github_find_plugin_root(string $source): string {
    $source = untrailingslashit($source);

    if ($source === '' || !is_dir($source)) {
        return '';
    }

    if (is_file($source . '/tool-kits.php')) {
        return $source;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            if ($item->getFilename() !== 'tool-kits.php') {
                continue;
            }

            $path = $item->getPath();
            if (is_string($path) && $path !== '') {
                return untrailingslashit($path);
            }
        }
    } catch (Exception $e) {
        tk_github_log('Failed while scanning extracted package: ' . $e->getMessage());
    }

    return '';
}

function tk_github_is_target_upgrade(array $hook_extra): bool {
    $plugin_file = plugin_basename(TK_PATH . 'tool-kits.php');

    if (!empty($hook_extra['plugin']) && $hook_extra['plugin'] === $plugin_file) {
        return true;
    }

    if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins']) && in_array($plugin_file, $hook_extra['plugins'], true)) {
        return true;
    }

    return false;
}

function tk_github_store_status(string $status, string $message, array $context = array()): void {
    $payload = array(
        'status'    => $status,
        'message'   => $message,
        'context'   => $context,
        'timestamp' => time(),
    );

    set_transient('tk_github_updater_status', $payload, DAY_IN_SECONDS);
    update_option('tk_github_updater_status', $payload, false);
}

function tk_github_get_stored_status() {
    $status = get_transient('tk_github_updater_status');
    if ($status === false) {
        $status = get_option('tk_github_updater_status');
    }

    return is_array($status) ? $status : array();
}

function tk_github_clear_stored_status(): void {
    delete_transient('tk_github_updater_status');
    delete_option('tk_github_updater_status');
}

function tk_github_get_diagnostics(): array {
    $release = get_transient('tk_github_latest_release');
    $package = '';
    $asset_name = '';
    $published_at = '';
    $tag_name = '';
    $release_url = '';
    $cached = false;

    if (is_array($release)) {
        $cached = true;
        $package = tk_github_resolve_package_url($release);
        $published_at = (string) ($release['published_at'] ?? '');
        $tag_name = (string) ($release['tag_name'] ?? '');
        $release_url = (string) ($release['html_url'] ?? '');

        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                $download_url = (string) ($asset['browser_download_url'] ?? '');
                if ($download_url !== '' && $download_url === $package) {
                    $asset_name = (string) ($asset['name'] ?? '');
                    break;
                }
            }
        }
    }

    $current_version = defined('TK_VERSION') ? (string) TK_VERSION : '';
    $normalized_tag = tk_github_normalize_version($tag_name);
    $update_available = $normalized_tag !== '' && $current_version !== '' ? version_compare($normalized_tag, $current_version, '>') : false;
    $package_valid = $package !== '' && $asset_name === 'tool-kits.zip';
    $release_age_hours = null;

    if ($published_at !== '') {
        $published_ts = strtotime($published_at);
        if ($published_ts !== false) {
            $release_age_hours = max(0, (int) floor((time() - $published_ts) / HOUR_IN_SECONDS));
        }
    }

    $checks = array(
        array(
            'label'   => 'Release cache is available',
            'status'  => $cached ? 'ok' : 'warn',
            'detail'  => $cached ? 'A cached GitHub release payload is available.' : 'No cached GitHub release payload found. Refresh the release cache first.',
        ),
        array(
            'label'   => 'Latest tag resolves to a version',
            'status'  => $normalized_tag !== '' ? 'ok' : 'warn',
            'detail'  => $normalized_tag !== '' ? 'Resolved latest version: ' . $normalized_tag : 'No valid tag/version could be resolved from the cached release.',
        ),
        array(
            'label'   => 'Release package is exact asset',
            'status'  => $package_valid ? 'ok' : 'warn',
            'detail'  => $package_valid ? 'Updater resolved the exact asset tool-kits.zip.' : 'Updater did not resolve the exact tool-kits.zip asset. Old installs may fail to update.',
        ),
        array(
            'label'   => 'Update availability',
            'status'  => $update_available ? 'warn' : 'ok',
            'detail'  => $update_available ? 'A newer version is available than the installed version.' : 'Installed version is up to date or release information is incomplete.',
        ),
    );

    return array(
        'current_version' => $current_version,
        'repo'            => defined('TK_GITHUB_REPO') ? (string) TK_GITHUB_REPO : '',
        'tag_name'        => $tag_name,
        'normalized_tag'  => $normalized_tag,
        'release_url'     => $release_url,
        'published_at'    => $published_at,
        'release_age_hours' => $release_age_hours,
        'package_url'     => $package,
        'asset_name'      => $asset_name,
        'package_valid'   => $package_valid,
        'update_available' => $update_available,
        'release_cached'  => $cached,
        'checks'          => $checks,
        'status'          => tk_github_get_stored_status(),
    );
}

function tk_github_upgrader_pre_download($reply, $package, $upgrader, $hook_extra) {
    if (!is_array($hook_extra) || !tk_github_is_target_upgrade($hook_extra)) {
        return $reply;
    }

    tk_github_store_status('running', 'Preparing to download update package.', array(
        'package' => (string) $package,
    ));
    tk_github_log('Preparing to download package: ' . (string) $package);

    $download = tk_github_download_package((string) $package);
    if (is_wp_error($download)) {
        tk_github_store_status('failed', $download->get_error_message(), array(
            'package'    => (string) $package,
            'error_code' => $download->get_error_code(),
        ));
        tk_github_log('Package download failed: ' . $download->get_error_message());
        return $download;
    }

    tk_github_store_status('running', 'Update package downloaded and validated.', array(
        'package' => (string) $package,
        'file'    => basename((string) $download),
    ));

    return $download;
}

function tk_github_upgrader_package_options($options) {
    if (!is_array($options) || empty($options['hook_extra']) || !is_array($options['hook_extra']) || !tk_github_is_target_upgrade($options['hook_extra'])) {
        return $options;
    }

    tk_github_log('Package options: destination=' . (string) ($options['destination'] ?? '') . ' clear_destination=' . (!empty($options['clear_destination']) ? 'yes' : 'no'));

    return $options;
}

function tk_github_upgrader_install_package_result($result, $hook_extra) {
    if (!is_array($hook_extra) || !tk_github_is_target_upgrade($hook_extra)) {
        return $result;
    }

    if (is_wp_error($result)) {
        $error_message = $result->get_error_message();
        $error_code = $result->get_error_code();
        $error_data = $result->get_error_data($error_code);

        tk_github_store_status('failed', $error_message, array(
            'error_code' => $error_code,
            'error_data' => $error_data,
        ));
        tk_github_log('Install package failed: code=' . $error_code . ' message=' . $error_message . ' data=' . wp_json_encode($error_data));

        return $result;
    }

    if (is_array($result)) {
        tk_github_store_status('installed', 'Package installed by WordPress.', array(
            'destination'      => (string) ($result['destination'] ?? ''),
            'destination_name' => (string) ($result['destination_name'] ?? ''),
        ));
        tk_github_log('Install package succeeded: destination=' . (string) ($result['destination'] ?? ''));
    }

    return $result;
}

function tk_github_upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra) {
    if (!is_array($hook_extra) || !tk_github_is_target_upgrade($hook_extra)) {
        return $source;
    }

    if (empty($source) || !is_string($source) || !is_dir($source)) {
        tk_github_log('Invalid source during source selection: ' . print_r($source, true));
        return new WP_Error('tk_github_invalid_source', 'Invalid upgrade source directory.');
    }

    $plugin_root = tk_github_find_plugin_root($source);
    if ($plugin_root === '') {
        tk_github_log('tool-kits.php not found in extracted package. Source: ' . $source);
        return new WP_Error('tk_github_invalid_package', 'The update package does not contain tool-kits.php.');
    }

    $source = untrailingslashit($source);
    $plugin_root = untrailingslashit($plugin_root);

    tk_github_log('Source selection: source=' . $source . ' plugin_root=' . $plugin_root);

    if ($plugin_root === $source && basename($source) === 'tool-kits') {
        return $source;
    }

    if (basename($plugin_root) !== 'tool-kits') {
        $desired_root = trailingslashit(dirname($plugin_root)) . 'tool-kits';
        
        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->move($plugin_root, $desired_root, true)) {
            $plugin_root = $desired_root;
        } elseif (@rename($plugin_root, $desired_root)) {
            $plugin_root = $desired_root;
        } else {
            return new WP_Error('tk_github_invalid_root_name', 'The update package root must be named tool-kits, and could not be renamed automatically.');
        }
    }

    return $plugin_root;
}

function tk_github_upgrader_post_install($response, $hook_extra, $result) {
    if (empty($hook_extra['plugin'])) {
        return $response;
    }

    $plugin_file = plugin_basename(TK_PATH . 'tool-kits.php');
    if ($hook_extra['plugin'] !== $plugin_file) {
        return $response;
    }

    if (!is_array($result) || empty($result['destination']) || !is_string($result['destination'])) {
        return $response;
    }

    $expected_destination = trailingslashit(WP_PLUGIN_DIR) . 'tool-kits';
    if (untrailingslashit($result['destination']) !== untrailingslashit($expected_destination)) {
        tk_github_log('Unexpected plugin destination after install: ' . $result['destination']);
    }

    if (!empty($result['destination_name']) && $result['destination_name'] !== 'tool-kits') {
        tk_github_log('Unexpected destination_name after install: ' . $result['destination_name']);
    }

    tk_github_store_status('installed', 'Plugin update installed.', array(
        'destination'      => $result['destination'],
        'destination_name' => (string) ($result['destination_name'] ?? ''),
    ));

    $active_plugin = plugin_basename($expected_destination . '/tool-kits.php');
    if (is_plugin_active($plugin_file) && !is_plugin_active($active_plugin) && file_exists($expected_destination . '/tool-kits.php')) {
        activate_plugin($active_plugin);
    }

    delete_transient('tk_github_latest_release');
    delete_site_transient('update_plugins');

    return $response;
}

function tk_github_upgrader_process_complete($upgrader, $hook_extra): void {
    if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }

    if (empty($hook_extra['action']) || $hook_extra['action'] !== 'update') {
        return;
    }

    if (empty($hook_extra['plugins']) || !is_array($hook_extra['plugins'])) {
        return;
    }

    if (!tk_github_is_target_upgrade($hook_extra)) {
        return;
    }

    tk_github_store_status('completed', 'Plugin update process completed successfully.');
    delete_transient('tk_github_latest_release');
    delete_site_transient('update_plugins');
}

function tk_github_log(string $message): void {
    error_log('[Tool Kits][GitHub Updater] ' . $message);

    if (function_exists('tk_log')) {
        tk_log('[GitHub Updater] ' . $message);
    }
}

function tk_github_plugin_action_links(array $links): array {
    $url = wp_nonce_url(
        admin_url('admin-post.php?action=tk_github_check_now'),
        'tk_github_check_now'
    );

    $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Check Updates Now', 'tool-kits') . '</a>';

    return $links;
}

function tk_github_check_now_handler(): void {
    if (!current_user_can('update_plugins')) {
        wp_die('Forbidden');
    }

    check_admin_referer('tk_github_check_now');

    delete_transient('tk_github_latest_release');
    delete_site_transient('update_plugins');
    tk_github_clear_stored_status();

    wp_clean_plugins_cache(true);
    wp_update_plugins();

    $redirect = add_query_arg(
        array(
            'tk_update_checked' => '1',
        ),
        admin_url('plugins.php')
    );

    wp_safe_redirect($redirect);
    exit;
}

function tk_github_clear_status_handler(): void {
    if (!current_user_can('update_plugins')) {
        wp_die('Forbidden');
    }

    check_admin_referer('tk_github_clear_status');

    tk_github_clear_stored_status();

    $redirect = add_query_arg(
        array(
            'page'                    => 'tool-kits-diagnostics',
            'tk_github_status_cleared' => '1',
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($redirect);
    exit;
}

function tk_github_check_now_notice(): void {
    if (!is_admin()) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'plugins') {
        return;
    }

    $status = get_transient('tk_github_updater_status');
    if ($status === false) {
        $status = get_option('tk_github_updater_status');
    }

    if (isset($_GET['tk_update_checked']) && (string) $_GET['tk_update_checked'] === '1' && empty($status)) {
        echo '<div class="notice notice-success is-dismissible"><p>Tool Kits update check has been refreshed.</p></div>';
        return;
    }

    if (!is_array($status) || empty($status['status']) || empty($status['message'])) {
        return;
    }

    $class = 'notice-info';
    if ($status['status'] === 'failed') {
        $class = 'notice-error';
    } elseif ($status['status'] === 'completed' || $status['status'] === 'installed') {
        $class = 'notice-success';
    }

    $context = '';
    if (!empty($status['context']) && is_array($status['context'])) {
        $pairs = array();
        foreach ($status['context'] as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $pairs[] = $key . '=' . (string) $value;
            }
        }
        if (!empty($pairs)) {
            $context = '<br><small><code>' . esc_html(implode(' | ', $pairs)) . '</code></small>';
        }
    }

    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>Tool Kits updater:</strong> ' . esc_html((string) $status['message']) . $context . '</p></div>';
}
