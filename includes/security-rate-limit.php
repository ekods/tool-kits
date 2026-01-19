<?php
if (!defined('ABSPATH')) exit;

/**
 * Rate limit login attempts (IP based)
 */

function tk_rate_limit_init() {
    add_filter('authenticate', 'tk_rate_limit_authenticate', 30, 3);
    add_action('admin_post_tk_rate_limit_save', 'tk_rate_limit_save');
    add_action('login_form', 'tk_rate_limit_unlock_prompt');
    add_action('login_footer', 'tk_rate_limit_unlock_script');
    add_action('wp_ajax_tk_rate_limit_unlock', 'tk_rate_limit_unlock');
    add_action('wp_ajax_nopriv_tk_rate_limit_unlock', 'tk_rate_limit_unlock');
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

function tk_rate_limit_unlock_prompt() {
    if (!tk_rate_limit_enabled()) {
        return;
    }
    if (!get_transient(tk_rate_limit_lock_key())) {
        return;
    }
    $nonce = wp_create_nonce('tk_rate_limit_unlock');
    ?>
    <p class="tk-rate-limit-unlock">
        <button type="button" class="button button-secondary tk-rate-limit-unlock-button" data-nonce="<?php echo esc_attr($nonce); ?>">
            <?php esc_html_e('Unlock login attempts', 'tool-kits'); ?>
        </button>
        <span class="description"><?php esc_html_e('You are currently locked out; click to reset this IP and try again.', 'tool-kits'); ?></span>
    </p>
    <?php
}

function tk_rate_limit_unlock_script() {
    if (!tk_rate_limit_enabled()) {
        return;
    }
    if (!get_transient(tk_rate_limit_lock_key())) {
        return;
    }
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <script>
    (function(){
        document.addEventListener('click', function(e){
            var button = e.target.closest('.tk-rate-limit-unlock-button');
            if (!button) {
                return;
            }
            e.preventDefault();
            var nonce = button.getAttribute('data-nonce');
            button.disabled = true;
            fetch('<?php echo esc_js($ajax_url); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'tk_rate_limit_unlock',
                    nonce: nonce
                })
            }).then(function(resp){ return resp.json(); }).then(function(data){
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.data || 'Unable to unlock attempts.');
                    button.disabled = false;
                }
            }).catch(function(){
                button.disabled = false;
            });
        });
    })();
    </script>
    <?php
}

function tk_rate_limit_unlock() {
    check_ajax_referer('tk_rate_limit_unlock', 'nonce');
    if (!tk_rate_limit_enabled()) {
        wp_send_json_error('disabled');
    }
    delete_transient(tk_rate_limit_lock_key());
    delete_transient(tk_rate_limit_key());
    wp_send_json_success();
}
