<?php
if (!defined('ABSPATH')) { exit; }

function tk_firewall_init(): void {
    add_action('init', 'tk_firewall_enforce_request_rules', 0);
    add_action('admin_menu', 'tk_firewall_register_page', 20);
    add_action('admin_post_tk_firewall_save', 'tk_firewall_save');
    add_action('admin_post_tk_firewall_clear_log', 'tk_firewall_clear_log');
}

function tk_firewall_register_page(): void {
    $license_valid = (string) tk_get_option('license_status', 'inactive') === 'valid';
    $license_limited = (string) tk_get_option('license_type', '') === 'local';
    if (!tk_toolkits_can_manage() || !$license_valid || $license_limited) {
        return;
    }

    add_submenu_page(
        'tool-kits',
        __('Firewall', 'tool-kits'),
        __('Firewall', 'tool-kits'),
        'manage_options',
        'tool-kits-firewall',
        'tk_firewall_render_page'
    );
}

function tk_firewall_parse_lines($value): array {
    if (!is_string($value)) {
        return array();
    }
    $lines = preg_split('/\r\n|\r|\n/', $value);
    $lines = is_array($lines) ? $lines : array();
    return array_values(array_unique(array_filter(array_map('trim', $lines))));
}

function tk_firewall_request_ip(): string {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    $ip = (string) apply_filters('tk_firewall_request_ip', $ip);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function tk_firewall_ip_matches_rule(string $ip, string $rule): bool {
    $rule = trim($rule);
    if ($ip === '' || $rule === '') {
        return false;
    }
    if (strpos($rule, '/') === false) {
        return hash_equals(strtolower($rule), strtolower($ip));
    }

    list($network, $prefix) = array_pad(explode('/', $rule, 2), 2, '');
    $ip_binary = @inet_pton($ip);
    $network_binary = @inet_pton($network);
    if ($ip_binary === false || $network_binary === false || strlen($ip_binary) !== strlen($network_binary)) {
        return false;
    }
    $bits = strlen($ip_binary) * 8;
    $prefix = filter_var($prefix, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => $bits)));
    if ($prefix === false) {
        return false;
    }

    $full_bytes = intdiv((int) $prefix, 8);
    $remaining_bits = (int) $prefix % 8;
    if ($full_bytes > 0 && substr($ip_binary, 0, $full_bytes) !== substr($network_binary, 0, $full_bytes)) {
        return false;
    }
    if ($remaining_bits === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remaining_bits)) & 0xFF;
    return (ord($ip_binary[$full_bytes]) & $mask) === (ord($network_binary[$full_bytes]) & $mask);
}

function tk_firewall_ip_matches_any(string $ip, array $rules): bool {
    foreach ($rules as $rule) {
        if (tk_firewall_ip_matches_rule($ip, (string) $rule)) {
            return true;
        }
    }
    return false;
}

function tk_firewall_log_event(string $reason, string $ip): void {
    if (function_exists('tk_security_events_record')) {
        $location = function_exists('tk_security_alert_ip_location') ? tk_security_alert_ip_location($ip) : '';
        $is_blocklist = stripos($reason, 'Blocked IP/CIDR') !== false || stripos($reason, 'Blocked user agent') !== false;
        tk_security_events_record(array(
            'event_type' => 'blocked',
            'category' => $is_blocklist ? 'blocklist' : 'complex',
            'ip' => $ip,
            'location' => $location,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            'reason' => $reason,
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? strtolower((string) $_SERVER['REQUEST_METHOD']) : '',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        ));
    }

    if (!(int) tk_get_option('firewall_log_enabled', 1)) {
        return;
    }
    $events = tk_get_option('firewall_event_log', array());
    $events = is_array($events) ? $events : array();
    array_unshift($events, array(
        'time' => time(),
        'ip' => $ip,
        'reason' => sanitize_text_field($reason),
        'method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(strtolower((string) $_SERVER['REQUEST_METHOD'])) : '',
        'uri' => isset($_SERVER['REQUEST_URI']) ? substr(sanitize_text_field((string) $_SERVER['REQUEST_URI']), 0, 500) : '',
    ));
    tk_update_option('firewall_event_log', array_slice($events, 0, 200));
}

function tk_firewall_enforce_request_rules(): void {
    if (!(int) tk_get_option('hardening_waf_enabled', 0)) {
        return;
    }
    if ((defined('WP_CLI') && WP_CLI) || (function_exists('wp_doing_cron') && wp_doing_cron())) {
        return;
    }

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return;
    }

    $ip = tk_firewall_request_ip();
    $allowlist = tk_firewall_parse_lines((string) tk_get_option('firewall_ip_allowlist', ''));
    if ($ip !== '' && tk_firewall_ip_matches_any($ip, $allowlist)) {
        if (!defined('TK_FIREWALL_REQUEST_ALLOWLISTED')) {
            define('TK_FIREWALL_REQUEST_ALLOWLISTED', true);
        }
        return;
    }

    $blocklist = tk_firewall_parse_lines((string) tk_get_option('firewall_ip_blocklist', ''));
    if ($ip !== '' && tk_firewall_ip_matches_any($ip, $blocklist)) {
        tk_firewall_log_event('Blocked IP/CIDR rule', $ip);
        wp_die(__('Request blocked by firewall.', 'tool-kits'), __('Forbidden', 'tool-kits'), array('response' => 403));
    }

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : '';
    foreach (tk_firewall_parse_lines((string) tk_get_option('firewall_blocked_user_agents', '')) as $blocked_agent) {
        if ($blocked_agent !== '' && $user_agent !== '' && strpos($user_agent, strtolower($blocked_agent)) !== false) {
            tk_firewall_log_event('Blocked user agent: ' . $blocked_agent, $ip);
            wp_die(__('Request blocked by firewall.', 'tool-kits'), __('Forbidden', 'tool-kits'), array('response' => 403));
        }
    }
}

function tk_firewall_save(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_firewall_save');

    $methods = isset($_POST['waf_check_methods'])
        ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['waf_check_methods'])))
        : 'GET, POST';
    $methods = array_values(array_intersect(
        array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'),
        array_filter(array_map('trim', explode(',', $methods)))
    ));
    if (empty($methods)) {
        $methods = array('GET', 'POST');
    }

    $allowlist_raw = (string) tk_post('firewall_ip_allowlist', '');
    $blocklist_raw = (string) tk_post('firewall_ip_blocklist', '');
    $blocked_agents_raw = (string) tk_post('firewall_blocked_user_agents', '');
    $request_ip = tk_firewall_request_ip();
    $request_is_allowed = $request_ip !== '' && tk_firewall_ip_matches_any($request_ip, tk_firewall_parse_lines($allowlist_raw));
    if ($request_ip !== '' && !$request_is_allowed && tk_firewall_ip_matches_any($request_ip, tk_firewall_parse_lines($blocklist_raw))) {
        wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-firewall', 'tk_firewall_error' => 'self_block'), admin_url('admin.php')));
        exit;
    }
    $current_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : '';
    if (!$request_is_allowed && $current_agent !== '') {
        foreach (tk_firewall_parse_lines($blocked_agents_raw) as $blocked_agent) {
            if ($blocked_agent !== '' && strpos($current_agent, strtolower($blocked_agent)) !== false) {
                wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-firewall', 'tk_firewall_error' => 'self_agent'), admin_url('admin.php')));
                exit;
            }
        }
    }

    tk_update_option('hardening_waf_enabled', !empty($_POST['firewall_enabled']) ? 1 : 0);
    tk_update_option('hardening_waf_log_to_file', !empty($_POST['waf_log_to_file']) ? 1 : 0);
    tk_update_option('hardening_waf_check_methods', implode(', ', $methods));
    tk_update_option('hardening_waf_allow_paths', (string) tk_post('waf_allow_paths', ''));
    tk_update_option('firewall_ip_allowlist', $allowlist_raw);
    tk_update_option('firewall_ip_blocklist', $blocklist_raw);
    tk_update_option('firewall_blocked_user_agents', $blocked_agents_raw);
    tk_update_option('firewall_log_enabled', !empty($_POST['firewall_log_enabled']) ? 1 : 0);

    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-firewall', 'tk_saved' => '1'), admin_url('admin.php')));
    exit;
}

function tk_firewall_clear_log(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_firewall_clear_log');
    tk_update_option('firewall_event_log', array());
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-firewall', 'tk_log_cleared' => '1'), admin_url('admin.php')));
    exit;
}

function tk_firewall_render_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $events = tk_get_option('firewall_event_log', array());
    $events = is_array($events) ? $events : array();
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Firewall', 'tool-kits'), __('Block malicious payloads, IP ranges, and unwanted user agents before WordPress renders a page.', 'tool-kits'), 'dashicons-shield-alt'); ?>
        <?php if (isset($_GET['tk_saved'])) : ?><?php tk_notice(__('Firewall settings saved.', 'tool-kits'), 'success'); ?><?php endif; ?>
        <?php if (isset($_GET['tk_log_cleared'])) : ?><?php tk_notice(__('Firewall event log cleared.', 'tool-kits'), 'success'); ?><?php endif; ?>
        <?php if (isset($_GET['tk_firewall_error']) && $_GET['tk_firewall_error'] === 'self_block') : ?><?php tk_notice(__('Settings were not saved because the blocklist matches your current IP. Add your IP to the allowlist first.', 'tool-kits'), 'error'); ?><?php endif; ?>
        <?php if (isset($_GET['tk_firewall_error']) && $_GET['tk_firewall_error'] === 'self_agent') : ?><?php tk_notice(__('Settings were not saved because a blocked user-agent rule matches your current browser.', 'tool-kits'), 'error'); ?><?php endif; ?>

        <div class="tk-card" style="margin-bottom:24px;">
            <h2><?php esc_html_e('Firewall Rules', 'tool-kits'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_firewall_save'); ?>
                <input type="hidden" name="action" value="tk_firewall_save">
                <div style="display:grid; gap:18px;">
                    <?php tk_render_switch('firewall_enabled', 'Enable Firewall', 'Enables the existing payload WAF plus the IP and user-agent rules below.', (int) tk_get_option('hardening_waf_enabled', 0)); ?>
                    <?php tk_render_switch('waf_log_to_file', 'Write WAF file log', 'Store payload detections in the protected Tool Kits log directory.', (int) tk_get_option('hardening_waf_log_to_file', 0)); ?>
                    <?php tk_render_switch('firewall_log_enabled', 'Store firewall event log', 'Keep the latest 200 IP and user-agent blocks in WordPress options.', (int) tk_get_option('firewall_log_enabled', 1)); ?>
                    <div class="tk-control-row"><div class="tk-control-info"><label>HTTP methods inspected</label><p class="description">Comma-separated. Supported: GET, POST, PUT, PATCH, DELETE.</p></div><input class="regular-text" name="waf_check_methods" value="<?php echo esc_attr((string) tk_get_option('hardening_waf_check_methods', 'GET, POST')); ?>"></div>
                    <div class="tk-control-row" style="align-items:flex-start;"><div class="tk-control-info"><label>Allowed paths</label><p class="description">One path fragment per line. Payload WAF skips matching paths.</p></div><textarea class="large-text" rows="5" name="waf_allow_paths"><?php echo esc_textarea((string) tk_get_option('hardening_waf_allow_paths', '')); ?></textarea></div>
                    <div class="tk-control-row" style="align-items:flex-start;"><div class="tk-control-info"><label>IP/CIDR allowlist</label><p class="description">One exact IP or CIDR per line. Allow rules take precedence. Current server peer IP: <code><?php echo esc_html(tk_firewall_request_ip()); ?></code></p></div><textarea class="large-text" rows="5" name="firewall_ip_allowlist" placeholder="203.0.113.10&#10;2001:db8::/32"><?php echo esc_textarea((string) tk_get_option('firewall_ip_allowlist', '')); ?></textarea></div>
                    <div class="tk-control-row" style="align-items:flex-start;"><div class="tk-control-info"><label>IP/CIDR blocklist</label><p class="description">One exact IPv4, IPv6, or CIDR range per line.</p></div><textarea class="large-text" rows="5" name="firewall_ip_blocklist" placeholder="198.51.100.0/24"><?php echo esc_textarea((string) tk_get_option('firewall_ip_blocklist', '')); ?></textarea></div>
                    <div class="tk-control-row" style="align-items:flex-start;"><div class="tk-control-info"><label>Blocked user agents</label><p class="description">Case-insensitive fragments, one per line.</p></div><textarea class="large-text" rows="5" name="firewall_blocked_user_agents" placeholder="sqlmap&#10;masscan"><?php echo esc_textarea((string) tk_get_option('firewall_blocked_user_agents', '')); ?></textarea></div>
                </div>
                <p style="margin-top:24px;"><button class="button button-primary button-hero"><?php esc_html_e('Save Firewall', 'tool-kits'); ?></button></p>
            </form>
        </div>

        <div class="tk-card">
            <div style="display:flex; justify-content:space-between; gap:16px; align-items:center;">
                <div><h2 style="margin-bottom:4px;"><?php esc_html_e('Recent Blocks', 'tool-kits'); ?></h2><p class="description"><?php esc_html_e('Latest IP and user-agent rule matches.', 'tool-kits'); ?></p></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php tk_nonce_field('tk_firewall_clear_log'); ?><input type="hidden" name="action" value="tk_firewall_clear_log"><button class="button" <?php disabled(empty($events)); ?>><?php esc_html_e('Clear Log', 'tool-kits'); ?></button></form>
            </div>
            <table class="widefat striped"><thead><tr><th>Time</th><th>IP</th><th>Reason</th><th>Request</th></tr></thead><tbody>
            <?php if (empty($events)) : ?><tr><td colspan="4"><?php esc_html_e('No blocked requests recorded.', 'tool-kits'); ?></td></tr><?php endif; ?>
            <?php foreach (array_slice($events, 0, 100) as $event) : ?>
                <tr><td><?php echo esc_html(wp_date('Y-m-d H:i:s', (int) ($event['time'] ?? 0))); ?></td><td><code><?php echo esc_html((string) ($event['ip'] ?? '')); ?></code></td><td><?php echo esc_html((string) ($event['reason'] ?? '')); ?></td><td><code><?php echo esc_html(strtoupper((string) ($event['method'] ?? '')) . ' ' . (string) ($event['uri'] ?? '')); ?></code></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
    <?php
}
