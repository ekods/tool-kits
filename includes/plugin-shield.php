<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Plugin Shield Module
 * Proteksi khusus untuk Tool Kits agar tidak mudah di-hack, dinonaktifkan, 
 * atau lisensinya dimanipulasi oleh user lain.
 */

function tk_plugin_shield_init() {
    // 1. Sembunyikan plugin dari daftar jika bukan Owner
    add_filter('all_plugins', 'tk_shield_hide_from_plugin_list');
    
    // 2. Cegah deaktifasi jika Shield aktif
    add_filter('plugin_action_links', 'tk_shield_remove_deactivate_link', 10, 4);
    add_action('deactivate_plugin', 'tk_shield_block_deactivation', 10, 2);

    // 3. Verifikasi integritas lisensi (anti-DB tampering)
    add_action('admin_init', 'tk_shield_verify_license_integrity');
}

/**
 * Menyembunyikan Tool Kits dari daftar plugin untuk user non-owner.
 */
function tk_shield_hide_from_plugin_list($plugins) {
    if (!tk_get_option('toolkits_shield_stealth_enabled', 0)) {
        return $plugins;
    }

    // Jika user adalah Owner, biarkan tetap terlihat
    if (tk_toolkits_is_owner()) {
        return $plugins;
    }

    $plugin_slug = 'tool-kits/tool-kits.php';
    if (isset($plugins[$plugin_slug])) {
        unset($plugins[$plugin_slug]);
    }

    return $plugins;
}

/**
 * Menghapus link "Deactivate" di halaman plugins.
 */
function tk_shield_remove_deactivate_link($actions, $plugin_file, $plugin_data, $context) {
    if ($plugin_file !== 'tool-kits/tool-kits.php') {
        return $actions;
    }

    if (!tk_get_option('toolkits_shield_lock_enabled', 0)) {
        return $actions;
    }

    // Jika bukan owner, hapus link deaktifasi dan hapus
    if (!tk_toolkits_is_owner()) {
        unset($actions['deactivate']);
        unset($actions['delete']);
    }

    return $actions;
}

/**
 * Blokir aksi deaktifasi via URL/Direct request.
 */
function tk_shield_block_deactivation($plugin, $silent) {
    if ($plugin !== 'tool-kits/tool-kits.php') {
        return;
    }

    if (!tk_get_option('toolkits_shield_lock_enabled', 0)) {
        return;
    }

    if (!tk_toolkits_is_owner()) {
        wp_die(__('Tool Kits Shield: Deactivation is restricted to the site owner.', 'tool-kits'));
    }
}

/**
 * Memastikan lisensi tidak diubah secara manual di Database.
 */
function tk_shield_verify_license_integrity() {
    $status = (string) tk_get_option('license_status', 'inactive');
    if ($status === 'inactive') {
        return;
    }

    if ($status !== 'valid') {
        return;
    }

    if (!function_exists('tk_license_has_valid_signature') || !tk_license_has_valid_signature()) {
        tk_update_option('license_status', 'inactive');
        tk_update_option('license_message', function_exists('tk_license_integrity_violation_message') ? tk_license_integrity_violation_message() : 'License integrity violation detected. Please re-activate.');
        tk_update_option('license_signature', '');
    }
}

/**
 * Helper untuk mengecek apakah user saat ini adalah Owner sah.
 */
function tk_toolkits_is_owner() {
    if (!is_user_logged_in()) return false;
    
    $owner_id = (int) tk_get_option('toolkits_owner_user_id', 1);
    $user = wp_get_current_user();
    
    return ($user && (int) $user->ID === $owner_id);
}
