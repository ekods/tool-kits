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
add_action('admin_post_tk_github_check_now', 'tk_github_check_now_handler');
add_filter('plugin_action_links_' . plugin_basename(TK_PATH . 'tool-kits.php'), 'tk_github_plugin_action_links');
add_action('admin_notices', 'tk_github_check_now_notice');

function tk_github_plugin_update_check($transient) {
    if (!is_object($transient) || empty($transient->checked)) {
        return $transient;
    }

    $plugin_file = plugin_basename(TK_PATH . 'tool-kits.php');
    $current_version = TK_VERSION;

    $release = tk_github_fetch_latest_release();
    if (is_wp_error($release)) {
        tk_github_log('Update check failed: ' . $release->get_error_message());
        return $transient;
    }

    $latest_version = tk_github_normalize_version($release['tag_name'] ?? '');
    if ($latest_version === '' || !version_compare($latest_version, $current_version, '>')) {
        return $transient;
    }

    $package = tk_github_resolve_package_url($release);
    if ($package === '') {
        return $transient;
    }

    $update = new stdClass();
    $update->slug = TK_SLUG;
    $update->plugin = $plugin_file;
    $update->new_version = $latest_version;
    $update->url = TK_GITHUB_REPO_URL;
    $update->package = $package;

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

    $info = new stdClass();
    $info->name = 'Tool Kits';
    $info->slug = 'tool-kits';
    $info->version = tk_github_normalize_version($release['tag_name'] ?? '');
    $info->tested = '6.6';
    $info->requires = '5.8';
    $info->homepage = TK_GITHUB_REPO_URL;
    $info->download_link = tk_github_resolve_package_url($release);
    $info->sections = array(
        'description' => '<p>Fetches the latest Tool Kits release from GitHub.</p>',
        'installation' => '<p>Download updates from GitHub releases.</p>',
        'changelog' => wp_strip_all_tags($release['body'] ?? ''),
    );

    return $info;
}

function tk_github_fetch_latest_release() {
    $cache_key = 'tk_github_latest_release';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $url = 'https://api.github.com/repos/' . TK_GITHUB_REPO . '/releases/latest';
    $args = array(
        'timeout' => 20,
        'headers' => array('User-Agent' => 'Tool Kits Updater'),
    );
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        tk_github_log('GitHub latest release request error: ' . $response->get_error_message());
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $release = null;
    if ($code === 200) {
        $body = wp_remote_retrieve_body($response);
        $parsed = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($parsed['tag_name'])) {
            $release = $parsed;
        }
    } else {
        tk_github_log('GitHub latest release HTTP ' . $code . '. Trying fallback list endpoint.');
    }

    // Fallback for edge-cases (e.g. latest endpoint unavailable/rate-limited).
    if (!is_array($release)) {
        $fallback_url = 'https://api.github.com/repos/' . TK_GITHUB_REPO . '/releases?per_page=10';
        $fallback = wp_remote_get($fallback_url, $args);
        if (is_wp_error($fallback)) {
            tk_github_log('GitHub releases fallback request error: ' . $fallback->get_error_message());
            return $fallback;
        }
        $fallback_code = (int) wp_remote_retrieve_response_code($fallback);
        if ($fallback_code !== 200) {
            return new WP_Error('tk_github_http', 'GitHub API returned ' . $fallback_code);
        }
        $fallback_body = wp_remote_retrieve_body($fallback);
        $releases = json_decode($fallback_body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($releases)) {
            return new WP_Error('tk_github_parse', 'Invalid GitHub releases fallback response.');
        }
        foreach ($releases as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['draft'])) {
                continue;
            }
            // Skip prerelease by default. Remove this guard if you want prerelease updates.
            if (!empty($item['prerelease'])) {
                continue;
            }
            if (!empty($item['tag_name'])) {
                $release = $item;
                break;
            }
        }
        if (!is_array($release)) {
            return new WP_Error('tk_github_empty', 'No published stable release with tag_name was found.');
        }
    }

    set_transient($cache_key, $release, 6 * HOUR_IN_SECONDS);
    return $release;
}

function tk_github_normalize_version($tag) {
    $version = (string) $tag;
    if (strpos($version, 'v') === 0) {
        $version = substr($version, 1);
    }
    return trim($version);
}

function tk_github_resolve_package_url(array $release) {
    if (!empty($release['assets']) && is_array($release['assets'])) {
        foreach ($release['assets'] as $asset) {
            if (!empty($asset['browser_download_url']) && stripos($asset['name'] ?? '', 'tool-kits') !== false && stripos($asset['name'] ?? '', '.zip') !== false) {
                return $asset['browser_download_url'];
            }
        }
    }

    if (!empty($release['zipball_url'])) {
        return $release['zipball_url'];
    }

    return TK_GITHUB_REPO_URL . '/archive/refs/heads/main.zip';
}

function tk_github_upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra) {
    if (empty($hook_extra['plugin'])) {
        return $source;
    }

    $plugin_file = plugin_basename(TK_PATH . 'tool-kits.php');
    if ($hook_extra['plugin'] !== $plugin_file) {
        return $source;
    }

    if (empty($remote_source) || !is_string($remote_source)) {
        return $source;
    }

    $expected = trailingslashit($remote_source) . 'tool-kits';
    if ($source === $expected) {
        return $source;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    $fs = WP_Filesystem();
    if (!$fs) {
        return $source;
    }

    $dest = trailingslashit($remote_source) . 'tool-kits';
    if ($fs->is_dir($dest)) {
        return $dest;
    }

    $fs->move($source, $dest, true);
    return $dest;
}

function tk_github_log(string $message): void {
    if (function_exists('tk_log')) {
        tk_log('[GitHub Updater] ' . $message);
        return;
    }
    error_log('[Tool Kits][GitHub Updater] ' . $message);
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
    wp_update_plugins();

    $redirect = add_query_arg(
        array(
            'tk_update_checked' => 1,
        ),
        admin_url('plugins.php')
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
    if (!isset($_GET['tk_update_checked']) || (string) $_GET['tk_update_checked'] !== '1') {
        return;
    }
    echo '<div class="notice notice-success is-dismissible"><p>Tool Kits update check has been refreshed.</p></div>';
}
