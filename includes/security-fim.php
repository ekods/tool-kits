<?php
if (!defined('ABSPATH')) { exit; }

function tk_fim_init() {
    add_action('admin_post_tk_fim_scan', 'tk_fim_scan_handler');
    add_action('admin_post_tk_fim_clear', 'tk_fim_clear_handler');
}

function tk_fim_scan_handler() {
    tk_require_admin_post('tk_fim_scan');
    
    global $wp_version;
    $locale = get_locale();
    $api_url = 'https://api.wordpress.org/core/checksums/1.0/?version=' . $wp_version . '&locale=' . $locale;
    
    $response = wp_remote_get($api_url, array('sslverify' => true, 'timeout' => 15));
    
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-monitoring',
            'tk_fim_status' => 'fail',
            'tk_fim_msg' => 'Failed to reach WordPress.org checksums API. ' . (is_wp_error($response) ? $response->get_error_message() : ''),
        ), admin_url('admin.php')) . '#integrity');
        exit;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (empty($data['checksums']) || !is_array($data['checksums'])) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-monitoring',
            'tk_fim_status' => 'fail',
            'tk_fim_msg' => 'Invalid checksum data received.',
        ), admin_url('admin.php')) . '#integrity');
        exit;
    }
    
    $checksums = $data['checksums'];
    $altered_files = array();
    
    foreach ($checksums as $file => $expected_hash) {
        $file_path = ABSPATH . $file;
        if (!file_exists($file_path)) {
            // Content bundles and distribution documentation may be intentionally removed.
            if (strpos($file, 'wp-content/') !== 0 && !in_array($file, array('readme.html', 'license.txt', 'wp-config-sample.php'), true)) {
                $altered_files[] = $file . ' (missing)';
            }
            continue;
        }
        if (!is_file($file_path) || !is_readable($file_path)) {
            $altered_files[] = $file . ' (unreadable)';
            continue;
        }
        
        $actual_hash = md5_file($file_path);
        if ($actual_hash !== $expected_hash) {
            $altered_files[] = $file;
        }
    }
    
    tk_update_option('tk_fim_last_scan_result', $altered_files);
    tk_update_option('tk_fim_last_scan_time', time());
    
    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-monitoring',
        'tk_fim_status' => 'ok',
        'tk_fim_msg' => 'File integrity scan completed. ' . count($altered_files) . ' integrity issues found.',
    ), admin_url('admin.php')) . '#integrity');
    exit;
}

function tk_fim_clear_handler() {
    tk_require_admin_post('tk_fim_clear');
    
    tk_update_option('tk_fim_last_scan_result', array());
    tk_update_option('tk_fim_last_scan_time', 0);
    
    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-monitoring',
        'tk_fim_status' => 'ok',
        'tk_fim_msg' => 'Scan results cleared.',
    ), admin_url('admin.php')) . '#integrity');
    exit;
}

function tk_fim_get_status() {
    return array(
        'last_scan' => (int) tk_get_option('tk_fim_last_scan_time', 0),
        'results'   => (array) tk_get_option('tk_fim_last_scan_result', array()),
    );
}
