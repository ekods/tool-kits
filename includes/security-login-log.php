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
        agent VARCHAR(255),
        status VARCHAR(20),
        PRIMARY KEY (id)
    ) {$wpdb->get_charset_collate()};";

    dbDelta($sql);
}

function tk_login_log_insert($username, $user_id, $status) {
    if (!tk_get_option('login_log_enabled', 1)) return;

    global $wpdb;
    $wpdb->insert(tk_login_log_table(), [
        'time'     => current_time('mysql', 1),
        'username' => $username,
        'user_id'  => $user_id,
        'ip'       => tk_get_ip(),
        'agent'    => tk_user_agent(),
        'status'   => $status,
    ]);
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
        <h1>Login Log</h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_login_log_save'); ?>
            <input type="hidden" name="action" value="tk_login_log_save">
            <label><input type="checkbox" name="enabled" value="1" <?php checked(1, $enabled); ?>> Enable login logging</label>
            <p><button class="button button-primary">Save</button></p>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
            <?php tk_nonce_field('tk_login_log_clear'); ?>
            <input type="hidden" name="action" value="tk_login_log_clear">
            <button class="button button-secondary">Clear Log</button>
        </form>
        <div class="tk-tabs" style="margin-top:20px;">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button<?php echo $status === 'success' ? ' is-active' : ''; ?>" data-status="success">Success</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $status === 'failed' ? ' is-active' : ''; ?>" data-status="failed">Failed</button>
            </div>
        </div>
        <h2>Recent Entries</h2>
        <table class="widefat striped">
            <thead><tr><th>Time</th><th>Username</th><th>User ID</th><th>IP</th><th>Status</th></tr></thead>
            <tbody>
                <?php if ($rows) : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row->time); ?></td>
                            <td><?php echo esc_html($row->username); ?></td>
                            <td><?php echo esc_html($row->user_id); ?></td>
                            <td><?php echo esc_html($row->ip); ?></td>
                            <td><?php echo esc_html(ucfirst($row->status)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="5">No log entries yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
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
    <script>
    (function(){
        var base = '<?php echo esc_js(add_query_arg(array('page' => 'tool-kits-security-login-log'), admin_url('admin.php'))); ?>';
        document.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
            button.addEventListener('click', function(){
                var status = button.getAttribute('data-status') || 'all';
                var url = base;
                url += '&tk_status=' + encodeURIComponent(status);
                window.location.href = url + '#' + status;
            });
        });
    })();
    </script>
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
