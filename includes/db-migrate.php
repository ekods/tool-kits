<?php
if (!defined('ABSPATH')) { exit; }

function tk_db_migrate_init() {
    add_action('admin_post_tk_db_export', 'tk_db_export_handler');
    add_action('admin_post_tk_db_run_replace', 'tk_db_run_find_replace_handler');
    add_action('admin_post_tk_db_download_temp_export', 'tk_db_download_temp_export_handler');
    add_action('admin_post_tk_db_local_prod', 'tk_db_export_local_prod_handler');
    add_action('admin_post_tk_db_change_prefix', 'tk_db_change_prefix_handler');
    add_action('admin_post_tk_db_import', 'tk_db_import_handler');
    add_action('admin_post_tk_db_live_replace', 'tk_db_live_replace_handler');
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

function tk_db_normalize_site_reference(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    } elseif (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $host = strtolower((string) $parts['host']);
    if (isset($parts['port']) && (int) $parts['port'] > 0) {
        $host .= ':' . (int) $parts['port'];
    }
    $path = isset($parts['path']) ? trim((string) $parts['path']) : '';
    $path = $path === '' ? '' : '/' . ltrim(rtrim($path, '/'), '/');
    return '//' . $host . $path;
}

function tk_db_normalize_site_absolute_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    } elseif (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $scheme = isset($parts['scheme']) && in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)
        ? strtolower((string) $parts['scheme'])
        : 'https';
    $host = strtolower((string) $parts['host']);
    if (isset($parts['port']) && (int) $parts['port'] > 0) {
        $host .= ':' . (int) $parts['port'];
    }
    $path = isset($parts['path']) ? trim((string) $parts['path']) : '';
    $path = $path === '' ? '' : '/' . ltrim(rtrim($path, '/'), '/');
    return $scheme . '://' . $host . $path;
}

function tk_db_local_prod_add_pair(array &$pairs, array &$seen, string $find, string $replace): void {
    $find = trim($find);
    if ($find === '') {
        return;
    }
    $key = $find . '||' . $replace;
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $pairs[] = array('find' => $find, 'replace' => $replace);
}

function tk_db_local_prod_add_url_pairs(array &$pairs, array &$seen, string $find, string $replace): void {
    tk_db_local_prod_add_pair($pairs, $seen, $find, $replace);
    // Cover JSON-escaped URLs commonly found in block content/meta.
    tk_db_local_prod_add_pair($pairs, $seen, str_replace('/', '\\/', $find), str_replace('/', '\\/', $replace));
}

function tk_db_local_prod_build_pairs(string $local_site_url, string $production_site_url, string $local_site_path, string $production_site_path): array {
    $pairs = array();
    $seen = array();
    $local_ref = tk_db_normalize_site_reference($local_site_url);
    $prod_ref = tk_db_normalize_site_reference($production_site_url);
    $local_abs = tk_db_normalize_site_absolute_url($local_site_url);
    $prod_abs = tk_db_normalize_site_absolute_url($production_site_url);
    if ($local_ref !== '' && $prod_ref !== '') {
        tk_db_local_prod_add_url_pairs($pairs, $seen, $local_ref, $prod_ref);
        if ($prod_abs !== '') {
            tk_db_local_prod_add_url_pairs($pairs, $seen, 'http:' . $local_ref, $prod_abs);
            tk_db_local_prod_add_url_pairs($pairs, $seen, 'https:' . $local_ref, $prod_abs);
        }
    }
    if ($local_abs !== '' && $prod_abs !== '') {
        $local_no_slash = rtrim($local_abs, '/');
        $prod_no_slash = rtrim($prod_abs, '/');
        tk_db_local_prod_add_url_pairs($pairs, $seen, $local_no_slash, $prod_no_slash);
        tk_db_local_prod_add_url_pairs($pairs, $seen, $local_no_slash . '/', $prod_no_slash . '/');
    }
    // Ensure exact current WordPress URL options are replaced.
    $current_home = trim((string) get_option('home', ''));
    $current_siteurl = trim((string) get_option('siteurl', ''));
    if ($prod_abs !== '') {
        $prod_no_slash = rtrim($prod_abs, '/');
        if ($current_home !== '') {
            tk_db_local_prod_add_url_pairs($pairs, $seen, rtrim($current_home, '/'), $prod_no_slash);
            tk_db_local_prod_add_url_pairs($pairs, $seen, rtrim($current_home, '/') . '/', $prod_no_slash . '/');
        }
        if ($current_siteurl !== '') {
            tk_db_local_prod_add_url_pairs($pairs, $seen, rtrim($current_siteurl, '/'), $prod_no_slash);
            tk_db_local_prod_add_url_pairs($pairs, $seen, rtrim($current_siteurl, '/') . '/', $prod_no_slash . '/');
        }
    }
    $local_path = rtrim(trim($local_site_path), '/');
    $prod_path = rtrim(trim($production_site_path), '/');
    if ($local_path !== '' && $prod_path !== '' && $local_path !== $prod_path) {
        tk_db_local_prod_add_pair($pairs, $seen, $local_path, $prod_path);
        tk_db_local_prod_add_pair($pairs, $seen, $local_path . '/', $prod_path . '/');
    }
    return $pairs;
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

function tk_db_escape_identifier(string $identifier): string {
    return str_replace('`', '``', $identifier);
}

function tk_db_quote_value(string $value): string {
    // Escape for SQL string literals without converting `%` to wpdb placeholder hashes.
    $escaped = strtr($value, array(
        "\\" => "\\\\",
        "'" => "\\'",
        "\0" => "\\0",
        "\n" => "\\n",
        "\r" => "\\r",
        "\x1a" => "\\Z",
    ));
    return "'" . $escaped . "'";
}

function tk_db_apply_pairs_to_value($value, array $pairs, string $column = '', array $row = array()) {
    $out = $value;
    if (tk_db_should_skip_replacement($column, $row)) {
        return $out;
    }
    if ($column === 'option_value') {
        $name = isset($row['option_name']) ? (string) $row['option_name'] : '';
        // WP code editor cache can leak local absolute file paths into migrations.
        if ($name === 'recently_edited') {
            return serialize(array());
        }
    }
    foreach ($pairs as $pair) {
        if (!is_string($pair['find']) || $pair['find'] === '') {
            continue;
        }
        $out = tk_maybe_unserialize_replace($pair['find'], $pair['replace'], $out);
    }
    return $out;
}

/**
 * Decide whether replacement should be skipped for a row/column pair.
 */
function tk_db_should_skip_replacement(string $column, array $row): bool {
    if ($column !== 'option_value') {
        return false;
    }
    $name = isset($row['option_name']) ? (string) $row['option_name'] : '';
    return $name === 'permalink_structure';
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

function tk_db_list_export_objects(): array {
    global $wpdb;

    $results = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
    if (!is_array($results)) {
        return array(
            'tables' => array(),
            'views' => array(),
        );
    }

    $objects = array(
        'tables' => array(),
        'views' => array(),
    );

    foreach ($results as $row) {
        $name = isset($row[0]) ? (string) $row[0] : '';
        $type = strtoupper(isset($row[1]) ? (string) $row[1] : 'BASE TABLE');
        if ($name === '') {
            continue;
        }
        if ($type === 'VIEW') {
            $objects['views'][] = $name;
            continue;
        }
        $objects['tables'][] = $name;
    }

    return $objects;
}

function tk_db_normalize_create_statement(string $statement, string $type = 'table'): string {
    if ($type === 'view') {
        $statement = preg_replace('/\s+DEFINER=`[^`]+`@`[^`]+`/i', '', $statement);
        if (!is_string($statement)) {
            return '';
        }
    }

    return $statement;
}

function tk_db_get_additional_export_objects(): array {
    global $wpdb;

    $objects = array(
        'triggers' => array(),
        'procedures' => array(),
        'functions' => array(),
        'events' => array(),
    );

    $triggers = $wpdb->get_results('SHOW TRIGGERS', ARRAY_A);
    if (is_array($triggers)) {
        foreach ($triggers as $row) {
            $name = isset($row['Trigger']) ? (string) $row['Trigger'] : '';
            if ($name !== '') {
                $objects['triggers'][] = $name;
            }
        }
    }

    $procedures = $wpdb->get_results('SHOW PROCEDURE STATUS WHERE Db = DATABASE()', ARRAY_A);
    if (is_array($procedures)) {
        foreach ($procedures as $row) {
            $name = isset($row['Name']) ? (string) $row['Name'] : '';
            if ($name !== '') {
                $objects['procedures'][] = $name;
            }
        }
    }

    $functions = $wpdb->get_results('SHOW FUNCTION STATUS WHERE Db = DATABASE()', ARRAY_A);
    if (is_array($functions)) {
        foreach ($functions as $row) {
            $name = isset($row['Name']) ? (string) $row['Name'] : '';
            if ($name !== '') {
                $objects['functions'][] = $name;
            }
        }
    }

    $events = $wpdb->get_results('SHOW EVENTS WHERE Db = DATABASE()', ARRAY_A);
    if (is_array($events)) {
        foreach ($events as $row) {
            $name = isset($row['Name']) ? (string) $row['Name'] : '';
            if ($name !== '') {
                $objects['events'][] = $name;
            }
        }
    }

    return $objects;
}

function tk_db_export_additional_objects_notice(array $objects): string {
    $lines = array();
    $labels = array(
        'triggers' => 'Triggers',
        'procedures' => 'Procedures',
        'functions' => 'Functions',
        'events' => 'Events',
    );

    foreach ($labels as $key => $label) {
        $items = isset($objects[$key]) && is_array($objects[$key]) ? array_values(array_filter($objects[$key], 'strlen')) : array();
        if (empty($items)) {
            continue;
        }
        $lines[] = '-- Warning: ' . $label . ' are not included in this export: ' . implode(', ', $items);
    }

    if (empty($lines)) {
        return '';
    }

    return implode("\n", $lines) . "\n\n";
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
    fwrite($fh, tk_db_export_additional_objects_notice(tk_db_get_additional_export_objects()));
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $objects = tk_db_list_export_objects();
    $tables = $objects['tables'];
    $views = $objects['views'];

    foreach ($tables as $table) {
        $expected_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        if (!empty($wpdb->last_error)) {
            fclose($fh);
            @unlink($sql_path);
            return ['ok' => false, 'message' => 'Failed reading row count for table `' . $table . '`: ' . $wpdb->last_error];
        }
        $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        if (!empty($create[1])) {
            fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
            fwrite($fh, $create[1] . ";\n\n");
        }

        $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        if (!empty($wpdb->last_error)) {
            fclose($fh);
            @unlink($sql_path);
            return ['ok' => false, 'message' => 'Failed reading rows for table `' . $table . '`: ' . $wpdb->last_error];
        }
        $exported_rows = 0;
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array();
                foreach ($row as $col => $v) {
                    if (is_null($v)) {
                        $vals[] = "NULL";
                        continue;
                    }
                    $processed = is_string($v) ? tk_db_apply_pairs_to_value($v, $pairs, $col, $row) : $v;
                    $vals[] = tk_db_quote_value((string) $processed);
                }
                fwrite($fh, "INSERT INTO `$table` (`" . implode("`,`", array_map('tk_db_escape_identifier', $cols)) . "`) VALUES (" . implode(",", $vals) . ");\n");
                $exported_rows++;
            }
            fwrite($fh, "\n");
        }
        if ($expected_rows !== $exported_rows) {
            fclose($fh);
            @unlink($sql_path);
            return ['ok' => false, 'message' => 'Row count mismatch on table `' . $table . '` (expected ' . $expected_rows . ', exported ' . $exported_rows . '). Export cancelled to prevent data loss.'];
        }
    }

    foreach ($views as $view) {
        $create = $wpdb->get_row("SHOW CREATE TABLE `$view`", ARRAY_N);
        if (!empty($wpdb->last_error)) {
            fclose($fh);
            @unlink($sql_path);
            return ['ok' => false, 'message' => 'Failed reading view definition for `' . $view . '`: ' . $wpdb->last_error];
        }
        if (!empty($create[1])) {
            fwrite($fh, "DROP VIEW IF EXISTS `$view`;\n");
            fwrite($fh, tk_db_normalize_create_statement((string) $create[1], 'view') . ";\n\n");
        }
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    $raw = file_get_contents($sql_path);
    if ($raw === false) {
        @unlink($sql_path);
        return ['ok' => false, 'message' => 'Failed reading export file.'];
    }

    // Rows are already processed column-by-column above (serialized-safe).

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
        // Keep raw text (including `%...%` patterns) to avoid mutating tokens like `%category%`.
        $find = isset($finds[$i]) ? trim((string) $finds[$i]) : '';
        $replace = isset($replaces[$i]) ? trim((string) $replaces[$i]) : '';
        $find = wp_check_invalid_utf8($find, true);
        $replace = wp_check_invalid_utf8($replace, true);
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

function tk_db_import_status_message(): string {
    $summary = get_transient('tk_db_import_last_summary');
    if (!is_array($summary)) {
        return '';
    }

    delete_transient('tk_db_import_last_summary');

    $message = isset($summary['message']) ? (string) $summary['message'] : '';
    $file_name = isset($summary['file_name']) ? (string) $summary['file_name'] : '';

    if ($message === '') {
        return '';
    }

    if ($file_name !== '') {
        $message .= ' File: ' . $file_name . '.';
    }

    return $message;
}

function tk_db_prefix_status_message(): string {
    $summary = get_transient('tk_db_prefix_last_summary');
    if (!is_array($summary)) {
        return '';
    }

    delete_transient('tk_db_prefix_last_summary');

    $old_prefix = isset($summary['old_prefix']) ? (string) $summary['old_prefix'] : '';
    $new_prefix = isset($summary['new_prefix']) ? (string) $summary['new_prefix'] : '';
    $renamed_tables = isset($summary['renamed_tables']) ? (int) $summary['renamed_tables'] : 0;
    $updated_columns = isset($summary['updated_columns']) ? (int) $summary['updated_columns'] : 0;

    if ($old_prefix === '' || $new_prefix === '') {
        return '';
    }

    return sprintf(
        'Prefix renamed from %s to %s. %d tables renamed; %d metadata columns updated. Update wp-config.php accordingly.',
        $old_prefix,
        $new_prefix,
        $renamed_tables,
        $updated_columns
    );
}

function tk_db_prefix_error_message(string $error_code, string $error_detail = ''): string {
    switch ($error_code) {
        case 'prefix_empty':
            return 'New prefix is required.';
        case 'prefix_same':
            return 'New prefix must be different from the current prefix.';
        case 'no_tables':
            return 'No tables were found for the current prefix.';
        case 'backup_failed':
            return $error_detail !== '' ? 'Backup failed before renaming prefix: ' . $error_detail : 'Backup failed before renaming prefix.';
        case 'rename_failed':
            return $error_detail !== '' ? 'Failed to rename the database prefix: ' . $error_detail : 'Failed to rename the database prefix.';
        default:
            return $error_detail;
    }
}

function tk_db_export_error_message(string $error_code, string $error_detail = ''): string {
    switch ($error_code) {
        case 'export_failed':
            return $error_detail !== '' ? 'Failed to prepare export download: ' . $error_detail : 'Failed to prepare export download.';
        default:
            return $error_detail;
    }
}

function tk_render_db_tools_page() {
    if (!tk_is_admin_user()) return;

    $new_prefix = tk_get_option('db_new_prefix', '');
    $export_token = isset($_GET['tk_export_token']) ? sanitize_text_field((string) $_GET['tk_export_token']) : '';
    $export_name = isset($_GET['tk_export_name']) ? sanitize_file_name($_GET['tk_export_name']) : '';
    $tk_msg = isset($_GET['tk_msg']) ? sanitize_text_field((string) $_GET['tk_msg']) : '';
    $tk_error_code = isset($_GET['tk_err']) ? sanitize_key((string) $_GET['tk_err']) : '';
    $tk_error_msg = isset($_GET['tk_err_msg']) ? sanitize_text_field((string) $_GET['tk_err_msg']) : '';
    $prefix_msg = isset($_GET['tk_prefix_msg']) ? sanitize_text_field((string) $_GET['tk_prefix_msg']) : '';
    $prefix_error_code = isset($_GET['tk_err']) ? sanitize_key((string) $_GET['tk_err']) : '';
    $prefix_error_msg = isset($_GET['tk_err_msg']) ? sanitize_text_field((string) $_GET['tk_err_msg']) : '';
    $import_msg = isset($_GET['tk_import_msg']) ? sanitize_text_field((string) $_GET['tk_import_msg']) : '';
    $import_status = isset($_GET['tk_import_status']) ? sanitize_key((string) $_GET['tk_import_status']) : '';
    if ($import_msg === '' && $import_status !== '') {
        $import_msg = tk_db_import_status_message();
    }
    $local_prod_msg = isset($_GET['tk_local_prod_msg']) ? sanitize_text_field((string) $_GET['tk_local_prod_msg']) : '';
    $local_prod_status = isset($_GET['tk_local_prod_status']) ? sanitize_key((string) $_GET['tk_local_prod_status']) : '';
    $local_prod_export_token = isset($_GET['tk_local_prod_token']) ? sanitize_text_field((string) $_GET['tk_local_prod_token']) : '';
    $local_prod_export_name = isset($_GET['tk_local_prod_name']) ? sanitize_file_name((string) $_GET['tk_local_prod_name']) : '';
    $backup_token = isset($_GET['tk_backup_token']) ? sanitize_text_field((string) $_GET['tk_backup_token']) : '';
    $backup_name = isset($_GET['tk_backup_name']) ? sanitize_file_name((string) $_GET['tk_backup_name']) : '';
    if ($prefix_msg === '' && isset($_GET['tk_ok']) && (string) $_GET['tk_ok'] === '1') {
        $prefix_msg = tk_db_prefix_status_message();
    }
    $suggested_prefix = tk_db_random_prefix();
    $pairs_for_render = tk_db_get_saved_pairs();
    if (empty($pairs_for_render)) {
        $pairs_for_render = tk_db_default_pairs();
    }

    $local_site_url_default = home_url('/');
    $local_site_path_default = tk_db_default_find_path();
    $saved_prod_url = (string) tk_get_option('db_local_prod_url', '');
    $saved_prod_path = (string) tk_get_option('db_local_prod_path', '');

    $allowed_tabs = array('export-db', 'preload-export', 'local-to-prod', 'live-replace', 'import-db', 'change-prefix', 'db-cleanup');
    $requested_tab = isset($_GET['tk_tab']) ? sanitize_key($_GET['tk_tab']) : '';
    $active_tab = in_array($requested_tab, $allowed_tabs, true) ? $requested_tab : 'export-db';
    ?>
    <div class="wrap tk-wrap tk-db-tools">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Database Tools', 'tool-kits'), __('Advanced database management, find & replace, and prefix migration.', 'tool-kits'), 'dashicons-database'); ?>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'export-db' ? ' is-active' : ''; ?>" data-panel="export-db"><?php _e('Export', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'preload-export' ? ' is-active' : ''; ?>" data-panel="preload-export"><?php _e('Quick Prepare', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'local-to-prod' ? ' is-active' : ''; ?>" data-panel="local-to-prod"><?php _e('Migration', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'live-replace' ? ' is-active' : ''; ?>" data-panel="live-replace"><?php _e('Search & Replace', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'import-db' ? ' is-active' : ''; ?>" data-panel="import-db"><?php _e('Import', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'change-prefix' ? ' is-active' : ''; ?>" data-panel="change-prefix"><?php _e('Prefix', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'db-cleanup' ? ' is-active' : ''; ?>" data-panel="db-cleanup"><?php _e('Cleanup', 'tool-kits'); ?></button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'export-db' ? ' is-active' : ''; ?>" data-panel-id="export-db">
                    <h2 style="margin-bottom:8px;">Database Export</h2>
                    <p class="description" style="margin-bottom:24px;">Export all WordPress tables into a <code>.sql</code> dump. Useful for manual backups or environment migration.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_db_export'); ?>
                        <input type="hidden" name="action" value="tk_db_export">
                        <input type="hidden" name="tk_tab" value="export-db">
                        <button class="button button-primary button-hero" style="min-width:200px; border-radius:10px;">Download SQL Dump</button>
                    </form>
                    <div style="margin-top:24px; padding:16px; background:rgba(243,156,18,0.05); border:1px solid rgba(243,156,18,0.2); border-radius:12px;">
                        <p class="description" style="margin:0; color:#d35400;"><strong>Note:</strong> SQL files can be large. If your host limits execution, use phpMyAdmin or WP-CLI instead.</p>
                    </div>
                </div>
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'preload-export' ? ' is-active' : ''; ?>" data-panel-id="preload-export">
                    <h2>2) Export Download (Preload temporary DB)</h2>
                    <p>Create a temporary export with serialized-safe find/replace pairs and download it immediately.</p>
                    <?php tk_db_render_pairs_summary($pairs_for_render); ?>
                    <?php
                    $export_error_notice = '';
                    if ($active_tab === 'preload-export' && $tk_error_code !== '') {
                        $export_error_notice = tk_db_export_error_message($tk_error_code, $tk_error_msg);
                    }
                    if ($export_error_notice !== '') :
                    ?>
                        <?php tk_notice($export_error_notice, 'error'); ?>
                    <?php endif; ?>
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
                        <p><button class="button button-primary" data-confirm="Run the find/replace pairs and prepare a temporary export now? Ensure the database is backed up or already exported.">Export</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'local-to-prod' ? ' is-active' : ''; ?>" data-panel-id="local-to-prod">
                    <h2 style="margin-bottom:8px;">Migration Export (Local to Prod)</h2>
                    <p class="description" style="margin-bottom:24px;">Automatically replace URLs and paths in your database for a seamless move to production.</p>
                    
                    <?php if ($local_prod_msg !== '') : ?>
                        <?php tk_notice($local_prod_msg, $local_prod_status === 'ok' ? 'success' : 'error'); ?>
                    <?php endif; ?>

                    <?php if ($local_prod_export_token && $local_prod_export_name) : ?>
                        <div style="background:var(--tk-bg-soft); padding:16px; border-radius:12px; border:1px solid var(--tk-border-soft); margin-bottom:24px;">
                            <?php $download_local_prod = admin_url('admin-post.php?action=tk_db_download_temp_export&token=' . urlencode($local_prod_export_token)); ?>
                            <p style="margin:0; display:flex; align-items:center; justify-content:space-between;">
                                <span>Prepared: <code><?php echo esc_html($local_prod_export_name); ?></code></span>
                                <a href="<?php echo esc_url($download_local_prod); ?>" class="button button-primary"><?php _e('Download SQL', 'tool-kits'); ?></a>
                            </p>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_db_export_local_prod'); ?>
                        <input type="hidden" name="action" value="tk_db_export_local_prod">
                        <input type="hidden" name="tk_tab" value="local-to-prod">
                        
                        <div class="tk-grid tk-grid-2" style="gap:20px;">
                            <div style="background:var(--tk-bg-soft); padding:16px; border-radius:12px;">
                                <h4 style="margin:0 0 12px;"><?php _e('Site URL Configuration', 'tool-kits'); ?></h4>
                                <div style="margin-bottom:12px;">
                                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Local Site URL</label>
                                    <input type="text" class="regular-text" name="local_site_url" value="<?php echo esc_attr($local_site_url_default); ?>" required style="width:100%;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Production Site URL</label>
                                    <input type="text" class="regular-text" name="production_site_url" value="<?php echo esc_attr($saved_prod_url); ?>" placeholder="https://example.com" required style="width:100%;">
                                </div>
                            </div>
                            <div style="background:var(--tk-bg-soft); padding:16px; border-radius:12px;">
                                <h4 style="margin:0 0 12px;"><?php _e('Path Configuration (Optional)', 'tool-kits'); ?></h4>
                                <div style="margin-bottom:12px;">
                                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Local Absolute Path</label>
                                    <input type="text" class="regular-text" name="local_site_path" value="<?php echo esc_attr($local_site_path_default); ?>" style="width:100%;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Production Path</label>
                                    <input type="text" class="regular-text" name="production_site_path" value="<?php echo esc_attr($saved_prod_path); ?>" placeholder="/home/user/public_html" style="width:100%;">
                                </div>
                            </div>
                        </div>

                        <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--tk-border-soft);">
                            <button class="button button-primary button-hero" data-confirm="Create migration export for production now?">Generate Migration Export</button>
                        </div>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'live-replace' ? ' is-active' : ''; ?>" data-panel-id="live-replace">
                    <h2>Live Search & Replace</h2>
                    <p>Search and replace strings directly in the active database. <strong>Warning: This action is irreversible. Always backup first.</strong></p>
                    <?php if (isset($_GET['tk_live_replace_msg'])) : ?>
                        <?php tk_notice(sanitize_text_field((string) $_GET['tk_live_replace_msg']), isset($_GET['tk_live_replace_status']) && $_GET['tk_live_replace_status'] === 'ok' ? 'success' : 'error'); ?>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_db_live_replace'); ?>
                        <input type="hidden" name="action" value="tk_db_live_replace">
                        <input type="hidden" name="tk_tab" value="live-replace">
                        <?php tk_db_render_pairs_table($pairs_for_render, 'tk-live-pairs', 'pairs_find', 'pairs_replace', tk_db_default_pairs()); ?>
                        <p><button class="button button-primary" data-confirm="Are you sure you want to run this replacement on the live database? This cannot be undone!">Run Live Replace</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'import-db' ? ' is-active' : ''; ?>" data-panel-id="import-db">
                    <h2 style="margin-bottom:8px;">Database Import</h2>
                    <p class="description" style="margin-bottom:24px;">Upload a <code>.sql</code> or <code>.sql.gz</code> file to overwrite the current database.</p>
                    
                    <?php if ($import_msg !== '') : ?>
                        <?php tk_notice($import_msg, $import_status === 'ok' ? 'success' : 'error'); ?>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <?php tk_nonce_field('tk_db_import'); ?>
                        <input type="hidden" name="action" value="tk_db_import">
                        <input type="hidden" name="tk_tab" value="import-db">
                        
                        <div style="padding:40px; border:2px dashed var(--tk-border-soft); border-radius:16px; text-align:center; background:var(--tk-bg-soft); margin-bottom:24px;">
                            <span class="dashicons dashicons-upload" style="font-size:48px; width:48px; height:48px; color:var(--tk-primary); margin-bottom:16px;"></span>
                            <div style="margin-bottom:20px;">
                                <input type="file" name="sql_file" accept=".sql,.gz" required style="font-size:13px;">
                            </div>
                            <p class="description"><?php _e('Supported formats: .sql, .sql.gz (Max file size: ', 'tool-kits'); ?><?php echo size_format(wp_max_upload_size()); ?>)</p>
                        </div>

                        <button class="button button-primary button-hero" style="width:100%; border-radius:10px;" data-confirm="Importing will overwrite data in the database. Continue?">Start Database Import</button>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel<?php echo $active_tab === 'change-prefix' ? ' is-active' : ''; ?>" data-panel-id="change-prefix">
                    <h2 style="margin-bottom:8px;">Rename Database Prefix</h2>
                    <p class="description" style="margin-bottom:24px;">Change your table prefix from <code><?php global $wpdb; echo esc_html($wpdb->prefix); ?></code> to enhance security against automated SQL injection.</p>
                    
                    <?php
                    $prefix_error_notice = '';
                    if ($active_tab === 'change-prefix' && $prefix_error_code !== '') {
                        $prefix_error_notice = tk_db_prefix_error_message($prefix_error_code, $prefix_error_msg);
                    }
                    if ($prefix_error_notice !== '') :
                    ?>
                        <?php tk_notice($prefix_error_notice, 'error'); ?>
                    <?php endif; ?>
                    
                    <?php if ($prefix_msg) : ?>
                        <?php tk_notice($prefix_msg, 'success'); ?>
                    <?php endif; ?>

                    <?php if ($backup_token && $backup_name) : ?>
                        <div style="background:var(--tk-bg-soft); padding:16px; border-radius:12px; border:1px solid var(--tk-border-soft); margin-bottom:24px;">
                            <?php $download_url = admin_url('admin-post.php?action=tk_db_download_temp_export&token=' . urlencode($backup_token)); ?>
                            <p style="margin:0; display:flex; align-items:center; justify-content:space-between;">
                                <span>Backup created: <code><?php echo esc_html($backup_name); ?></code></span>
                                <a href="<?php echo esc_url($download_url); ?>" class="button button-small"><?php _e('Download Backup', 'tool-kits'); ?></a>
                            </p>
                        </div>
                    <?php endif; ?>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:24px;">
                        <div style="background:var(--tk-bg-soft); padding:20px; border-radius:16px;">
                            <h4 style="margin:0 0 16px;"><?php _e('Prefix Configuration', 'tool-kits'); ?></h4>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php tk_nonce_field('tk_db_change_prefix'); ?>
                                <input type="hidden" name="action" value="tk_db_change_prefix">
                                <input type="hidden" name="tk_tab" value="change-prefix">

                                <div style="margin-bottom:20px;">
                                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:8px;">New Database Prefix</label>
                                    <input type="text" id="tk-prefix-input" name="new_prefix" value="<?php echo esc_attr($new_prefix); ?>" placeholder="abc_" required style="width:100%; border-radius:10px;">
                                </div>
                                <button class="button button-primary button-hero" style="width:100%; border-radius:10px;" data-confirm="This is a risky operation. Backup first, then proceed with renaming the prefix?">Start Renaming</button>
                            </form>
                        </div>
                        <div style="background:var(--tk-bg-soft); padding:20px; border-radius:16px; border:1px dashed var(--tk-border-soft);">
                            <h4 style="margin:0 0 12px;"><?php _e('Recommendations', 'tool-kits'); ?></h4>
                            <div style="margin-bottom:16px;">
                                <label style="display:block; font-size:12px; color:var(--tk-muted); margin-bottom:4px;">Suggested Prefix</label>
                                <code id="tk-prefix-example" style="font-size:16px; font-weight:700; color:var(--tk-primary);"><?php echo esc_html($suggested_prefix); ?></code>
                                <button type="button" class="button button-link" id="tk-prefix-refresh" style="font-size:11px; padding:0; margin-left:8px; vertical-align:baseline; text-decoration:none;">Refresh</button>
                            </div>
                            <div style="padding-top:12px; border-top:1px solid var(--tk-border-soft);">
                                <p class="description" style="font-size:11px; line-height:1.4; color:#d35400;">
                                    <strong>Important:</strong> After renaming, you must update <code>wp-config.php</code> manually:<br>
                                    <code>$table_prefix = 'NEW_PREFIX_';</code>
                                </p>
                            </div>
                        </div>
                    </div>
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
    tk_require_admin_post('tk_db_export');
    global $wpdb;

    @set_time_limit(0);
    $prefix = tk_db_export_file_prefix();
    tk_log("Generating SQL export for {$prefix}.");

    $objects = tk_db_list_export_objects();
    $tables = $objects['tables'];
    $views = $objects['views'];
    if (empty($tables) && empty($views)) {
        wp_die('No tables found.');
    }

    $sql = "-- Tool Kits SQL Export\n";
    $sql .= "-- Site: " . home_url('/') . "\n";
    $sql .= "-- Date: " . gmdate('c') . " (UTC)\n\n";
    $sql .= tk_db_export_additional_objects_notice(tk_db_get_additional_export_objects());
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
                        $vals[] = tk_db_quote_value((string) $v);
                    }
                }
                $sql .= "INSERT INTO `$table` (`" . implode("`,`", array_map('tk_db_escape_identifier', $cols)) . "`) VALUES (" . implode(",", $vals) . ");\n";
            }
            $sql .= "\n";
        }
    }

    foreach ($views as $view) {
        $create = $wpdb->get_row("SHOW CREATE TABLE `$view`", ARRAY_N);
        if (!empty($create[1])) {
            $sql .= "DROP VIEW IF EXISTS `$view`;\n";
            $sql .= tk_db_normalize_create_statement((string) $create[1], 'view') . ";\n\n";
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

function tk_db_import_handler() {
    tk_require_admin_post('tk_db_import');

    if (empty($_FILES['sql_file']) || !is_array($_FILES['sql_file'])) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_tab' => 'import-db',
            'tk_import_status' => 'fail',
            'tk_import_msg' => 'No file uploaded.',
        ), admin_url('admin.php')));
        exit;
    }

    $file = $_FILES['sql_file'];
    if (!empty($file['error'])) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_tab' => 'import-db',
            'tk_import_status' => 'fail',
            'tk_import_msg' => 'Upload failed.',
        ), admin_url('admin.php')));
        exit;
    }

    $mimes = array(
        'sql' => 'application/sql',
        'gz' => 'application/gzip',
    );
    $uploaded = wp_handle_upload($file, array(
        'test_form' => false,
        'mimes' => $mimes,
    ));
    if (isset($uploaded['error'])) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_tab' => 'import-db',
            'tk_import_status' => 'fail',
            'tk_import_msg' => $uploaded['error'],
        ), admin_url('admin.php')));
        exit;
    }

    $path = isset($uploaded['file']) ? (string) $uploaded['file'] : '';
    $file_name = $path !== '' ? basename($path) : '';
    $result = tk_db_import_sql_file($path);
    if ($path !== '' && file_exists($path)) {
        @unlink($path);
    }
    $msg = $result['message'] ?? 'Import complete.';
    $status = !empty($result['ok']) ? 'ok' : 'fail';
    set_transient('tk_db_import_last_summary', array(
        'status'    => $status,
        'message'   => $msg,
        'file_name' => $file_name,
    ), MINUTE_IN_SECONDS * 10);
    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-db',
        'tk_tab' => 'import-db',
        'tk_import_status' => $status,
        'tk_import_msg' => $msg,
    ), admin_url('admin.php')));
    exit;
}

function tk_db_import_sql_file(string $path): array {
    global $wpdb;
    if ($path === '' || !is_readable($path)) {
        return array('ok' => false, 'message' => 'Import file not readable.');
    }
    @set_time_limit(0);
    $is_gz = substr($path, -3) === '.gz';
    $handle = $is_gz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
    if (!$handle) {
        return array('ok' => false, 'message' => 'Failed to open import file.');
    }

    $in_block_comment = false;
    $buffer = '';
    $queries = 0;
    $errors = 0;
    $last_error = '';

    while (!($is_gz ? gzeof($handle) : feof($handle))) {
        $line = $is_gz ? gzgets($handle) : fgets($handle);
        if ($line === false) {
            break;
        }
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($in_block_comment) {
            $end = strpos($line, '*/');
            if ($end === false) {
                continue;
            }
            $line = substr($line, $end + 2);
            $in_block_comment = false;
            if (trim($line) === '') {
                continue;
            }
        }
        if (strpos($line, '/*') === 0) {
            if (strpos($line, '*/') === false) {
                $in_block_comment = true;
                continue;
            }
            $line = preg_replace('/\/\*.*?\*\//', '', $line);
            if (trim($line) === '') {
                continue;
            }
        }
        if (strpos($line, '--') === 0 || strpos($line, '#') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') {
            $res = $wpdb->query($buffer);
            if ($res === false) {
                $errors++;
                $last_error = $wpdb->last_error;
            } else {
                $queries++;
            }
            $buffer = '';
        }
    }
    if ($buffer !== '') {
        $res = $wpdb->query($buffer);
        if ($res === false) {
            $errors++;
            $last_error = $wpdb->last_error;
        } else {
            $queries++;
        }
    }

    if ($is_gz) {
        gzclose($handle);
    } else {
        fclose($handle);
    }

    if ($errors > 0) {
        $message = 'Import completed with errors. Queries: ' . $queries . '. Last error: ' . $last_error;
        return array('ok' => false, 'message' => $message);
    }
    return array('ok' => true, 'message' => 'Import completed. Queries: ' . $queries);
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
    tk_require_admin_post('tk_db_run_replace');

    $pairs = tk_db_collect_pairs_from_request();
    tk_db_save_pairs($pairs);
    $result = tk_db_export_with_pairs($pairs);

    if (!$result['ok']) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_err' => 'export_failed',
            'tk_err_msg' => $result['message'],
            'tk_tab' => 'preload-export',
        ), admin_url('admin.php')));
        exit;
    }

    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-db',
        'tk_export_token' => $result['token'],
        'tk_ok' => 1,
        'tk_msg' => $result['message'],
        'tk_export_name' => $result['name'],
        'tk_tab' => 'preload-export',
    ), admin_url('admin.php')));
    exit;
}

function tk_db_export_local_prod_handler() {
    tk_require_admin_post('tk_db_export_local_prod');

    $local_site_url = trim((string) tk_post('local_site_url', home_url('/')));
    $production_site_url = trim((string) tk_post('production_site_url', ''));
    $local_site_path = trim((string) tk_post('local_site_path', tk_db_default_find_path()));
    $production_site_path = trim((string) tk_post('production_site_path', ''));

    if ($production_site_url === '') {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_tab' => 'local-to-prod',
            'tk_local_prod_status' => 'fail',
            'tk_local_prod_msg' => 'Production Site URL is required.',
        ), admin_url('admin.php')));
        exit;
    }

    $pairs = tk_db_local_prod_build_pairs($local_site_url, $production_site_url, $local_site_path, $production_site_path);
    if (empty($pairs)) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_tab' => 'local-to-prod',
            'tk_local_prod_status' => 'fail',
            'tk_local_prod_msg' => 'No valid find/replace pairs generated. Check local and production URL/path values.',
        ), admin_url('admin.php')));
        exit;
    }

    tk_update_option('db_local_prod_url', $production_site_url);
    tk_update_option('db_local_prod_path', $production_site_path);
    tk_db_save_pairs($pairs);
    $result = tk_db_export_with_pairs($pairs);
    if (empty($result['ok'])) {
        $message = isset($result['message']) ? (string) $result['message'] : 'Failed to create migration export.';
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_tab' => 'local-to-prod',
            'tk_local_prod_status' => 'fail',
            'tk_local_prod_msg' => $message,
        ), admin_url('admin.php')));
        exit;
    }

    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-db',
        'tk_tab' => 'local-to-prod',
        'tk_local_prod_status' => 'ok',
        'tk_local_prod_msg' => 'Migration export is ready.',
        'tk_local_prod_token' => $result['token'],
        'tk_local_prod_name' => $result['name'],
    ), admin_url('admin.php')));
    exit;
}

function tk_db_change_prefix_handler() {
    tk_require_admin_post('tk_db_change_prefix');
    global $wpdb;

    $new = tk_post('new_prefix');
    $new = preg_replace('/[^a-zA-Z0-9_]/', '', $new);

    if ($new === '') {
        wp_safe_redirect(add_query_arg(array('page'=>'tool-kits-db','tk_err'=>'prefix_empty','tk_tab'=>'change-prefix'), admin_url('admin.php')));
        exit;
    }

    $old = $wpdb->prefix;
    if ($new === $old) {
        wp_safe_redirect(add_query_arg(array('page'=>'tool-kits-db','tk_err'=>'prefix_same','tk_tab'=>'change-prefix'), admin_url('admin.php')));
        exit;
    }

    @set_time_limit(0);

    $tables = $wpdb->get_col("SHOW TABLES LIKE '{$old}%'");
    if (empty($tables)) {
        wp_safe_redirect(add_query_arg(array('page'=>'tool-kits-db','tk_err'=>'no_tables','tk_tab'=>'change-prefix'), admin_url('admin.php')));
        exit;
    }

    $backup = tk_db_export_with_pairs(array());
    if (empty($backup['ok'])) {
        $msg = isset($backup['message']) ? (string) $backup['message'] : 'Backup failed.';
        wp_safe_redirect(add_query_arg(array(
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
    $updated_columns = 0;

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
            $updated_columns++;
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
        wp_safe_redirect(add_query_arg(array(
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
    set_transient('tk_db_prefix_last_summary', array(
        'old_prefix'      => $old,
        'new_prefix'      => $new,
        'renamed_tables'  => count($renamed),
        'updated_columns' => $updated_columns,
    ), MINUTE_IN_SECONDS * 10);

    wp_safe_redirect(add_query_arg(array(
        'page'=>'tool-kits-db',
        'tk_ok' => 1,
        'tk_prefix_msg' => 'Prefix renamed; update wp-config.php accordingly.',
        'tk_backup_token' => $backup_token,
        'tk_backup_name' => $backup_name,
        'tk_tab' => 'change-prefix',
    ), admin_url('admin.php')));
    exit;
}

function tk_db_live_replace_handler() {
    tk_require_admin_post('tk_db_live_replace');
    global $wpdb;

    $pairs = tk_db_collect_pairs_from_request();
    tk_db_save_pairs($pairs);

    if (empty($pairs)) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tool-kits-db',
            'tk_tab' => 'live-replace',
            'tk_live_replace_status' => 'fail',
            'tk_live_replace_msg' => 'No pairs to replace.',
        ), admin_url('admin.php')));
        exit;
    }

    @set_time_limit(0);
    $objects = tk_db_list_export_objects();
    $tables = $objects['tables'];

    $updated_rows = 0;
    $errors = [];

    foreach ($tables as $table) {
        $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        if (empty($rows)) {
            continue;
        }

        $pk = '';
        $cols = $wpdb->get_results("SHOW COLUMNS FROM `$table`");
        foreach ($cols as $col) {
            if ($col->Key === 'PRI') {
                $pk = $col->Field;
                break;
            }
        }

        if (!$pk) {
            continue; // Can't update without PK easily
        }

        foreach ($rows as $row) {
            $update_data = array();
            $update_format = array();
            foreach ($row as $col => $v) {
                if (is_null($v)) continue;
                if ($col === $pk) continue; // Don't replace primary key value

                $processed = is_string($v) ? tk_db_apply_pairs_to_value($v, $pairs, $col, $row) : $v;
                if ($processed !== $v) {
                    $update_data[$col] = $processed;
                    $update_format[] = '%s';
                }
            }

            if (!empty($update_data)) {
                $res = $wpdb->update($table, $update_data, array($pk => $row[$pk]));
                if ($res !== false) {
                    $updated_rows++;
                } else {
                    $errors[] = "Failed updating table $table at $pk = " . $row[$pk];
                }
            }
        }
    }

    if (!empty($errors)) {
        $msg = "Replaced $updated_rows rows, but encountered errors. Check logs.";
        tk_log("Live replace errors: " . implode(", ", $errors));
        $status = 'fail';
    } else {
        $msg = "Successfully updated $updated_rows rows.";
        $status = 'ok';
    }

    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-db',
        'tk_tab' => 'live-replace',
        'tk_live_replace_status' => $status,
        'tk_live_replace_msg' => $msg,
    ), admin_url('admin.php')));
    exit;
}
