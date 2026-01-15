<?php
if (!defined('ABSPATH')) exit;

/**
 * Rate limit login attempts (IP based)
 */

function tk_rate_limit_init() {
    add_filter('authenticate', 'tk_rate_limit_authenticate', 30, 3);
    add_action('admin_post_tk_rate_limit_save', 'tk_rate_limit_save');
}

function tk_rate_limit_enabled() {
    return (int) tk_get_option('rate_limit_enabled', 0) === 1;
}

function tk_rate_limit_key() {
    return 'tk_rl_' . md5(tk_get_ip());
}

function tk_rate_limit_lock_key() {
    return 'tk_rl_lock_' . md5(tk_get_ip());
}

function tk_rate_limit_authenticate($user, $username, $password) {
    if (!tk_rate_limit_enabled()) return $user;

    // only trigger inside the login form
    if (!isset($_POST['log'], $_POST['pwd'])) return $user;

    // currently locked out
    if (get_transient(tk_rate_limit_lock_key())) {
        return new WP_Error(
            'tk_rate_limited',
            __('Too many login attempts. Please try again later.', 'tool-kits')
        );
    }

    if (is_wp_error($user)) {
        tk_rate_limit_increment();
    }

    return $user;
}

function tk_rate_limit_increment() {
    $window = max(1, (int) tk_get_option('rate_limit_window_minutes', 10));
    $max    = max(1, (int) tk_get_option('rate_limit_max_attempts', 5));
    $lock   = max(1, (int) tk_get_option('rate_limit_lockout_minutes', 30));

    $key  = tk_rate_limit_key();
    $data = get_transient($key);

    if (!is_array($data)) {
        $data = [
            'count' => 0,
            'start' => time()
        ];
    }

    if (time() - $data['start'] > ($window * MINUTE_IN_SECONDS)) {
        $data = [
            'count' => 0,
            'start' => time()
        ];
    }

    $data['count']++;
    set_transient($key, $data, $window * MINUTE_IN_SECONDS);

    if ($data['count'] >= $max) {
        set_transient(tk_rate_limit_lock_key(), 1, $lock * MINUTE_IN_SECONDS);
    }
}

function tk_render_rate_limit_page() {
    if (!tk_is_admin_user()) return;
    ?>
    <div class="wrap tk-wrap">
        <h1>Rate Limit</h1>
        <div class="tk-card">
            <p>Throttle repeated login attempts at the IP level. The settings below control how long the window is and how long a lockout lasts.</p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php tk_nonce_field('tk_rate_limit_save'); ?>
                <input type="hidden" name="action" value="tk_rate_limit_save">

                <label>
                    <input type="checkbox" name="enabled" value="1"
                        <?php checked(1, tk_get_option('rate_limit_enabled', 0)); ?>>
                    Enable login rate limit
                </label>

                <p>
                    Window (minutes)<br>
                    <input type="number" name="window" value="<?php echo esc_attr(tk_get_option('rate_limit_window_minutes', 10)); ?>">
                </p>

                <p>
                    Max attempts<br>
                    <input type="number" name="max" value="<?php echo esc_attr(tk_get_option('rate_limit_max_attempts', 5)); ?>">
                </p>

                <p>
                    Lockout duration (minutes)<br>
                    <input type="number" name="lock" value="<?php echo esc_attr(tk_get_option('rate_limit_lockout_minutes', 30)); ?>">
                </p>

                <p><button class="button button-primary">Save</button></p>
            </form>
        </div>
    </div>
    <?php
}

function tk_rate_limit_save() {
    tk_check_nonce('tk_rate_limit_save');

    tk_update_option('rate_limit_enabled', !empty($_POST['enabled']) ? 1 : 0);
    tk_update_option('rate_limit_window_minutes', (int) $_POST['window']);
    tk_update_option('rate_limit_max_attempts', (int) $_POST['max']);
    tk_update_option('rate_limit_lockout_minutes', (int) $_POST['lock']);

    wp_redirect(admin_url('admin.php?page=tool-kits-security-rate-limit'));
    exit;
}
