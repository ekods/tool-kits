<?php
if (!defined('ABSPATH')) exit;

/**
 * Rate limit login attempts (IP based)
 */

function tk_rate_limit_init() {
    add_action('init', 'tk_rate_limit_honey_trap_request', 0);
    add_filter('authenticate', 'tk_rate_limit_authenticate', 30, 3);
    add_action('admin_post_tk_rate_limit_save', 'tk_rate_limit_save');
    add_action('admin_post_tk_rate_limit_unblock', 'tk_rate_limit_unblock_handler');
    add_action('wp_ajax_tk_rate_limit_unlock', 'tk_rate_limit_unlock');
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

function tk_rate_limit_offense_key() {
    return 'tk_rl_offense_' . md5(tk_get_ip());
}

function tk_rate_limit_parse_minutes_list($raw): array {
    if (!is_string($raw) || trim($raw) === '') {
        return array(15, 60, 360, 1440);
    }
    $parts = preg_split('/[\s,]+/', $raw);
    $parts = is_array($parts) ? $parts : array();
    $minutes = array();
    foreach ($parts as $part) {
        $value = (int) trim((string) $part);
        if ($value > 0) {
            $minutes[] = min($value, 10080);
        }
    }
    return !empty($minutes) ? array_values($minutes) : array(15, 60, 360, 1440);
}

function tk_rate_limit_progressive_steps(): array {
    return tk_rate_limit_parse_minutes_list((string) tk_get_option('rate_limit_progressive_steps', '15, 60, 360, 1440'));
}

function tk_rate_limit_next_lock_minutes(): int {
    if ((int) tk_get_option('rate_limit_progressive_enabled', 1) !== 1) {
        return max(1, (int) tk_get_option('rate_limit_lockout_minutes', 30));
    }

    $steps = tk_rate_limit_progressive_steps();
    $offenses = (int) get_transient(tk_rate_limit_offense_key());
    $index = min(max(0, $offenses), count($steps) - 1);
    $minutes = (int) $steps[$index];
    set_transient(tk_rate_limit_offense_key(), $offenses + 1, 7 * DAY_IN_SECONDS);
    return max(1, $minutes);
}

function tk_rate_limit_lock_current_ip(int $minutes, string $reason = ''): void {
    $ip = tk_get_ip();
    if (tk_rate_limit_is_whitelisted($ip)) {
        return;
    }
    set_transient(tk_rate_limit_lock_key(), 1, max(1, $minutes) * MINUTE_IN_SECONDS);
    if (function_exists('tk_security_events_record')) {
        tk_security_events_record(array(
            'event_type' => 'blocked',
            'category' => 'brute_force',
            'ip' => $ip,
            'user_agent' => tk_user_agent(),
            'reason' => $reason !== '' ? $reason : 'progressive_lockout',
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        ));
    }
}

function tk_rate_limit_honey_trap_paths(): array {
    $raw = (string) tk_get_option('rate_limit_honey_trap_paths', "/wp-login.php\n/login\n/admin\n/wp-admin.php\n/administrator\n/user/login");
    return tk_rate_limit_normalize_honey_trap_paths($raw);
}

function tk_rate_limit_normalize_honey_trap_paths(string $raw): array {
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $lines = is_array($lines) ? $lines : array();
    $paths = array();
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $path = '/' . trim($line, '/');
        if ($path !== '/') {
            $paths[] = strtolower($path);
        }
    }
    return array_values(array_unique($paths));
}

function tk_rate_limit_request_path(): string {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($request_uri === '') {
        return '';
    }
    $path = wp_parse_url($request_uri, PHP_URL_PATH);
    return is_string($path) ? '/' . trim($path, '/') : '';
}

function tk_rate_limit_honey_trap_matches_path(string $path): bool {
    if ($path === '') {
        return false;
    }
    foreach (tk_rate_limit_honey_trap_paths() as $trap_path) {
        if ($path === $trap_path || substr($path, -strlen($trap_path)) === $trap_path) {
            return true;
        }
    }
    return false;
}

function tk_rate_limit_honey_trap_request(): void {
    if ((int) tk_get_option('rate_limit_honey_trap_enabled', 1) !== 1) {
        return;
    }
    if (!tk_get_option('hide_login_enabled', 0)) {
        return;
    }
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    $path = strtolower(tk_rate_limit_request_path());
    if ($path === '') {
        return;
    }
    if ($path === '/wp-admin/admin-ajax.php' || $path === '/wp-admin/admin-post.php') {
        return;
    }
    if (!tk_rate_limit_honey_trap_matches_path($path)) {
        return;
    }

    $minutes = tk_rate_limit_next_lock_minutes();
    tk_rate_limit_lock_current_ip($minutes, 'login_honey_trap:' . $path);
    wp_safe_redirect(home_url('/'));
    exit;
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
        $lock = tk_rate_limit_next_lock_minutes();
        tk_rate_limit_lock_current_ip($lock, 'progressive_login_lockout');
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
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Login Rate Limiter', 'tool-kits'), __('Prevent brute-force attacks by limiting the number of login attempts from specific IPs.', 'tool-kits'), 'dashicons-warning'); ?>
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
                                <input type="checkbox" name="progressive_enabled" value="1"
                                    <?php checked(1, tk_get_option('rate_limit_progressive_enabled', 1)); ?>>
                                Enable progressive lockout
                            </label>
                        </p>

                        <p>
                            Progressive lockout steps (minutes)<br>
                            <input type="text" name="progressive_steps" class="regular-text" value="<?php echo esc_attr((string) tk_get_option('rate_limit_progressive_steps', '15, 60, 360, 1440')); ?>">
                            <span class="description">Comma-separated. Example: 15, 60, 360, 1440.</span>
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

                        <hr>

                        <h3><?php esc_html_e('Auto Block Rules', 'tool-kits'); ?></h3>
                        <p class="description"><?php esc_html_e('Automatically block obvious bot login traffic and IPs that exceed the failed-login threshold.', 'tool-kits'); ?></p>

                        <p>
                            <label>
                                <input type="checkbox" name="auto_block_enabled" value="1"
                                    <?php checked(1, tk_get_option('security_auto_block_enabled', 1)); ?>>
                                Enable auto block rules
                            </label>
                        </p>

                        <p>
                            Failed-login threshold<br>
                            <input type="number" name="auto_block_threshold" value="<?php echo esc_attr(tk_get_option('security_auto_block_threshold', 10)); ?>" min="2">
                        </p>

                        <p>
                            Threshold window (minutes)<br>
                            <input type="number" name="auto_block_window" value="<?php echo esc_attr(tk_get_option('security_auto_block_window_minutes', 10)); ?>" min="1">
                        </p>

                        <p>
                            Blocked login user-agent fragments (one per line)<br>
                            <textarea name="auto_block_user_agents" rows="5" class="large-text"><?php echo esc_textarea((string) tk_get_option('security_auto_block_user_agents', '')); ?></textarea>
                        </p>

                        <hr>

                        <h3><?php esc_html_e('Login Honey Trap', 'tool-kits'); ?></h3>
                        <p class="description"><?php esc_html_e('When Hide Login is active, requests to common bot login paths are redirected to the homepage and temporarily locked out.', 'tool-kits'); ?></p>

                        <p>
                            <label>
                                <input type="checkbox" name="honey_trap_enabled" value="1"
                                    <?php checked(1, tk_get_option('rate_limit_honey_trap_enabled', 1)); ?>>
                                Enable login honey trap
                            </label>
                        </p>

                        <p>
                            Honey trap paths (one per line)<br>
                            <textarea name="honey_trap_paths" rows="6" class="large-text"><?php echo esc_textarea((string) tk_get_option('rate_limit_honey_trap_paths', "/wp-login.php\n/login\n/admin\n/wp-admin.php\n/administrator\n/user/login")); ?></textarea>
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
    tk_require_admin_post('tk_rate_limit_save');

    tk_update_option('rate_limit_enabled', !empty($_POST['enabled']) ? 1 : 0);
    tk_update_option('rate_limit_window_minutes', (int) $_POST['window']);
    tk_update_option('rate_limit_max_attempts', (int) $_POST['max']);
    tk_update_option('rate_limit_lockout_minutes', (int) $_POST['lock']);
    tk_update_option('rate_limit_progressive_enabled', !empty($_POST['progressive_enabled']) ? 1 : 0);
    $progressive_steps = isset($_POST['progressive_steps']) ? (string) wp_unslash($_POST['progressive_steps']) : '';
    tk_update_option('rate_limit_progressive_steps', implode(', ', tk_rate_limit_parse_minutes_list($progressive_steps)));
    tk_update_option('rate_limit_block_on_fail', !empty($_POST['block_on_fail']) ? 1 : 0);
    tk_update_option('security_auto_block_enabled', !empty($_POST['auto_block_enabled']) ? 1 : 0);
    tk_update_option('security_auto_block_threshold', max(2, (int) $_POST['auto_block_threshold']));
    tk_update_option('security_auto_block_window_minutes', max(1, (int) $_POST['auto_block_window']));
    $auto_agents = isset($_POST['auto_block_user_agents']) ? (string) wp_unslash($_POST['auto_block_user_agents']) : '';
    $auto_agents = implode("\n", array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $auto_agents)))));
    tk_update_option('security_auto_block_user_agents', $auto_agents);
    tk_update_option('rate_limit_honey_trap_enabled', !empty($_POST['honey_trap_enabled']) ? 1 : 0);
    $honey_paths = isset($_POST['honey_trap_paths']) ? (string) wp_unslash($_POST['honey_trap_paths']) : '';
    $honey_paths = implode("\n", tk_rate_limit_normalize_honey_trap_paths($honey_paths));
    tk_update_option('rate_limit_honey_trap_paths', $honey_paths);
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

    wp_safe_redirect(admin_url('admin.php?page=tool-kits-security-rate-limit'));
    exit;
}

function tk_rate_limit_unblock_handler() {
    tk_require_admin_post('tk_rate_limit_unblock');
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
    if (!is_user_logged_in() || !tk_is_admin_user()) {
        return;
    }
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
    if (!is_user_logged_in() || !tk_is_admin_user()) {
        wp_send_json_error('forbidden');
    }
    if (!tk_rate_limit_enabled()) {
        wp_send_json_error('disabled');
    }
    delete_transient(tk_rate_limit_lock_key());
    delete_transient(tk_rate_limit_key());
    wp_send_json_success();
}
