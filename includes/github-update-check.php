<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub-based updater to surface releases for Tool Kits.
 */
add_filter('site_transient_update_plugins', 'tk_github_plugin_update_check', 20);
add_filter('plugins_api', 'tk_github_plugin_api', 20, 3);

function tk_github_plugin_update_check($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_file = plugin_basename(TK_PATH . 'tool-kits.php');
    $current_version = TK_VERSION;

    $release = tk_github_fetch_latest_release();
    if (is_wp_error($release)) {
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
    $response = wp_remote_get($url, array(
        'timeout' => 20,
        'headers' => array('User-Agent' => 'Tool Kits Updater'),
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_Error('tk_github_http', 'GitHub API returned ' . $code);
    }

    $body = wp_remote_retrieve_body($response);
    $release = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($release['tag_name'])) {
        return new WP_Error('tk_github_parse', 'Invalid GitHub API response.');
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
