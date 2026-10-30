<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/job-lock.php';

function tk_db_cleanup_tasks(array $input): array {
    $tasks = array();
    foreach (array('revisions', 'trash_posts', 'spam_comments', 'trash_comments', 'transients', 'auto_drafts', 'orphaned_postmeta') as $task) {
        if (!empty($input['do_' . $task])) { $tasks[] = $task; }
    }
    if (!empty($input['do_spam_comments']) || !empty($input['do_trash_comments'])) { $tasks[] = 'orphaned_commentmeta'; }
    if (!empty($input['do_orphaned_acf'])) { $tasks[] = 'acf_postmeta'; $tasks[] = 'acf_options'; }
    if (!empty($input['do_optimize'])) { $tasks[] = 'optimize'; }
    return $tasks;
}

function tk_db_cleanup_job_start(array $input, int $owner): array {
    global $wpdb;
    $tasks = tk_db_cleanup_tasks($input);
    if (!$tasks) { throw new RuntimeException('Select at least one cleanup action.'); }
    $previous = get_option('tk_jobs_cleanup', array());
    if (in_array($previous['status'] ?? '', array('running', 'paused'), true)) {
        throw new RuntimeException('A cleanup already exists. Resume it or cancel it before starting another.');
    }
    // High-water marks keep newly created rows out of this job, including after a pause.
    $limits = array();
    foreach (array('posts' => 'ID', 'comments' => 'comment_ID', 'postmeta' => 'meta_id', 'commentmeta' => 'meta_id', 'options' => 'option_id') as $table => $column) {
        $limits[$table] = (int) tk_db_cleanup_query_value("SELECT MAX($column) FROM {$wpdb->$table}");
    }
    $tables = array();
    if (in_array('optimize', $tasks, true)) {
        // Main-site wp_ also prefixes other blogs and network tables; use the blog table registry there.
        $tables = is_multisite() ? array_values($wpdb->tables('blog', true))
            : $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
    }
    if ($wpdb->last_error) { throw new RuntimeException('Could not inspect database tables. No cleanup was started.'); }
    $job = array('id' => bin2hex(random_bytes(16)), 'owner' => $owner, 'status' => 'running', 'tasks' => $tasks,
        'index' => 0, 'cursor' => 0, 'limits' => $limits, 'tables' => $tables, 'started_at' => time(),
        'transients_all' => !empty($input['transients_all']), 'counts' => array(), 'error' => '');
    update_option('tk_jobs_cleanup', $job, false);
    return $job;
}

function tk_db_cleanup_query_value(string $sql) {
    global $wpdb;
    $value = $wpdb->get_var($sql);
    if ($wpdb->last_error) { throw new RuntimeException('Database read failed. Cleanup has been paused.'); }
    return $value;
}

/** One bounded page of primary keys. Never use OFFSET against a shrinking result set. */
function tk_db_cleanup_rows(array $job, string $task): array {
    global $wpdb;
    $cursor = (int) $job['cursor'];
    $specs = array(
        'revisions' => array('posts', 'ID', "post_type = 'revision'"),
        'trash_posts' => array('posts', 'ID', "post_status = 'trash' AND post_type <> 'revision'"),
        'auto_drafts' => array('posts', 'ID', "post_status = 'auto-draft'"),
        'spam_comments' => array('comments', 'comment_ID', "comment_approved = 'spam'"),
        'trash_comments' => array('comments', 'comment_ID', "comment_approved = 'trash'"),
        'orphaned_postmeta' => array('postmeta', 'meta_id', "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = r.post_id)"),
        'orphaned_commentmeta' => array('commentmeta', 'meta_id', "NOT EXISTS (SELECT 1 FROM {$wpdb->comments} c WHERE c.comment_ID = r.comment_id)"),
        'acf_postmeta' => array('postmeta', 'meta_id', "SUBSTRING(meta_key, 1, 1) = '_' AND SUBSTRING(meta_value, 1, 6) = 'field_'"),
        'acf_options' => array('options', 'option_id', "SUBSTRING(option_name, 1, 1) = '_' AND SUBSTRING(option_value, 1, 6) = 'field_'"),
    );
    if ($task === 'transients') {
        // Literal prefixes (not LIKE wildcards); timeout rows are the candidates for expired-only mode.
        $where = !empty($job['transients_all'])
            ? "(SUBSTRING(option_name, 1, 11) = '_transient_' OR SUBSTRING(option_name, 1, 16) = '_site_transient_')"
            : "((SUBSTRING(option_name, 1, 19) = '_transient_timeout_' OR SUBSTRING(option_name, 1, 24) = '_site_transient_timeout_') AND CAST(option_value AS UNSIGNED) > 0 AND CAST(option_value AS UNSIGNED) < " . (int) $job['started_at'] . ')';
        // Active Tool Kits jobs, nonce guards and locks must survive even an explicit clear-all.
        $where .= " AND SUBSTRING(option_name, 1, 14) <> '_transient_tk_' AND SUBSTRING(option_name, 1, 22) <> '_transient_timeout_tk_' AND SUBSTRING(option_name, 1, 19) <> '_site_transient_tk_' AND SUBSTRING(option_name, 1, 27) <> '_site_transient_timeout_tk_'";
        $specs[$task] = array('options', 'option_id', $where);
    }
    if (!isset($specs[$task])) { throw new RuntimeException('Unknown cleanup action.'); }
    list($table, $key, $where) = $specs[$task];
    $limit = (int) $job['limits'][$table];
    $columns = $task === 'acf_postmeta' ? 'meta_id, post_id, meta_key, meta_value'
        : ($task === 'acf_options' ? 'option_id, option_name, option_value' : ($task === 'transients' ? 'option_id, option_name' : $key));
    $rows = $wpdb->get_results("SELECT $columns FROM {$wpdb->$table} r WHERE $key > $cursor AND $key <= $limit AND ($where) ORDER BY $key ASC LIMIT 50", ARRAY_A);
    if ($wpdb->last_error) { throw new RuntimeException('Database read failed. Cleanup has been paused.'); }
    return (array) $rows;
}

function tk_db_cleanup_count(array &$job, string $key, int $count = 1): void {
    $job['counts'][$key] = ($job['counts'][$key] ?? 0) + $count;
}

function tk_db_cleanup_delete_row(array &$job, string $task, array $row): void {
    global $wpdb;
    if (in_array($task, array('revisions', 'trash_posts', 'auto_drafts'), true)) {
        // WordPress APIs also remove dependent metadata/comments and invalidate object caches.
        if (!wp_delete_post((int) $row['ID'], true)) { throw new RuntimeException('A post could not be deleted. Cleanup has been paused.'); }
        tk_db_cleanup_count($job, $task);
    } elseif (in_array($task, array('spam_comments', 'trash_comments'), true)) {
        if (!wp_delete_comment((int) $row['comment_ID'], true)) { throw new RuntimeException('A comment could not be deleted. Cleanup has been paused.'); }
        tk_db_cleanup_count($job, $task);
    } elseif ($task === 'orphaned_postmeta' || $task === 'orphaned_commentmeta') {
        $type = $task === 'orphaned_postmeta' ? 'post' : 'comment';
        if (!delete_metadata_by_mid($type, (int) $row['meta_id'])) { throw new RuntimeException('Metadata could not be deleted. Cleanup has been paused.'); }
        tk_db_cleanup_count($job, $task);
    } elseif ($task === 'transients') {
        $name = $row['option_name'];
        $site = strpos($name, '_site_transient_') === 0;
        $timeout = strpos($name, $site ? '_site_transient_timeout_' : '_transient_timeout_') === 0;
        $prefix = $site ? ($timeout ? '_site_transient_timeout_' : '_site_transient_') : ($timeout ? '_transient_timeout_' : '_transient_');
        $key = substr($name, strlen($prefix));
        // Never clear shared network transients through a per-site cleanup.
        if ($site && is_multisite()) { return; }
        if (empty($job['transients_all'])) {
            $expires = (int) get_option($prefix . $key, 0);
            if (!$timeout || $expires <= 0 || $expires >= (int) $job['started_at']) { return; }
        }
        if ($site ? delete_site_transient($key) : delete_transient($key)) { tk_db_cleanup_count($job, 'transients'); }
        if ($wpdb->last_error) { throw new RuntimeException('Transient deletion failed. Cleanup has been paused.'); }
    } elseif ($task === 'acf_postmeta' || $task === 'acf_options') {
        $options = $task === 'acf_options';
        $key = $row[$options ? 'option_name' : 'meta_key'];
        $field = $row[$options ? 'option_value' : 'meta_value'];
        if (!preg_match('/^field_[A-Za-z0-9_-]+$/D', $field) || (function_exists('acf_get_field') && acf_get_field($field))) { return; }
        if ($options) {
            if (get_option($key) !== $field) { return; }
            if (delete_option(substr($key, 1))) { tk_db_cleanup_count($job, 'acf_option_values'); }
            if ($wpdb->last_error) { throw new RuntimeException('ACF value deletion failed. Cleanup has been paused.'); }
            if (!delete_option($key)) { throw new RuntimeException('ACF reference deletion failed. Cleanup has been paused.'); }
            tk_db_cleanup_count($job, 'acf_option_references');
        } else {
            $values = (int) tk_db_cleanup_query_value($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", (int) $row['post_id'], substr($key, 1)));
            delete_post_meta((int) $row['post_id'], substr($key, 1));
            if ($wpdb->last_error) { throw new RuntimeException('ACF value deletion failed. Cleanup has been paused.'); }
            tk_db_cleanup_count($job, 'acf_values', $values);
            if (!delete_metadata_by_mid('post', (int) $row['meta_id'])) { throw new RuntimeException('ACF reference deletion failed. Cleanup has been paused.'); }
            tk_db_cleanup_count($job, 'acf_references');
        }
    }
}

function tk_db_cleanup_job_step(array $job): array {
    global $wpdb;
    if ($job['status'] !== 'running') { return $job; }
    $task = $job['tasks'][$job['index']] ?? '';
    try {
        if ($task === 'optimize') {
            $table = $job['tables'][$job['cursor']] ?? null;
            if ($table !== null) {
                $result = $wpdb->get_results('OPTIMIZE TABLE `' . str_replace('`', '``', $table) . '`', ARRAY_A);
                if ($wpdb->last_error || !$result || array_filter($result, static function ($row) { return strtolower($row['Msg_type'] ?? '') === 'error'; })) {
                    throw new RuntimeException('Table optimization failed. Cleanup has been paused.');
                }
                tk_db_cleanup_count($job, 'optimized_tables'); $job['cursor']++;
            }
            $done = $job['cursor'] >= count($job['tables']);
        } else {
            if (strpos($task, 'acf_') === 0 && !function_exists('acf_get_field')) {
                $usage = tk_db_cleanup_acf_usage_scan(true);
                if (empty($usage['safe_to_remove'])) { throw new RuntimeException('ACF cleanup blocked: active usage found or source scan incomplete.'); }
            }
            $rows = tk_db_cleanup_rows($job, $task);
            $deadline = microtime(true) + 3;
            $done = count($rows) < 50;
            foreach ($rows as $row) {
                tk_db_cleanup_delete_row($job, $task, $row);
                $job['cursor'] = (int) ($row['ID'] ?? $row['comment_ID'] ?? $row['meta_id'] ?? $row['option_id']);
                if (microtime(true) >= $deadline) { $done = false; break; }
            }
        }
        if ($done) { $job['index']++; $job['cursor'] = 0; }
        if ($job['index'] >= count($job['tasks'])) {
            $job['status'] = 'completed';
            if (in_array('revisions', $job['tasks'], true)) { update_option('tk_db_cleanup_revision_last_run', time(), false); }
            if (in_array('acf_postmeta', $job['tasks'], true)) {
                $counts = $job['counts'];
                update_option('tk_db_cleanup_acf_last_run', array('timestamp' => time(), 'references' => $counts['acf_references'] ?? 0,
                    'values' => $counts['acf_values'] ?? 0, 'option_references' => $counts['acf_option_references'] ?? 0,
                    'option_values' => $counts['acf_option_values'] ?? 0, 'rows_deleted' => array_sum(array_intersect_key($counts, array_flip(array('acf_references', 'acf_values', 'acf_option_references', 'acf_option_values'))))), false);
                delete_transient('tk_db_cleanup_acf_scan');
            }
        }
        $job['error'] = '';
    } catch (Throwable $error) {
        $job['status'] = 'paused'; $job['error'] = $error->getMessage();
    }
    update_option('tk_jobs_cleanup', $job, false);
    return $job;
}

function tk_db_cleanup_job_command(string $command, array $input, int $owner): array {
    if (!in_array($command, array('start', 'step', 'pause', 'resume', 'cancel'), true)) { throw new RuntimeException('Invalid cleanup command.'); }
    $lease = tk_job_lock('cleanup', 300);
    if (!$lease) { throw new RuntimeException('Another cleanup request is still in progress. Try again shortly.'); }
    try {
        if ($command === 'start') { return tk_db_cleanup_job_start($input, $owner); }
        $job = get_option('tk_jobs_cleanup', array());
        if (!$job) { return array(); }
        if ((int) $job['owner'] !== $owner) { throw new RuntimeException('This cleanup belongs to another administrator.'); }
        if (!isset($input['job_id']) || !hash_equals($job['id'], (string) $input['job_id'])) { throw new RuntimeException('Cleanup changed. Reload this page.'); }
        if ($command === 'step') { return tk_db_cleanup_job_step($job); }
        if ($command === 'pause' && $job['status'] === 'running') { $job['status'] = 'paused'; }
        elseif ($command === 'resume' && $job['status'] === 'paused') { $job['status'] = 'running'; $job['error'] = ''; }
        elseif ($command === 'cancel' && in_array($job['status'], array('running', 'paused'), true)) { $job['status'] = 'cancelled'; }
        update_option('tk_jobs_cleanup', $job, false);
        return $job;
    } finally { tk_job_unlock('cleanup', $lease); }
}

function tk_db_cleanup_job_ajax(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !tk_is_admin_user() || tk_toolkits_is_locked()) {
        wp_send_json_error(array('message' => 'Forbidden.'), 403);
    }
    check_ajax_referer('tk_db_cleanup_run', '_tk_nonce');
    try {
        $job = tk_db_cleanup_job_command(sanitize_key($_POST['command'] ?? ''), wp_unslash($_POST), get_current_user_id());
        wp_send_json_success($job);
    } catch (RuntimeException $error) { wp_send_json_error(array('message' => $error->getMessage()), 409); }
}
