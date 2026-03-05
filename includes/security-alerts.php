<?php
if (!defined('ABSPATH')) { exit; }

function tk_security_alerts_init() {
    add_action('user_register', 'tk_security_alert_user_register', 10, 1);
    add_action('set_user_role', 'tk_security_alert_set_user_role', 10, 3);
    add_action('wp_login', 'tk_security_alert_login', 10, 2);
}

function tk_security_alert_enabled(): bool {
    return (int) tk_get_option('toolkits_alert_enabled', 1) === 1;
}

function tk_security_alert_email(): string {
    $email = (string) tk_get_option('toolkits_alert_email', '');
    if ($email === '') {
        $email = (string) get_option('admin_email', '');
    }
    return $email;
}

function tk_security_alert_send(string $subject, string $message): void {
    if (!tk_security_alert_enabled()) {
        return;
    }
    $email = tk_security_alert_email();
    if ($email === '') {
        return;
    }
    wp_mail($email, $subject, $message);
    tk_toolkits_audit_log('security_alert', array('subject' => $subject));
}

function tk_security_alert_site_context(): array {
    $site_name = (string) get_bloginfo('name');
    $home = (string) home_url('/');
    $admin = (string) admin_url('/');
    $server = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : '';
    return array(
        'site_name' => $site_name,
        'home_url' => $home,
        'admin_url' => $admin,
        'wp_version' => (string) get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'server_name' => $server,
    );
}

function tk_security_alert_ip_is_public(string $ip): bool {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function tk_security_alert_ip_location(string $ip): string {
    if (!tk_security_alert_ip_is_public($ip)) {
        return 'Private/local IP';
    }

    $cache_key = 'tk_geo_' . md5($ip);
    $cached = get_transient($cache_key);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $location = 'Unknown';
    $endpoints = array(
        'https://ipapi.co/' . rawurlencode($ip) . '/json/',
        'https://ipwho.is/' . rawurlencode($ip),
    );

    foreach ($endpoints as $url) {
        $response = wp_remote_get($url, array(
            'timeout' => 4,
            'redirection' => 2,
        ));
        if (is_wp_error($response)) {
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            continue;
        }
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            continue;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            continue;
        }

        // ipapi.co fields
        $city = isset($data['city']) ? trim((string) $data['city']) : '';
        $region = isset($data['region']) ? trim((string) $data['region']) : '';
        $country = isset($data['country_name']) ? trim((string) $data['country_name']) : '';
        // ipwho.is fallback field
        if ($country === '' && isset($data['country'])) {
            $country = trim((string) $data['country']);
        }

        $parts = array();
        if ($city !== '') {
            $parts[] = $city;
        }
        if ($region !== '') {
            $parts[] = $region;
        }
        if ($country !== '') {
            $parts[] = $country;
        }
        if (!empty($parts)) {
            $location = implode(', ', $parts);
            break;
        }
    }

    set_transient($cache_key, $location, HOUR_IN_SECONDS * 12);
    return $location;
}

function tk_security_alert_user_register($user_id) {
    if (!(int) tk_get_option('toolkits_alert_admin_created', 1)) {
        return;
    }
    $user = get_userdata($user_id);
    if (!$user || !in_array('administrator', (array) $user->roles, true)) {
        return;
    }
    $ip = tk_get_ip();
    $site = tk_security_alert_site_context();
    $location = tk_security_alert_ip_location($ip);
    $creator_label = 'System/Unknown';
    $actor_id = get_current_user_id();
    if ($actor_id > 0) {
        $actor = get_userdata($actor_id);
        if ($actor) {
            $creator_label = $actor->user_login . ' (ID: ' . (int) $actor->ID . ')';
        }
    }

    $subject = 'Tool Kits: New admin user created';
    $message = "A new admin user was created.\n\n";
    $message .= 'User: ' . $user->user_login . "\n";
    $message .= 'User ID: ' . (int) $user->ID . "\n";
    $message .= 'Email: ' . $user->user_email . "\n";
    $message .= 'Created by: ' . $creator_label . "\n";
    $message .= 'IP: ' . ($ip !== '' ? $ip : 'Unknown') . "\n";
    $message .= 'Location: ' . $location . "\n";
    $message .= 'Time: ' . wp_date('Y-m-d H:i:s T') . "\n";
    $message .= "\n";
    $message .= "Website details\n";
    $message .= 'Site Name: ' . $site['site_name'] . "\n";
    $message .= 'Home URL: ' . $site['home_url'] . "\n";
    $message .= 'Admin URL: ' . $site['admin_url'] . "\n";
    $message .= 'Server: ' . ($site['server_name'] !== '' ? $site['server_name'] : 'Unknown') . "\n";
    $message .= 'WP Version: ' . $site['wp_version'] . "\n";
    $message .= 'PHP Version: ' . $site['php_version'] . "\n";
    tk_security_alert_send($subject, $message);
}

function tk_security_alert_set_user_role($user_id, $new_role, $old_roles) {
    if (!(int) tk_get_option('toolkits_alert_role_change', 1)) {
        return;
    }
    $old_roles = is_array($old_roles) ? $old_roles : array();
    if ($new_role !== 'administrator' && !in_array('administrator', $old_roles, true)) {
        return;
    }
    $user = get_userdata($user_id);
    if (!$user) {
        return;
    }
    $subject = 'Tool Kits: Admin role change detected';
    $message = "Admin role change detected.\n\n";
    $message .= 'User: ' . $user->user_login . "\n";
    $message .= 'Email: ' . $user->user_email . "\n";
    $message .= 'Old roles: ' . implode(', ', $old_roles) . "\n";
    $message .= 'New role: ' . $new_role . "\n";
    $message .= 'IP: ' . tk_get_ip() . "\n";
    $message .= 'Time: ' . date('Y-m-d H:i:s') . "\n";
    tk_security_alert_send($subject, $message);
}

function tk_security_alert_login($user_login, $user) {
    if (!(int) tk_get_option('toolkits_alert_admin_login_new_ip', 1)) {
        return;
    }
    if (!$user || !in_array('administrator', (array) $user->roles, true)) {
        return;
    }
    $ip = tk_get_ip();
    if ($ip === '') {
        return;
    }
    $known = get_user_meta($user->ID, 'tk_admin_login_ips', true);
    if (!is_array($known)) {
        $known = array();
    }
    if (in_array($ip, $known, true)) {
        return;
    }
    $known[] = $ip;
    update_user_meta($user->ID, 'tk_admin_login_ips', $known);

    $site = tk_security_alert_site_context();
    $hostname = @gethostbyaddr($ip);
    if (!is_string($hostname) || $hostname === '' || $hostname === $ip) {
        $hostname = 'Unknown';
    }
    $location = tk_security_alert_ip_location($ip);
    $user_agent = function_exists('tk_user_agent') ? tk_user_agent() : '';
    if ($user_agent === '') {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'Unknown';
    }

    $site_name = trim((string) $site['site_name']);
    $subject = ($site_name !== '' ? '[' . $site_name . '] ' : '') . 'Tool Kits: Admin login from new IP';
    $message = "Admin login from new IP detected.\n\n";
    $message .= 'User: ' . $user_login . "\n";
    $message .= 'IP: ' . $ip . "\n";
    $message .= 'Hostname: ' . $hostname . "\n";
    $message .= 'Location: ' . $location . "\n";
    $message .= 'User Agent: ' . $user_agent . "\n";
    $message .= 'Time: ' . wp_date('Y-m-d H:i:s T') . "\n";
    $message .= "\n";
    $message .= "Website details\n";
    $message .= 'Site Name: ' . $site['site_name'] . "\n";
    $message .= 'Home URL: ' . $site['home_url'] . "\n";
    $message .= 'Admin URL: ' . $site['admin_url'] . "\n";
    $message .= 'Server: ' . ($site['server_name'] !== '' ? $site['server_name'] : 'Unknown') . "\n";
    $message .= 'WP Version: ' . $site['wp_version'] . "\n";
    $message .= 'PHP Version: ' . $site['php_version'] . "\n";
    tk_security_alert_send($subject, $message);
}
