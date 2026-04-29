<?php
if (!defined('ABSPATH')) exit;

/**
 * Login activity logger
 */

function tk_login_log_init() {
    add_action('wp_login_failed', 'tk_login_log_failed');
    add_action('wp_login', 'tk_login_log_success', 10, 2);
    add_action('admin_post_tk_login_log_save', 'tk_login_log_save');
    add_action('admin_post_tk_login_log_clear', 'tk_login_log_clear');
    tk_login_log_install_table();
}

function tk_login_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'tk_login_log';
}

function tk_login_log_install_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE " . tk_login_log_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        time DATETIME NOT NULL,
        username VARCHAR(60),
        user_id BIGINT,
        ip VARCHAR(64),
        location VARCHAR(191),
        agent VARCHAR(255),
        status VARCHAR(20),
        PRIMARY KEY (id)
    ) {$wpdb->get_charset_collate()};";

    dbDelta($sql);
}

function tk_login_log_insert($username, $user_id, $status) {
    if (!tk_get_option('login_log_enabled', 1)) return;

    global $wpdb;
    $ip = tk_get_ip();
    $location = tk_login_log_location_for_ip($ip);
    $wpdb->insert(tk_login_log_table(), [
        'time'     => current_time('mysql', 1),
        'username' => $username,
        'user_id'  => $user_id,
        'ip'       => $ip,
        'location' => $location,
        'agent'    => tk_user_agent(),
        'status'   => $status,
    ]);
}

function tk_login_log_location_for_ip($ip): string {
    $ip = is_string($ip) ? trim($ip) : '';
    if ($ip === '') {
        return 'Unknown';
    }
    if (function_exists('tk_security_alert_ip_location')) {
        return tk_security_alert_ip_location($ip);
    }
    return 'Unknown';
}

function tk_login_log_row_location($row): string {
    $stored = isset($row->location) ? trim((string) $row->location) : '';
    if ($stored !== '') {
        return $stored;
    }
    $ip = isset($row->ip) ? (string) $row->ip : '';
    return tk_login_log_location_for_ip($ip);
}

function tk_login_log_failed($username) {
    tk_login_log_insert($username, 0, 'failed');
}

function tk_login_log_success($username, $user) {
    tk_login_log_insert($username, $user->ID, 'success');
}

function tk_render_login_log_page() {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('login_log_enabled', 1);
    $status = isset($_GET['tk_status']) ? sanitize_key($_GET['tk_status']) : 'failed';
    if (!in_array($status, array('success', 'failed'), true)) {
        $status = 'failed';
    }
    global $wpdb;
    $per_page = 20;
    $page = isset($_GET['tk_log_page']) ? max(1, (int) $_GET['tk_log_page']) : 1;
    $where = '';
    $params = array();
    if ($status !== 'all') {
        $where = 'WHERE status = %s';
        $params[] = $status;
    }
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . tk_login_log_table() . " {$where}", $params));
    $total_pages = max(1, (int) ceil($total / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
    $params[] = $per_page;
    $params[] = $offset;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . tk_login_log_table() . " {$where} ORDER BY time DESC LIMIT %d OFFSET %d", $params));
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Login Activity Log', 'tool-kits'), __('Track all login activity and detect unauthorized access attempts in real-time.', 'tool-kits'), 'dashicons-lock'); ?>
        <div class="tk-card" style="margin-bottom:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="flex:1;">
                    <?php tk_render_switch('enabled', 'Enable Login Activity Logging', 'Monitor all successful and failed authentication attempts.', $enabled); ?>
                </div>
                <div style="display:flex; gap:10px;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                        <?php tk_nonce_field('tk_login_log_save'); ?>
                        <input type="hidden" name="action" value="tk_login_log_save">
                        <input type="hidden" name="enabled" value="<?php echo $enabled; ?>">
                        <button class="button button-primary button-hero" style="height:40px; padding:0 20px;">Save Changes</button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                        <?php tk_nonce_field('tk_login_log_clear'); ?>
                        <input type="hidden" name="action" value="tk_login_log_clear">
                        <button class="button button-secondary button-hero" style="height:40px; padding:0 20px;" onclick="return confirm('Clear all logs?')">Clear Log</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="tk-tabs" style="margin-bottom:20px;">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button<?php echo $status === 'failed' ? ' is-active' : ''; ?>" data-status="failed">Failed Attempts</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $status === 'success' ? ' is-active' : ''; ?>" data-status="success">Successful Logins</button>
            </div>
        </div>
        <div class="tk-card no-padding">
            <div class="tk-table-scroll">
            <table class="widefat striped tk-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User & IP</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td style="font-weight:500;">
                                    <div style="font-size:13px;"><?php echo esc_html(date_i18n('M d, Y', strtotime($row->time))); ?></div>
                                    <div style="font-size:11px; color:var(--tk-muted);"><?php echo esc_html(date_i18n('H:i:s', strtotime($row->time))); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?php echo esc_html($row->username); ?></div>
                                    <div style="font-size:11px; opacity:0.7;"><code><?php echo esc_html($row->ip); ?></code></div>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <span class="dashicons dashicons-location" style="font-size:14px; width:14px; height:14px; color:var(--tk-muted);"></span>
                                        <?php echo esc_html(tk_login_log_row_location($row)); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="tk-badge <?php echo $row->status === 'success' ? 'tk-on' : 'tk-warn'; ?>">
                                        <?php echo esc_html(ucfirst($row->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="tk-log-details">
                                        <table class="tk-mini-table" style="width:100%; border-collapse:collapse; font-size:11px;">
                                            <tr>
                                                <td style="font-weight:700; width:80px; padding:4px 0; color:#1e293b;">Agent:</td>
                                                <td style="padding:4px 0; color:#64748b; font-size:10px;"><?php echo esc_html($row->agent); ?></td>
                                            </tr>
                                            <?php if ($row->user_id > 0) : ?>
                                            <tr>
                                                <td style="font-weight:700; width:80px; padding:4px 0; color:#1e293b;">User ID:</td>
                                                <td style="padding:4px 0; color:#64748b;"><?php echo esc_html($row->user_id); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6" style="text-align:center; padding:40px; color:var(--tk-muted);">No log entries yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                $base = add_query_arg(array('page' => 'tool-kits-security-login-log', 'tk_status' => $status), admin_url('admin.php'));
                $prev_page = max(1, $page - 1);
                $next_page = min($total_pages, $page + 1);
                $prev_url = add_query_arg('tk_log_page', $prev_page, $base);
                $next_url = add_query_arg('tk_log_page', $next_page, $base);
                ?>
                <span class="displaying-num"><?php echo sprintf(_n('%d entry', '%d entries', $total, 'tool-kits'), $total); ?></span>
                <span class="pagination-links">
                    <a class="first-page button <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : esc_url(add_query_arg('tk_log_page', 1, $base)); ?>" aria-label="First page">&laquo;</a>
                    <a class="prev-page button <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : esc_url($prev_url); ?>" aria-label="Previous page">&lsaquo;</a>
                    <span class="paging-input"><?php printf('%d of %d', $page, $total_pages); ?></span>
                    <a class="next-page button <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $page >= $total_pages ? '#' : esc_url($next_url); ?>" aria-label="Next page">&rsaquo;</a>
                    <a class="last-page button <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $page >= $total_pages ? '#' : esc_url(add_query_arg('tk_log_page', $total_pages, $base)); ?>" aria-label="Last page">&raquo;</a>
                </span>
            </div>
        </div>
    </div>
    <?php
    tk_csp_print_inline_script(
        "(function(){
            var base = '" . esc_js(add_query_arg(array('page' => 'tool-kits-security-login-log'), admin_url('admin.php'))) . "';
            document.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var status = button.getAttribute('data-status') || 'all';
                    var url = base;
                    url += '&tk_status=' + encodeURIComponent(status);
                    window.location.href = url + '#' + status;
                });
            });
        })();",
        array('id' => 'tk-login-log-tabs')
    );
    ?>
    </div>
    <?php
}

function tk_login_log_save() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_login_log_save');
    tk_update_option('login_log_enabled', !empty($_POST['enabled']) ? 1 : 0);
    wp_redirect(add_query_arg(array('page'=>'tool-kits-security-login-log','tk_saved'=>1), admin_url('admin.php')));
    exit;
}

function tk_login_log_clear() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_login_log_clear');
    global $wpdb;
    $wpdb->query('TRUNCATE TABLE ' . tk_login_log_table());
    wp_redirect(add_query_arg(array('page'=>'tool-kits-security-login-log','tk_cleared'=>1), admin_url('admin.php')));
    exit;
}
