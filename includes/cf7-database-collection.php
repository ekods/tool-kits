<?php
/**
 * Collection engine and views adapted from CF7 Database Collection 1.0.3 (GPLv2 or later).
 * Source: plugins/cf7-database-collection/cf7-database-collection.php.
 * Tool Kits owns hooks, access, settings and assets in cf7-database.php.
 */
if (!defined('ABSPATH')) { exit; }

function tk_cf7dbc_activate() {
    global $wpdb;

    $table_name = tk_cf7dbc_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        form_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        form_title VARCHAR(255) NOT NULL DEFAULT '',
        submitted_at DATETIME NOT NULL,
        user_ip VARCHAR(100) NULL,
        user_agent TEXT NULL,
        page_url TEXT NULL,
        spam_status VARCHAR(20) NOT NULL DEFAULT 'clean',
        spam_score INT(11) NOT NULL DEFAULT 0,
        spam_reasons TEXT NULL,
        read_status VARCHAR(20) NOT NULL DEFAULT 'unread',
        read_at DATETIME NULL,
        data LONGTEXT NOT NULL,
        PRIMARY KEY  (id),
        KEY form_id (form_id),
        KEY spam_status (spam_status),
        KEY read_status (read_status),
        KEY form_submitted_at (form_id, submitted_at),
        KEY submitted_at (submitted_at)
    ) {$charset_collate};";

    dbDelta($sql);
}


function tk_cf7dbc_maybe_upgrade_schema() {
    $schema_version = '1.0.1';

    if (get_option('cf7dbc_schema_version') === $schema_version) {
        return;
    }

    tk_cf7dbc_activate();
    update_option('cf7dbc_schema_version', $schema_version);
}

function tk_cf7dbc_is_contact_form_7_active() {
    return defined('WPCF7_VERSION') || class_exists('WPCF7_ContactForm');
}

function tk_cf7dbc_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'cf7dbc_submissions';
}

/**
 * Hook Contact Form 7 after the email is sent.
 */

function tk_cf7dbc_save_submission($contact_form) {
    if (!tk_cf7_database_enabled() || !tk_license_features_enabled()) { return; }
    tk_cf7dbc_maybe_upgrade_schema();
    if (!class_exists('WPCF7_Submission')) {
        return;
    }

    $submission = WPCF7_Submission::get_instance();

    if (!$submission) {
        return;
    }

    $posted_data = $submission->get_posted_data();

    if (!is_array($posted_data)) {
        return;
    }

    $clean_data = [];

    foreach ($posted_data as $key => $value) {
        // Ignore internal CF7 fields.
        if (strpos($key, '_wpcf7') === 0) {
            continue;
        }

        $clean_data[sanitize_key($key)] = tk_cf7dbc_sanitize_submission_value($value);
    }

    $spam_detection = tk_cf7dbc_detect_spam($clean_data);

    global $wpdb;

    $wpdb->insert(
        tk_cf7dbc_table_name(),
        [
            'form_id'      => absint($contact_form->id()),
            'form_title'   => sanitize_text_field($contact_form->title()),
            'submitted_at' => current_time('mysql'),
            'user_ip'      => tk_cf7dbc_get_user_ip(),
            'user_agent'   => isset($_SERVER['HTTP_USER_AGENT']) ? tk_cf7dbc_sanitize_submission_value($_SERVER['HTTP_USER_AGENT']) : '',
            'page_url'     => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '',
            'spam_status'  => $spam_detection['status'],
            'spam_score'   => $spam_detection['score'],
            'spam_reasons' => wp_json_encode($spam_detection['reasons']),
            'read_status'  => 'unread',
            'data'         => wp_json_encode($clean_data),
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
    );
}

function tk_cf7dbc_sanitize_submission_value($value) {
    $value = wp_unslash($value);

    if (is_array($value)) {
        return array_map('tk_cf7dbc_sanitize_submission_value', $value);
    }

    $value = (string) $value;
    $value = wp_check_invalid_utf8($value);
    $value = wp_strip_all_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

    return sanitize_textarea_field($value);
}

function tk_cf7dbc_format_submission_value($value) {
    if (is_array($value)) {
        return implode(', ', array_map('tk_cf7dbc_format_submission_value', $value));
    }

    return (string) $value;
}

function tk_cf7dbc_escape_excel_cell($value) {
    $value = tk_cf7dbc_format_submission_value($value);

    if (preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

function tk_cf7dbc_export_excluded_fields() {
    return [
        'tk_captcha_answer',
        'tk_captcha_token',
        'tk_robot_honeypot',
        'tk_hp_field',
        'tk_form_ts',
        'user_agent',
        'user agent',
        'detection_reasons',
        'detection reasons',
        'spam_score',
        'spam score',
    ];
}

function tk_cf7dbc_should_export_field($field) {
    $normalized = strtolower(str_replace('-', '_', trim((string) $field)));

    return !in_array($normalized, tk_cf7dbc_export_excluded_fields(), true);
}

function tk_cf7dbc_get_export_filename() {
    $site_name = sanitize_title(get_bloginfo('name'));

    if ($site_name === '') {
        $site_name = 'website';
    }

    return $site_name . '-submissions-' . gmdate('Y-m-d-H-i-s') . '.xlsx';
}

function tk_cf7dbc_excel_column_name($index) {
    $name = '';

    do {
        $name = chr(65 + ($index % 26)) . $name;
        $index = (int) floor($index / 26) - 1;
    } while ($index >= 0);

    return $name;
}

function tk_cf7dbc_write_excel_row(XMLWriter $writer, $values, $row_number, $is_header = false) {
    $writer->startElement('row');
    $writer->writeAttribute('r', (string) $row_number);

    foreach (array_values($values) as $column_index => $value) {
        $writer->startElement('c');
        $writer->writeAttribute('r', tk_cf7dbc_excel_column_name($column_index) . $row_number);
        $writer->writeAttribute('t', 'inlineStr');

        if ($is_header) {
            $writer->writeAttribute('s', '1');
        }

        $writer->startElement('is');
        $writer->startElement('t');
        $writer->writeAttribute('xml:space', 'preserve');
        $writer->text(tk_cf7dbc_escape_excel_cell($value));
        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
    }

    $writer->endElement();
}

function tk_cf7dbc_create_excel_file($headers, $rows, $filename) {
    if (!class_exists('ZipArchive') || !class_exists('XMLWriter')) {
        return new WP_Error('tk_cf7dbc_excel_support', __('The server requires the PHP ZIP and XMLWriter extensions to create Excel files.', 'tool-kits'));
    }

    $excel_path = wp_tempnam($filename);
    $sheet_path = wp_tempnam('cf7dbc-worksheet.xml');

    if (!$excel_path || !$sheet_path) {
        return new WP_Error('tk_cf7dbc_excel_temp', __('Unable to create the temporary Excel file.', 'tool-kits'));
    }

    $writer = new XMLWriter();

    if (!$writer->openUri($sheet_path)) {
        @unlink($excel_path);
        @unlink($sheet_path);
        return new WP_Error('tk_cf7dbc_excel_sheet', __('Unable to create the Excel worksheet.', 'tool-kits'));
    }

    $last_column = tk_cf7dbc_excel_column_name(max(count($headers) - 1, 0));
    $last_row = max(count($rows) + 1, 1);

    $writer->startDocument('1.0', 'UTF-8', 'yes');
    $writer->startElement('worksheet');
    $writer->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $writer->startElement('sheetViews');
    $writer->startElement('sheetView');
    $writer->writeAttribute('workbookViewId', '0');
    $writer->startElement('pane');
    $writer->writeAttribute('ySplit', '1');
    $writer->writeAttribute('topLeftCell', 'A2');
    $writer->writeAttribute('activePane', 'bottomLeft');
    $writer->writeAttribute('state', 'frozen');
    $writer->endElement();
    $writer->endElement();
    $writer->endElement();
    $writer->startElement('sheetData');
    tk_cf7dbc_write_excel_row($writer, $headers, 1, true);

    foreach ($rows as $row_index => $row) {
        tk_cf7dbc_write_excel_row($writer, $row, $row_index + 2);
    }

    $writer->endElement();
    $writer->startElement('autoFilter');
    $writer->writeAttribute('ref', 'A1:' . $last_column . $last_row);
    $writer->endElement();
    $writer->endElement();
    $writer->endDocument();
    $writer->flush();

    $zip = new ZipArchive();

    if ($zip->open($excel_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($excel_path);
        @unlink($sheet_path);
        return new WP_Error('tk_cf7dbc_excel_zip', __('Unable to create the Excel archive.', 'tool-kits'));
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Submissions" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->addFile($sheet_path, 'xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($sheet_path);

    return $excel_path;
}

function tk_cf7dbc_detect_spam($data) {
    $score = 0;
    $reasons = [];
    $flat_text = strtolower(tk_cf7dbc_flatten_submission_data($data));
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower(tk_cf7dbc_sanitize_submission_value($_SERVER['HTTP_USER_AGENT'])) : '';
    $page_url = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
    $bot_detected = false;

    if ($user_agent === '') {
        $score += 3;
        $reasons[] = 'Missing browser user agent';
    } elseif (preg_match('/bot|crawler|spider|scrapy|curl|wget|python|httpclient|libwww|headless/i', $user_agent)) {
        $bot_detected = true;
        $score += 4;
        $reasons[] = 'Bot-like user agent';
    }

    if ($page_url === '') {
        $score += 1;
        $reasons[] = 'Missing referring page';
    }

    $url_count = preg_match_all('/https?:\/\/|www\./i', $flat_text);

    if ($url_count >= 6) {
        $score += 5;
        $reasons[] = 'Contains many links';
    } elseif ($url_count >= 3) {
        $score += 3;
        $reasons[] = 'Contains multiple links';
    }

    $spam_keywords = [
        'casino',
        'viagra',
        'cialis',
        'loan',
        'crypto',
        'bitcoin',
        'forex',
        'porn',
        'sex',
        'seo backlinks',
        'guest post',
        'telegram',
        'whatsapp',
    ];

    $keyword_hits = 0;

    foreach ($spam_keywords as $keyword) {
        if (strpos($flat_text, $keyword) !== false) {
            $keyword_hits++;
        }
    }

    if ($keyword_hits > 0) {
        $score += min(6, $keyword_hits * 2);
        $reasons[] = 'Contains common spam keywords';
    }

    if (preg_match('/<a\s|<\/a>|url=|\[link/i', $flat_text)) {
        $score += 2;
        $reasons[] = 'Contains link markup';
    }

    foreach ($data as $key => $value) {
        $field_value = strtolower(is_array($value) ? implode(' ', $value) : (string) $value);

        if (preg_match('/name|your-name|first-name|last-name/i', (string) $key) && preg_match('/https?:\/\/|www\.|\.ru|\.cn|\.xyz/i', $field_value)) {
            $score += 4;
            $reasons[] = 'Name field contains URL-like text';
            break;
        }
    }

    if (trim($flat_text) !== '' && strlen($flat_text) > 1200 && $url_count > 0) {
        $score += 2;
        $reasons[] = 'Long message with links';
    }

    $status = 'clean';

    if ($score >= 7) {
        $status = 'spam';
    } elseif ($bot_detected) {
        $status = 'bot';
    } elseif ($score >= 4) {
        $status = 'suspicious';
    }

    return [
        'status'  => $status,
        'score'   => $score,
        'reasons' => array_values(array_unique($reasons)),
    ];
}

function tk_cf7dbc_flatten_submission_data($data) {
    $values = [];

    foreach ($data as $value) {
        if (is_array($value)) {
            $values[] = implode(' ', array_map('strval', $value));
            continue;
        }

        $values[] = (string) $value;
    }

    return implode(' ', $values);
}

function tk_cf7dbc_get_user_ip() {
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];

    foreach ($keys as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }

        $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

function tk_cf7dbc_get_unread_count() {
    global $wpdb;
    $table_name = tk_cf7dbc_table_name();
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE read_status = 'unread'");
}

function tk_cf7dbc_get_unread_counts_by_form() {
    global $wpdb;
    $table_name = tk_cf7dbc_table_name();

    return $wpdb->get_results(
        "SELECT form_id, form_title, COUNT(*) as unread_count
        FROM {$table_name}
        WHERE read_status = 'unread'
        GROUP BY form_id"
    );
}

function tk_cf7dbc_get_stats() {
    global $wpdb;
    $table_name = tk_cf7dbc_table_name();

    $stats = [
        'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"),
        'unread' => tk_cf7dbc_get_unread_count(),
        'spam' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE spam_status = 'spam'"),
        'bot' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE spam_status = 'bot'"),
        'suspicious' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE spam_status = 'suspicious'"),
    ];

    return $stats;
}

function tk_cf7dbc_get_top_forms_stats($limit = 5) {
    global $wpdb;
    $table_name = tk_cf7dbc_table_name();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT form_id, form_title, COUNT(*) as count
        FROM {$table_name}
        GROUP BY form_id
        ORDER BY count DESC
        LIMIT %d",
        $limit
    ));
}

function tk_cf7dbc_get_recent_submissions($limit = 5) {
    global $wpdb;
    $table_name = tk_cf7dbc_table_name();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} ORDER BY submitted_at DESC LIMIT %d",
        $limit
    ));
}

function tk_cf7dbc_get_db_size() {
    global $wpdb;
    $table_name = tk_cf7dbc_table_name();
    $db_name = DB_NAME;

    $size = $wpdb->get_row($wpdb->prepare(
        "SELECT
            (data_length + index_length) / 1024 / 1024 AS size_mb
        FROM information_schema.TABLES
        WHERE table_schema = %s AND table_name = %s",
        $db_name,
        $table_name
    ));

    return $size ? round($size->size_mb, 2) : 0;
}

/** Shared Tool Kits identity; data and controls stay outside its custom button styles. */
function tk_cf7dbc_render_page_heading($title, $description) {
    echo '<div class="tk-wrap cf7dbc-page-heading">';
    tk_render_header_branding();
    tk_render_page_hero($title, $description, 'dashicons-database');
    echo '</div>';
}

function tk_cf7dbc_detail_url($id, $form_id) {
    return add_query_arg(array('page' => tk_cf7_database_collection_slug(), 'form_id' => absint($form_id), 'submission_id' => absint($id)), admin_url('admin.php'));
}

function tk_cf7dbc_render_modal($open_id = 0) {
    echo '<div id="tk-cf7dbc-modal" style="display:none" data-open-id="' . esc_attr(absint($open_id)) . '"><div id="tk-cf7dbc-modal-content"></div></div>';
}

function tk_cf7dbc_deleted_notice() {
    if (isset($_GET['deleted'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Submission deleted successfully.', 'tool-kits') . '</p></div>';
    }
}

function tk_cf7dbc_render_list_table($kind, $form_id = 0) {
    require_once __DIR__ . '/class-cf7-database-list-table.php';
    $table = new TK_CF7_Database_List_Table($kind, $form_id);
    $table->prepare_items();
    $table->display();
}

function tk_cf7dbc_render_admin_page() {
    if (!tk_cf7_database_can_manage()) {
        return;
    }

    $form_id_filter = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
    $submission_id = isset($_GET['submission_id']) ? absint($_GET['submission_id']) : 0;

    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    if ($submission_id > 0) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT form_id FROM ' . tk_cf7dbc_table_name() . ' WHERE id = %d', $submission_id));
        if ($row) { $form_id_filter = absint($row->form_id); }
    }

    if ($form_id_filter > 0) {
        tk_cf7dbc_render_form_submissions($form_id_filter, $paged, $per_page, $offset, $submission_id);
        return;
    }

    if ($submission_id > 0 || (isset($_GET['view']) && $_GET['view'] === 'forms')) {
        tk_cf7dbc_render_forms_overview($paged, $per_page, $offset, $submission_id);
        return;
    }

    tk_cf7dbc_render_dashboard();
}

function tk_cf7dbc_render_dashboard() {
    $stats = tk_cf7dbc_get_stats();
    $recent = tk_cf7dbc_get_recent_submissions(5);
    $top_forms = tk_cf7dbc_get_top_forms_stats(5);
    $all_forms = tk_cf7dbc_get_contact_forms();
    $forms_count = count($all_forms);
    ?>
    <div class="wrap cf7dbc-wrap">
        <?php tk_cf7dbc_render_page_heading(__('CF7 Database', 'tool-kits'), __('Browse saved Contact Form 7 messages and export submissions.', 'tool-kits')); ?>
        <div class="cf7dbc-header">
            <div class="cf7dbc-header-content">
                <div>
                    <h2><?php esc_html_e('Saved submissions', 'tool-kits'); ?></h2>
                    <p class="description" data-cf7-unread-summary><?php printf(esc_html__('%d unread submissions.', 'tool-kits'), $stats['unread']); ?></p>
                </div>
                <div class="cf7dbc-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tool-kits-cf7-collection&view=forms')); ?>" class="button">
                        <span class="dashicons dashicons-list-view" style="margin-right: 8px;"></span>
                        <?php esc_html_e('Browse Forms', 'tool-kits'); ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="cf7dbc-dashboard-grid">
            <div class="cf7dbc-stat-card">
                <div class="cf7dbc-stat-icon blue">
                    <span class="dashicons dashicons-database"></span>
                </div>
                <div class="cf7dbc-stat-content">
                    <h3><?php esc_html_e('Total Submissions', 'tool-kits'); ?></h3>
                    <div class="value"><?php echo number_format_i18n($stats['total']); ?></div>
                </div>
            </div>

            <div class="cf7dbc-stat-card">
                <div class="cf7dbc-stat-icon green">
                    <span class="dashicons dashicons-email-alt"></span>
                </div>
                <div class="cf7dbc-stat-content">
                    <h3><?php esc_html_e('Unread Messages', 'tool-kits'); ?></h3>
                    <div class="value" data-cf7-unread-count><?php echo number_format_i18n($stats['unread']); ?></div>
                </div>
            </div>

            <div class="cf7dbc-stat-card">
                <div class="cf7dbc-stat-icon orange">
                    <span class="dashicons dashicons-shield"></span>
                </div>
                <div class="cf7dbc-stat-content">
                    <h3><?php esc_html_e('Spam Detected', 'tool-kits'); ?></h3>
                    <div class="value"><?php echo number_format_i18n($stats['spam'] + $stats['bot']); ?></div>
                </div>
            </div>

            <div class="cf7dbc-stat-card">
                <div class="cf7dbc-stat-icon purple">
                    <span class="dashicons dashicons-feedback"></span>
                </div>
                <div class="cf7dbc-stat-content">
                    <h3><?php esc_html_e('Forms', 'tool-kits'); ?></h3>
                    <div class="value"><?php echo number_format_i18n($forms_count); ?></div>
                </div>
            </div>
        </div>

        <div class="cf7dbc-dashboard-row" >
            <div class="cf7dbc-widget">
                <div class="cf7dbc-widget-header">
                    <h2><?php esc_html_e('Recent Submissions', 'tool-kits'); ?></h2>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tool-kits-cf7-collection&view=forms')); ?>" class="button button-small"><?php esc_html_e('View All', 'tool-kits'); ?></a>
                </div>
                <div class="cf7dbc-widget-content">
                    <div class="cf7dbc-recent-list">
                        <?php if (empty($recent)) : ?>
                            <div style="padding: 40px; text-align: center; color: #64748b;">
                                <?php esc_html_e('No submissions yet.', 'tool-kits'); ?>
                            </div>
                        <?php else : ?>
                            <?php foreach ($recent as $item) : ?>
                                <?php
                                $detail_url = admin_url('admin.php?page=tool-kits-cf7-collection&form_id=' . absint($item->form_id) . '&submission_id=' . absint($item->id));
                                $is_unread = tk_cf7dbc_is_unread($item);
                                ?>
                                <div class="cf7dbc-recent-item" data-cf7-submission="<?php echo esc_attr($item->id); ?>">
                                    <div class="cf7dbc-recent-info">
                                        <h4><?php echo esc_html($item->form_title); ?></h4>
                                        <p>
                                            <span class="dashicons dashicons-clock" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></span>
                                            <?php echo esc_html($item->submitted_at); ?>
                                        </p>
                                    </div>
                                    <div class="cf7dbc-recent-actions">
                                        <?php if ($is_unread) : ?>
                                            <span class="cf7dbc-badge cf7dbc-badge-unread" style="margin-right: 8px;"><?php esc_html_e('New', 'tool-kits'); ?></span>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url($detail_url); ?>" class="button button-small tk-cf7dbc-view" data-submission-id="<?php echo esc_attr($item->id); ?>"><?php esc_html_e('View', 'tool-kits'); ?></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="cf7dbc-widget">
                <div class="cf7dbc-widget-header">
                    <h2><?php esc_html_e('Top Forms', 'tool-kits'); ?></h2>
                </div>
                <div class="cf7dbc-widget-content">
                    <div class="cf7dbc-form-stats">
                        <?php if (empty($top_forms)) : ?>
                            <div style="padding: 40px; text-align: center; color: #64748b;">
                                <?php esc_html_e('No data available.', 'tool-kits'); ?>
                            </div>
                        <?php else : ?>
                            <?php
                            $max_count = 0;
                            foreach ($top_forms as $tf) {
                                if ($tf->count > $max_count) $max_count = $tf->count;
                            }
                            ?>
                            <?php foreach ($top_forms as $tf) : ?>
                                <?php
                                $percentage = $max_count > 0 ? ($tf->count / $max_count) * 100 : 0;
                                ?>
                                <div class="cf7dbc-form-stat-item">
                                    <div class="cf7dbc-form-stat-label">
                                        <span><?php echo esc_html($tf->form_title); ?></span>
                                        <span><?php echo number_format_i18n($tf->count); ?></span>
                                    </div>
                                    <div class="cf7dbc-progress-bg">
                                        <div class="cf7dbc-progress-bar" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php tk_cf7dbc_render_modal(); ?>
    </div>
    <?php
}

function tk_cf7dbc_render_forms_overview($paged, $per_page, $offset, $open_id = 0) {
    $export_url = wp_nonce_url(admin_url('admin.php?page=tool-kits-cf7-collection&tk_cf7dbc_export=1'), 'tk_cf7dbc_export');
    ?>
    <div class="wrap cf7dbc-wrap">
        <?php tk_cf7dbc_render_page_heading(__('Form List', 'tool-kits'), __('Browse the submissions saved for each Contact Form 7 form.', 'tool-kits')); ?>
        <?php tk_cf7dbc_deleted_notice(); ?>
        <div class="cf7dbc-actions">
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tool-kits-cf7-collection')); ?>"><?php esc_html_e('Dashboard', 'tool-kits'); ?></a>
            <a href="<?php echo esc_url($export_url); ?>" class="button button-primary"><?php esc_html_e('Export Excel', 'tool-kits'); ?></a>
        </div>
        <form method="get">
            <input type="hidden" name="page" value="tool-kits-cf7-collection">
            <input type="hidden" name="view" value="forms">
            <?php tk_cf7dbc_render_list_table('forms'); ?>
        </form>
        <?php tk_cf7dbc_render_modal($open_id); ?>
    </div>
    <?php
}

function tk_cf7dbc_get_contact_forms() {
    return get_posts([
        'post_type'      => 'wpcf7_contact_form',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);
}

function tk_cf7dbc_render_form_submissions($form_id, $paged, $per_page, $offset, $open_id = 0) {
    global $wpdb;
    $form_title = get_the_title($form_id);
    if (!$form_title) {
        $form_title = (string) $wpdb->get_var($wpdb->prepare('SELECT form_title FROM ' . tk_cf7dbc_table_name() . ' WHERE form_id = %d ORDER BY submitted_at DESC LIMIT 1', $form_id));
    }
    $export_url = wp_nonce_url(admin_url('admin.php?page=tool-kits-cf7-collection&tk_cf7dbc_export=1&form_id=' . absint($form_id)), 'tk_cf7dbc_export');
    ?>
    <div class="wrap cf7dbc-wrap">
        <?php tk_cf7dbc_render_page_heading(__('Message List', 'tool-kits'), $form_title ?: __('Untitled', 'tool-kits')); ?>
        <?php tk_cf7dbc_deleted_notice(); ?>
        <div class="cf7dbc-actions">
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tool-kits-cf7-collection&view=forms')); ?>"><?php esc_html_e('Back to form list', 'tool-kits'); ?></a>
            <a href="<?php echo esc_url($export_url); ?>" class="button button-primary"><?php esc_html_e('Export This Form Excel', 'tool-kits'); ?></a>
        </div>
        <form method="get">
            <input type="hidden" name="page" value="tool-kits-cf7-collection">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
            <?php tk_cf7dbc_render_list_table('messages', $form_id); ?>
        </form>
        <?php tk_cf7dbc_render_modal($open_id); ?>
    </div>
    <?php
}

/** Keep internal Tool Kits fields out of the reader, including nested field groups. */
function tk_cf7dbc_visible_message_fields($data) {
    $fields = array();
    foreach (is_array($data) ? $data : array() as $key => $value) {
        if (strpos(strtolower(ltrim(trim((string) $key), '_')), 'tk_') === 0) { continue; }
        $fields[$key] = is_array($value) ? tk_cf7dbc_visible_message_fields($value) : $value;
    }
    return $fields;
}

/** Recognize common CF7 field names; preserve every other visible field below the message. */
function tk_cf7dbc_inbox_data($data) {
    $fields = tk_cf7dbc_visible_message_fields($data);
    $inbox = array('name' => '', 'email' => '', 'subject' => '', 'message' => '');
    $aliases = array(
        'name' => array('your-name', 'name', 'full-name', 'fullname', 'sender-name', 'nama', 'nama-lengkap'),
        'email' => array('your-email', 'email', 'email-address', 'sender-email'),
        'subject' => array('your-subject', 'subject', 'email-subject', 'subjek'),
        'message' => array('your-message', 'message', 'message-body', 'comments', 'comment', 'pesan', 'komentar'),
    );
    foreach ($aliases as $part => $names) {
        foreach ($names as $name) {
            foreach ($fields as $key => $value) {
                if (strtolower(str_replace('_', '-', trim((string) $key))) === $name) {
                    $inbox[$part] = tk_cf7dbc_format_submission_value($value);
                    unset($fields[$key]);
                    break 2;
                }
            }
        }
    }
    $inbox['fields'] = $fields;
    return $inbox;
}

/** Escaped email-style reader; the WordPress Thickbox supplies the dialog chrome. */
function tk_cf7dbc_render_submission_detail($row) {
    $inbox = tk_cf7dbc_inbox_data(json_decode($row->data, true));
    $subject = trim($inbox['subject']) !== '' ? $inbox['subject'] : ($row->form_title ?: __('Message Content', 'tool-kits'));
    $metadata = array(
        __('Submission ID', 'tool-kits') => esc_html($row->id),
        __('Date', 'tool-kits') => esc_html($row->submitted_at),
        __('Read Status', 'tool-kits') => tk_cf7dbc_get_read_status_badge($row),
        __('Read At', 'tool-kits') => $row->read_at ? esc_html($row->read_at) : '&mdash;',
        __('IP', 'tool-kits') => esc_html($row->user_ip),
        __('Spam Status', 'tool-kits') => tk_cf7dbc_get_spam_status_badge($row),
        __('Spam Score', 'tool-kits') => esc_html((int) ($row->spam_score ?? 0)),
        __('Detection Reasons', 'tool-kits') => esc_html(tk_cf7dbc_get_spam_reasons_text($row)),
        __('Page', 'tool-kits') => $row->page_url ? '<a href="' . esc_url($row->page_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($row->page_url) . '</a>' : '&mdash;',
        __('User Agent', 'tool-kits') => esc_html($row->user_agent),
    );
    ?>
    <div class="cf7dbc-message-detail">
        <article class="cf7dbc-email" aria-labelledby="cf7dbc-email-subject">
            <header class="cf7dbc-email-header">
                <h2 id="cf7dbc-email-subject"><?php echo esc_html($subject); ?></h2>
                <dl class="cf7dbc-email-envelope">
                    <div><dt><?php esc_html_e('From', 'tool-kits'); ?></dt><dd>
                        <?php if (trim($inbox['name']) !== '') : ?><strong class="cf7dbc-email-sender"><?php echo esc_html($inbox['name']); ?></strong><?php endif; ?>
                        <?php if (trim($inbox['email']) !== '') : ?><span class="cf7dbc-email-address">&lt;<?php echo esc_html($inbox['email']); ?>&gt;</span><?php endif; ?>
                        <?php if (trim($inbox['name'] . $inbox['email']) === '') : ?><?php esc_html_e('Unknown sender', 'tool-kits'); ?><?php endif; ?>
                    </dd></div>
                    <div><dt><?php esc_html_e('Date', 'tool-kits'); ?></dt><dd><?php echo esc_html($row->submitted_at); ?></dd></div>
                    <div><dt><?php esc_html_e('Form', 'tool-kits'); ?></dt><dd><?php echo esc_html($row->form_title ?: __('Untitled', 'tool-kits')); ?></dd></div>
                </dl>
            </header>
            <div class="cf7dbc-email-body">
                <h3 class="screen-reader-text"><?php esc_html_e('Message Content', 'tool-kits'); ?></h3>
                <?php if (trim($inbox['message']) !== '') : ?>
                    <div class="cf7dbc-email-message"><?php echo esc_html($inbox['message']); ?></div>
                <?php elseif (!$inbox['fields']) : ?>
                    <p><?php esc_html_e('No message content.', 'tool-kits'); ?></p>
                <?php endif; ?>
                <?php if ($inbox['fields']) : ?>
                    <div class="cf7dbc-email-additional">
                        <?php if (trim($inbox['message']) !== '') : ?><h3><?php esc_html_e('Additional details', 'tool-kits'); ?></h3><?php endif; ?>
                        <dl class="cf7dbc-email-fields">
                        <?php foreach ($inbox['fields'] as $key => $value) : ?>
                            <div><dt><?php echo esc_html(ucwords(str_replace(array('-', '_'), ' ', preg_replace('/^your[-_]/i', '', (string) $key)))); ?></dt><dd><?php echo esc_html(tk_cf7dbc_format_submission_value($value)); ?></dd></div>
                        <?php endforeach; ?>
                        </dl>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <details class="cf7dbc-technical-details">
            <summary><?php esc_html_e('Submission details', 'tool-kits'); ?></summary>
            <table class="widefat striped"><tbody>
            <?php foreach ($metadata as $label => $value) : ?>
                <tr><th scope="row"><?php echo esc_html($label); ?></th><td><?php echo wp_kses_post($value); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </details>
    </div>
    <?php
}

function tk_cf7dbc_mark_submission_as_read($row) {
    if (!tk_cf7dbc_is_unread($row)) {
        return;
    }

    global $wpdb;

    $wpdb->update(
        tk_cf7dbc_table_name(),
        [
            'read_status' => 'read',
            'read_at'     => current_time('mysql'),
        ],
        ['id' => absint($row->id)],
        ['%s', '%s'],
        ['%d']
    );
}

function tk_cf7dbc_is_unread($row) {
    return !isset($row->read_status) || $row->read_status !== 'read';
}

function tk_cf7dbc_get_read_status_badge($row) {
    if (tk_cf7dbc_is_unread($row)) {
        return sprintf(
            '<span class="cf7dbc-badge cf7dbc-badge-unread">%s</span>',
            esc_html__('Unread', 'tool-kits')
        );
    }

    return sprintf(
        '<span class="cf7dbc-badge cf7dbc-badge-read">%s</span>',
        esc_html__('Read', 'tool-kits')
    );
}

function tk_cf7dbc_get_spam_status_badge($row) {
    $status = isset($row->spam_status) ? sanitize_key($row->spam_status) : 'clean';
    $score = isset($row->spam_score) ? (int) $row->spam_score : 0;
    $labels = [
        'clean'      => __('Clean', 'tool-kits'),
        'suspicious' => __('Suspicious', 'tool-kits'),
        'bot'        => __('Bot', 'tool-kits'),
        'spam'       => __('Spam/Bot', 'tool-kits'),
    ];
    $colors = [
        'clean'      => '#0a7f36',
        'suspicious' => '#996800',
        'bot'        => '#6b46c1',
        'spam'       => '#b32d2e',
    ];

    if (!isset($labels[$status])) {
        $status = 'clean';
    }

    return sprintf(
        '<span class="cf7dbc-badge" style="background:%1$s; color:#fff;">%2$s (%3$d)</span>',
        esc_attr($colors[$status]),
        esc_html($labels[$status]),
        $score
    );
}

function tk_cf7dbc_get_spam_reasons_text($row) {
    if (empty($row->spam_reasons)) {
        return __('No spam indicators detected.', 'tool-kits');
    }

    $reasons = json_decode($row->spam_reasons, true);

    if (!is_array($reasons) || empty($reasons)) {
        return __('No spam indicators detected.', 'tool-kits');
    }

    return implode(', ', array_map('sanitize_text_field', $reasons));
}

/**
 * Renders the header branding section.
 */
function tk_cf7dbc_export_excel() {
    if (!tk_cf7_database_can_manage()) {
        wp_die(esc_html__('Access denied.', 'tool-kits'));
    }

    check_admin_referer('tk_cf7dbc_export');

    global $wpdb;

    $table_name = tk_cf7dbc_table_name();
    $form_id_filter = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;

    if ($form_id_filter > 0) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_name} WHERE form_id = %d ORDER BY submitted_at DESC", $form_id_filter));
    } else {
        $rows = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY submitted_at DESC");
    }

    $field_headers = [];
    $export_rows = [];

    foreach ($rows as $row) {
        $data = json_decode($row->data, true);
        $clean_data = [];

        if (is_array($data)) {
            foreach ($data as $field => $value) {
                if (!tk_cf7dbc_should_export_field($field)) {
                    continue;
                }

                $clean_data[$field] = $value;
                $field_headers[$field] = $field;
            }
        }

        $export_rows[] = [
            'row'  => $row,
            'data' => $clean_data,
        ];
    }

    $base_headers = ['ID', 'Form ID', 'Form Title', 'Submitted At', 'Read Status', 'Read At', 'User IP', 'Page URL', 'Spam Status'];
    $headers = array_merge($base_headers, array_values($field_headers));
    $excel_rows = [];

    foreach ($export_rows as $export_row) {
        $row = $export_row['row'];
        $data = $export_row['data'];
        $excel_row = [
            $row->id,
            $row->form_id,
            $row->form_title,
            $row->submitted_at,
            tk_cf7dbc_is_unread($row) ? 'unread' : 'read',
            isset($row->read_at) ? $row->read_at : '',
            $row->user_ip,
            $row->page_url,
            isset($row->spam_status) ? $row->spam_status : 'clean',
        ];

        foreach ($field_headers as $field) {
            $excel_row[] = isset($data[$field]) ? $data[$field] : '';
        }

        $excel_rows[] = $excel_row;
    }

    $filename = tk_cf7dbc_get_export_filename();
    $excel_path = tk_cf7dbc_create_excel_file($headers, $excel_rows, $filename);

    if (is_wp_error($excel_path)) {
        wp_die(esc_html($excel_path->get_error_message()));
    }

    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($excel_path));
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($excel_path);
    @unlink($excel_path);
    exit;
}
