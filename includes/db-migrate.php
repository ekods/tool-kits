<?php
if (!defined('ABSPATH')) { exit; }

function tk_db_migrate_init() {
    add_action('admin_post_tk_db_export', 'tk_db_export_handler');
    add_action('admin_post_tk_db_run_replace', 'tk_db_run_find_replace_handler');
    add_action('admin_post_tk_db_download_temp_export', 'tk_db_download_temp_export_handler');
    add_action('admin_post_tk_db_change_prefix', 'tk_db_change_prefix_handler');
}

function tk_db_export_file_prefix(): string {
    global $wpdb;
    $db_name = defined('DB_NAME') ? DB_NAME : '';
    if ($db_name === '' && isset($wpdb->dbname)) {
        $db_name = $wpdb->dbname;
    }
    $db_name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $db_name);
    $prefix = 'toolkits-db';
    if ($db_name !== '') {
        $prefix .= '-' . $db_name;
    }
    return sanitize_file_name($prefix);
}

function tk_db_default_find_url(): string {
    $home = home_url('/');
    $parts = wp_parse_url($home);
    if (!is_array($parts) || empty($parts['host'])) {
        return '//example.com/';
    }
    $host = $parts['host'];
    $path = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
    return '//' . $host . $path;
}

function tk_db_default_find_path(): string {
    if (defined('ABSPATH')) {
        return rtrim(ABSPATH, '/');
    }
    if (function_exists('get_home_path')) {
        return rtrim(get_home_path(), '/');
    }
    return rtrim(untrailingslashit(get_template_directory()), '/');
}

function tk_db_default_pairs(): array {
    return [
        ['find' => tk_db_default_find_url(), 'replace' => ''],
        ['find' => tk_db_default_find_path(), 'replace' => ''],
    ];
}

function tk_db_random_prefix(int $length = 6): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $prefix = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $prefix .= $chars[random_int(0, $max)];
    }
    return $prefix . '_';
}

function tk_db_export_temp_dir(): string {
    $upload = wp_upload_dir();
    $base = trailingslashit($upload['basedir']) . 'tool-kits-db-export/';
    if (!file_exists($base)) {
        wp_mkdir_p($base);
    }
    return $base;
}

function tk_db_apply_pairs_to_value($value, array $pairs) {
    $out = $value;
    foreach ($pairs as $pair) {
        if (!is_string($pair['find']) || $pair['find'] === '') {
            continue;
        }
        $out = tk_maybe_unserialize_replace($pair['find'], $pair['replace'], $out);
    }
    return $out;
}

function tk_db_register_temp_export(string $path): string {
    $token = wp_generate_password(24, false, false);
    set_transient('tk_db_temp_export_' . $token, $path, MINUTE_IN_SECONDS * 10);
    return $token;
}

function tk_db_get_temp_export_path(string $token): ?string {
    if ($token === '') {
        return null;
    }
    $path = get_transient('tk_db_temp_export_' . $token);
    if (!is_string($path) || !file_exists($path)) {
        return null;
    }
    return $path;
}

function tk_db_export_with_pairs(array $pairs): array {
    global $wpdb;
    @set_time_limit(0);

    $prefix = tk_db_export_file_prefix();
    tk_log(sprintf('Preparing preload export for %s (%d pairs).', $prefix, count($pairs)));

    $dir = tk_db_export_temp_dir();
    $sql_path = $dir . $prefix . '-preload-' . date('dmY-His') . '.sql';
    $gz_path = $sql_path . '.gz';

    $fh = @fopen($sql_path, 'wb');
    if (!$fh) {
        return ['ok' => false, 'message' => 'Unable to create export file.'];
    }

    fwrite($fh, "-- Tool Kits Preloaded Export\n");
    fwrite($fh, "-- Site: " . home_url('/') . "\n");
    fwrite($fh, "-- Date: " . gmdate('c') . " (UTC)\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $wpdb->get_col("SHOW TABLES");
    if (!is_array($tables)) {
        $tables = array();
    }

    foreach ($tables as $table) {
        $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        if (!empty($create[1])) {
            fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
            fwrite($fh, $create[1] . ";\n\n");
        }

        $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array();
                foreach ($row as $v) {
                    if (is_null($v)) {
                        $vals[] = "NULL";
                        continue;
                    }
                    $processed = is_string($v) ? tk_db_apply_pairs_to_value($v, $pairs) : $v;
                    $vals[] = "'" . esc_sql((string)$processed) . "'";
                }
                fwrite($fh, "INSERT INTO `$table` (`" . implode("`,`", array_map('esc_sql', $cols)) . "`) VALUES (" . implode(",", $vals) . ");\n");
            }
            fwrite($fh, "\n");
        }
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    $raw = file_get_contents($sql_path);
    if ($raw === false) {
        @unlink($sql_path);
        return ['ok' => false, 'message' => 'Failed reading export file.'];
    }

    $raw = tk_db_apply_pairs_to_value($raw, $pairs);

    $gz_handle = @gzopen($gz_path, 'wb6');
    if (!$gz_handle) {
        @unlink($sql_path);
        return ['ok' => false, 'message' => 'Failed creating compressed export.'];
    }

    if (@gzwrite($gz_handle, $raw) === false) {
        @gzclose($gz_handle);
        @unlink($sql_path);
        @unlink($gz_path);
        return ['ok' => false, 'message' => 'Failed writing compressed export.'];
    }

    gzclose($gz_handle);
    @unlink($sql_path);

    $token = tk_db_register_temp_export($gz_path);
    $size = @filesize($gz_path);
    if ($size !== false) {
        tk_log('Preloaded export ready for download: ' . $gz_path . ' (' . $size . ' bytes)');
    } else {
        tk_log('Preloaded export ready for download: ' . $gz_path);
    }
    return ['ok' => true, 'message' => 'Preloaded export is ready.', 'token' => $token, 'path' => $gz_path, 'name' => basename($gz_path)];
}

function tk_db_render_pairs_table(array $pairs, string $table_id, string $find_name, string $replace_name, array $default_pairs = array()): void {
    echo '<div class="tk-find-replace-wrapper" data-find-name="' . esc_attr($find_name) . '" data-replace-name="' . esc_attr($replace_name) . '">';
    echo '<table id="' . esc_attr($table_id) . '" class="tk-pairs-table" role="presentation">';
    echo '<thead><tr><th style="width:48%;">FIND</th><th style="width:48%;">REPLACE</th><th style="width:4%;"></th></tr></thead>';
    echo '<tbody>';
    if (empty($pairs)) {
        $pairs[] = array('find' => '', 'replace' => '');
    }
    $last = end($pairs);
    $last_find = isset($last['find']) ? trim((string)$last['find']) : '';
    $last_replace = isset($last['replace']) ? trim((string)$last['replace']) : '';
    if ($last_find !== '' || $last_replace !== '') {
        $pairs[] = array('find' => '', 'replace' => '');
    }
    $default_pairs = array_values($default_pairs);
    foreach ($pairs as $index => $pair) {
        $value_find = isset($pair['find']) ? $pair['find'] : '';
        $value_replace = isset($pair['replace']) ? $pair['replace'] : '';
        $placeholder_find = '';
        if ($value_find === '' && isset($default_pairs[$index]['find']) && $default_pairs[$index]['find'] !== '') {
            $placeholder_find = $default_pairs[$index]['find'];
        }
        echo '<tr class="tk-pair-row">';
        echo '<td><input class="regular-text" type="text" name="' . esc_attr($find_name) . '[]" value="' . esc_attr((string)$value_find) . '" placeholder="' . esc_attr($placeholder_find) . '" autocomplete="off" /></td>';
        echo '<td><input class="regular-text" type="text" name="' . esc_attr($replace_name) . '[]" value="' . esc_attr((string)$value_replace) . '" placeholder="Leave blank to keep the current value" autocomplete="off" /></td>';
        echo '<td class="tk-col-actions"><button type="button" class="button tk-remove-row" title="Remove row">×</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p class="tk-pairs-actions"><button type="button" class="button button-secondary tk-add-row">+ Add Row</button></p>';
    echo '</div>';
}

function tk_db_get_saved_pairs(): array {
    $pairs = tk_get_option('db_pairs', array());
    if (!is_array($pairs)) {
        return array();
    }
    $out = array();
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $out[] = array(
            'find' => isset($pair['find']) ? (string) $pair['find'] : '',
            'replace' => isset($pair['replace']) ? (string) $pair['replace'] : '',
        );
    }
    return $out;
}

function tk_db_save_pairs(array $pairs): void {
    $clean = array();
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $find = isset($pair['find']) ? trim((string) $pair['find']) : '';
        $replace = isset($pair['replace']) ? (string) $pair['replace'] : '';
        if ($find === '') {
            continue;
        }
        $clean[] = array('find' => $find, 'replace' => $replace);
    }
    tk_update_option('db_pairs', $clean);
}

function tk_db_collect_pairs_from_request(): array {
    $finds = isset($_POST['pairs_find']) ? tk_post('pairs_find', array()) : array();
    $replaces = isset($_POST['pairs_replace']) ? tk_post('pairs_replace', array()) : array();
    if (!is_array($finds)) { $finds = array($finds); }
    if (!is_array($replaces)) { $replaces = array($replaces); }
    $pairs = array();
    $count = max(count($finds), count($replaces));
    for ($i = 0; $i < $count; $i++) {
        $find = isset($finds[$i]) ? trim(sanitize_text_field($finds[$i])) : '';
        $replace = isset($replaces[$i]) ? trim(sanitize_text_field($replaces[$i])) : '';
        if ($find === '') {
            continue;
        }
        $pairs[] = array('find' => $find, 'replace' => $replace);
    }
    return $pairs;
}

function tk_db_render_pairs_summary(array $pairs): void {
    $lines = array();
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $find = isset($pair['find']) ? trim((string) $pair['find']) : '';
        if ($find === '') {
            continue;
        }
        $replace = isset($pair['replace']) ? trim((string) $pair['replace']) : '';
        $replace_display = $replace !== '' ? '<code>' . esc_html($replace) . '</code>' : '<span class="tk-empty">unchanged</span>';
        $lines[] = '<li><span class="tk-pair-label">Find:</span> <code>' . esc_html($find) . '</code> <span class="tk-pair-label">Replace:</span> ' . $replace_display . '</li>';
    }
    if (empty($lines)) {
        return;
    }
    echo '<div class="tk-pairs-summary">';
    echo '<strong>Last saved replacements</strong>';
    echo '<ul class="tk-list">';
    foreach ($lines as $line) {
        echo $line;
    }
    echo '</ul></div>';
}

function tk_render_db_tools_page() {
    if (!tk_is_admin_user()) return;

    $new_prefix = tk_get_option('db_new_prefix', '');
    $export_token = isset($_GET['tk_export_token']) ? sanitize_text_field((string) $_GET['tk_export_token']) : '';
    $export_name = isset($_GET['tk_export_name']) ? sanitize_file_name($_GET['tk_export_name']) : '';
    $tk_msg = isset($_GET['tk_msg']) ? sanitize_text_field((string) $_GET['tk_msg']) : '';
    $prefix_msg = isset($_GET['tk_prefix_msg']) ? sanitize_text_field((string) $_GET['tk_prefix_msg']) : '';
    $backup_token = isset($_GET['tk_backup_token']) ? sanitize_text_field((string) $_GET['tk_backup_token']) : '';
    $backup_name = isset($_GET['tk_backup_name']) ? sanitize_file_name((string) $_GET['tk_backup_name']) : '';
    $suggested_prefix = tk_db_random_prefix();
    $pairs_for_render = tk_db_get_saved_pairs();
    if (empty($pairs_for_render)) {
        $pairs_for_render = tk_db_default_pairs();
    }

    $allowed_tabs = array('export-db', 'preload-export', 'change-prefix', 'db-cleanup');
    $requested_tab = isset($_GET['tk_tab']) ? sanitize_key($_GET['tk_tab']) : '';
    $active_tab = in_array($requested_tab, $allowed_tabs, true) ? $requested_tab : 'export-db';
    ?>
    <div class="wrap tk-wrap">
        <h1>Database</h1>
        <div class="tk-tabs tk-db-tools">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'export-db' ? ' is-active' : ''; ?>" data-panel="export-db">Export Database</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'preload-export' ? ' is-active' : ''; ?>" data-panel="preload-export">Export Download</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'change-prefix' ? ' is-active' : ''; ?>" data-panel="change-prefix">Change Prefix</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'db-cleanup' ? ' is-active' : ''; ?>" data-panel="db-cleanup">DB Cleanup</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'export-db' ? ' is-active' : ''; ?>" data-panel-id="export-db">
                    <h2>1) Export Database (SQL)</h2>
                    <p>Export all WordPress tables into a <code>.sql</code> dump. Useful when moving between environments.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_db_export'); ?>
                        <input type="hidden" name="action" value="tk_db_export">
                        <input type="hidden" name="tk_tab" value="export-db">
                        <p><button class="button button-primary">Download SQL</button></p>
                    </form>
                    <p class="description">Note: SQL files can be large. If your host limits execution, use phpMyAdmin or WP-CLI instead.</p>
                </div>
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'preload-export' ? ' is-active' : ''; ?>" data-panel-id="preload-export">
                    <h2>2) Export Download (Preload temporary DB)</h2>
                    <p>Create a temporary export with serialized-safe find/replace pairs and download it immediately.</p>
                    <?php tk_db_render_pairs_summary($pairs_for_render); ?>
                    <?php if ($export_token && $export_name) : ?>
                        <?php $download_url = admin_url('admin-post.php?action=tk_db_download_temp_export&token=' . urlencode($export_token)); ?>
                        <p class="description">
                            Preload ready: <code><?php echo esc_html($export_name); ?></code>. <a href="<?php echo esc_url($download_url); ?>">Download the prepared SQL file</a>.
                        </p>
                    <?php endif; ?>
                    <?php if ($tk_msg) : ?>
                        <p class="description"><?php echo esc_html($tk_msg); ?></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_db_run_replace'); ?>
                        <input type="hidden" name="action" value="tk_db_run_replace">
                        <input type="hidden" name="tk_tab" value="preload-export">
                        <?php tk_db_render_pairs_table($pairs_for_render, 'tk-run-pairs', 'pairs_find', 'pairs_replace', tk_db_default_pairs()); ?>
                        <p><button class="button button-primary" onclick="return confirm('Run the find/replace pairs and prepare a temporary export now? Ensure the database is backed up or already exported.')">Export</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'change-prefix' ? ' is-active' : ''; ?>" data-panel-id="change-prefix">
                    <h2>3) Change DB Prefix (Rename Tables)</h2>
                    <p>Rename tables from <code><?php global $wpdb; echo esc_html($wpdb->prefix); ?></code> to a new prefix.
                    This updates <code>options</code> and <code>usermeta</code> keys, but please edit <code>$table_prefix</code> in <code>wp-config.php</code> manually afterward.</p>
                    <?php if ($prefix_msg) : ?>
                        <p class="description"><?php echo esc_html($prefix_msg); ?></p>
                    <?php endif; ?>
                    <?php if ($backup_token && $backup_name) : ?>
                        <?php $download_url = admin_url('admin-post.php?action=tk_db_download_temp_export&token=' . urlencode($backup_token)); ?>
                        <p class="description">Backup created: <code><?php echo esc_html($backup_name); ?></code>.
                            <a href="<?php echo esc_url($download_url); ?>">Download backup</a>.
                        </p>
                    <?php endif; ?>
                    <?php
                    $wp_config_path = tk_hardening_wp_config_path();
                    if ($wp_config_path !== '' && !is_writable($wp_config_path)) :
                    ?>
                        <p class="description" style="color:#b32d2e;">wp-config.php is not writable. Please update <code>$table_prefix</code> manually.</p>
                    <?php endif; ?>
                    <p class="description">Suggested prefix: <code id="tk-prefix-example"><?php echo esc_html($suggested_prefix); ?></code>
                        <button type="button" class="button button-secondary" id="tk-prefix-refresh">Refresh Suggestion</button>
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_db_change_prefix'); ?>
                        <input type="hidden" name="action" value="tk_db_change_prefix">
                        <input type="hidden" name="tk_tab" value="change-prefix">

                        <label><strong>New Prefix</strong></label>
                        <input class="regular-text" type="text" id="tk-prefix-input" name="new_prefix" value="<?php echo esc_attr($new_prefix); ?>" placeholder="abc_" required>

                        <p><button class="button button-primary" onclick="return confirm('This is a risky operation. Backup first, then proceed with renaming the prefix?')">Rename Prefix</button></p>
                    </form>

                    <p class="description">Tip: After renaming, update <code>wp-config.php</code>:
                        <code>$table_prefix = 'NEWPREFIX_';</code>
                    </p>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'db-cleanup' ? ' is-active' : ''; ?>" data-panel-id="db-cleanup">
                    <?php tk_render_db_cleanup_panel(); ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var wrapper = document.querySelector('.tk-db-tools');
            if (!wrapper) { return; }
            function createPairRow(findName, replaceName) {
                var tr = document.createElement('tr');
                tr.className = 'tk-pair-row';
                tr.innerHTML = '<td><input class="regular-text" type="text" name="' + findName + '[]" autocomplete="off" /></td>' +
                               '<td><input class="regular-text" type="text" name="' + replaceName + '[]" autocomplete="off" /></td>' +
                               '<td class="tk-col-actions"><button type="button" class="button tk-remove-row" title="Remove row">×</button></td>';
                return tr;
            }

            function attachPairs(wrapperEl) {
                var body = wrapperEl.querySelector('tbody');
                var addBtn = wrapperEl.querySelector('.tk-add-row');
                var findName = wrapperEl.getAttribute('data-find-name') || 'pairs_find';
                var replaceName = wrapperEl.getAttribute('data-replace-name') || 'pairs_replace';
                if (!body) { return; }

                if (addBtn) {
                    addBtn.addEventListener('click', function(){
                        body.appendChild(createPairRow(findName, replaceName));
                    });
                }

                wrapperEl.addEventListener('click', function(e){
                    if (e.target && e.target.classList.contains('tk-remove-row')) {
                        if (body.children.length <= 1) { return; }
                        var row = e.target.closest('tr');
                        if (row) {
                            row.remove();
                        }
                    }
                });
            }

            function activateTab(panelId) {
                wrapper.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                });
                wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(btn){
                    btn.classList.toggle('is-active', btn.getAttribute('data-panel') === panelId);
                });
            }

            function getPanelFromHash() {
                var hash = window.location.hash || '';
                if (!hash) { return ''; }
                return hash.replace('#', '');
            }

            function tk_generate_prefix(length) {
                length = length || 6;
                var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
                var result = '';
                for (var i = 0; i < length; i++) {
                    result += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                return result + '_';
            }

            function initPrefixSuggestion() {
                var button = document.getElementById('tk-prefix-refresh');
                var example = document.getElementById('tk-prefix-example');
                var input = document.getElementById('tk-prefix-input');
                if (!button || !example) return;
                button.addEventListener('click', function(){
                    var suggestion = tk_generate_prefix(6);
                    example.textContent = suggestion;
                    if (input) {
                        input.value = suggestion;
                    }
                });
            }

            wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    if (panelId) {
                        window.location.hash = panelId;
                        activateTab(panelId);
                    }
                });
            });

            document.addEventListener('DOMContentLoaded', function(){
                document.querySelectorAll('.tk-find-replace-wrapper').forEach(function(findWrapper){
                    attachPairs(findWrapper);
                });
                initPrefixSuggestion();
                var initial = getPanelFromHash();
                if (initial && wrapper.querySelector('.tk-tab-panel[data-panel-id="' + initial + '"]')) {
                    activateTab(initial);
                }
            });
        })();
        </script>
    </div>
    <?php
}

function tk_render_db_migrate_page() {
    tk_render_db_tools_page();
}

function tk_db_export_handler() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_db_export');
    global $wpdb;

    @set_time_limit(0);
    $prefix = tk_db_export_file_prefix();
    tk_log("Generating SQL export for {$prefix}.");

    $tables = $wpdb->get_col("SHOW TABLES");
    if (empty($tables)) {
        wp_die('No tables found.');
    }

    $sql = "-- Tool Kits SQL Export\n";
    $sql .= "-- Site: " . home_url('/') . "\n";
    $sql .= "-- Date: " . gmdate('c') . " (UTC)\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        if (!empty($create[1])) {
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $create[1] . ";\n\n";
        }

        $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array();
                foreach ($row as $v) {
                    if (is_null($v)) {
                        $vals[] = "NULL";
                    } else {
                        $vals[] = "'" . esc_sql($v) . "'";
                    }
                }
                $sql .= "INSERT INTO `$table` (`" . implode("`,`", array_map('esc_sql', $cols)) . "`) VALUES (" . implode(",", $vals) . ");\n";
            }
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = $prefix . '-export-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

function tk_db_download_temp_export_handler() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $path = tk_db_get_temp_export_path($token);
    if (!$path) {
        wp_die('Prepared export not found or expired.');
    }

    nocache_headers();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function tk_db_run_find_replace_handler() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_db_run_replace');

    $pairs = tk_db_collect_pairs_from_request();
    tk_db_save_pairs($pairs);
    $result = tk_db_export_with_pairs($pairs);

    if (!$result['ok']) {
        wp_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_err' => 'export_failed',
            'tk_err_msg' => $result['message'],
            'tk_tab' => 'preload-export',
        ), admin_url('admin.php')));
        exit;
    }

    wp_redirect(add_query_arg(array(
        'page' => 'tool-kits-db',
        'tk_export_token' => $result['token'],
        'tk_ok' => 1,
        'tk_msg' => $result['message'],
        'tk_export_name' => $result['name'],
        'tk_tab' => 'preload-export',
    ), admin_url('admin.php')));
    exit;
}

function tk_db_change_prefix_handler() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_db_change_prefix');
    global $wpdb;

    $new = tk_post('new_prefix');
    $new = preg_replace('/[^a-zA-Z0-9_]/', '', $new);

    if ($new === '') {
        wp_redirect(add_query_arg(array('page'=>'tool-kits-db','tk_err'=>'prefix_empty','tk_tab'=>'change-prefix'), admin_url('admin.php')));
        exit;
    }

    $old = $wpdb->prefix;
    if ($new === $old) {
        wp_redirect(add_query_arg(array('page'=>'tool-kits-db','tk_err'=>'prefix_same','tk_tab'=>'change-prefix'), admin_url('admin.php')));
        exit;
    }

    @set_time_limit(0);

    $tables = $wpdb->get_col("SHOW TABLES LIKE '{$old}%'");
    if (empty($tables)) {
        wp_redirect(add_query_arg(array('page'=>'tool-kits-db','tk_err'=>'no_tables','tk_tab'=>'change-prefix'), admin_url('admin.php')));
        exit;
    }

    $backup = tk_db_export_with_pairs(array());
    if (empty($backup['ok'])) {
        $msg = isset($backup['message']) ? (string) $backup['message'] : 'Backup failed.';
        wp_redirect(add_query_arg(array(
            'page'=>'tool-kits-db',
            'tk_err'=>'backup_failed',
            'tk_err_msg'=>$msg,
            'tk_tab'=>'change-prefix',
        ), admin_url('admin.php')));
        exit;
    }

    $backup_token = isset($backup['token']) ? (string) $backup['token'] : '';
    $backup_name = isset($backup['name']) ? (string) $backup['name'] : '';

    $wpdb->query('START TRANSACTION');
    $errors = array();
    $renamed = array();

    foreach ($tables as $table) {
        $suffix = substr($table, strlen($old));
        $new_name = $new . $suffix;
        $res = $wpdb->query("RENAME TABLE `$table` TO `$new_name`");
        if ($res === false) {
            $errors[] = $wpdb->last_error;
            break;
        }
        $renamed[] = array('old' => $table, 'new' => $new_name);
    }

    if (empty($errors)) {
        $db_name = $wpdb->dbname;
        $columns = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s
                   AND COLUMN_NAME IN ('option_name','meta_key')
                   AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')",
                $db_name
            ),
            ARRAY_A
        );
        foreach ($columns as $column) {
            $table = $column['TABLE_NAME'];
            $col = $column['COLUMN_NAME'];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            $res = $wpdb->query($wpdb->prepare(
                "UPDATE `$table` SET `$col` = REPLACE(`$col`, %s, %s) WHERE `$col` LIKE %s",
                $old,
                $new,
                $wpdb->esc_like($old) . '%'
            ));
            if ($res === false) {
                $errors[] = $wpdb->last_error;
                break;
            }
        }
    }

    if (!empty($errors)) {
        foreach (array_reverse($renamed) as $pair) {
            if (!isset($pair['old'], $pair['new'])) {
                continue;
            }
            $wpdb->query("RENAME TABLE `{$pair['new']}` TO `{$pair['old']}`");
        }
        $wpdb->query('ROLLBACK');
        $msg = implode('; ', $errors);
        wp_redirect(add_query_arg(array(
            'page'=>'tool-kits-db',
            'tk_err'=>'rename_failed',
            'tk_err_msg'=>$msg,
            'tk_backup_token' => $backup_token,
            'tk_backup_name' => $backup_name,
            'tk_tab'=>'change-prefix',
        ), admin_url('admin.php')));
        exit;
    }

    $wpdb->query('COMMIT');

    wp_redirect(add_query_arg(array(
        'page'=>'tool-kits-db',
        'tk_ok' => 1,
        'tk_prefix_msg' => 'Prefix renamed; update wp-config.php accordingly.',
        'tk_backup_token' => $backup_token,
        'tk_backup_name' => $backup_name,
        'tk_tab' => 'change-prefix',
    ), admin_url('admin.php')));
    exit;
}
