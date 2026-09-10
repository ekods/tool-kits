<?php
if (!defined('ABSPATH')) { exit; }

function tk_otp_init(): void {
    add_filter('authenticate', 'tk_otp_maybe_require_challenge', 60, 3);
    add_action('login_form_tk_otp', 'tk_otp_login_form_handler');
    add_action('admin_post_tk_otp_save', 'tk_otp_save');
}

function tk_otp_enabled(): bool {
    if (defined('TOOLKITS_DISABLE_OTP') && TOOLKITS_DISABLE_OTP) {
        return false;
    }
    return tk_license_features_enabled() && (int) tk_get_option('otp_login_enabled', 0) === 1;
}

function tk_otp_user_requires_otp(WP_User $user): bool {
    if (!tk_otp_enabled()) {
        return false;
    }
    $roles = tk_get_option('otp_login_roles', array('administrator'));
    $roles = is_array($roles) ? array_filter(array_map('sanitize_key', $roles)) : array('administrator');
    if (empty($roles)) {
        return false;
    }
    return count(array_intersect($roles, (array) $user->roles)) > 0;
}

function tk_otp_maybe_require_challenge($user, $username, $password) {
    if (is_wp_error($user) || !$user instanceof WP_User || !tk_otp_user_requires_otp($user)) {
        return $user;
    }
    if (tk_otp_is_noninteractive_request() || tk_otp_trusted_cookie_valid($user)) {
        return $user;
    }

    $remember = !empty($_POST['rememberme']);
    $redirect_to = isset($_REQUEST['redirect_to']) ? esc_url_raw((string) wp_unslash($_REQUEST['redirect_to'])) : admin_url();
    $challenge = tk_otp_create_challenge((int) $user->ID, $remember, $redirect_to);
    if (empty($challenge['token'])) {
        return new WP_Error('tk_otp_failed', __('Could not create OTP challenge. Please try again.', 'tool-kits'));
    }
    if (!tk_otp_send_code($user, $challenge)) {
        tk_otp_log('send_failed', (int) $user->ID, 'Failed to send login OTP email.');
        delete_transient('tk_otp_' . (string) $challenge['token']);
        return new WP_Error('tk_otp_send_failed', __('Could not send OTP email. Please contact the site administrator.', 'tool-kits'));
    }

    tk_otp_log('sent', (int) $user->ID, 'OTP challenge email sent.');
    wp_safe_redirect(tk_otp_challenge_url((string) $challenge['token']));
    exit;
}

function tk_otp_is_noninteractive_request(): bool {
    if ((defined('WP_CLI') && WP_CLI) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return true;
    }
    if (wp_doing_cron()) {
        return true;
    }
    $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : '';
    return $action === 'tk_otp';
}

function tk_otp_create_challenge(int $user_id, bool $remember, string $redirect_to): array {
    $token = wp_generate_password(32, false, false);
    $code = (string) random_int(100000, 999999);
    $ttl = max(1, min(30, (int) tk_get_option('otp_login_expiry_minutes', 5))) * MINUTE_IN_SECONDS;
    $challenge = array(
        'token' => $token,
        'user_id' => $user_id,
        'hash' => wp_hash_password($code),
        'expires_at' => time() + $ttl,
        'attempts' => 0,
        'remember' => $remember ? 1 : 0,
        'redirect_to' => $redirect_to !== '' ? $redirect_to : admin_url(),
        'last_sent' => time(),
    );
    set_transient('tk_otp_' . $token, $challenge, $ttl);
    $challenge['code'] = $code;
    return $challenge;
}

function tk_otp_challenge_url(string $token): string {
    $login_url = wp_login_url();
    if (function_exists('tk_hide_login_custom_url') && (int) tk_get_option('hide_login_enabled', 0) === 1) {
        $login_url = tk_hide_login_custom_url();
    }

    return add_query_arg(array(
        'action' => 'tk_otp',
        'tk_otp_token' => $token,
    ), $login_url);
}

function tk_otp_send_code(WP_User $user, array $challenge): bool {
    $code = isset($challenge['code']) ? (string) $challenge['code'] : '';
    if ($code === '' || empty($user->user_email)) {
        return false;
    }
    $expires = max(1, min(30, (int) tk_get_option('otp_login_expiry_minutes', 5)));
    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $replacements = array(
        '{code}' => $code,
        '{site_name}' => $site_name,
        '{expires_minutes}' => (string) $expires,
        '{user_login}' => (string) $user->user_login,
    );
    $subject = strtr((string) tk_get_option('otp_login_email_subject', 'Your {site_name} login code'), $replacements);
    $message = strtr((string) tk_get_option('otp_login_email_message', "Your login verification code is: {code}\n\nThis code expires in {expires_minutes} minutes."), $replacements);
    return wp_mail((string) $user->user_email, $subject, $message);
}

function tk_otp_login_form_handler(): void {
    $token = isset($_REQUEST['tk_otp_token']) ? sanitize_text_field((string) wp_unslash($_REQUEST['tk_otp_token'])) : '';
    $challenge = $token !== '' ? get_transient('tk_otp_' . $token) : false;
    $error = '';
    $message = '';

    if (!is_array($challenge) || empty($challenge['user_id'])) {
        tk_otp_render_login_form($token, __('OTP challenge expired or invalid. Please login again.', 'tool-kits'), '', true);
    }

    $user = get_user_by('id', (int) $challenge['user_id']);
    if (!$user instanceof WP_User) {
        delete_transient('tk_otp_' . $token);
        tk_otp_render_login_form($token, __('User account not found. Please login again.', 'tool-kits'), '', true);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $posted_action = isset($_POST['tk_otp_action']) ? sanitize_key((string) wp_unslash($_POST['tk_otp_action'])) : 'verify';
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field((string) wp_unslash($_POST['_wpnonce'])), 'tk_otp_' . $token)) {
            $error = __('Security check failed. Please try again.', 'tool-kits');
        } elseif ($posted_action === 'resend') {
            [$challenge, $message, $error] = tk_otp_handle_resend($token, $challenge, $user);
        } else {
            tk_otp_handle_verify($token, $challenge, $user);
        }
    }

    tk_otp_render_login_form($token, $error, $message, false, $challenge);
}

function tk_otp_handle_resend(string $token, array $challenge, WP_User $user): array {
    $cooldown = max(15, min(600, (int) tk_get_option('otp_login_resend_cooldown', 60)));
    $last_sent = (int) ($challenge['last_sent'] ?? 0);
    if ($last_sent > 0 && time() - $last_sent < $cooldown) {
        return array($challenge, '', sprintf(__('Please wait %d seconds before requesting another code.', 'tool-kits'), $cooldown - (time() - $last_sent)));
    }

    $code = (string) random_int(100000, 999999);
    $challenge['hash'] = wp_hash_password($code);
    $challenge['attempts'] = 0;
    $challenge['last_sent'] = time();
    $ttl = max(1, (int) ($challenge['expires_at'] ?? time()) - time());
    $send_challenge = $challenge;
    $send_challenge['code'] = $code;

    if (!tk_otp_send_code($user, $send_challenge)) {
        tk_otp_log('send_failed', (int) $user->ID, 'Failed to resend login OTP email.');
        return array($challenge, '', __('Could not resend OTP email.', 'tool-kits'));
    }

    set_transient('tk_otp_' . $token, $challenge, $ttl);
    tk_otp_log('resent', (int) $user->ID, 'OTP challenge email resent.');
    return array($challenge, __('A new OTP code has been sent.', 'tool-kits'), '');
}

function tk_otp_handle_verify(string $token, array $challenge, WP_User $user): void {
    $code = isset($_POST['tk_otp_code']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['tk_otp_code'])) : '';
    $max_attempts = max(1, min(10, (int) tk_get_option('otp_login_max_attempts', 5)));
    $attempts = (int) ($challenge['attempts'] ?? 0) + 1;

    if ($code !== '' && wp_check_password($code, (string) ($challenge['hash'] ?? ''), (int) $user->ID)) {
        delete_transient('tk_otp_' . $token);
        wp_set_auth_cookie((int) $user->ID, !empty($challenge['remember']), is_ssl());
        wp_set_current_user((int) $user->ID);
        do_action('wp_login', $user->user_login, $user);
        if (!empty($_POST['tk_otp_trust_device'])) {
            tk_otp_set_trusted_cookie($user);
        }
        tk_otp_log('success', (int) $user->ID, 'OTP verification succeeded.');
        wp_safe_redirect(!empty($challenge['redirect_to']) ? (string) $challenge['redirect_to'] : admin_url());
        exit;
    }

    if ($attempts >= $max_attempts) {
        delete_transient('tk_otp_' . $token);
        tk_otp_log('locked', (int) $user->ID, 'OTP challenge locked after too many attempts.');
        tk_otp_render_login_form($token, __('Too many invalid OTP attempts. Please login again.', 'tool-kits'), '', true);
    }

    $challenge['attempts'] = $attempts;
    $ttl = max(1, (int) ($challenge['expires_at'] ?? time()) - time());
    set_transient('tk_otp_' . $token, $challenge, $ttl);
    tk_otp_log('failed', (int) $user->ID, 'Invalid OTP code.');
    tk_otp_render_login_form($token, sprintf(__('Invalid OTP code. Attempts remaining: %d.', 'tool-kits'), $max_attempts - $attempts), '', false, $challenge);
}

function tk_otp_render_login_form(string $token, string $error = '', string $message = '', bool $terminal = false, array $challenge = array()): void {
    $title = __('Login Verification', 'tool-kits');
    $notice = $message !== '' ? '<p class="message">' . esc_html($message) . '</p>' : '<p class="message">' . esc_html__('Enter the verification code sent to your email.', 'tool-kits') . '</p>';
    $wp_error = new WP_Error();
    if ($error !== '') {
        $wp_error->add('tk_otp_error', $error);
    }
    login_header($title, $notice, $wp_error);
    if (!$terminal) :
        $trusted_days = max(0, min(365, (int) tk_get_option('otp_login_trusted_days', 0)));
        ?>
        <form name="tk-otp-form" id="loginform" action="<?php echo esc_url(tk_otp_challenge_url($token)); ?>" method="post">
            <?php wp_nonce_field('tk_otp_' . $token); ?>
            <input type="hidden" name="tk_otp_token" value="<?php echo esc_attr($token); ?>">
            <input type="hidden" name="tk_otp_action" value="verify">
            <p>
                <label for="tk_otp_code"><?php esc_html_e('Verification Code', 'tool-kits'); ?></label>
                <input type="text" name="tk_otp_code" id="tk_otp_code" class="input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" required>
            </p>
            <?php if ($trusted_days > 0) : ?>
                <p class="forgetmenot"><label><input name="tk_otp_trust_device" type="checkbox" value="1"> <?php printf(esc_html__('Trust this device for %d days', 'tool-kits'), $trusted_days); ?></label></p>
            <?php endif; ?>
            <p class="submit">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e('Verify Login', 'tool-kits'); ?></button>
            </p>
        </form>
        <form action="<?php echo esc_url(tk_otp_challenge_url($token)); ?>" method="post" style="margin-top:10px;">
            <?php wp_nonce_field('tk_otp_' . $token); ?>
            <input type="hidden" name="tk_otp_token" value="<?php echo esc_attr($token); ?>">
            <input type="hidden" name="tk_otp_action" value="resend">
            <button type="submit" class="button"><?php esc_html_e('Resend Code', 'tool-kits'); ?></button>
        </form>
        <?php
    else :
        ?>
        <p><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to login', 'tool-kits'); ?></a></p>
        <?php
    endif;
    login_footer();
    exit;
}

function tk_otp_trusted_cookie_name(int $user_id): string {
    return 'tk_otp_trusted_' . $user_id;
}

function tk_otp_trusted_cookie_valid(WP_User $user): bool {
    $days = max(0, min(365, (int) tk_get_option('otp_login_trusted_days', 0)));
    if ($days <= 0) {
        return false;
    }
    $name = tk_otp_trusted_cookie_name((int) $user->ID);
    $value = isset($_COOKIE[$name]) ? sanitize_text_field((string) wp_unslash($_COOKIE[$name])) : '';
    if ($value === '' || strpos($value, ':') === false) {
        return false;
    }
    [$expires, $mac] = explode(':', $value, 2);
    if ((int) $expires < time()) {
        return false;
    }
    return hash_equals(tk_otp_trusted_mac($user, (int) $expires), $mac);
}

function tk_otp_set_trusted_cookie(WP_User $user): void {
    $days = max(0, min(365, (int) tk_get_option('otp_login_trusted_days', 0)));
    if ($days <= 0) {
        return;
    }
    $expires = time() + ($days * DAY_IN_SECONDS);
    $value = $expires . ':' . tk_otp_trusted_mac($user, $expires);
    $args = array(
        'expires' => $expires,
        'path' => COOKIEPATH ?: '/',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    );
    if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
        $args['domain'] = COOKIE_DOMAIN;
    }
    setcookie(tk_otp_trusted_cookie_name((int) $user->ID), $value, $args);
}

function tk_otp_trusted_mac(WP_User $user, int $expires): string {
    return hash_hmac('sha256', (int) $user->ID . '|' . $expires . '|' . substr((string) $user->user_pass, -12), wp_salt('auth'));
}

function tk_otp_log(string $event, int $user_id, string $message): void {
    $log = tk_get_option('otp_login_audit_log', array());
    $log = is_array($log) ? $log : array();
    array_unshift($log, array(
        'time' => time(),
        'event' => sanitize_key($event),
        'user_id' => $user_id,
        'ip' => function_exists('tk_get_ip') ? tk_get_ip() : '',
        'message' => $message,
    ));
    tk_update_option('otp_login_audit_log', array_slice($log, 0, 100));
}

function tk_render_otp_page(): void {
    if (!tk_is_admin_user()) return;
    $roles = tk_get_option('otp_login_roles', array('administrator'));
    $roles = is_array($roles) ? array_filter(array_map('sanitize_key', $roles)) : array('administrator');
    $editable_roles = get_editable_roles();
    $log = tk_get_option('otp_login_audit_log', array());
    $log = is_array($log) ? $log : array();
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero('OTP Login', 'Require email verification codes after valid username and password authentication.', 'dashicons-email-alt'); ?>
        <?php if (isset($_GET['tk_saved']) && sanitize_key((string) $_GET['tk_saved']) === '1') : ?>
            <?php tk_notice('OTP Login settings saved.', 'success'); ?>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_otp_save'); ?>
            <input type="hidden" name="action" value="tk_otp_save">
            <div class="tk-card">
                <h2>OTP Flow</h2>
                <?php tk_render_switch('otp_login_enabled', 'Enable OTP Login', 'After a valid password, selected roles must verify a one-time email code.', (int) tk_get_option('otp_login_enabled', 0)); ?>
                <p class="description">Emergency bypass: set <code>define('TOOLKITS_DISABLE_OTP', true);</code> in <code>wp-config.php</code>.</p>
                <h3>Protected Roles</h3>
                <div class="tk-grid tk-grid-3">
                    <?php foreach ($editable_roles as $role_key => $role) : ?>
                        <label><input type="checkbox" name="otp_login_roles[]" value="<?php echo esc_attr((string) $role_key); ?>" <?php checked(in_array((string) $role_key, $roles, true)); ?>> <?php echo esc_html(translate_user_role((string) ($role['name'] ?? $role_key))); ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="tk-grid tk-grid-3" style="margin-top:16px;">
                    <p><label>Code expiry minutes</label><br><input type="number" min="1" max="30" name="otp_login_expiry_minutes" value="<?php echo esc_attr((string) tk_get_option('otp_login_expiry_minutes', 5)); ?>"></p>
                    <p><label>Max attempts</label><br><input type="number" min="1" max="10" name="otp_login_max_attempts" value="<?php echo esc_attr((string) tk_get_option('otp_login_max_attempts', 5)); ?>"></p>
                    <p><label>Resend cooldown seconds</label><br><input type="number" min="15" max="600" name="otp_login_resend_cooldown" value="<?php echo esc_attr((string) tk_get_option('otp_login_resend_cooldown', 60)); ?>"></p>
                    <p><label>Trusted device days</label><br><input type="number" min="0" max="365" name="otp_login_trusted_days" value="<?php echo esc_attr((string) tk_get_option('otp_login_trusted_days', 0)); ?>"></p>
                </div>
            </div>
            <div class="tk-card" style="margin-top:16px;">
                <h2>Email Template</h2>
                <p><label>Subject</label><br><input type="text" class="regular-text" name="otp_login_email_subject" value="<?php echo esc_attr((string) tk_get_option('otp_login_email_subject', 'Your {site_name} login code')); ?>"></p>
                <p><label>Message</label><br><textarea name="otp_login_email_message" rows="6" style="width:100%; max-width:720px;"><?php echo esc_textarea((string) tk_get_option('otp_login_email_message', "Your login verification code is: {code}\n\nThis code expires in {expires_minutes} minutes.")); ?></textarea></p>
                <p class="description">Available tokens: <code>{code}</code>, <code>{site_name}</code>, <code>{expires_minutes}</code>, <code>{user_login}</code>.</p>
            </div>
            <p style="margin-top:16px;"><button class="button button-primary button-hero">Save OTP Settings</button></p>
        </form>
        <div class="tk-card" style="margin-top:16px;">
            <h2>OTP Audit Log</h2>
            <?php if (!empty($log)) : ?>
                <table class="widefat striped">
                    <thead><tr><th>Time</th><th>Event</th><th>User</th><th>IP</th><th>Message</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($log, 0, 30) as $row) : ?>
                        <tr>
                            <td><?php echo !empty($row['time']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $row['time'])) : '-'; ?></td>
                            <td><?php echo esc_html((string) ($row['event'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['user_id'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['ip'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['message'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description">No OTP events yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function tk_otp_save(): void {
    tk_require_admin_post('tk_otp_save');
    $roles = isset($_POST['otp_login_roles']) && is_array($_POST['otp_login_roles']) ? array_values(array_unique(array_map('sanitize_key', wp_unslash($_POST['otp_login_roles'])))) : array();
    tk_update_option('otp_login_enabled', !empty($_POST['otp_login_enabled']) ? 1 : 0);
    tk_update_option('otp_login_roles', $roles);
    tk_update_option('otp_login_expiry_minutes', max(1, min(30, (int) tk_post('otp_login_expiry_minutes', 5))));
    tk_update_option('otp_login_max_attempts', max(1, min(10, (int) tk_post('otp_login_max_attempts', 5))));
    tk_update_option('otp_login_resend_cooldown', max(15, min(600, (int) tk_post('otp_login_resend_cooldown', 60))));
    tk_update_option('otp_login_trusted_days', max(0, min(365, (int) tk_post('otp_login_trusted_days', 0))));
    tk_update_option('otp_login_email_subject', sanitize_text_field((string) tk_post('otp_login_email_subject', 'Your {site_name} login code')));
    tk_update_option('otp_login_email_message', sanitize_textarea_field((string) tk_post('otp_login_email_message', '')));
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-security-otp', 'tk_saved' => 1), admin_url('admin.php')));
    exit;
}
