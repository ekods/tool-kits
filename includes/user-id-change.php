<?php
if (!defined('ABSPATH')) { exit; }

function tk_user_id_change_init() {
    add_action('admin_post_tk_user_id_change', 'tk_user_id_change_save');
    add_action('wp_ajax_tk_user_id_generate', 'tk_user_id_generate_ajax');
}

function tk_render_user_id_change_panel() {
    if (!tk_is_admin_user()) return;
    $status = isset($_GET['tk_user_id_changed']) ? sanitize_key($_GET['tk_user_id_changed']) : '';
    $detail = isset($_GET['tk_user_id_msg']) ? sanitize_text_field(wp_unslash($_GET['tk_user_id_msg'])) : '';
    $suggested = tk_user_id_generate_suggestion();
    if ($status === 'ok') {
        tk_notice('User ID updated. ' . $detail, 'success');
    } elseif ($status === 'fail') {
        tk_notice('Failed to update user ID. ' . $detail, 'error');
    }

    $admins = get_users(array(
        'role' => 'administrator',
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
    ?>
    <div class="tk-card">
        <h2>Change User ID</h2>
        <p>Use with caution. This updates core references (users, usermeta, posts, comments, links) but may not update custom plugin tables.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_user_id_change'); ?>
            <input type="hidden" name="action" value="tk_user_id_change">
            <p>
                <label>Select user</label><br>
                <select name="user_id">
                    <?php foreach ($admins as $admin) : ?>
                        <option value="<?php echo esc_attr((string) $admin->ID); ?>">
                            <?php echo esc_html($admin->user_login . ' (ID ' . $admin->ID . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label>New user ID</label><br>
                <input type="number" id="tk-user-id-new" name="new_user_id" min="1" required value="<?php echo esc_attr((string) $suggested); ?>">
            </p>
            <p>
                <button class="button" type="button" id="tk-user-id-generate" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_user_id_generate')); ?>">Generate Random ID</button>
            </p>
            <p><button class="button button-primary">Update User ID</button></p>
        </form>
    </div>
    <script>
    (function(){
        var btn = document.getElementById('tk-user-id-generate');
        var input = document.getElementById('tk-user-id-new');
        if (!btn || !input) {
            return;
        }
        btn.addEventListener('click', function(){
            var nonce = btn.getAttribute('data-nonce');
            btn.disabled = true;
            var data = new URLSearchParams();
            data.append('action', 'tk_user_id_generate');
            data.append('nonce', nonce);
            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: data.toString()
            }).then(function(resp){ return resp.json(); }).then(function(res){
                if (res && res.success && res.data && res.data.id) {
                    input.value = res.data.id;
                }
            }).finally(function(){
                btn.disabled = false;
            });
        });
    })();
    </script>
    <?php
}

function tk_user_id_change_save() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_user_id_change');

    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $new_id = isset($_POST['new_user_id']) ? (int) $_POST['new_user_id'] : 0;
    if ($user_id <= 0 || $new_id <= 0 || $user_id === $new_id) {
        tk_user_id_change_redirect('fail', 'Invalid user ID.');
    }

    $user = get_user_by('id', $user_id);
    if (!$user) {
        tk_user_id_change_redirect('fail', 'User not found.');
    }
    if (get_user_by('id', $new_id)) {
        tk_user_id_change_redirect('fail', 'New ID already exists.');
    }

    global $wpdb;
    $wpdb->query('START TRANSACTION');
    $ok = true;

    $ok = $ok && $wpdb->update($wpdb->users, array('ID' => $new_id), array('ID' => $user_id), array('%d'), array('%d')) !== false;
    $ok = $ok && $wpdb->update($wpdb->usermeta, array('user_id' => $new_id), array('user_id' => $user_id), array('%d'), array('%d')) !== false;
    if (isset($wpdb->posts)) {
        $ok = $ok && $wpdb->update($wpdb->posts, array('post_author' => $new_id), array('post_author' => $user_id), array('%d'), array('%d')) !== false;
    }
    if (isset($wpdb->comments)) {
        $ok = $ok && $wpdb->update($wpdb->comments, array('user_id' => $new_id), array('user_id' => $user_id), array('%d'), array('%d')) !== false;
    }
    if (isset($wpdb->links)) {
        $ok = $ok && $wpdb->update($wpdb->links, array('link_owner' => $new_id), array('link_owner' => $user_id), array('%d'), array('%d')) !== false;
    }

    if ($ok) {
        $wpdb->query('COMMIT');
        tk_toolkits_audit_log('user_id_change', array('from' => $user_id, 'to' => $new_id));
        tk_user_id_change_redirect('ok', 'User ' . $user->user_login . ' updated to ID ' . $new_id . '.');
    }

    $wpdb->query('ROLLBACK');
    tk_user_id_change_redirect('fail', 'Database update failed.');
}

function tk_user_id_change_redirect($status, $message) {
    wp_redirect(add_query_arg(array(
        'page' => 'tool-kits-optimization',
        'tk_tab' => 'user-id',
        'tk_user_id_changed' => $status,
        'tk_user_id_msg' => $message,
    ), admin_url('admin.php')));
    exit;
}

function tk_user_id_generate_suggestion(): int {
    global $wpdb;
    $min = 1000;
    $max = 99999;
    for ($i = 0; $i < 20; $i++) {
        $candidate = random_int($min, $max);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $candidate));
        if (!$exists) {
            return $candidate;
        }
    }
    $next = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->users}");
    return max($min, $next + 1);
}

function tk_user_id_generate_ajax() {
    check_ajax_referer('tk_user_id_generate', 'nonce');
    if (!tk_is_admin_user()) {
        wp_send_json_error(array('message' => 'forbidden'));
    }
    $id = tk_user_id_generate_suggestion();
    wp_send_json_success(array('id' => $id));
}
