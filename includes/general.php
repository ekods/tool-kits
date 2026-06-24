<?php
if (!defined('ABSPATH')) { exit; }

function tk_general_init(): void {
    add_action('admin_post_tk_general_save', 'tk_general_save');
    add_action('admin_post_tk_general_sitemap_generate', 'tk_general_sitemap_generate');
    
    $tz = (string) tk_get_option('tk_server_timezone', '');
    if ($tz !== '') {
        date_default_timezone_set($tz);
    }
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
                <button type="button" class="tk-tabs-nav-button" data-panel="admin-menu">Admin Menu</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="cookie-consent">Cookie Consent</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="uploads">Uploads</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="user-id">User ID</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="server-time">Server Time</button>
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
                                    tk_render_switch('hide_toolkits_menu', 'Hide Tool Kits Menu', 'Remove Tool Kits from the main sidebar.', $hide_toolkits_menu);
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


                <div class="tk-tab-panel" data-panel-id="user-id">
                    <?php tk_render_user_id_change_panel(); ?>
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
    if (!in_array($section, array('editing', '404-handling', 'xml-sitemap', 'admin-menu', 'cookie-consent', 'server-time'), true)) {
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
