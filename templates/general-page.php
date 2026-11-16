<?php
if (!defined('ABSPATH')) { exit; }

    if (!tk_toolkits_can_manage()) {
        return;
    }

    $saved = isset($_GET['tk_saved']) ? sanitize_key((string) $_GET['tk_saved']) : '';
    $classic_editor_enabled = (int) tk_get_option('classic_editor_enabled', 0);
    $classic_widgets_enabled = (int) tk_get_option('classic_widgets_enabled', 0);
    $duplicate_content_enabled = (int) tk_get_option('duplicate_content_enabled', 0);
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
    $sitemap_google_property = (string) tk_get_option('sitemap_google_property', '');
    if ($sitemap_google_property === '') {
        $sitemap_google_property = home_url('/');
    }
    $sitemap_google_connected = trim((string) tk_get_option('sitemap_google_access_token', '')) !== '';
    $sitemap_bing_connected = trim((string) tk_get_option('sitemap_bing_access_token', '')) !== '';
    $sitemap_submission = tk_get_option('sitemap_submission_last_result', array());
    $sitemap_submission = is_array($sitemap_submission) ? $sitemap_submission : array();
    $sitemap_submit_status = isset($_GET['tk_sitemap_submit']) ? sanitize_key((string) $_GET['tk_sitemap_submit']) : '';
    $sitemap_submit_provider = isset($_GET['tk_sitemap_provider']) ? sanitize_key((string) $_GET['tk_sitemap_provider']) : '';
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
                            tk_render_switch('duplicate_content_enabled', 'Enable Duplicate Page/Post', 'Adds a Duplicate action to Posts and Pages. Copies are always created as drafts.', $duplicate_content_enabled);
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
                    <?php if ($sitemap_submit_status === 'ok') : ?>
                        <?php tk_notice(ucfirst($sitemap_submit_provider) . ' accepted the sitemap submission.', 'success'); ?>
                    <?php elseif ($sitemap_submit_status === 'fail') : ?>
                        <?php tk_notice(ucfirst($sitemap_submit_provider) . ' sitemap submission failed. Review the result below and refresh the OAuth token if needed.', 'error'); ?>
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

                            <div class="tk-sitemap-integrations">
                                <h3>Sitemap Submission</h3>
                                <p class="description">Connect verified webmaster properties. Access tokens are stored but never printed back into this page.</p>
                                <div class="tk-grid tk-grid-2">
                                    <div class="tk-sitemap-provider">
                                        <h4>Google Search Console</h4>
                                        <label>Property URL</label>
                                        <input type="text" name="sitemap_google_property" value="<?php echo esc_attr($sitemap_google_property); ?>" placeholder="https://example.com/ or sc-domain:example.com">
                                        <label>OAuth Access Token</label>
                                        <input type="password" name="sitemap_google_access_token" value="" autocomplete="new-password" placeholder="<?php echo $sitemap_google_connected ? 'Connected - enter a token to replace it' : 'Paste access token'; ?>">
                                        <?php if ($sitemap_google_connected) : ?>
                                            <label class="tk-checkbox-line"><input type="checkbox" name="sitemap_google_token_clear" value="1"> Remove saved Google token</label>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tk-sitemap-provider">
                                        <h4>Bing Webmaster Tools</h4>
                                        <label>OAuth Access Token</label>
                                        <input type="password" name="sitemap_bing_access_token" value="" autocomplete="new-password" placeholder="<?php echo $sitemap_bing_connected ? 'Connected - enter a token to replace it' : 'Paste access token'; ?>">
                                        <?php if ($sitemap_bing_connected) : ?>
                                            <label class="tk-checkbox-line"><input type="checkbox" name="sitemap_bing_token_clear" value="1"> Remove saved Bing token</label>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
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

                    <div class="tk-sitemap-submit-panel">
                        <h4>Submit Generated Sitemap</h4>
                        <p class="description"><code><?php echo esc_html($sitemap_url); ?></code></p>
                        <div class="tk-inline">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php tk_nonce_field('tk_general_sitemap_submit'); ?>
                                <input type="hidden" name="action" value="tk_general_sitemap_submit">
                                <input type="hidden" name="provider" value="google">
                                <button class="button button-secondary" <?php disabled(!$sitemap_google_connected); ?>>Submit to Google</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php tk_nonce_field('tk_general_sitemap_submit'); ?>
                                <input type="hidden" name="action" value="tk_general_sitemap_submit">
                                <input type="hidden" name="provider" value="bing">
                                <button class="button button-secondary" <?php disabled(!$sitemap_bing_connected); ?>>Submit to Bing</button>
                            </form>
                        </div>
                        <?php if (!empty($sitemap_submission)) : ?>
                            <p class="description">
                                Last result:
                                <strong><?php echo esc_html(ucfirst((string) ($sitemap_submission['provider'] ?? ''))); ?></strong>,
                                <?php echo !empty($sitemap_submission['ok']) ? 'success' : 'failed'; ?>,
                                <?php echo !empty($sitemap_submission['submitted_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $sitemap_submission['submitted_at'])) : '-'; ?>.
                                <?php echo esc_html((string) ($sitemap_submission['message'] ?? '')); ?>
                            </p>
                        <?php endif; ?>
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

                                <div class="tk-responsive-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:12px;">
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
                    <h2>Site Timezone</h2>
                    <p>Sets the WordPress site timezone (same as Settings &rarr; General). PHP's default timezone stays UTC, as WordPress requires.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_general_save'); ?>
                        <input type="hidden" name="action" value="tk_general_save">
                        <input type="hidden" name="tk_general_section" value="server-time">

                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <div class="tk-control-row">
                                <div class="tk-control-info">
                                    <label>Timezone</label>
                                    <p class="description">Select the site timezone.</p>
                                </div>
                                <select name="tk_server_timezone" style="width: 250px;">
                                    <option value="">-- Unchanged --</option>
                                    <?php
                                    $current_tz = (string) get_option('timezone_string', '');
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

                        <?php if (current_user_can('delete_plugins')) : ?>
                        <div class="tk-maintenance-panel">
                            <div class="tk-maintenance-panel-head">
                                <span class="dashicons dashicons-trash"></span>
                                <div>
                                    <h3>Uninstall Data</h3>
                                    <p class="description">When Tool Kits is deleted from Plugins, also remove its settings, reports, logs, login/security event tables, page cache files and per-post SEO/GEO fields (titles, descriptions, focus keywords, FAQ items). Off by default: deleting the plugin keeps everything so a reinstall picks up where it left off. .htaccess hardening rules and optimized image copies stay.</p>
                                </div>
                            </div>
                            <?php if (!empty($_GET['tk_uninstall_saved'])) : ?>
                                <?php tk_notice('Uninstall preference saved.', 'success'); ?>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php tk_nonce_field('tk_general_uninstall_save'); ?>
                                <input type="hidden" name="action" value="tk_general_uninstall_save">
                                <label class="tk-checkbox-line">
                                    <input type="checkbox" name="uninstall_delete_data" value="1" <?php checked(1, (int) get_option('tk_uninstall_delete_data', 0)); ?>>
                                    Delete all Tool Kits data when the plugin is deleted
                                </label>
                                <button class="button button-secondary">Save</button>
                            </form>
                        </div>
                        <?php endif; ?>
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
