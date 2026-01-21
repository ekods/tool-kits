<?php
if (!defined('ABSPATH')) exit;

/**
 * Rate limit login attempts (IP based)
 */

function tk_rate_limit_init() {
    add_filter('authenticate', 'tk_rate_limit_authenticate', 30, 3);
    add_action('admin_post_tk_rate_limit_save', 'tk_rate_limit_save');
    add_action('admin_post_tk_rate_limit_unblock', 'tk_rate_limit_unblock_handler');
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

function tk_rate_limit_parse_ip_list($raw) {
    if (!is_string($raw) || trim($raw) === '') {
        return array();
    }
    $parts = preg_split('/[\s,]+/', $raw);
    if (!is_array($parts)) {
        return array();
    }
    $list = array();
    foreach ($parts as $part) {
        $ip = trim($part);
        if ($ip === '') {
            continue;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }
        $list[] = $ip;
    }
    return array_values(array_unique($list));
}

function tk_rate_limit_whitelist_ips() {
    $raw = (string) tk_get_option('rate_limit_whitelist', '');
    return tk_rate_limit_parse_ip_list($raw);
}

function tk_rate_limit_is_whitelisted($ip) {
    if (!is_string($ip) || $ip === '') {
        return false;
    }
    return in_array($ip, tk_rate_limit_whitelist_ips(), true);
}

function tk_rate_limit_blocked_ips() {
    $blocked = tk_get_option('rate_limit_blocked_ips', array());
    if (!is_array($blocked)) {
        return array();
    }
    return $blocked;
}

function tk_rate_limit_is_blocked($ip) {
    if (!is_string($ip) || $ip === '') {
        return false;
    }
    $blocked = tk_rate_limit_blocked_ips();
    return isset($blocked[$ip]);
}

function tk_rate_limit_block_ip($ip) {
    if (!is_string($ip) || $ip === '') {
        return;
    }
    if (tk_rate_limit_is_whitelisted($ip)) {
        return;
    }
    $blocked = tk_rate_limit_blocked_ips();
    if (!isset($blocked[$ip])) {
        $blocked[$ip] = time();
        tk_update_option('rate_limit_blocked_ips', $blocked);
    }
}

function tk_rate_limit_unblock_ips($ips) {
    if (!is_array($ips) || empty($ips)) {
        return;
    }
    $blocked = tk_rate_limit_blocked_ips();
    foreach ($ips as $ip) {
        $ip = is_string($ip) ? trim($ip) : '';
        if ($ip === '') {
            continue;
        }
        unset($blocked[$ip]);
    }
    tk_update_option('rate_limit_blocked_ips', $blocked);
}

function tk_rate_limit_authenticate($user, $username, $password) {
    if (!tk_rate_limit_enabled()) return $user;

    // only trigger inside the login form
    if (!isset($_POST['log'], $_POST['pwd'])) return $user;

    $ip = tk_get_ip();
    if (tk_rate_limit_is_whitelisted($ip)) {
        return $user;
    }
    if (tk_rate_limit_is_blocked($ip)) {
        return new WP_Error(
            'tk_rate_limited_blocked',
            __('Your IP is blocked. Please contact the site administrator.', 'tool-kits')
        );
    }

    // currently locked out
    if (get_transient(tk_rate_limit_lock_key())) {
        return new WP_Error(
            'tk_rate_limited',
            __('Too many login attempts. Please try again later.', 'tool-kits')
        );
    }

    if (is_wp_error($user)) {
        tk_rate_limit_increment();
        if ((int) tk_get_option('rate_limit_block_on_fail', 0) === 1) {
            tk_rate_limit_block_ip($ip);
        }
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
    $unblocked = isset($_GET['tk_unblocked']) ? sanitize_key($_GET['tk_unblocked']) : '';
    if ($unblocked === '1') {
        tk_notice('Blocked IPs updated.', 'success');
    }
    $blocked = tk_rate_limit_blocked_ips();
    if (!is_array($blocked)) {
        $blocked = array();
    }
    ksort($blocked);
    ?>
    <div class="wrap tk-wrap">
        <h1>Rate Limit</h1>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="settings">Settings</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="blocked">Blocked IPs</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="settings">
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

                        <p>
                            <label>
                                <input type="checkbox" name="block_on_fail" value="1"
                                    <?php checked(1, tk_get_option('rate_limit_block_on_fail', 0)); ?>>
                                Block IP on failed login (manual unblock required)
                            </label>
                        </p>

                        <p>
                            Whitelist IPs (one per line)<br>
                            <textarea name="whitelist" rows="4" class="large-text"><?php echo esc_textarea((string) tk_get_option('rate_limit_whitelist', '')); ?></textarea>
                        </p>

                        <p><button class="button button-primary">Save</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="blocked">
                    <h2>Blocked IPs</h2>
                    <?php if (empty($blocked)) : ?>
                        <p>No blocked IPs.</p>
                    <?php else : ?>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php tk_nonce_field('tk_rate_limit_unblock'); ?>
                            <input type="hidden" name="action" value="tk_rate_limit_unblock">
                            <table class="tk-table">
                                <thead>
                                    <tr>
                                        <th>Unblock</th>
                                        <th>IP</th>
                                        <th>Blocked at</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($blocked as $ip => $time) : ?>
                                    <tr>
                                        <td><input type="checkbox" name="blocked_ips[]" value="<?php echo esc_attr($ip); ?>"></td>
                                        <td><code><?php echo esc_html($ip); ?></code></td>
                                        <td><?php echo $time ? esc_html(date_i18n('Y-m-d H:i', (int) $time)) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p><button class="button button-secondary">Unblock selected</button></p>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            function activateTab(panelId) {
                document.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                });
                document.querySelectorAll('.tk-tabs-nav-button').forEach(function(btn){
                    btn.classList.toggle('is-active', btn.getAttribute('data-panel') === panelId);
                });
            }
            function getPanelFromHash() {
                var hash = window.location.hash || '';
                if (!hash) { return ''; }
                return hash.replace('#', '');
            }
            document.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    if (panelId) {
                        window.location.hash = panelId;
                        activateTab(panelId);
                    }
                });
            });
            var initial = getPanelFromHash();
            if (initial) {
                activateTab(initial);
            }
        })();
        </script>
    </div>
    <?php
}

function tk_rate_limit_save() {
    tk_check_nonce('tk_rate_limit_save');

    tk_update_option('rate_limit_enabled', !empty($_POST['enabled']) ? 1 : 0);
    tk_update_option('rate_limit_window_minutes', (int) $_POST['window']);
    tk_update_option('rate_limit_max_attempts', (int) $_POST['max']);
    tk_update_option('rate_limit_lockout_minutes', (int) $_POST['lock']);
    tk_update_option('rate_limit_block_on_fail', !empty($_POST['block_on_fail']) ? 1 : 0);
    $whitelist_raw = isset($_POST['whitelist']) ? (string) wp_unslash($_POST['whitelist']) : '';
    $whitelist_ips = tk_rate_limit_parse_ip_list($whitelist_raw);
    tk_update_option('rate_limit_whitelist', implode("\n", $whitelist_ips));
    if (!empty($whitelist_ips)) {
        $blocked = tk_rate_limit_blocked_ips();
        foreach ($whitelist_ips as $ip) {
            unset($blocked[$ip]);
        }
        tk_update_option('rate_limit_blocked_ips', $blocked);
    }

    wp_redirect(admin_url('admin.php?page=tool-kits-security-rate-limit'));
    exit;
}

function tk_rate_limit_unblock_handler() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_rate_limit_unblock');
    $ips = isset($_POST['blocked_ips']) ? (array) $_POST['blocked_ips'] : array();
    $clean = array();
    foreach ($ips as $ip) {
        $ip = is_string($ip) ? trim(wp_unslash($ip)) : '';
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }
        $clean[] = $ip;
    }
    tk_rate_limit_unblock_ips($clean);
    wp_redirect(admin_url('admin.php?page=tool-kits-security-rate-limit&tk_unblocked=1'));
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
