<?php
if (!defined('ABSPATH')) { exit; }

function tk_db_cleanup_init() {
    add_action('admin_post_tk_db_cleanup_run', 'tk_db_cleanup_run_handler');
}

function tk_render_db_cleanup_page() {
    tk_render_db_tools_page();
}

function tk_render_db_cleanup_panel() {
    if (!tk_is_admin_user()) return;
    global $wpdb;

    $counts = array(
        'revisions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"),
        'autosaves' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_name LIKE '%autosave%'"),
        'trash_posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"),
        'spam_comments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"),
        'trash_comments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"),
        'transients' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"),
    );

    ?>
    <?php
    if (isset($_GET['tk_done'])) {
        tk_notice('Cleanup selesai. Optimasi tabel dijalankan juga.', 'success');
    }
    ?>

    <div class="tk-card">
        <h2>Cleanup Actions</h2>
        <p>Berikut count saat ini (real-time):</p>
        <ul class="tk-list">
            <li>Revisions: <strong><?php echo esc_html($counts['revisions']); ?></strong></li>
            <li>Trash Posts: <strong><?php echo esc_html($counts['trash_posts']); ?></strong></li>
            <li>Spam Comments: <strong><?php echo esc_html($counts['spam_comments']); ?></strong></li>
            <li>Trash Comments: <strong><?php echo esc_html($counts['trash_comments']); ?></strong></li>
            <li>Transients: <strong><?php echo esc_html($counts['transients']); ?></strong></li>
        </ul>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_db_cleanup_run'); ?>
            <input type="hidden" name="action" value="tk_db_cleanup_run">

            <label><input type="checkbox" name="do_revisions" value="1" checked> Delete revisions</label><br>
            <label><input type="checkbox" name="do_trash_posts" value="1" checked> Delete trash posts</label><br>
            <label><input type="checkbox" name="do_spam_comments" value="1" checked> Delete spam comments</label><br>
            <label><input type="checkbox" name="do_trash_comments" value="1" checked> Delete trash comments</label><br>
            <label><input type="checkbox" name="do_transients" value="1" checked> Delete transients</label><br>
            <label><input type="checkbox" name="do_optimize" value="1" checked> Optimize tables</label>

            <p><button class="button button-primary" onclick="return confirm('Jalankan cleanup? Pastikan backup dulu jika perlu.')">Run Cleanup</button></p>
        </form>
    </div>
    <?php
}

function tk_db_cleanup_run_handler() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_db_cleanup_run');

    global $wpdb;

    $do_revisions = !empty($_POST['do_revisions']);
    $do_trash_posts = !empty($_POST['do_trash_posts']);
    $do_spam_comments = !empty($_POST['do_spam_comments']);
    $do_trash_comments = !empty($_POST['do_trash_comments']);
    $do_transients = !empty($_POST['do_transients']);
    $do_optimize = !empty($_POST['do_optimize']);

    @set_time_limit(0);

    if ($do_revisions) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
    }
    if ($do_trash_posts) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
    }
    if ($do_spam_comments) {
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
    }
    if ($do_trash_comments) {
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
    }
    if ($do_transients) {
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
    }

    if ($do_optimize) {
        $tables = $wpdb->get_col("SHOW TABLES");
        foreach ($tables as $t) {
            $wpdb->query("OPTIMIZE TABLE `$t`");
        }
    }

    wp_redirect(add_query_arg(array('page'=>'tool-kits-db','tk_tab'=>'db-cleanup','tk_done'=>1), admin_url('admin.php')));
    exit;
}
