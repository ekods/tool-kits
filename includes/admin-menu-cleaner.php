<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Admin Menu Cleaner Module
 * Handles hiding specific menu items from the WordPress dashboard.
 */

function tk_admin_menu_cleaner_init() {
    add_action('admin_menu', 'tk_admin_menu_cleaner_apply', 9999);
    add_action('admin_post_tk_general_admin_menu_save', 'tk_admin_menu_cleaner_save');
}

/**
 * Applies the hiding rules to the admin menu.
 */
function tk_admin_menu_cleaner_apply() {
    if (!is_admin()) return;
    
    // Don't hide menus for users who can manage Tool Kits unless explicitly enabled for admins?
    // Actually, usually users want to hide menus for everyone or specific roles.
    // For now, let's allow hiding for the current user session if configured.
    
    $hidden_menus = tk_get_option('tk_hidden_admin_menus', array());
    if (!is_array($hidden_menus) || empty($hidden_menus)) {
        return;
    }

    foreach ($hidden_menus as $menu_slug) {
        remove_menu_page($menu_slug);
    }
    
    // Handle specific submenus if any (future expansion)
    $hidden_submenus = tk_get_option('tk_hidden_admin_submenus', array());
    if (is_array($hidden_submenus)) {
        foreach ($hidden_submenus as $parent => $subs) {
            if (is_array($subs)) {
                foreach ($subs as $sub) {
                    remove_submenu_page($parent, $sub);
                }
            }
        }
    }
}

/**
 * Get a list of common WordPress top-level menus.
 * We use a static list because global $menu is only available during the admin_menu hook.
 */
function tk_admin_menu_get_core_items() {
    return array(
        'index.php'              => array('label' => __('Dashboard', 'tool-kits'), 'icon' => 'dashicons-dashboard'),
        'edit.php'               => array('label' => __('Posts', 'tool-kits'), 'icon' => 'dashicons-admin-post'),
        'upload.php'             => array('label' => __('Media', 'tool-kits'), 'icon' => 'dashicons-admin-media'),
        'edit.php?post_type=page' => array('label' => __('Pages', 'tool-kits'), 'icon' => 'dashicons-admin-page'),
        'edit-comments.php'      => array('label' => __('Comments', 'tool-kits'), 'icon' => 'dashicons-admin-comments'),
        'themes.php'             => array('label' => __('Appearance', 'tool-kits'), 'icon' => 'dashicons-admin-appearance'),
        'plugins.php'            => array('label' => __('Plugins', 'tool-kits'), 'icon' => 'dashicons-admin-plugins'),
        'users.php'              => array('label' => __('Users', 'tool-kits'), 'icon' => 'dashicons-admin-users'),
        'tools.php'              => array('label' => __('Tools', 'tool-kits'), 'icon' => 'dashicons-admin-tools'),
        'options-general.php'    => array('label' => __('Settings', 'tool-kits'), 'icon' => 'dashicons-admin-generic'),
    );
}

/**
 * Save handler for admin menu settings.
 */
function tk_admin_menu_cleaner_save() {
    if (!tk_toolkits_can_manage()) wp_die('Forbidden');
    tk_check_nonce('tk_general_save');

    $hidden = isset($_POST['hidden_menus']) && is_array($_POST['hidden_menus']) ? array_map('sanitize_text_field', $_POST['hidden_menus']) : array();
    tk_update_option('tk_hidden_admin_menus', $hidden);

    // Legacy/Existing Tool Kits hide logic
    tk_update_option('hide_toolkits_menu', !empty($_POST['hide_toolkits_menu']) ? 1 : 0);
    tk_update_option('hide_cff_menu', !empty($_POST['hide_cff_menu']) ? 1 : 0);

    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-general', 'tk_saved' => '1'), admin_url('admin.php')) . '#admin-menu');
    exit;
}
