<?php
if (!defined('ABSPATH')) { exit; }

function tk_db_cleanup_init() {
    add_action('admin_post_tk_db_cleanup_run', 'tk_db_cleanup_run_handler');
    add_filter('wp_revisions_to_keep', 'tk_db_cleanup_revision_limit', 99, 2);
}

function tk_db_cleanup_effective_revision_limit(): int {
    if (defined('WP_POST_REVISIONS')) {
        if (WP_POST_REVISIONS === false) {
            return 0;
        }
        if (is_numeric(WP_POST_REVISIONS)) {
            return max(0, (int) WP_POST_REVISIONS);
        }
    }

    return 5;
}

function tk_db_cleanup_revision_limit($num, $post): int {
    return tk_db_cleanup_effective_revision_limit();
}

function tk_render_db_cleanup_page() {
    tk_render_db_tools_page();
}

/**
 * Conservatively detect whether the inactive ACF installation is still used.
 */
function tk_db_cleanup_acf_usage_scan(bool $force = false): array {
    $cache_key = 'tk_db_cleanup_acf_usage_scan';
    if (!$force) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    global $wpdb;

    $evidence = array();
    $complete = true;
    $field_groups = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'acf-field-group'
           AND post_status NOT IN ('trash', 'auto-draft')"
    );
    if ($field_groups > 0) {
        $evidence[] = $field_groups . ' ACF field group(s) still exist.';
    }

    $content_uses = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_status NOT IN ('trash', 'auto-draft', 'inherit')
           AND (post_content LIKE '%<!-- wp:acf/%' OR post_content LIKE '%[acf%')"
    );
    if ($content_uses > 0) {
        $evidence[] = $content_uses . ' post(s) still contain an ACF block or shortcode.';
    }

    $roots = array();
    if (function_exists('get_stylesheet_directory')) {
        $roots[] = get_stylesheet_directory();
    }
    if (function_exists('get_template_directory')) {
        $roots[] = get_template_directory();
    }
    $active_plugins = array_merge(
        (array) get_option('active_plugins', array()),
        array_keys((array) get_site_option('active_sitewide_plugins', array()))
    );
    foreach (array_unique($active_plugins) as $plugin_file) {
        $relative_path = ltrim((string) $plugin_file, '/');
        $plugin_directory = dirname($relative_path);
        $path = defined('WP_PLUGIN_DIR')
            ? ($plugin_directory === '.' ? WP_PLUGIN_DIR . '/' . $relative_path : WP_PLUGIN_DIR . '/' . $plugin_directory)
            : '';
        if ($path !== '' && is_readable($path) && (!defined('TK_PATH') || strpos(trailingslashit($path), TK_PATH) !== 0)) {
            $roots[] = $path;
        }
    }
    if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
        $roots[] = WPMU_PLUGIN_DIR;
    }

    $roots = array_values(array_unique(array_filter($roots)));
    foreach ($roots as $root) {
        if (!is_readable($root)) {
            $complete = false;
            $evidence[] = 'An active source path could not be scanned.';
        }
    }
    $roots = array_values(array_filter($roots, 'is_readable'));
    if (empty($roots)) {
        $complete = false;
        $evidence[] = 'No active theme or plugin source could be scanned.';
    }
    $pattern = '/\b(?:get_field|the_field|get_sub_field|the_sub_field|have_rows|the_row|acf_[a-z0-9_]+)\s*\(|<!--\s+wp:acf\/|\[acf\s/i';
    $files_checked = 0;
    $max_files = 5000;

    foreach ($roots as $root) {
        try {
            $paths = is_file($root)
                ? array(new SplFileInfo($root))
                : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($paths as $file) {
                if ($files_checked >= $max_files) {
                    $complete = false;
                    $evidence[] = 'Source scan limit was reached.';
                    break 2;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (preg_match('#/(?:vendor|node_modules|tests?|\.git|languages)/#', str_replace('\\', '/', $path))) {
                    continue;
                }
                if (strpos(str_replace('\\', '/', $path), '/acf-json/') !== false) {
                    $evidence[] = 'Local ACF JSON exists in ' . basename(dirname($path)) . '.';
                    continue;
                }
                if (!in_array(strtolower((string) $file->getExtension()), array('php', 'html', 'htm', 'js'), true)) {
                    continue;
                }
                $files_checked++;
                if (!$file->isReadable() || $file->getSize() > 2 * MB_IN_BYTES) {
                    $complete = false;
                    $evidence[] = 'A source file could not be safely scanned: ' . basename($path) . '.';
                    continue;
                }
                $contents = file_get_contents($path);
                if ($contents === false) {
                    $complete = false;
                    continue;
                }
                if (preg_match($pattern, $contents, $match)) {
                    $evidence[] = 'ACF API usage found in ' . basename($path) . ' (' . trim($match[0]) . ').';
                }
                if (count($evidence) >= 20) {
                    break 2;
                }
            }
        } catch (Throwable $exception) {
            $complete = false;
            $evidence[] = 'A source directory could not be completely scanned.';
        }
    }

    $result = array(
        'safe_to_remove' => $complete && empty($evidence),
        'complete' => $complete,
        'files_checked' => $files_checked,
        'evidence' => array_values(array_unique($evidence)),
    );
    set_transient($cache_key, $result, MINUTE_IN_SECONDS * 10);

    return $result;
}

/**
 * Find ACF postmeta and option references whose field definitions no longer exist.
 *
 * When ACF is inactive, all references become candidates only after a complete
 * usage scan finds no field groups, content usage, or API calls in active code.
 */
function tk_db_cleanup_acf_scan(bool $force = false): array {
    $cache_key = 'tk_db_cleanup_acf_scan';
    if (!$force) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    global $wpdb;

    $acf_active = function_exists('acf_get_field');
    $usage_scan = $acf_active
        ? array('safe_to_remove' => false, 'complete' => true, 'files_checked' => 0, 'evidence' => array('ACF is active.'))
        : tk_db_cleanup_acf_usage_scan($force);

    $postmeta_rows = $wpdb->get_results(
        "SELECT meta_value AS field_key, COUNT(*) AS reference_count
         FROM {$wpdb->postmeta}
         WHERE LEFT(meta_key, 1) = '_'
           AND LEFT(meta_value, 6) = 'field_'
         GROUP BY meta_value",
        ARRAY_A
    );
    $option_rows = $wpdb->get_results(
        "SELECT option_value AS field_key, COUNT(*) AS reference_count
         FROM {$wpdb->options}
         WHERE LEFT(option_name, 1) = '_'
           AND LEFT(option_value, 6) = 'field_'
         GROUP BY option_value",
        ARRAY_A
    );
    $field_keys = array();
    $reference_rows = 0;
    $postmeta_reference_rows = 0;
    $option_reference_rows = 0;

    foreach (array('postmeta' => $postmeta_rows, 'options' => $option_rows) as $storage => $rows) {
        foreach ((array) $rows as $row) {
            $field_key = isset($row['field_key']) ? (string) $row['field_key'] : '';
            if (!preg_match('/^field_[A-Za-z0-9_-]+$/', $field_key)) {
                continue;
            }
            if ($acf_active && acf_get_field($field_key)) {
                continue;
            }

            $count = isset($row['reference_count']) ? (int) $row['reference_count'] : 0;
            $field_keys[] = $field_key;
            $reference_rows += $count;
            if ($storage === 'options') {
                $option_reference_rows += $count;
            } else {
                $postmeta_reference_rows += $count;
            }
        }
    }

    $result = array(
        'acf_active' => $acf_active,
        'field_keys' => array_values(array_unique($field_keys)),
        'field_key_count' => count(array_unique($field_keys)),
        'reference_rows' => $reference_rows,
        'postmeta_reference_rows' => $postmeta_reference_rows,
        'option_reference_rows' => $option_reference_rows,
        'cleanup_allowed' => $acf_active || !empty($usage_scan['safe_to_remove']),
        'usage_scan' => $usage_scan,
    );

    set_transient($cache_key, $result, MINUTE_IN_SECONDS * 5);

    return $result;
}

function tk_db_cleanup_acf_orphans(array $scan): array {
    global $wpdb;

    if (empty($scan['cleanup_allowed'])) {
        return array(
            'references' => 0,
            'values' => 0,
            'option_references' => 0,
            'option_values' => 0,
        );
    }

    $deleted_references = 0;
    $deleted_values = 0;
    $deleted_option_references = 0;
    $deleted_option_values = 0;
    $field_keys = array_values(array_filter((array) ($scan['field_keys'] ?? array()), static function ($field_key) {
        return is_string($field_key) && preg_match('/^field_[A-Za-z0-9_-]+$/', $field_key);
    }));

    foreach (array_chunk($field_keys, 100) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
        $value_sql = $wpdb->prepare(
            "DELETE value_meta
             FROM {$wpdb->postmeta} value_meta
             INNER JOIN {$wpdb->postmeta} reference_meta
                ON reference_meta.post_id = value_meta.post_id
               AND value_meta.meta_key = SUBSTRING(reference_meta.meta_key, 2)
             WHERE LEFT(reference_meta.meta_key, 1) = '_'
               AND reference_meta.meta_value IN ({$placeholders})",
            ...$chunk
        );
        $value_result = $wpdb->query($value_sql);
        if ($value_result !== false) {
            $deleted_values += (int) $value_result;
        }

        $reference_sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta}
             WHERE LEFT(meta_key, 1) = '_'
               AND meta_value IN ({$placeholders})",
            ...$chunk
        );
        $reference_result = $wpdb->query($reference_sql);
        if ($reference_result !== false) {
            $deleted_references += (int) $reference_result;
        }

        $option_value_sql = $wpdb->prepare(
            "DELETE value_option
             FROM {$wpdb->options} value_option
             INNER JOIN {$wpdb->options} reference_option
                ON value_option.option_name = SUBSTRING(reference_option.option_name, 2)
             WHERE LEFT(reference_option.option_name, 1) = '_'
               AND reference_option.option_value IN ({$placeholders})",
            ...$chunk
        );
        $option_value_result = $wpdb->query($option_value_sql);
        if ($option_value_result !== false) {
            $deleted_option_values += (int) $option_value_result;
        }

        $option_reference_sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE LEFT(option_name, 1) = '_'
               AND option_value IN ({$placeholders})",
            ...$chunk
        );
        $option_reference_result = $wpdb->query($option_reference_sql);
        if ($option_reference_result !== false) {
            $deleted_option_references += (int) $option_reference_result;
        }
    }

    delete_transient('tk_db_cleanup_acf_scan');

    return array(
        'references' => $deleted_references,
        'values' => $deleted_values,
        'option_references' => $deleted_option_references,
        'option_values' => $deleted_option_values,
    );
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
        'acf_references'   => 'unused ACF references deleted',
        'acf_values'       => 'unused ACF values deleted',
        'acf_option_references' => 'unused ACF option references deleted',
        'acf_option_values'=> 'unused ACF option values deleted',
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
        return 'Cleanup completed, but no changes were reported.';
    }

    return 'Cleanup completed: ' . implode('; ', $parts) . '.';
}

function tk_render_db_cleanup_panel() {
    if (!tk_is_admin_user()) return;
    global $wpdb;

    $acf_scan = tk_db_cleanup_acf_scan();
    $counts = array(
        'revisions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"),
        'autosaves' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_name LIKE '%autosave%'"),
        'trash_posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"),
        'spam_comments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"),
        'trash_comments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"),
        'transients' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"),
        'auto_drafts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"),
        'orphaned_postmeta' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"),
        'orphaned_acf' => (int) ($acf_scan['reference_rows'] ?? 0),
    );

    // Calculate DB Size
    $db_size = $wpdb->get_results("SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = (SELECT DATABASE())");
    $total_size = isset($db_size[0]->size) ? $db_size[0]->size : 0;

    ?>
    <?php
    if (isset($_GET['tk_done'])) {
        $message = tk_db_cleanup_status_message();
        if ($message === '') {
            $message = 'Cleanup completed.';
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
                <div class="tk-stat-row" style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--tk-border-soft);">
                    <span>Unused ACF References</span>
                    <strong><?php echo number_format($counts['orphaned_acf']); ?></strong>
                    <small style="color:var(--tk-muted); margin-left:8px;">Post meta: <?php echo number_format((int) $acf_scan['postmeta_reference_rows']); ?> · Options: <?php echo number_format((int) $acf_scan['option_reference_rows']); ?></small>
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
                    if (!empty($acf_scan['cleanup_allowed'])) {
                        tk_render_switch(
                            'do_orphaned_acf',
                            'Unused ACF Data',
                            !empty($acf_scan['acf_active'])
                                ? 'Delete values whose ACF field key is no longer registered. Found ' . number_format($counts['orphaned_acf']) . ' references.'
                                : 'No ACF usage was found in active code, content, or field groups. Delete ' . number_format($counts['orphaned_acf']) . ' legacy references and their paired values.',
                            false
                        );
                    } else {
                        $acf_evidence = (array) ($acf_scan['usage_scan']['evidence'] ?? array());
                        ?>
                        <div class="tk-control-row">
                            <div class="tk-control-info">
                                <strong>Unused ACF Data</strong>
                                <p class="description">Cleanup blocked: <?php echo esc_html(implode(' ', array_slice($acf_evidence, 0, 3))); ?></p>
                            </div>
                            <span class="tk-badge tk-warn">In use</span>
                        </div>
                        <?php
                    }
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
    $do_orphaned_acf = !empty($_POST['do_orphaned_acf']);
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
        'acf_references'    => 0,
        'acf_values'        => 0,
        'acf_option_references' => 0,
        'acf_option_values' => 0,
        'optimized_tables'  => 0,
    );

    @set_time_limit(0);

    if ($do_revisions) {
        $result = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $summary['revisions'] = $result !== false ? (int) $result : 0;
        update_option('tk_db_cleanup_revision_last_run', time(), false);
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
    if ($do_orphaned_acf) {
        $acf_result = tk_db_cleanup_acf_orphans(tk_db_cleanup_acf_scan(true));
        $summary['acf_references'] = (int) $acf_result['references'];
        $summary['acf_values'] = (int) $acf_result['values'];
        $summary['acf_option_references'] = (int) $acf_result['option_references'];
        $summary['acf_option_values'] = (int) $acf_result['option_values'];
        update_option('tk_db_cleanup_acf_last_run', array(
            'timestamp' => time(),
            'references' => $summary['acf_references'],
            'values' => $summary['acf_values'],
            'option_references' => $summary['acf_option_references'],
            'option_values' => $summary['acf_option_values'],
            'rows_deleted' => array_sum(array(
                $summary['acf_references'],
                $summary['acf_values'],
                $summary['acf_option_references'],
                $summary['acf_option_values'],
            )),
        ), false);
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
