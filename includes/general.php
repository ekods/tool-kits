<?php
if (!defined('ABSPATH')) { exit; }

function tk_general_init(): void {
    add_action('admin_post_tk_general_save', 'tk_general_save');
    add_action('admin_post_tk_general_sitemap_generate', 'tk_general_sitemap_generate');
    add_action('admin_post_tk_general_sitemap_submit', 'tk_general_sitemap_submit');
    add_action('admin_post_tk_general_settings_export', 'tk_general_settings_export');
    add_action('admin_post_tk_general_settings_import', 'tk_general_settings_import');
    add_action('admin_post_tk_general_preset_apply', 'tk_general_preset_apply');
    add_action('admin_post_tk_general_uninstall_save', 'tk_general_uninstall_save');
add_action('admin_post_tk_general_diagnostics_export', 'tk_general_diagnostics_export');
    add_action('admin_enqueue_scripts', 'tk_general_maybe_enqueue_assets');
    add_action('login_enqueue_scripts', 'tk_general_login_branding_styles');
    add_filter('login_headerurl', 'tk_general_login_header_url');
    add_filter('login_headertext', 'tk_general_login_header_text');

    if ((int) tk_get_option('duplicate_content_enabled', 0) === 1) {
        add_filter('post_row_actions', 'tk_general_duplicate_row_action', 10, 2);
        add_filter('page_row_actions', 'tk_general_duplicate_row_action', 10, 2);
        add_action('admin_post_tk_duplicate_content', 'tk_general_duplicate_content_handler');
    }
    // WordPress requires PHP's default timezone to stay UTC; the site timezone lives in timezone_string.
}

function tk_general_duplicate_row_action(array $actions, $post): array {
    if (
        !is_object($post)
        || !isset($post->ID, $post->post_type)
        || !in_array($post->post_type, array('post', 'page'), true)
        || !current_user_can('edit_post', (int) $post->ID)
    ) {
        return $actions;
    }

    $url = wp_nonce_url(
        admin_url('admin-post.php?action=tk_duplicate_content&post=' . (int) $post->ID),
        'tk_duplicate_content_' . (int) $post->ID
    );
    $actions['tk_duplicate'] = '<a href="' . esc_url($url) . '">' . esc_html__('Duplicate', 'tool-kits') . '</a>';
    return $actions;
}

function tk_general_duplicate_post(int $post_id) {
    $source = get_post($post_id);
    if (!$source || !in_array($source->post_type, array('post', 'page'), true)) {
        return new WP_Error('tk_duplicate_invalid_post', __('The selected content cannot be duplicated.', 'tool-kits'));
    }

    // wp_insert_post() and add_post_meta() unslash their input; raw DB values must be slashed first
    // or backslashes in block attributes and JSON meta (e.g. _elementor_data) are lost.
    $duplicate_id = wp_insert_post(
        wp_slash(array(
            'post_author' => (int) $source->post_author,
            'post_content' => $source->post_content,
            'post_content_filtered' => $source->post_content_filtered,
            'post_excerpt' => $source->post_excerpt,
            'post_title' => sprintf(__('Copy of %s', 'tool-kits'), $source->post_title),
            'post_status' => 'draft',
            'post_type' => $source->post_type,
            'comment_status' => $source->comment_status,
            'ping_status' => $source->ping_status,
            'post_password' => $source->post_password,
            'post_parent' => (int) $source->post_parent,
            'menu_order' => (int) $source->menu_order,
        )),
        true
    );
    if (is_wp_error($duplicate_id)) {
        return $duplicate_id;
    }

    $polylang_active = function_exists('pll_get_post_language') && function_exists('pll_set_post_language');
    foreach (get_object_taxonomies($source->post_type) as $taxonomy) {
        if ($polylang_active && in_array($taxonomy, array('language', 'post_translations'), true)) {
            continue;
        }
        $term_ids = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (!is_wp_error($term_ids)) {
            wp_set_object_terms($duplicate_id, $term_ids, $taxonomy);
        }
    }

    $excluded_meta = array('_edit_lock', '_edit_last', '_wp_old_slug', '_icl_lang_duplicate_of');
    foreach (get_post_meta($post_id) as $meta_key => $values) {
        if (in_array($meta_key, $excluded_meta, true)) {
            continue;
        }
        foreach ((array) $values as $value) {
            // add_post_meta() unslashes with map_deep(), which also walks object properties; wp_slash() does not,
            // so slash the same way to keep backslashes inside object-type meta.
            $value = map_deep(maybe_unserialize($value), function ($item) {
                return is_string($item) ? addslashes($item) : $item;
            });
            add_post_meta($duplicate_id, $meta_key, $value);
        }
    }

    tk_general_duplicate_language($post_id, (int) $duplicate_id, $source->post_type);

    return (int) $duplicate_id;
}

function tk_general_duplicate_language(int $source_id, int $duplicate_id, string $post_type): void {
    if (function_exists('pll_get_post_language') && function_exists('pll_set_post_language')) {
        $language = (string) pll_get_post_language($source_id, 'slug');
        if ($language !== '') {
            pll_set_post_language($duplicate_id, $language);
        }
        return;
    }

    if (!function_exists('has_filter') || !has_filter('wpml_post_language_details')) {
        return;
    }

    $details = apply_filters('wpml_post_language_details', null, $source_id);
    $language_code = is_array($details) && isset($details['language_code']) ? (string) $details['language_code'] : '';
    if ($language_code === '') {
        return;
    }

    do_action(
        'wpml_set_element_language_details',
        array(
            'element_id' => $duplicate_id,
            'element_type' => 'post_' . $post_type,
            'trid' => false,
            'language_code' => $language_code,
            'source_language_code' => null,
        )
    );
}

function tk_general_duplicate_content_handler(): void {
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    check_admin_referer('tk_duplicate_content_' . $post_id);

    if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
        wp_die(__('You are not allowed to duplicate this content.', 'tool-kits'));
    }

    $duplicate_id = tk_general_duplicate_post($post_id);
    if (is_wp_error($duplicate_id)) {
        wp_die(esc_html($duplicate_id->get_error_message()));
    }

    wp_safe_redirect(get_edit_post_link($duplicate_id, 'raw'));
    exit;
}

function tk_general_sensitive_option_keys(): array {
    return array(
        'hardening_httpauth_pass',
        'heartbeat_auth_key',
        'heartbeat_http_pass',
        'license_key',
        'license_message',
        'sitemap_bing_access_token',
        'sitemap_google_access_token',
        'monitoring_healthcheck_key',
        'smtp_gmail_access_token',
        'smtp_gmail_client_secret',
        'smtp_gmail_refresh_token',
        'smtp_microsoft_access_token',
        'smtp_microsoft_client_secret',
        'smtp_microsoft_refresh_token',
        'smtp_password',
        'toolkits_ip_allowlist',
    );
}

function tk_general_sitemap_url(): string {
    $path = trim((string) tk_get_option('seo_sitemap_path', 'sitemap.xml'));
    return home_url('/' . ltrim($path !== '' ? $path : 'sitemap.xml', '/'));
}

function tk_general_sitemap_submit_google(string $sitemap_url) {
    $token = trim((string) tk_get_option('sitemap_google_access_token', ''));
    $property = trim((string) tk_get_option('sitemap_google_property', home_url('/')));
    if ($token === '' || $property === '') {
        return new WP_Error('tk_sitemap_google_credentials', __('Google Search Console property and access token are required.', 'tool-kits'));
    }
    $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/sitemaps/' . rawurlencode($sitemap_url);
    return wp_remote_request($endpoint, array(
        'method' => 'PUT',
        'timeout' => 20,
        'headers' => array('Authorization' => 'Bearer ' . $token),
    ));
}

function tk_general_sitemap_submit_bing(string $sitemap_url) {
    $token = trim((string) tk_get_option('sitemap_bing_access_token', ''));
    if ($token === '') {
        return new WP_Error('tk_sitemap_bing_credentials', __('Bing Webmaster OAuth access token is required.', 'tool-kits'));
    }
    return wp_remote_post('https://www.bing.com/webmaster/api.svc/json/SubmitFeed', array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json; charset=utf-8',
        ),
        'body' => wp_json_encode(array(
            'siteUrl' => untrailingslashit(home_url('/')),
            'feedUrl' => $sitemap_url,
        )),
    ));
}

function tk_general_sitemap_submission_result(string $provider, $response): array {
    $result = array(
        'provider' => $provider,
        'submitted_at' => time(),
        'ok' => false,
        'message' => '',
    );
    if (is_wp_error($response)) {
        $result['message'] = $response->get_error_message();
        return $result;
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $result['status'] = $status;
    $result['ok'] = $status >= 200 && $status < 300;
    $result['message'] = $result['ok']
        ? __('Sitemap submitted successfully.', 'tool-kits')
        : sprintf(__('Submission failed with HTTP status %d.', 'tool-kits'), $status);
    return $result;
}

function tk_general_sitemap_submit(): void {
    tk_require_admin_post('tk_general_sitemap_submit');
    $provider = sanitize_key((string) tk_post('provider', ''));
    $sitemap_url = tk_general_sitemap_url();
    if ($provider === 'google') {
        $response = tk_general_sitemap_submit_google($sitemap_url);
    } elseif ($provider === 'bing') {
        $response = tk_general_sitemap_submit_bing($sitemap_url);
    } else {
        wp_die(esc_html__('Unknown sitemap provider.', 'tool-kits'));
    }
    $result = tk_general_sitemap_submission_result($provider, $response);
    tk_update_option('sitemap_submission_last_result', $result);
    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-general',
        'tk_sitemap_submit' => $result['ok'] ? 'ok' : 'fail',
        'tk_sitemap_provider' => $provider,
    ), admin_url('admin.php')) . '#xml-sitemap');
    exit;
}

function tk_general_mask_sensitive_options(array $options): array {
    foreach (tk_general_sensitive_option_keys() as $key) {
        if (array_key_exists($key, $options) && $options[$key] !== '') {
            $options[$key] = '***';
        }
    }
    return $options;
}

function tk_general_settings_payload(bool $mask_sensitive = false): array {
    $options = tk_get_options();
    if ($mask_sensitive) {
        $options = tk_general_mask_sensitive_options($options);
    }

    return array(
        'type' => 'tool-kits-settings',
        'version' => TK_VERSION,
        'site_url' => home_url('/'),
        'created_at' => gmdate('c'),
        'options' => $options,
    );
}

function tk_general_download_json(string $filename, array $payload): void {
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function tk_general_settings_presets(): array {
    return array(
        'basic-security' => array(
            'label' => 'Basic Security',
            'description' => 'Safer login and common hardening defaults for most sites.',
            'options' => array(
                'captcha_enabled' => 1,
                'captcha_on_login' => 1,
                'form_guard_enabled' => 1,
                'rate_limit_enabled' => 1,
                'rate_limit_window_minutes' => 10,
                'rate_limit_max_attempts' => 5,
                'rate_limit_lockout_minutes' => 30,
                'rate_limit_progressive_enabled' => 1,
                'rate_limit_honey_trap_enabled' => 1,
                'security_login_error_obfuscation_enabled' => 1,
                'security_block_bad_usernames_enabled' => 1,
                'security_login_origin_guard_enabled' => 1,
                'security_404_scanner_trap_enabled' => 1,
                'login_log_enabled' => 1,
                'hardening_disable_file_editor' => 1,
                'hardening_disable_rest_user_enum' => 1,
                'hardening_block_author_enumeration' => 1,
                'hardening_reduce_wp_fingerprint' => 1,
                'hardening_remove_query_ver' => 1,
                'hardening_disable_emojis' => 1,
                'hardening_clean_wp_head' => 1,
                'hardening_hide_wp_version' => 1,
                'hardening_security_headers' => 1,
            ),
        ),
        'strict-hardening' => array(
            'label' => 'Strict Hardening',
            'description' => 'Aggressive protection for production sites with higher security requirements.',
            'options' => array(
                'captcha_enabled' => 1,
                'captcha_on_login' => 1,
                'form_guard_enabled' => 1,
                'rate_limit_enabled' => 1,
                'rate_limit_max_attempts' => 3,
                'rate_limit_lockout_minutes' => 60,
                'rate_limit_progressive_enabled' => 1,
                'rate_limit_honey_trap_enabled' => 1,
                'security_login_error_obfuscation_enabled' => 1,
                'security_block_bad_usernames_enabled' => 1,
                'security_login_origin_guard_enabled' => 1,
                'security_404_scanner_trap_enabled' => 1,
                'hardening_disable_file_editor' => 1,
                'hardening_disable_xmlrpc' => 1,
                'hardening_disable_rest_user_enum' => 1,
                'hardening_block_author_enumeration' => 1,
                'hardening_reduce_wp_fingerprint' => 1,
                'hardening_remove_query_ver' => 1,
                'hardening_disable_emojis' => 1,
                'hardening_disable_pingbacks' => 1,
                'hardening_hide_wp_version' => 1,
                'hardening_clean_wp_head' => 1,
                'hardening_block_uploads_php' => 1,
                'hardening_block_unwanted_files_enabled' => 1,
                'hardening_security_headers' => 1,
                'hardening_waf_enabled' => 1,
                'firewall_log_enabled' => 1,
            ),
        ),
        'performance' => array(
            'label' => 'Performance',
            'description' => 'Enable low-risk speed improvements and asset optimizations.',
            'options' => array(
                'page_cache_enabled' => 1,
                'page_cache_ttl' => 3600,
                'page_cache_auto_preload_after_purge' => 1,
                'lazy_load_enabled' => 1,
                'lazy_load_html_images' => 1,
                'lazy_load_iframe_video' => 1,
                'minify_html_enabled' => 1,
                'minify_inline_css' => 1,
                'minify_inline_js' => 0,
                'assets_lcp_bg_preload_enabled' => 1,
                'assets_preconnect_auto_enabled' => 1,
                'assets_font_display_swap' => 1,
                'assets_dimensions_enabled' => 1,
            ),
        ),
        'seo-ready' => array(
            'label' => 'SEO Ready',
            'description' => 'Turn on metadata, schema, canonical tags, and XML sitemap generation.',
            'options' => array(
                'seo_enabled' => 1,
                'seo_meta_desc_enabled' => 1,
                'seo_canonical_enabled' => 1,
                'seo_og_enabled' => 1,
                'seo_schema_enabled' => 1,
                'seo_sitemap_enabled' => 1,
                'seo_sitemap_path' => 'sitemap.xml',
                'seo_sitemap_include_taxonomies' => 1,
                'seo_sitemap_include_images' => 1,
                'seo_sitemap_changefreq' => 'weekly',
                'seo_sitemap_priority' => 0.8,
            ),
        ),
    );
}

function tk_general_diagnostics_payload(): array {
    global $wpdb;
    $active_plugins = get_option('active_plugins', array());
    if (!is_array($active_plugins)) {
        $active_plugins = array();
    }

    $theme = wp_get_theme();
    $upload = wp_get_upload_dir();
    $options = tk_general_mask_sensitive_options(tk_get_options());
    $connection_summary = function_exists('tk_toolkits_connection_summary') ? tk_toolkits_connection_summary() : array();

    return array(
        'type' => 'tool-kits-diagnostics',
        'created_at' => gmdate('c'),
        'site' => array(
            'url' => home_url('/'),
            'admin_url' => admin_url(),
            'is_multisite' => is_multisite(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => isset($wpdb) && method_exists($wpdb, 'db_version') ? $wpdb->db_version() : '',
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['SERVER_SOFTWARE'])) : '',
        ),
        'tool_kits' => array(
            'version' => TK_VERSION,
            'options' => $options,
            'connection_summary' => $connection_summary,
        ),
        'theme' => array(
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'stylesheet' => get_stylesheet(),
            'template' => get_template(),
        ),
        'plugins' => array(
            'active_count' => count($active_plugins),
            'active' => $active_plugins,
        ),
        'filesystem' => array(
            'abspath_writable' => is_writable(ABSPATH),
            'wp_content_writable' => defined('WP_CONTENT_DIR') ? is_writable(WP_CONTENT_DIR) : null,
            'uploads_basedir' => isset($upload['basedir']) ? $upload['basedir'] : '',
            'uploads_writable' => isset($upload['basedir']) ? is_writable($upload['basedir']) : null,
        ),
    );
}

function tk_general_settings_export(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_general_settings_export');

    tk_toolkits_audit_log('settings_export', array('user' => wp_get_current_user()->user_login));
    tk_general_download_json('tool-kits-settings-' . gmdate('Ymd-His') . '.json', tk_general_settings_payload(false));
}

function tk_general_diagnostics_export(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_general_diagnostics_export');

    tk_toolkits_audit_log('diagnostics_export', array('user' => wp_get_current_user()->user_login));
    tk_general_download_json('tool-kits-diagnostics-' . gmdate('Ymd-His') . '.json', tk_general_diagnostics_payload());
}

function tk_general_clean_import_options(array $incoming): array {
    $clean = array();
    foreach ($incoming as $key => $value) {
        if (!is_string($key) || !preg_match('/^[a-z0-9_]+$/', $key)) {
            continue;
        }
        if (tk_is_report_option($key)) {
            continue;
        }
        if (in_array($key, array('google_tag_manager_head', 'google_tag_manager_body'), true)
            && (!current_user_can('unfiltered_html') || !is_string($value))) {
            continue;
        }
        if (is_object($value)) {
            continue;
        }
        $clean[$key] = $value;
    }
    return $clean;
}

function tk_general_settings_import(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_general_settings_import');

    if (empty($_FILES['settings_file']['tmp_name']) || !is_uploaded_file($_FILES['settings_file']['tmp_name'])) {
        wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_imported=fail&tk_import_msg=' . rawurlencode('No settings file uploaded.') . '#maintenance'));
        exit;
    }

    $raw = file_get_contents($_FILES['settings_file']['tmp_name']);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_imported=fail&tk_import_msg=' . rawurlencode('Invalid JSON file.') . '#maintenance'));
        exit;
    }

    $incoming = array();
    if (isset($data['options']) && is_array($data['options'])) {
        $incoming = $data['options'];
    } elseif (isset($data['tk_options']) && is_array($data['tk_options'])) {
        $incoming = $data['tk_options'];
    }

    $clean = tk_general_clean_import_options($incoming);
    if (empty($clean)) {
        wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_imported=fail&tk_import_msg=' . rawurlencode('No valid Tool Kits settings found.') . '#maintenance'));
        exit;
    }

    $current = tk_get_options();
    $replace = !empty($_POST['replace_settings']);
    $next = $replace ? $clean : array_merge($current, $clean);
    update_option('tk_options', $next, false);
    tk_toolkits_audit_log('settings_import', array(
        'mode' => $replace ? 'replace' : 'merge',
        'keys' => count($clean),
        'user' => wp_get_current_user()->user_login,
    ));

    wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_imported=ok&tk_import_msg=' . rawurlencode(count($clean) . ' settings imported.') . '#maintenance'));
    exit;
}

// Kept outside tk_options so a settings import or preset can never turn on data deletion.
function tk_general_uninstall_save(): void {
    if (!tk_toolkits_can_manage() || !current_user_can('delete_plugins')) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_general_uninstall_save');
    $enabled = !empty($_POST['uninstall_delete_data']) ? 1 : 0;
    update_option('tk_uninstall_delete_data', $enabled, false);
    tk_toolkits_audit_log('uninstall_delete_data', array('enabled' => $enabled, 'user' => wp_get_current_user()->user_login));
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_uninstall_saved=1#maintenance'));
    exit;
}

function tk_general_preset_apply(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_general_preset_apply');

    $preset_key = isset($_POST['preset_key']) ? sanitize_key((string) $_POST['preset_key']) : '';
    $presets = tk_general_settings_presets();
    if (!isset($presets[$preset_key])) {
        wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_preset=fail#maintenance'));
        exit;
    }

    foreach ($presets[$preset_key]['options'] as $key => $value) {
        tk_update_option($key, $value);
    }
    tk_toolkits_audit_log('preset_apply', array(
        'preset' => $preset_key,
        'keys' => count($presets[$preset_key]['options']),
        'user' => wp_get_current_user()->user_login,
    ));

    wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_preset=ok&tk_preset_name=' . rawurlencode($presets[$preset_key]['label']) . '#maintenance'));
    exit;
}

function tk_general_maybe_enqueue_assets($hook_suffix): void {
    if ($hook_suffix !== 'tool-kits_page_tool-kits-general') {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_add_inline_script('media-editor', tk_general_media_picker_script());
    wp_add_inline_script('wp-color-picker', "jQuery(function($){ $('.tk-color-field').wpColorPicker({ change: function(){ setTimeout(function(){ document.dispatchEvent(new Event('tkLoginBrandingUpdate')); }, 0); }, clear: function(){ setTimeout(function(){ document.dispatchEvent(new Event('tkLoginBrandingUpdate')); }, 0); } }); });");
}

function tk_general_media_picker_script(): string {
    return <<<'JS'
(function(){
    function setPreview(field, url) {
        var preview = document.querySelector('[data-tk-media-preview="' + field + '"]');
        if (!preview) { return; }
        if (url) {
            preview.innerHTML = '<img src="' + url + '" alt="">';
            preview.classList.remove('is-empty');
        } else {
            preview.innerHTML = '<span>No image selected</span>';
            preview.classList.add('is-empty');
        }
        updateMockPreview();
    }

    function updateMockPreview() {
        var mock = document.querySelector('[data-tk-login-mock]');
        if (!mock) { return; }

        var enabled = document.querySelector('[name="login_branding_enabled"]');
        var logoInput = document.querySelector('[data-tk-media-input="login_logo"]');
        var bgInput = document.querySelector('[data-tk-media-input="login_background"]');
        var logoPreview = document.querySelector('[data-tk-media-preview="login_logo"] img');
        var bgPreview = document.querySelector('[data-tk-media-preview="login_background"] img');
        var logoImg = mock.querySelector('[data-tk-login-mock-logo]');
        var logoFallback = mock.querySelector('[data-tk-login-mock-fallback]');
        var logoBrand = mock.querySelector('.tk-login-mock-brand');
        var overlay = mock.querySelector('[data-tk-login-mock-overlay]');
        var inactive = mock.querySelector('[data-tk-login-mock-inactive]');
        var width = document.querySelector('[name="login_logo_width"]');
        var height = document.querySelector('[name="login_logo_height"]');
        var color = document.querySelector('[name="login_overlay_color"]');
        var opacity = document.querySelector('[name="login_overlay_opacity"]');
        var formBackground = document.querySelector('[name="login_form_background_color"]');
        var formColor = document.querySelector('[name="login_form_text_color"]');
        var isEnabled = !enabled || enabled.checked;

        mock.classList.toggle('is-disabled', !isEnabled);
        if (inactive) {
            inactive.style.display = isEnabled ? 'none' : 'flex';
        }
        if (bgPreview && bgInput && bgInput.value) {
            mock.style.backgroundImage = 'url("' + bgPreview.src + '")';
        } else {
            mock.style.backgroundImage = '';
        }
        if (logoImg && logoPreview && logoInput && logoInput.value) {
            logoImg.src = logoPreview.src;
            logoImg.style.display = 'block';
            if (logoFallback) {
                logoFallback.style.display = 'none';
            }
        } else if (logoImg) {
            logoImg.removeAttribute('src');
            logoImg.style.display = 'none';
            if (logoFallback) {
                logoFallback.style.display = 'block';
            }
        }
        if (logoBrand && width && height) {
            logoBrand.style.setProperty('--tk-login-logo-width', parseInt(width.value || '240', 10) + 'px');
            logoBrand.style.setProperty('--tk-login-logo-height', parseInt(height.value || '96', 10) + 'px');
        }
        if (logoImg && width && height) {
            logoImg.style.maxWidth = parseInt(width.value || '240', 10) + 'px';
            logoImg.style.maxHeight = parseInt(height.value || '96', 10) + 'px';
        }
        if (overlay && color && opacity) {
            var value = color.value || '#0f172a';
            var alpha = Math.max(0, Math.min(100, parseInt(opacity.value || '46', 10))) / 100;
            overlay.style.background = hexToRgba(value, alpha);
        }
        var form = mock.querySelector('.tk-login-mock-form');
        if (form && formBackground) {
            form.style.backgroundColor = formBackground.value || '#ffffff';
        }
        if (form && formColor) {
            form.style.color = formColor.value || '#1f2937';
            form.querySelectorAll('.tk-login-mock-line, .tk-login-mock-check, .tk-login-mock-button').forEach(function(item){
                item.style.color = formColor.value || '#1f2937';
            });
        }
    }

    function hexToRgba(hex, alpha) {
        var normalized = hex.replace('#', '');
        if (normalized.length === 3) {
            normalized = normalized.split('').map(function(char){ return char + char; }).join('');
        }
        var intValue = parseInt(normalized, 16);
        if (Number.isNaN(intValue)) {
            return 'rgba(15, 23, 42, ' + alpha + ')';
        }
        return 'rgba(' + ((intValue >> 16) & 255) + ', ' + ((intValue >> 8) & 255) + ', ' + (intValue & 255) + ', ' + alpha + ')';
    }

    document.addEventListener('click', function(event){
        var chooseButton = event.target.closest('[data-tk-media-select]');
        var removeButton = event.target.closest('[data-tk-media-remove]');

        if (chooseButton) {
            event.preventDefault();
            var field = chooseButton.getAttribute('data-tk-media-select');
            var input = document.querySelector('[data-tk-media-input="' + field + '"]');
            if (!input || !window.wp || !wp.media) { return; }

            var frame = wp.media({
                title: chooseButton.getAttribute('data-title') || 'Select image',
                button: { text: chooseButton.getAttribute('data-button') || 'Use image' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                input.value = attachment.id || '';
                setPreview(field, attachment.url || '');
            });

            frame.open();
        }

        if (removeButton) {
            event.preventDefault();
            var removeField = removeButton.getAttribute('data-tk-media-remove');
            var removeInput = document.querySelector('[data-tk-media-input="' + removeField + '"]');
            if (removeInput) {
                removeInput.value = '';
            }
            setPreview(removeField, '');
        }
    });

    document.addEventListener('input', function(event){
        if (event.target.closest('[data-tk-login-branding-control]')) {
            updateMockPreview();
        }
    });
    document.addEventListener('change', function(event){
        if (event.target.closest('[data-tk-login-branding-control]') || event.target.name === 'login_branding_enabled') {
            updateMockPreview();
        }
    });
    document.addEventListener('tkLoginBrandingUpdate', updateMockPreview);
    document.addEventListener('DOMContentLoaded', updateMockPreview);
    setTimeout(updateMockPreview, 0);
})();
JS;
}

function tk_general_login_header_url(string $url): string {
    return home_url('/');
}

function tk_general_login_header_text(string $text): string {
    return get_bloginfo('name');
}

function tk_general_login_branding_styles(): void {
    if ((int) tk_get_option('login_branding_enabled', 0) !== 1) {
        return;
    }

    $logo_id = (int) tk_get_option('login_logo_id', 0);
    $background_id = (int) tk_get_option('login_background_id', 0);
    $logo_url = $logo_id > 0 ? wp_get_attachment_image_url($logo_id, 'full') : '';
    $background_url = $background_id > 0 ? wp_get_attachment_image_url($background_id, 'full') : '';
    $logo_width = max(80, min(420, (int) tk_get_option('login_logo_width', 240)));
    $logo_height = max(40, min(180, (int) tk_get_option('login_logo_height', 96)));
    $overlay_color = sanitize_hex_color((string) tk_get_option('login_overlay_color', '#0f172a'));
    $overlay_color = $overlay_color ?: '#0f172a';
    $overlay_opacity = max(0, min(100, (int) tk_get_option('login_overlay_opacity', 46)));
    $overlay_rgba = tk_general_hex_to_rgba($overlay_color, $overlay_opacity / 100);
    $form_background_color = sanitize_hex_color((string) tk_get_option('login_form_background_color', '#ffffff'));
    $form_background_color = $form_background_color ?: '#ffffff';
    $form_text_color = sanitize_hex_color((string) tk_get_option('login_form_text_color', '#1f2937'));
    $form_text_color = $form_text_color ?: '#1f2937';

    ?>
    <style>
        <?php if ($background_url) : ?>
        body.login {
            background-image: linear-gradient(<?php echo esc_html($overlay_rgba); ?>, <?php echo esc_html($overlay_rgba); ?>), url('<?php echo esc_url($background_url); ?>');
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
        }
        body.login #login {
            padding-top: 7vh;
        }
        body.login #nav,
        body.login #backtoblog {
            text-shadow: 0 1px 3px rgba(15, 23, 42, 0.45);
        }
        body.login #nav a,
        body.login #backtoblog a {
            color: #fff;
        }
        <?php endif; ?>
        body.login form {
            border: 0;
            border-radius: 12px;
            background: <?php echo esc_html($form_background_color); ?>;
            color: <?php echo esc_html($form_text_color); ?>;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.2);
        }
        body.login form label,
        body.login form .forgetmenot label,
        body.login form p,
        body.login #login_error,
        body.login .message {
            color: <?php echo esc_html($form_text_color); ?>;
        }
        body.login form input[type="checkbox"] {
            border-color: <?php echo esc_html($form_text_color); ?>;
        }
        <?php if ($logo_url) : ?>
        body.login h1 a {
            width: 100%;
            max-width: <?php echo esc_html((string) $logo_width); ?>px;
            height: <?php echo esc_html((string) $logo_height); ?>px;
            background-image: url('<?php echo esc_url($logo_url); ?>');
            background-position: center;
            background-repeat: no-repeat;
            background-size: contain;
        }
        <?php endif; ?>
    </style>
    <?php
}

function tk_general_hex_to_rgba(string $hex, float $alpha): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = '0f172a';
    }

    $alpha = max(0, min(1, $alpha));
    return sprintf(
        'rgba(%d, %d, %d, %.2F)',
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
        $alpha
    );
}

function tk_general_sanitize_image_attachment_id($value): int {
    $attachment_id = absint($value);
    if ($attachment_id <= 0) {
        return 0;
    }

    $url = wp_get_attachment_image_url($attachment_id, 'full');
    $mime = get_post_mime_type($attachment_id);
    if (!is_string($mime) || strpos($mime, 'image/') !== 0) {
        return 0;
    }

    return $url ? $attachment_id : 0;
}

function tk_render_general_page(): void {
    require __DIR__ . '/../templates/general-page.php';
}

function tk_general_save(): void {
    $settings = array();
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }

    tk_check_nonce('tk_general_save');

    $section = isset($_POST['tk_general_section']) ? sanitize_key((string) $_POST['tk_general_section']) : 'editing';
    if (!in_array($section, array('editing', '404-handling', 'xml-sitemap', 'login-page', 'admin-menu', 'cookie-consent', 'server-time'), true)) {
        $section = 'editing';
    }

    if ($section === 'editing') {
        $settings['classic_editor_enabled'] = !empty($_POST['classic_editor_enabled']) ? 1 : 0;
        $settings['classic_widgets_enabled'] = !empty($_POST['classic_widgets_enabled']) ? 1 : 0;
        $settings['duplicate_content_enabled'] = !empty($_POST['duplicate_content_enabled']) ? 1 : 0;
    } elseif ($section === '404-handling') {
        $settings['monitoring_404_redirect_home'] = !empty($_POST['monitoring_404_redirect_home']) ? 1 : 0;
    } elseif ($section === 'xml-sitemap') {
        $sitemap_path = sanitize_text_field((string) tk_post('seo_sitemap_path', 'sitemap.xml'));
        $sitemap_path = trim($sitemap_path);
        if ($sitemap_path === '') {
            $sitemap_path = 'sitemap.xml';
        }
        $settings['seo_sitemap_enabled'] = !empty($_POST['seo_sitemap_enabled']) ? 1 : 0;
        $settings['seo_sitemap_path'] = ltrim($sitemap_path, '/');
        $settings['seo_sitemap_include_taxonomies'] = !empty($_POST['seo_sitemap_include_taxonomies']) ? 1 : 0;
        $settings['seo_sitemap_include_images'] = !empty($_POST['seo_sitemap_include_images']) ? 1 : 0;
        $changefreq = sanitize_key((string) tk_post('seo_sitemap_changefreq', 'weekly'));
        if (!in_array($changefreq, array('always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'), true)) {
            $changefreq = 'weekly';
        }
        $settings['seo_sitemap_changefreq'] = $changefreq;
        $settings['seo_sitemap_priority'] = max(0, min(1, (float) tk_post('seo_sitemap_priority', 0.8)));
        $settings['seo_sitemap_exclude_paths'] = (string) tk_post('seo_sitemap_exclude_paths', '');
        $settings['sitemap_google_property'] = sanitize_text_field((string) tk_post('sitemap_google_property', home_url('/')));
        if (!empty($_POST['sitemap_google_token_clear'])) {
            $settings['sitemap_google_access_token'] = '';
        } elseif (trim((string) tk_post('sitemap_google_access_token', '')) !== '') {
            $settings['sitemap_google_access_token'] = sanitize_text_field((string) tk_post('sitemap_google_access_token', ''));
        }
        if (!empty($_POST['sitemap_bing_token_clear'])) {
            $settings['sitemap_bing_access_token'] = '';
        } elseif (trim((string) tk_post('sitemap_bing_access_token', '')) !== '') {
            $settings['sitemap_bing_access_token'] = sanitize_text_field((string) tk_post('sitemap_bing_access_token', ''));
        }
    } elseif ($section === 'login-page') {
        $overlay_color = sanitize_hex_color((string) tk_post('login_overlay_color', '#0f172a'));
        if (!$overlay_color) {
            $overlay_color = '#0f172a';
        }

        $settings['login_branding_enabled'] = !empty($_POST['login_branding_enabled']) ? 1 : 0;
        $settings['login_logo_id'] = tk_general_sanitize_image_attachment_id(tk_post('login_logo_id', 0));
        $settings['login_background_id'] = tk_general_sanitize_image_attachment_id(tk_post('login_background_id', 0));
        $settings['login_logo_width'] = max(80, min(420, absint(tk_post('login_logo_width', 240))));
        $settings['login_logo_height'] = max(40, min(180, absint(tk_post('login_logo_height', 96))));
        $settings['login_overlay_color'] = $overlay_color;
        $settings['login_overlay_opacity'] = max(0, min(100, absint(tk_post('login_overlay_opacity', 46))));
        $form_background_color = sanitize_hex_color((string) tk_post('login_form_background_color', '#ffffff'));
        $form_text_color = sanitize_hex_color((string) tk_post('login_form_text_color', '#1f2937'));
        $settings['login_form_background_color'] = $form_background_color ?: '#ffffff';
        $settings['login_form_text_color'] = $form_text_color ?: '#1f2937';
    } elseif ($section === 'admin-menu') {
        $settings['hide_toolkits_menu'] = !empty($_POST['hide_toolkits_menu']) ? 1 : 0;
        $settings['hide_cff_menu'] = !empty($_POST['hide_cff_menu']) ? 1 : 0;
        $hidden = isset($_POST['hidden_menus']) && is_array($_POST['hidden_menus']) ? array_map('sanitize_text_field', $_POST['hidden_menus']) : array();
        $settings['tk_hidden_admin_menus'] = $hidden;
    } elseif ($section === 'cookie-consent') {
        $settings['cookie_consent_enabled'] = !empty($_POST['cookie_consent_enabled']) ? 1 : 0;
        $settings['cookie_consent_text'] = wp_kses_post((string) tk_post('cookie_consent_text', ''));
        $settings['cookie_consent_privacy_url'] = sanitize_url((string) tk_post('cookie_consent_privacy_url', ''));
        $settings['cookie_consent_expiry'] = absint(tk_post('cookie_consent_expiry', 365));
    } elseif ($section === 'server-time') {
        $tz = sanitize_text_field((string) tk_post('tk_server_timezone', ''));
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            update_option('timezone_string', $tz);
        }
        // Retire the old override so it is no longer applied to PHP's default timezone.
        $settings['tk_server_timezone'] = '';
    }

    tk_update_options($settings);
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-general&tk_saved=1#' . $section));
    exit;
}

function tk_general_sitemap_generate(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }

    tk_check_nonce('tk_general_sitemap_generate');

    if (!function_exists('tk_seo_generate_sitemap_file')) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-general',
            'tk_sitemap_generated' => 'fail',
            'tk_sitemap_msg' => 'Sitemap generator is unavailable.',
        ), admin_url('admin.php')) . '#xml-sitemap');
        exit;
    }

    $result = tk_seo_generate_sitemap_file();
    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-general',
            'tk_sitemap_generated' => 'fail',
            'tk_sitemap_msg' => $result->get_error_message(),
        ), admin_url('admin.php')) . '#xml-sitemap');
        exit;
    }

    $url = is_array($result) && !empty($result['url']) ? (string) $result['url'] : home_url('/' . ltrim((string) tk_get_option('seo_sitemap_path', 'sitemap.xml'), '/'));
    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-general',
        'tk_sitemap_generated' => 'ok',
        'tk_sitemap_msg' => 'XML sitemap generated: ' . $url,
    ), admin_url('admin.php')) . '#xml-sitemap');
    exit;
}
