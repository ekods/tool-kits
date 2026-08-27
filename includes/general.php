<?php
if (!defined('ABSPATH')) { exit; }

function tk_general_init(): void {
    add_action('admin_post_tk_general_save', 'tk_general_save');
    add_action('admin_post_tk_general_sitemap_generate', 'tk_general_sitemap_generate');
    add_action('admin_post_tk_general_settings_export', 'tk_general_settings_export');
    add_action('admin_post_tk_general_settings_import', 'tk_general_settings_import');
    add_action('admin_post_tk_general_preset_apply', 'tk_general_preset_apply');
    add_action('admin_post_tk_general_diagnostics_export', 'tk_general_diagnostics_export');
    add_action('admin_enqueue_scripts', 'tk_general_maybe_enqueue_assets');
    add_action('login_enqueue_scripts', 'tk_general_login_branding_styles');
    add_filter('login_headerurl', 'tk_general_login_header_url');
    add_filter('login_headertext', 'tk_general_login_header_text');
    
    $tz = (string) tk_get_option('tk_server_timezone', '');
    if ($tz !== '') {
        date_default_timezone_set($tz);
    }
}

function tk_general_sensitive_option_keys(): array {
    return array(
        'hardening_httpauth_pass',
        'heartbeat_auth_key',
        'heartbeat_http_pass',
        'license_key',
        'license_message',
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
                'login_log_enabled' => 1,
                'hardening_disable_file_editor' => 1,
                'hardening_disable_rest_user_enum' => 1,
                'hardening_block_author_enumeration' => 1,
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
                'hardening_disable_file_editor' => 1,
                'hardening_disable_xmlrpc' => 1,
                'hardening_disable_rest_user_enum' => 1,
                'hardening_block_author_enumeration' => 1,
                'hardening_remove_query_ver' => 1,
                'hardening_disable_emojis' => 1,
                'hardening_disable_pingbacks' => 1,
                'hardening_hide_wp_version' => 1,
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
        if ($key === 'toolkits_audit_log') {
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
    if (!tk_toolkits_can_manage()) {
        return;
    }

    $saved = isset($_GET['tk_saved']) ? sanitize_key((string) $_GET['tk_saved']) : '';
    $classic_editor_enabled = (int) tk_get_option('classic_editor_enabled', 0);
    $classic_widgets_enabled = (int) tk_get_option('classic_widgets_enabled', 0);
    $redirect_404_home = (int) tk_get_option('monitoring_404_redirect_home', 0);
    $hide_toolkits_menu = (int) tk_get_option('hide_toolkits_menu', 0);
    $hide_cff_menu = (int) tk_get_option('hide_cff_menu', 0);
    $cff_installed = function_exists('tk_is_cff_installed') && tk_is_cff_installed();
    $sitemap_enabled = (int) tk_get_option('seo_sitemap_enabled', 1);
    $sitemap_path = (string) tk_get_option('seo_sitemap_path', 'sitemap.xml');
    $sitemap_tax = (int) tk_get_option('seo_sitemap_include_taxonomies', 1);
    $sitemap_images = (int) tk_get_option('seo_sitemap_include_images', 1);
    $sitemap_changefreq = (string) tk_get_option('seo_sitemap_changefreq', 'weekly');
    $sitemap_priority = (string) tk_get_option('seo_sitemap_priority', '0.8');
    $sitemap_excludes = (string) tk_get_option('seo_sitemap_exclude_paths', '');
    $sitemap_url = home_url('/' . ltrim($sitemap_path, '/'));
    $sitemap_generated = isset($_GET['tk_sitemap_generated']) ? sanitize_key((string) $_GET['tk_sitemap_generated']) : '';
    $sitemap_message = isset($_GET['tk_sitemap_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['tk_sitemap_msg'])) : '';
    $login_logo_id = (int) tk_get_option('login_logo_id', 0);
    $login_background_id = (int) tk_get_option('login_background_id', 0);
    $login_logo_url = $login_logo_id > 0 ? wp_get_attachment_image_url($login_logo_id, 'medium') : '';
    $login_background_url = $login_background_id > 0 ? wp_get_attachment_image_url($login_background_id, 'large') : '';
    $login_branding_enabled = (int) tk_get_option('login_branding_enabled', 0);
    $login_logo_width = max(80, min(420, (int) tk_get_option('login_logo_width', 240)));
    $login_logo_height = max(40, min(180, (int) tk_get_option('login_logo_height', 96)));
    $login_overlay_color = sanitize_hex_color((string) tk_get_option('login_overlay_color', '#0f172a'));
    $login_overlay_color = $login_overlay_color ?: '#0f172a';
    $login_overlay_opacity = max(0, min(100, (int) tk_get_option('login_overlay_opacity', 46)));
    $login_overlay_rgba = tk_general_hex_to_rgba($login_overlay_color, $login_overlay_opacity / 100);
    $login_form_background_color = sanitize_hex_color((string) tk_get_option('login_form_background_color', '#ffffff'));
    $login_form_background_color = $login_form_background_color ?: '#ffffff';
    $login_form_text_color = sanitize_hex_color((string) tk_get_option('login_form_text_color', '#1f2937'));
    $login_form_text_color = $login_form_text_color ?: '#1f2937';
    $presets = tk_general_settings_presets();
    $imported = isset($_GET['tk_imported']) ? sanitize_key((string) $_GET['tk_imported']) : '';
    $import_message = isset($_GET['tk_import_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['tk_import_msg'])) : '';
    $preset_status = isset($_GET['tk_preset']) ? sanitize_key((string) $_GET['tk_preset']) : '';
    $preset_name = isset($_GET['tk_preset_name']) ? sanitize_text_field(wp_unslash((string) $_GET['tk_preset_name'])) : '';
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('General Settings', 'tool-kits'), __('Configure core plugin behavior, classic editor settings, and global defaults.', 'tool-kits'), 'dashicons-admin-generic'); ?>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('General settings saved.', 'success'); ?>
        <?php endif; ?>

        <div class="tk-tabs tk-general-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="editing">Editing</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="404-handling">404 Handling</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="xml-sitemap">XML Sitemap</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="login-page">Login Page</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="admin-menu">Admin Menu</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="cookie-consent">Cookie Consent</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="uploads">Uploads</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="server-time">Server Time</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="maintenance">Maintenance</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="editing">
                    <h2>Editing</h2>
                    <p>Site-wide editing behavior for posts and widgets.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_general_save'); ?>
                        <input type="hidden" name="action" value="tk_general_save">
                        <input type="hidden" name="tk_general_section" value="editing">
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <?php 
                            tk_render_switch('classic_editor_enabled', 'Enable Classic Editor', 'Uses the old-style Edit Post screen with TinyMCE and Meta Boxes.', $classic_editor_enabled);
                            tk_render_switch('classic_widgets_enabled', 'Enable Classic Widgets', 'Enables the previous classic widgets screens and disables the block editor for widgets.', $classic_widgets_enabled);
                            ?>
                        </div>
                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="404-handling">
                    <h2>404 Handling</h2>
                    <p>Site-wide behavior for missing frontend URLs.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_general_save'); ?>
                        <input type="hidden" name="action" value="tk_general_save">
                        <input type="hidden" name="tk_general_section" value="404-handling">
                        <?php 
                        tk_render_switch('monitoring_404_redirect_home', 'Redirect 404 to Homepage', 'Automatically redirect broken links to the homepage using a 302 redirect.', $redirect_404_home);
                        ?>
                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="xml-sitemap">
                    <h2>XML Sitemap Generator for Google</h2>
                    <p>Generate a Google-compatible XML sitemap for public content, taxonomy archives, and featured images.</p>
                    <?php if ($sitemap_generated === 'ok') : ?>
                        <?php tk_notice($sitemap_message !== '' ? $sitemap_message : 'XML sitemap generated.', 'success'); ?>
                    <?php elseif ($sitemap_generated === 'fail') : ?>
                        <?php tk_notice($sitemap_message !== '' ? $sitemap_message : 'Failed to generate XML sitemap.', 'error'); ?>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_general_save'); ?>
                        <input type="hidden" name="action" value="tk_general_save">
                        <input type="hidden" name="tk_general_section" value="xml-sitemap">

                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <?php tk_render_switch('seo_sitemap_enabled', 'Enable XML Sitemap', 'Automatically generate and update sitemap.xml.', $sitemap_enabled); ?>
                            
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Sitemap Path</label>
                                    <p class="description">URL relative to root.</p>
                                </div>
                                <input type="text" name="seo_sitemap_path" value="<?php echo esc_attr($sitemap_path); ?>" placeholder="sitemap.xml" style="width:200px;">
                            </div>

                            <?php 
                            tk_render_switch('seo_sitemap_include_taxonomies', 'Include Taxonomies', 'Include category and tag archives.', $sitemap_tax);
                            tk_render_switch('seo_sitemap_include_images', 'Include Images', 'Include featured images in post entries.', $sitemap_images);
                            ?>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft); display:flex; gap:10px;">
                            <button class="button button-primary">Save Settings</button>
                        </div>
                    </form>

                    <div style="margin-top:30px; padding:20px; background:var(--tk-bg-soft); border-radius:12px; border:1px solid var(--tk-border-soft);">
                        <h4 style="margin-top:0;">Manual Generation</h4>
                        <p class="description">Trigger a manual rebuild of the sitemap file now.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php tk_nonce_field('tk_general_sitemap_generate'); ?>
                            <input type="hidden" name="action" value="tk_general_sitemap_generate">
                            <button class="button button-secondary">Generate Now</button>
                            <a class="button" href="<?php echo esc_url($sitemap_url); ?>" target="_blank" rel="noopener">View Sitemap</a>
                        </form>
                    </div>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="login-page">
                    <h2>Login Page Branding</h2>
                    <p>Customize the WordPress login page logo and background image.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_general_save'); ?>
                        <input type="hidden" name="action" value="tk_general_save">
                        <input type="hidden" name="tk_general_section" value="login-page">

                        <div data-tk-login-branding-control>
                            <?php tk_render_switch('login_branding_enabled', 'Enable Custom Login Page', 'Apply the logo, background, and visual settings below to wp-login.php.', $login_branding_enabled); ?>
                        </div>

                        <div class="tk-login-branding-grid">
                            <div class="tk-media-control">
                                <div class="tk-control-info">
                                    <label>Login Logo</label>
                                    <p class="description">Recommended: transparent PNG or SVG-like mark, up to 320px wide.</p>
                                </div>
                                <div class="tk-media-picker">
                                    <input type="hidden" name="login_logo_id" value="<?php echo esc_attr((string) $login_logo_id); ?>" data-tk-media-input="login_logo">
                                    <div class="tk-media-preview <?php echo $login_logo_url ? '' : 'is-empty'; ?>" data-tk-media-preview="login_logo">
                                        <?php if ($login_logo_url) : ?>
                                            <img src="<?php echo esc_url($login_logo_url); ?>" alt="">
                                        <?php else : ?>
                                            <span>No image selected</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tk-inline">
                                        <button type="button" class="button button-secondary" data-tk-media-select="login_logo" data-title="Select login logo" data-button="Use this logo">Choose Logo</button>
                                        <button type="button" class="button" data-tk-media-remove="login_logo">Remove</button>
                                    </div>
                                </div>
                            </div>

                            <div class="tk-media-control">
                                <div class="tk-control-info">
                                    <label>Login Background</label>
                                    <p class="description">Recommended: landscape image at least 1600px wide.</p>
                                </div>
                                <div class="tk-media-picker">
                                    <input type="hidden" name="login_background_id" value="<?php echo esc_attr((string) $login_background_id); ?>" data-tk-media-input="login_background">
                                    <div class="tk-media-preview tk-media-preview--wide <?php echo $login_background_url ? '' : 'is-empty'; ?>" data-tk-media-preview="login_background">
                                        <?php if ($login_background_url) : ?>
                                            <img src="<?php echo esc_url($login_background_url); ?>" alt="">
                                        <?php else : ?>
                                            <span>No image selected</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tk-inline">
                                        <button type="button" class="button button-secondary" data-tk-media-select="login_background" data-title="Select login background" data-button="Use this background">Choose Background</button>
                                        <button type="button" class="button" data-tk-media-remove="login_background">Remove</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tk-login-branding-settings" data-tk-login-branding-control>
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Logo Max Width</label>
                                    <p class="description">Controls the displayed logo width on the login screen.</p>
                                </div>
                                <input type="number" name="login_logo_width" value="<?php echo esc_attr((string) $login_logo_width); ?>" min="80" max="420" step="1" class="small-text">
                            </div>
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Logo Height</label>
                                    <p class="description">Controls the reserved vertical logo space.</p>
                                </div>
                                <input type="number" name="login_logo_height" value="<?php echo esc_attr((string) $login_logo_height); ?>" min="40" max="180" step="1" class="small-text">
                            </div>
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Background Overlay</label>
                                    <p class="description">Use overlay color and opacity to keep the login form readable.</p>
                                </div>
                                <div class="tk-inline">
                                    <input type="text" name="login_overlay_color" value="<?php echo esc_attr($login_overlay_color); ?>" class="tk-color-field" data-default-color="#0f172a">
                                    <input type="number" name="login_overlay_opacity" value="<?php echo esc_attr((string) $login_overlay_opacity); ?>" min="0" max="100" step="1" class="small-text">
                                    <span class="tk-toolbar-note">%</span>
                                </div>
                            </div>
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Form Background</label>
                                    <p class="description">Set the background color for the login form.</p>
                                </div>
                                <input type="text" name="login_form_background_color" value="<?php echo esc_attr($login_form_background_color); ?>" class="tk-color-field" data-default-color="#ffffff">
                            </div>
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Form Font Color</label>
                                    <p class="description">Set the text color inside the login form.</p>
                                </div>
                                <input type="text" name="login_form_text_color" value="<?php echo esc_attr($login_form_text_color); ?>" class="tk-color-field" data-default-color="#1f2937">
                            </div>
                        </div>

                        <div class="tk-login-mock <?php echo $login_branding_enabled ? '' : 'is-disabled'; ?>" data-tk-login-mock style="<?php echo $login_background_url ? 'background-image:url(' . esc_url($login_background_url) . ');' : ''; ?>">
                            <div class="tk-login-mock-overlay" data-tk-login-mock-overlay style="background:<?php echo esc_attr($login_overlay_rgba); ?>;"></div>
                            <div class="tk-login-mock-inactive" data-tk-login-mock-inactive style="<?php echo $login_branding_enabled ? 'display:none;' : ''; ?>">Custom login page disabled</div>
                            <div class="tk-login-mock-stage">
                                <div class="tk-login-mock-brand" style="--tk-login-logo-width:<?php echo esc_attr((string) $login_logo_width); ?>px; --tk-login-logo-height:<?php echo esc_attr((string) $login_logo_height); ?>px;">
                                    <img data-tk-login-mock-logo src="<?php echo esc_url($login_logo_url); ?>" alt="" style="<?php echo $login_logo_url ? '' : 'display:none;'; ?> max-width:<?php echo esc_attr((string) $login_logo_width); ?>px; max-height:<?php echo esc_attr((string) $login_logo_height); ?>px;">
                                    <span data-tk-login-mock-fallback style="<?php echo $login_logo_url ? 'display:none;' : ''; ?>">Site Logo</span>
                                </div>
                                <div class="tk-login-mock-form" style="background-color:<?php echo esc_attr($login_form_background_color); ?>; color:<?php echo esc_attr($login_form_text_color); ?>;">
                                    <div class="tk-login-mock-line tk-login-mock-line--label"></div>
                                    <div class="tk-login-mock-field"></div>
                                    <div class="tk-login-mock-line tk-login-mock-line--label is-short"></div>
                                    <div class="tk-login-mock-field">
                                        <span class="tk-login-mock-line"></span>
                                        <span class="tk-login-mock-eye"></span>
                                    </div>
                                    <div class="tk-login-mock-row">
                                        <div class="tk-login-mock-check"></div>
                                        <div class="tk-login-mock-line tk-login-mock-line--remember"></div>
                                        <div class="tk-login-mock-button"></div>
                                    </div>
                                </div>
                                <div class="tk-login-mock-links" aria-hidden="true">
                                    <span></span>
                                    <span></span>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Settings</button>
                            <a class="button button-secondary" href="<?php echo esc_url(wp_login_url()); ?>" target="_blank" rel="noopener">Preview Login Page</a>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="admin-menu">
                    <h2>Admin UI Customization</h2>
                    <p>Manage the visibility of WordPress core menus and Tool Kits in the sidebar.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_general_save'); ?>
                        <input type="hidden" name="action" value="tk_general_save">
                        <input type="hidden" name="tk_general_section" value="admin-menu">
                        
                        <div style="display:flex; flex-direction:column; gap:32px;">
                            <div>
                                <h4 style="margin:0 0 16px; font-size:14px; color:var(--tk-primary); display:flex; align-items:center; gap:8px;">
                                    <span class="dashicons dashicons-admin-plugins" style="font-size:18px; width:18px; height:18px;"></span>
                                    Plugin Menus
                                </h4>
                                <div style="display:flex; flex-direction:column; gap:16px;">
                                    <?php 
                                    tk_render_switch('hide_toolkits_menu', 'Hide Tool Kits Menus', 'Remove all Tool Kits parent menus from the main sidebar.', $hide_toolkits_menu);
                                    if ($cff_installed) {
                                        tk_render_switch('hide_cff_menu', 'Hide CFF Menu', 'Remove Custom Font Framework from the sidebar.', $hide_cff_menu);
                                    }
                                    ?>
                                </div>
                            </div>

                            <div>
                                <h4 style="margin:0 0 8px; font-size:14px; color:var(--tk-primary); display:flex; align-items:center; gap:8px;">
                                    <span class="dashicons dashicons-visibility" style="font-size:18px; width:18px; height:18px;"></span>
                                    Hide Core Menus
                                </h4>
                                <p class="description" style="margin-bottom:20px;">Select which standard WordPress menus to hide from the dashboard sidebar.</p>
                                
                                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:12px;">
                                    <?php 
                                    $core_items = function_exists('tk_admin_menu_get_core_items') ? tk_admin_menu_get_core_items() : array();
                                    $hidden_menus = tk_get_option('tk_hidden_admin_menus', array());
                                    foreach ($core_items as $slug => $data) : 
                                        $is_checked = is_array($hidden_menus) && in_array($slug, $hidden_menus, true);
                                    ?>
                                        <label class="tk-checkable-card <?php echo $is_checked ? 'is-checked' : ''; ?>" style="background:var(--tk-bg-soft); border:1px solid var(--tk-border-soft); padding:16px; border-radius:14px; display:flex; align-items:center; gap:12px; cursor:pointer; transition:all 0.2s; position:relative;">
                                            <input type="checkbox" name="hidden_menus[]" value="<?php echo esc_attr($slug); ?>" <?php checked($is_checked); ?> style="margin:0;">
                                            <span class="dashicons <?php echo esc_attr($data['icon']); ?>" style="color:var(--tk-primary); opacity:0.8; font-size:20px; width:20px; height:20px;"></span>
                                            <span style="font-weight:600; font-size:13px; color:var(--tk-text);"><?php echo esc_html($data['label']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top:32px; padding-top:24px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="cookie-consent">
                    <h2>Cookie Consent</h2>
                    <p>Display a customizable cookie consent banner at the bottom of the screen.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_general_save'); ?>
                        <input type="hidden" name="action" value="tk_general_save">
                        <input type="hidden" name="tk_general_section" value="cookie-consent">
                        
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <?php 
                            tk_render_switch('cookie_consent_enabled', 'Enable Cookie Consent', 'Display the cookie consent banner on the frontend.', (int) tk_get_option('cookie_consent_enabled', 0));
                            ?>
                            <div class="tk-control-row" style="align-items: flex-start;">
                                <div class="tk-control-info" style="flex: 0 0 250px;">
                                    <label>Cookie Text</label>
                                    <p class="description">Default wording will be used if left empty.</p>
                                </div>
                                <div style="flex: 1; max-width: 600px; background: #fff;">
                                    <?php 
                                    $cookie_text_val = (string) tk_get_option('cookie_consent_text', '');
                                    if (empty($cookie_text_val)) {
                                        $cookie_text_val = 'By clicking <strong>"Accept"</strong>, you agree to the storing of cookies on your device to enhance site navigation, analyze site usage, and assist in our marketing efforts. View our <a href="#" class="tk-cookie-consent-link">Privacy Policy</a> for more information.';
                                    }
                                    wp_editor($cookie_text_val, 'cookie_consent_text', array(
                                        'textarea_name' => 'cookie_consent_text',
                                        'media_buttons' => false,
                                        'textarea_rows' => 5,
                                        'teeny'         => true,
                                    )); 
                                    ?>
                                </div>
                            </div>
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Privacy Policy URL</label>
                                    <p class="description">Link for the Privacy Policy in the default text.</p>
                                </div>
                                <input type="text" name="cookie_consent_privacy_url" value="<?php echo esc_attr((string) tk_get_option('cookie_consent_privacy_url', '')); ?>" placeholder="https://..." style="width: 100%; max-width: 400px;">
                            </div>
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Cookie Expiration (Days)</label>
                                    <p class="description">How long to remember the user's consent.</p>
                                </div>
                                <input type="number" name="cookie_consent_expiry" value="<?php echo esc_attr((string) tk_get_option('cookie_consent_expiry', '365')); ?>" min="1" max="3650" style="width: 100px;">
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-card tk-tab-panel" data-panel-id="server-time">
                    <h2>Server Timezone Override</h2>
                    <p>Force a specific timezone for PHP functions (e.g. date_default_timezone_set) if your server time differs from your local time.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_general_save'); ?>
                        <input type="hidden" name="action" value="tk_general_save">
                        <input type="hidden" name="tk_general_section" value="server-time">
                        
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>PHP Timezone</label>
                                    <p class="description">Select the timezone to enforce.</p>
                                </div>
                                <select name="tk_server_timezone" style="width: 250px;">
                                    <option value="">-- Default (Server) --</option>
                                    <?php 
                                    $current_tz = (string) tk_get_option('tk_server_timezone', '');
                                    foreach (timezone_identifiers_list() as $tz) {
                                        echo '<option value="' . esc_attr($tz) . '" ' . selected($current_tz, $tz, false) . '>' . esc_html($tz) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero">Save Settings</button>
                        </div>
                    </form>
                </div>

                <div class="tk-tab-panel" data-panel-id="uploads">
                    <?php tk_render_upload_limits_panel(); ?>
                </div>


                <div class="tk-card tk-tab-panel" data-panel-id="maintenance">
                    <h2>Maintenance</h2>
                    <p>Backup, restore, preset, and diagnostic tools for Tool Kits configuration.</p>

                    <?php if ($imported === 'ok') : ?>
                        <?php tk_notice($import_message !== '' ? $import_message : 'Settings imported.', 'success'); ?>
                    <?php elseif ($imported === 'fail') : ?>
                        <?php tk_notice($import_message !== '' ? $import_message : 'Settings import failed.', 'error'); ?>
                    <?php endif; ?>
                    <?php if ($preset_status === 'ok') : ?>
                        <?php tk_notice(($preset_name !== '' ? $preset_name : 'Preset') . ' applied.', 'success'); ?>
                    <?php elseif ($preset_status === 'fail') : ?>
                        <?php tk_notice('Preset could not be applied.', 'error'); ?>
                    <?php endif; ?>

                    <div class="tk-maintenance-grid">
                        <div class="tk-maintenance-panel">
                            <div class="tk-maintenance-panel-head">
                                <span class="dashicons dashicons-download"></span>
                                <div>
                                    <h3>Settings Backup</h3>
                                    <p class="description">Download a JSON backup of the current Tool Kits settings.</p>
                                </div>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php tk_nonce_field('tk_general_settings_export'); ?>
                                <input type="hidden" name="action" value="tk_general_settings_export">
                                <button class="button button-primary">Export Settings</button>
                            </form>
                        </div>

                        <div class="tk-maintenance-panel">
                            <div class="tk-maintenance-panel-head">
                                <span class="dashicons dashicons-upload"></span>
                                <div>
                                    <h3>Settings Restore</h3>
                                    <p class="description">Import a Tool Kits settings JSON file. Merge keeps existing values that are not in the file.</p>
                                </div>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                                <?php tk_nonce_field('tk_general_settings_import'); ?>
                                <input type="hidden" name="action" value="tk_general_settings_import">
                                <div class="tk-file-input">
                                    <input type="file" name="settings_file" accept="application/json,.json" required>
                                </div>
                                <label class="tk-checkbox-line">
                                    <input type="checkbox" name="replace_settings" value="1">
                                    Replace existing Tool Kits settings
                                </label>
                                <button class="button button-secondary" data-confirm="Importing settings can change active security and optimization behavior. Continue?">Import Settings</button>
                            </form>
                        </div>

                        <div class="tk-maintenance-panel">
                            <div class="tk-maintenance-panel-head">
                                <span class="dashicons dashicons-analytics"></span>
                                <div>
                                    <h3>Diagnostics Export</h3>
                                    <p class="description">Download a support report with environment details and masked Tool Kits settings.</p>
                                </div>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php tk_nonce_field('tk_general_diagnostics_export'); ?>
                                <input type="hidden" name="action" value="tk_general_diagnostics_export">
                                <button class="button button-secondary">Export Diagnostics</button>
                            </form>
                        </div>
                    </div>

                    <div class="tk-maintenance-presets">
                        <div class="tk-section-heading">
                            <div>
                                <h3>Preset Profiles</h3>
                                <p class="description">Apply a curated set of settings as a starting point. Existing unrelated settings are preserved.</p>
                            </div>
                        </div>
                        <div class="tk-preset-grid">
                            <?php foreach ($presets as $preset_key => $preset) : ?>
                                <form class="tk-preset-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php tk_nonce_field('tk_general_preset_apply'); ?>
                                    <input type="hidden" name="action" value="tk_general_preset_apply">
                                    <input type="hidden" name="preset_key" value="<?php echo esc_attr($preset_key); ?>">
                                    <div class="tk-preset-card-head">
                                        <h4><?php echo esc_html($preset['label']); ?></h4>
                                        <span><?php echo esc_html((string) count($preset['options'])); ?> settings</span>
                                    </div>
                                    <p><?php echo esc_html($preset['description']); ?></p>
                                    <div class="tk-preset-card-actions">
                                        <button class="button button-secondary" data-confirm="This will update <?php echo esc_attr((string) count($preset['options'])); ?> Tool Kits settings. Continue?">Apply Preset</button>
                                    </div>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var wrapper = document.querySelector('.tk-general-tabs');
            if (!wrapper) { return; }
            function activateTab(panelId) {
                wrapper.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                });
                wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                    button.classList.toggle('is-active', button.getAttribute('data-panel') === panelId);
                });
            }
            function getPanelFromHash() {
                var hash = window.location.hash || '';
                return hash ? hash.replace('#', '') : '';
            }
            wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    if (panelId) {
                        window.location.hash = panelId;
                        activateTab(panelId);
                    }
                });
            });
            var initial = getPanelFromHash();
            if (initial && wrapper.querySelector('.tk-tab-panel[data-panel-id="' + initial + '"]')) {
                activateTab(initial);
            }
        })();
        </script>
    </div>
    <?php
}

function tk_general_save(): void {
    if (!tk_toolkits_can_manage()) {
        wp_die('Forbidden');
    }

    tk_check_nonce('tk_general_save');

    $section = isset($_POST['tk_general_section']) ? sanitize_key((string) $_POST['tk_general_section']) : 'editing';
    if (!in_array($section, array('editing', '404-handling', 'xml-sitemap', 'login-page', 'admin-menu', 'cookie-consent', 'server-time'), true)) {
        $section = 'editing';
    }

    if ($section === 'editing') {
        tk_update_option('classic_editor_enabled', !empty($_POST['classic_editor_enabled']) ? 1 : 0);
        tk_update_option('classic_widgets_enabled', !empty($_POST['classic_widgets_enabled']) ? 1 : 0);
    } elseif ($section === '404-handling') {
        tk_update_option('monitoring_404_redirect_home', !empty($_POST['monitoring_404_redirect_home']) ? 1 : 0);
    } elseif ($section === 'xml-sitemap') {
        $sitemap_path = sanitize_text_field((string) tk_post('seo_sitemap_path', 'sitemap.xml'));
        $sitemap_path = trim($sitemap_path);
        if ($sitemap_path === '') {
            $sitemap_path = 'sitemap.xml';
        }
        tk_update_option('seo_sitemap_enabled', !empty($_POST['seo_sitemap_enabled']) ? 1 : 0);
        tk_update_option('seo_sitemap_path', ltrim($sitemap_path, '/'));
        tk_update_option('seo_sitemap_include_taxonomies', !empty($_POST['seo_sitemap_include_taxonomies']) ? 1 : 0);
        tk_update_option('seo_sitemap_include_images', !empty($_POST['seo_sitemap_include_images']) ? 1 : 0);
        $changefreq = sanitize_key((string) tk_post('seo_sitemap_changefreq', 'weekly'));
        if (!in_array($changefreq, array('always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'), true)) {
            $changefreq = 'weekly';
        }
        tk_update_option('seo_sitemap_changefreq', $changefreq);
        tk_update_option('seo_sitemap_priority', max(0, min(1, (float) tk_post('seo_sitemap_priority', 0.8))));
        tk_update_option('seo_sitemap_exclude_paths', (string) tk_post('seo_sitemap_exclude_paths', ''));
    } elseif ($section === 'login-page') {
        $overlay_color = sanitize_hex_color((string) tk_post('login_overlay_color', '#0f172a'));
        if (!$overlay_color) {
            $overlay_color = '#0f172a';
        }

        tk_update_option('login_branding_enabled', !empty($_POST['login_branding_enabled']) ? 1 : 0);
        tk_update_option('login_logo_id', tk_general_sanitize_image_attachment_id(tk_post('login_logo_id', 0)));
        tk_update_option('login_background_id', tk_general_sanitize_image_attachment_id(tk_post('login_background_id', 0)));
        tk_update_option('login_logo_width', max(80, min(420, absint(tk_post('login_logo_width', 240)))));
        tk_update_option('login_logo_height', max(40, min(180, absint(tk_post('login_logo_height', 96)))));
        tk_update_option('login_overlay_color', $overlay_color);
        tk_update_option('login_overlay_opacity', max(0, min(100, absint(tk_post('login_overlay_opacity', 46)))));
        $form_background_color = sanitize_hex_color((string) tk_post('login_form_background_color', '#ffffff'));
        $form_text_color = sanitize_hex_color((string) tk_post('login_form_text_color', '#1f2937'));
        tk_update_option('login_form_background_color', $form_background_color ?: '#ffffff');
        tk_update_option('login_form_text_color', $form_text_color ?: '#1f2937');
    } elseif ($section === 'admin-menu') {
        tk_update_option('hide_toolkits_menu', !empty($_POST['hide_toolkits_menu']) ? 1 : 0);
        tk_update_option('hide_cff_menu', !empty($_POST['hide_cff_menu']) ? 1 : 0);
        $hidden = isset($_POST['hidden_menus']) && is_array($_POST['hidden_menus']) ? array_map('sanitize_text_field', $_POST['hidden_menus']) : array();
        tk_update_option('tk_hidden_admin_menus', $hidden);
    } elseif ($section === 'cookie-consent') {
        tk_update_option('cookie_consent_enabled', !empty($_POST['cookie_consent_enabled']) ? 1 : 0);
        tk_update_option('cookie_consent_text', wp_kses_post((string) tk_post('cookie_consent_text', '')));
        tk_update_option('cookie_consent_privacy_url', sanitize_url((string) tk_post('cookie_consent_privacy_url', '')));
        tk_update_option('cookie_consent_expiry', absint(tk_post('cookie_consent_expiry', 365)));
    } elseif ($section === 'server-time') {
        $tz = sanitize_text_field((string) tk_post('tk_server_timezone', ''));
        if ($tz !== '' && !in_array($tz, timezone_identifiers_list(), true)) {
            $tz = '';
        }
        tk_update_option('tk_server_timezone', $tz);
    }

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
