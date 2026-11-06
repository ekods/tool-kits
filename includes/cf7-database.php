<?php
if (!defined('ABSPATH')) { exit; }

function tk_cf7_database_init(): void {
    add_action('admin_post_tk_cf7_database_save', 'tk_cf7_database_save');
    add_action('admin_init', 'tk_cf7_database_handle_actions');
    add_action('admin_enqueue_scripts', 'tk_cf7_database_assets');
    add_action('admin_menu', 'tk_cf7_database_register_menu');
    // Run after every plugin has loaded, regardless of plugin load order.
    add_action('init', 'tk_cf7_database_register_capture');
}

function tk_cf7_database_enabled(): bool {
    return (int) tk_get_option('cf7_database_enabled', 0) === 1;
}

/** Slug of the stand-alone message pages; Tool Kits itself only keeps the on/off switch. */
function tk_cf7_database_collection_slug(): string {
    return 'tool-kits-cf7-collection';
}

/** Top-level "CF7 Database" menu, right below Contact Form 7's "Contact" (position 30), only while enabled. */
function tk_cf7_database_register_menu(): void {
    if (!tk_cf7_database_enabled() || !tk_cf7_database_can_manage()) { return; }
    add_menu_page(__('CF7 Database', 'tool-kits'), __('CF7 Database', 'tool-kits'), tk_toolkits_capability(),
        tk_cf7_database_collection_slug(), 'tk_render_cf7_database_collection_page', 'dashicons-feedback', 31);
}

function tk_cf7_database_can_manage(): bool {
    return tk_is_admin_user() && tk_license_features_enabled();
}

function tk_cf7_database_load(): void {
    require_once __DIR__ . '/cf7-database-collection.php';
}

function tk_cf7_database_register_capture(): void {
    // Once explicitly saved here, the switch also controls the standalone
    // collector. Never change an existing standalone installation by default.
    if ((int) tk_get_option('cf7_database_managed', 0) === 1 && tk_license_features_enabled()) {
        remove_action('wpcf7_mail_sent', 'cf7dbc_save_submission');
    }
    if (!tk_cf7_database_enabled() || !tk_license_features_enabled()) { return; }
    remove_action('wpcf7_mail_sent', 'cf7dbc_save_submission');
    add_action('wpcf7_mail_sent', 'tk_cf7_database_capture');
}

function tk_cf7_database_capture($contact_form): void {
    if (!tk_cf7_database_enabled() || !tk_license_features_enabled() || !class_exists('WPCF7_Submission')) { return; }
    tk_cf7_database_load();
    tk_cf7dbc_save_submission($contact_form);
}

function tk_cf7_database_save(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Method not allowed', '', array('response' => 405)); }
    tk_require_admin_post('tk_cf7_database_save');
    if (!tk_license_features_enabled()) { wp_die('An active Tool Kits license is required.'); }
    $enabled = isset($_POST['cf7_database_enabled']) && $_POST['cf7_database_enabled'] === '1' ? 1 : 0;
    if ($enabled) {
        tk_cf7_database_load();
        tk_cf7dbc_maybe_upgrade_schema();
    }
    tk_update_option('cf7_database_enabled', $enabled);
    tk_update_option('cf7_database_managed', 1);
    wp_safe_redirect(admin_url('admin.php?page=tool-kits-cf7-database&tk_saved=1'));
    exit;
}

function tk_cf7_database_assets(): void {
    if (($_GET['page'] ?? '') !== tk_cf7_database_collection_slug() || !tk_cf7_database_can_manage()) { return; }
    wp_enqueue_style('tk-cf7-database', TK_URL . 'assets/cf7-database.css', array(), tk_asset_version('assets/cf7-database.css'));
}

function tk_cf7_database_handle_actions(): void {
    if (($_GET['page'] ?? '') !== tk_cf7_database_collection_slug() || !tk_cf7_database_enabled() || !tk_cf7_database_can_manage()) { return; }
    if (!isset($_GET['tk_cf7dbc_export']) && !isset($_GET['tk_cf7dbc_delete'])) { return; }
    if (!get_option('cf7dbc_schema_version')) { return; }
    tk_cf7_database_load();
    if (isset($_GET['tk_cf7dbc_export'])) { tk_cf7dbc_export_excel(); }
    if (isset($_GET['tk_cf7dbc_delete'], $_GET['_wpnonce'])) {
        $id = absint($_GET['tk_cf7dbc_delete']);
        check_admin_referer('tk_cf7dbc_delete_' . $id);
        global $wpdb;
        $wpdb->delete(tk_cf7dbc_table_name(), array('id' => $id), array('%d'));
        $url = add_query_arg(array('deleted' => 1, 'form_id' => absint($_GET['form_id'] ?? 0)), admin_url('admin.php?page=' . tk_cf7_database_collection_slug()));
        wp_safe_redirect($url);
        exit;
    }
}

function tk_render_cf7_database_page(): void {
    if (!tk_cf7_database_can_manage()) { return; }
    $enabled = tk_cf7_database_enabled();
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero('CF7 Database Collection', 'Store Contact Form 7 submissions, browse messages by form, and export them to Excel.', 'dashicons-database'); ?>
        <?php if (isset($_GET['tk_saved']) && $_GET['tk_saved'] === '1') { tk_notice('CF7 Database settings saved.', 'success'); } ?>
        <div class="tk-card">
            <div class="tk-section-heading">
                <h2>Submission Storage</h2>
                <span class="tk-badge <?php echo $enabled ? 'tk-on' : 'tk-off'; ?>"><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></span>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_cf7_database_save'); ?>
                <input type="hidden" name="action" value="tk_cf7_database_save">
                <p><label for="tk-cf7-database-enabled"><input type="checkbox" id="tk-cf7-database-enabled" name="cf7_database_enabled" value="1" aria-describedby="tk-cf7-database-help" <?php checked($enabled); ?>> Enable CF7 Database Collection</label></p>
                <p class="description" id="tk-cf7-database-help">Save new submissions after email is sent successfully, and show the CF7 Database menu for browsing and exporting them. Disabling stops collection and hides the menu; stored messages are kept and return when enabled again.</p>
                <?php if (!defined('WPCF7_VERSION') && !class_exists('WPCF7_ContactForm')) : ?>
                    <p class="description">Contact Form 7 is inactive. Install and activate it to collect new submissions.</p>
                <?php endif; ?>
                <?php if (function_exists('cf7dbc_save_submission')) : ?>
                    <p class="description">The standalone CF7 Database Collection plugin is active. After saving these settings, this switch controls collection while Tool Kits is active and licensed. Both use the same stored messages.</p>
                <?php endif; ?>
                <p class="tk-action-row">
                    <button class="button button-primary" type="submit">Save Settings</button>
                    <?php if ($enabled) : ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . tk_cf7_database_collection_slug())); ?>">Open CF7 Database</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>
    </div>
    <?php
}

/** The stand-alone CF7 Database pages: dashboard, form list, messages and export. */
function tk_render_cf7_database_collection_page(): void {
    if (!tk_cf7_database_enabled() || !tk_cf7_database_can_manage()) { return; }
    tk_cf7_database_load();
    tk_cf7dbc_maybe_upgrade_schema();
    tk_cf7dbc_render_admin_page();
}
