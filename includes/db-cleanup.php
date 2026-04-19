<?php
if (!defined('ABSPATH')) { exit; }

function tk_db_cleanup_init() {
    add_action('admin_post_tk_db_cleanup_run', 'tk_db_cleanup_run_handler');
}

function tk_render_db_cleanup_page() {
    tk_render_db_tools_page();
}

function tk_db_cleanup_status_message(): string {
    $summary = get_transient('tk_db_cleanup_last_summary');
    if (!is_array($summary)) {
        return '';
    }

    $parts = array();

    $map = array(
        'revisions'        => 'revisions deleted',
        'trash_posts'      => 'trash posts deleted',
        'spam_comments'    => 'spam comments deleted',
        'spam_commentmeta' => 'orphaned spam commentmeta deleted',
        'trash_comments'   => 'trash comments deleted',
        'trash_commentmeta'=> 'orphaned trash commentmeta deleted',
        'transients'       => 'transients deleted',
    );

    foreach ($map as $key => $label) {
        if (!isset($summary[$key])) {
            continue;
        }
        $parts[] = ((int) $summary[$key]) . ' ' . $label;
    }

    if (!empty($summary['optimized_tables'])) {
        $parts[] = ((int) $summary['optimized_tables']) . ' tables optimized';
    }

    delete_transient('tk_db_cleanup_last_summary');

    if (empty($parts)) {
        return 'Cleanup selesai, tetapi tidak ada perubahan yang dilaporkan.';
    }

    return 'Cleanup selesai: ' . implode('; ', $parts) . '.';
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
        $message = tk_db_cleanup_status_message();
        if ($message === '') {
            $message = 'Cleanup selesai.';
        }
        tk_notice($message, 'success');
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

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-confirm="Jalankan cleanup? Pastikan backup dulu jika perlu.">
            <?php tk_nonce_field('tk_db_cleanup_run'); ?>
            <input type="hidden" name="action" value="tk_db_cleanup_run">

            <label><input type="checkbox" name="do_revisions" value="1" checked> Delete revisions</label><br>
            <label><input type="checkbox" name="do_trash_posts" value="1" checked> Delete trash posts</label><br>
            <label><input type="checkbox" name="do_spam_comments" value="1" checked> Delete spam comments</label><br>
            <label><input type="checkbox" name="do_trash_comments" value="1" checked> Delete trash comments</label><br>
            <label><input type="checkbox" name="do_transients" value="1" checked> Delete transients</label><br>
            <label><input type="checkbox" name="do_optimize" value="1" checked> Optimize tables</label>

            <p><button class="button button-primary">Run Cleanup</button></p>
        </form>
    </div>
    <?php
}

function tk_db_cleanup_run_handler() {
    tk_require_admin_post('tk_db_cleanup_run');

    global $wpdb;

    $do_revisions = !empty($_POST['do_revisions']);
    $do_trash_posts = !empty($_POST['do_trash_posts']);
    $do_spam_comments = !empty($_POST['do_spam_comments']);
    $do_trash_comments = !empty($_POST['do_trash_comments']);
    $do_transients = !empty($_POST['do_transients']);
    $do_optimize = !empty($_POST['do_optimize']);
    $summary = array(
        'revisions'         => 0,
        'trash_posts'       => 0,
        'spam_comments'     => 0,
        'spam_commentmeta'  => 0,
        'trash_comments'    => 0,
        'trash_commentmeta' => 0,
        'transients'        => 0,
        'optimized_tables'  => 0,
    );

    @set_time_limit(0);

    if ($do_revisions) {
        $result = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $summary['revisions'] = $result !== false ? (int) $result : 0;
    }
    if ($do_trash_posts) {
        $result = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
        $summary['trash_posts'] = $result !== false ? (int) $result : 0;
    }
    if ($do_spam_comments) {
        $result = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        $summary['spam_comments'] = $result !== false ? (int) $result : 0;
        $result = $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
        $summary['spam_commentmeta'] = $result !== false ? (int) $result : 0;
    }
    if ($do_trash_comments) {
        $result = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
        $summary['trash_comments'] = $result !== false ? (int) $result : 0;
        $result = $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
        $summary['trash_commentmeta'] = $result !== false ? (int) $result : 0;
    }
    if ($do_transients) {
        $result = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
        $summary['transients'] = $result !== false ? (int) $result : 0;
    }

    if ($do_optimize) {
        $tables = $wpdb->get_col("SHOW TABLES");
        foreach ($tables as $t) {
            $result = $wpdb->query("OPTIMIZE TABLE `$t`");
            if ($result !== false) {
                $summary['optimized_tables']++;
            }
        }
    }

    set_transient('tk_db_cleanup_last_summary', $summary, MINUTE_IN_SECONDS * 10);

    wp_safe_redirect(add_query_arg(array('page'=>'tool-kits-db','tk_tab'=>'db-cleanup','tk_done'=>1), admin_url('admin.php')));
    exit;
}
