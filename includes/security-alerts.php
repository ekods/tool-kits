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

function tk_security_alert_user_register($user_id) {
    if (!(int) tk_get_option('toolkits_alert_admin_created', 1)) {
        return;
    }
    $user = get_userdata($user_id);
    if (!$user || !in_array('administrator', (array) $user->roles, true)) {
        return;
    }
    $subject = 'Tool Kits: New admin user created';
    $message = "A new admin user was created.\n\n";
    $message .= 'User: ' . $user->user_login . "\n";
    $message .= 'Email: ' . $user->user_email . "\n";
    $message .= 'IP: ' . tk_get_ip() . "\n";
    $message .= 'Time: ' . date('Y-m-d H:i:s') . "\n";
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

    $subject = 'Tool Kits: Admin login from new IP';
    $message = "Admin login from new IP detected.\n\n";
    $message .= 'User: ' . $user_login . "\n";
    $message .= 'IP: ' . $ip . "\n";
    $message .= 'Time: ' . date('Y-m-d H:i:s') . "\n";
    tk_security_alert_send($subject, $message);
}
