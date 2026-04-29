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
        'auto_drafts'      => 'auto-drafts deleted',
        'orphaned_postmeta'=> 'orphaned postmeta deleted',
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
        'auto_drafts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"),
        'orphaned_postmeta' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"),
    );

    // Calculate DB Size
    $db_size = $wpdb->get_results("SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = (SELECT DATABASE())");
    $total_size = isset($db_size[0]->size) ? $db_size[0]->size : 0;

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

    <div class="tk-grid tk-grid-2" style="gap:24px;">
        <div class="tk-card">
            <h3 style="margin-top:0; font-size:16px;">Statistics Overview</h3>
            <p class="description">Current status of redundant data in your database.</p>
            <div style="display:flex; flex-direction:column; gap:12px; margin-top:20px;">
                <div class="tk-stat-row" style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--tk-border-soft);">
                    <span>Revisions</span>
                    <strong><?php echo number_format($counts['revisions']); ?></strong>
                </div>
                <div class="tk-stat-row" style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--tk-border-soft);">
                    <span>Trash Items</span>
                    <strong><?php echo number_format($counts['trash_posts'] + $counts['trash_comments']); ?></strong>
                </div>
                <div class="tk-stat-row" style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--tk-border-soft);">
                    <span>Spam Comments</span>
                    <strong><?php echo number_format($counts['spam_comments']); ?></strong>
                </div>
                <div class="tk-stat-row" style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--tk-border-soft);">
                    <span>Transients</span>
                    <strong><?php echo number_format($counts['transients']); ?></strong>
                </div>
                <div class="tk-stat-row" style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--tk-border-soft);">
                    <span>Orphaned Meta</span>
                    <strong><?php echo number_format($counts['orphaned_postmeta']); ?></strong>
                </div>
                <div class="tk-stat-row" style="display:flex; justify-content:space-between; padding:15px 0 0; color:var(--tk-primary); font-weight:700;">
                    <span>Total Database Size</span>
                    <span><?php echo size_format($total_size); ?></span>
                </div>
            </div>
        </div>

        <div class="tk-card">
            <h3 style="margin-top:0; font-size:16px;">Cleanup Actions</h3>
            <p class="description">Select the items you wish to prune from your database.</p>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:20px;">
                <?php tk_nonce_field('tk_db_cleanup_run'); ?>
                <input type="hidden" name="action" value="tk_db_cleanup_run">

                <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:24px;">
                    <?php 
                    tk_render_switch('do_revisions', 'Delete Revisions', 'Remove all post and page revisions.', true);
                    tk_render_switch('do_trash_posts', 'Prune Trash', 'Permanently delete items in trash.', true);
                    tk_render_switch('do_spam_comments', 'Clear Spam', 'Delete all comments marked as spam.', true);
                    tk_render_switch('do_transients', 'Clear Transients', 'Remove expired and temporary options.', true);
                    tk_render_switch('do_auto_drafts', 'Delete Auto-drafts', 'Prune system-generated empty drafts.', true);
                    tk_render_switch('do_orphaned_postmeta', 'Orphaned Meta', 'Delete meta data with no parent post.', true);
                    tk_render_switch('do_optimize', 'Optimize Tables', 'Run OPTIMIZE TABLE on all database tables.', true);
                    ?>
                </div>

                <button class="button button-primary button-hero" style="width:100%;" onclick="return confirm('Pruning your database is permanent. Are you sure you have a backup?')">Run Database Pruning</button>
            </form>
        </div>
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
    $do_auto_drafts = !empty($_POST['do_auto_drafts']);
    $do_orphaned_postmeta = !empty($_POST['do_orphaned_postmeta']);
    $do_optimize = !empty($_POST['do_optimize']);
    $summary = array(
        'revisions'         => 0,
        'trash_posts'       => 0,
        'spam_comments'     => 0,
        'spam_commentmeta'  => 0,
        'trash_comments'    => 0,
        'trash_commentmeta' => 0,
        'transients'        => 0,
        'auto_drafts'       => 0,
        'orphaned_postmeta' => 0,
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
    if ($do_auto_drafts) {
        $result = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
        $summary['auto_drafts'] = $result !== false ? (int) $result : 0;
    }
    if ($do_orphaned_postmeta) {
        $result = $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
        $summary['orphaned_postmeta'] = $result !== false ? (int) $result : 0;
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
