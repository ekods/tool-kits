<?php
if (!defined('ABSPATH')) { exit; }

function tk_form_guard_init() {
    add_action('init', 'tk_form_guard_bootstrap', 1);
    add_action('comment_form_after_fields', 'tk_form_guard_render_comment_fields');
    add_action('comment_form_logged_in_after', 'tk_form_guard_render_comment_fields');
    add_action('admin_post_tk_form_guard_clear_rate_limits', 'tk_form_guard_clear_rate_limits_handler');
    add_filter('preprocess_comment', 'tk_form_guard_validate_comment', 5);
}

function tk_form_guard_enabled(): bool {
    return (int) tk_get_option('form_guard_enabled', 1) === 1;
}

function tk_form_guard_post_rate_key(string $context): string {
    return 'tk_form_guard_rl_' . md5($context . '|' . tk_get_ip());
}

function tk_form_guard_request_method(): string {
    return isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
}

function tk_form_guard_request_uri(): string {
    return isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
}

function tk_form_guard_request_context(): string {
    if (isset($_POST['comment_post_ID'], $_POST['comment'])) {
        return 'comment';
    }

    if (isset($_POST['log'], $_POST['pwd'])) {
        return 'login';
    }

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return 'ajax';
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return 'rest';
    }

    return 'frontend';
}

function tk_form_guard_is_public_post(): bool {
    if (tk_form_guard_request_method() !== 'POST') {
        return false;
    }

    if (defined('WP_CLI') && WP_CLI) {
        return false;
    }

    $context = tk_form_guard_request_context();
    if (in_array($context, array('login', 'comment', 'frontend'), true)) {
        return true;
    }

    return false;
}

function tk_form_guard_user_agent_is_suspicious(string $ua): bool {
    $ua = strtolower(trim($ua));
    if ($ua === '') {
        return (int) tk_get_option('form_guard_block_empty_ua', 1) === 1;
    }

    foreach (tk_antispam_line_list((string) tk_get_option('form_guard_blocked_user_agents', "curl\nwget\npython-requests\npython-urllib\nlibwww-perl\nscrapy\nhttpclient\ngo-http-client\njava/\nokhttp\npowershell\npostmanruntime")) as $needle) {
        $needle = strtolower(trim($needle));
        if ($needle !== '' && strpos($ua, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function tk_form_guard_rate_limit_exceeded(string $context): bool {
    $window = max(1, (int) tk_get_option('form_guard_post_window_minutes', 10));
    $max = max(1, (int) tk_get_option('form_guard_post_max_attempts', 8));
    $key = tk_form_guard_post_rate_key($context);
    $data = get_transient($key);

    if (!is_array($data)) {
        $data = array('count' => 0);
    }

    $data['count'] = isset($data['count']) ? (int) $data['count'] + 1 : 1;
    $data['context'] = $context;
    $data['ip'] = tk_get_ip();
    $data['user_agent'] = tk_user_agent();
    $data['first_seen'] = isset($data['first_seen']) ? (int) $data['first_seen'] : time();
    $data['last_seen'] = time();
    set_transient($key, $data, $window * MINUTE_IN_SECONDS);

    return $data['count'] > $max;
}

function tk_form_guard_rate_limit_counter_count(): int {
    global $wpdb;

    $like = $wpdb->esc_like('_transient_tk_form_guard_rl_') . '%';
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));
}

function tk_form_guard_rate_limit_counters(int $limit = 100): array {
    global $wpdb;

    $limit = max(1, min(500, $limit));
    $like = $wpdb->esc_like('_transient_tk_form_guard_rl_') . '%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d",
        $like,
        $limit
    ), ARRAY_A);

    if (!is_array($rows)) {
        return array();
    }

    $items = array();
    foreach ($rows as $row) {
        $option_name = isset($row['option_name']) ? (string) $row['option_name'] : '';
        if (strpos($option_name, '_transient_') !== 0) {
            continue;
        }

        $transient_key = substr($option_name, strlen('_transient_'));
        if (strpos($transient_key, 'tk_form_guard_rl_') !== 0) {
            continue;
        }

        $data = maybe_unserialize($row['option_value'] ?? '');
        $timeout = (int) get_option('_transient_timeout_' . $transient_key, 0);
        $items[] = array(
            'key' => $transient_key,
            'hash' => substr($transient_key, strlen('tk_form_guard_rl_')),
            'count' => is_array($data) && isset($data['count']) ? (int) $data['count'] : 0,
            'context' => is_array($data) && isset($data['context']) ? (string) $data['context'] : '',
            'ip' => is_array($data) && isset($data['ip']) ? (string) $data['ip'] : '',
            'user_agent' => is_array($data) && isset($data['user_agent']) ? (string) $data['user_agent'] : '',
            'first_seen' => is_array($data) && isset($data['first_seen']) ? (int) $data['first_seen'] : 0,
            'last_seen' => is_array($data) && isset($data['last_seen']) ? (int) $data['last_seen'] : 0,
            'expires_at' => $timeout,
        );
    }

    return $items;
}

function tk_form_guard_clear_rate_limit_counters(): int {
    global $wpdb;

    $counter_like = $wpdb->esc_like('_transient_tk_form_guard_rl_') . '%';
    $timeout_like = $wpdb->esc_like('_transient_timeout_tk_form_guard_rl_') . '%';

    return (int) $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $counter_like,
        $timeout_like
    ));
}

function tk_form_guard_delete_rate_limit_counters(array $keys): int {
    $deleted = 0;
    foreach ($keys as $key) {
        $key = sanitize_text_field((string) wp_unslash($key));
        if (strpos($key, 'tk_form_guard_rl_') !== 0) {
            continue;
        }

        delete_transient($key);
        $deleted++;
    }

    return $deleted;
}

function tk_form_guard_clear_rate_limits_handler(): void {
    tk_require_admin_post('tk_form_guard_clear_rate_limits');

    $keys = isset($_POST['counter_keys']) ? (array) $_POST['counter_keys'] : array();
    $deleted = !empty($keys) ? tk_form_guard_delete_rate_limit_counters($keys) : 0;
    if ($deleted === 0 && !empty($_POST['clear_all'])) {
        $deleted = tk_form_guard_clear_rate_limit_counters();
    }
    wp_redirect(admin_url('admin.php?page=tool-kits-security-spam&tk_tab=antispam&tk_form_guard_cleared=' . $deleted . '#antispam'));
    exit;
}

function tk_render_form_guard_rate_limit_tools(): void {
    if (!tk_is_admin_user()) {
        return;
    }

    $count = tk_form_guard_rate_limit_counter_count();
    $counters = tk_form_guard_rate_limit_counters();
    ?>
    <div class="tk-card" style="margin-top:20px;">
        <h2>Form Guard Unblock</h2>
        <p>Temporary Form Guard POST rate counters can block login or public form submissions when the count passes the configured limit.</p>
        <p>Active counters: <strong><?php echo esc_html((string) $count); ?></strong></p>
        <?php if (empty($counters)) : ?>
            <p>No active Form Guard counters.</p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_form_guard_clear_rate_limits'); ?>
                <input type="hidden" name="action" value="tk_form_guard_clear_rate_limits">
                <table class="widefat striped tk-table">
                    <thead>
                        <tr>
                            <th>Unblock</th>
                            <th>IP</th>
                            <th>Context</th>
                            <th>Count</th>
                            <th>Last Seen</th>
                            <th>Expires</th>
                            <th>Key</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($counters as $counter) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="counter_keys[]" value="<?php echo esc_attr($counter['key']); ?>">
                                    <button class="button button-small" name="counter_keys[]" value="<?php echo esc_attr($counter['key']); ?>" style="margin-left:8px;">Unblock</button>
                                </td>
                                <td><?php echo $counter['ip'] !== '' ? '<code>' . esc_html($counter['ip']) . '</code>' : '<span class="description">Unknown</span>'; ?></td>
                                <td><?php echo $counter['context'] !== '' ? esc_html($counter['context']) : '<span class="description">Legacy</span>'; ?></td>
                                <td><?php echo esc_html((string) $counter['count']); ?></td>
                                <td><?php echo $counter['last_seen'] > 0 ? esc_html(date_i18n('Y-m-d H:i', $counter['last_seen'])) : '-'; ?></td>
                                <td><?php echo $counter['expires_at'] > 0 ? esc_html(date_i18n('Y-m-d H:i', $counter['expires_at'])) : '-'; ?></td>
                                <td><code><?php echo esc_html(substr($counter['hash'], 0, 12)); ?>...</code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="display:flex; gap:10px; align-items:center;">
                    <button class="button button-secondary">Unblock selected</button>
                    <button class="button button-link-delete" name="clear_all" value="1">Clear all counters</button>
                </p>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function tk_form_guard_scalar_post_values(): array {
    $values = array();
    foreach ($_POST as $key => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $name = sanitize_key((string) $key);
        if ($name === '' || strpos($name, '_wp') === 0) {
            continue;
        }
        if (in_array($name, array('_tk_nonce', 'tk_hp_field', 'tk_form_ts', 'tk_comment_hp', 'tk_comment_ts', 'tk_captcha_token', 'tk_captcha_answer'), true)) {
            continue;
        }
        $values[$name] = trim((string) wp_unslash($value));
    }

    return $values;
}

function tk_form_guard_deny(string $reason, int $status = 403): void {
    tk_log('Blocked public form request: ' . $reason . ' | IP: ' . tk_get_ip() . ' | UA: ' . tk_user_agent());
    nocache_headers();
    status_header($status);
    wp_die(__('Request blocked by security policy.', 'tool-kits'), __('Access denied', 'tool-kits'), array('response' => $status));
}

function tk_form_guard_bootstrap(): void {
    if (!tk_form_guard_enabled() || !tk_form_guard_is_public_post()) {
        return;
    }

    if (is_user_logged_in() && current_user_can('manage_options')) {
        return;
    }

    $context = tk_form_guard_request_context();
    $ua = tk_user_agent();

    if (tk_form_guard_user_agent_is_suspicious($ua)) {
        tk_form_guard_deny('suspicious_user_agent:' . ($ua !== '' ? $ua : 'empty'));
    }

    if (function_exists('tk_rate_limit_enabled') && tk_rate_limit_enabled() && tk_rate_limit_is_blocked(tk_get_ip())) {
        tk_form_guard_deny('ip_blocked', 429);
    }

    if (tk_form_guard_rate_limit_exceeded($context)) {
        tk_form_guard_deny('public_post_rate_limit:' . $context, 429);
    }

    if (in_array($context, array('frontend', 'ajax'), true) && function_exists('tk_antispam_detect_random_submission_reason')) {
        $values = tk_form_guard_scalar_post_values();
        if (!empty($values)) {
            $reason = tk_antispam_detect_random_submission_reason($values);
            if ($reason !== '') {
                tk_form_guard_deny($reason);
            }
        }
    }
}

function tk_form_guard_render_comment_fields(): void {
    if (!tk_form_guard_enabled()) {
        return;
    }

    $ts = time();
    set_transient('tk_comment_form_' . md5(tk_get_ip() . '|' . tk_user_agent()), $ts, 30 * MINUTE_IN_SECONDS);

    echo '<p class="comment-form-tk-hp" style="position:absolute;left:-9999px;top:-9999px;height:1px;overflow:hidden;" aria-hidden="true">';
    echo '<label>' . esc_html__('Leave this field empty', 'tool-kits') . '<input type="text" name="tk_comment_hp" value="" tabindex="-1" autocomplete="off"></label>';
    echo '</p>';
    echo '<input type="hidden" name="tk_comment_ts" value="' . esc_attr((string) $ts) . '">';

    if (tk_get_option('captcha_enabled') && tk_get_option('captcha_on_comments')) {
        echo tk_captcha_render_markup();
    }
}

function tk_form_guard_comment_key(): string {
    return 'tk_comment_form_' . md5(tk_get_ip() . '|' . tk_user_agent());
}

function tk_form_guard_validate_comment(array $commentdata): array {
    if (!tk_form_guard_enabled()) {
        return $commentdata;
    }

    $hp = isset($_POST['tk_comment_hp']) ? trim((string) wp_unslash($_POST['tk_comment_hp'])) : '';
    if ($hp !== '') {
        tk_form_guard_deny('comment_honeypot');
    }

    $posted = isset($_POST['tk_comment_ts']) ? (int) $_POST['tk_comment_ts'] : 0;
    $stored = (int) get_transient(tk_form_guard_comment_key());
    if ($posted > 0 && $stored > 0) {
        $elapsed = time() - $stored;
        $min = max(0, (int) tk_get_option('form_guard_comment_min_seconds', 4));
        if ($elapsed < $min) {
            tk_form_guard_deny('comment_submitted_too_quickly');
        }
    }

    if (tk_get_option('captcha_enabled') && tk_get_option('captcha_on_comments')) {
        $validation = tk_captcha_validate_request();
        if (!$validation['present']) {
            tk_form_guard_deny('comment_captcha_missing');
        }
        if (!$validation['valid']) {
            tk_form_guard_deny('comment_captcha_invalid');
        }
    }

    return $commentdata;
}
