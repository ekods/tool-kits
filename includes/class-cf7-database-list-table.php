<?php
if (!defined('ABSPATH')) { exit; }
if (!class_exists('WP_List_Table')) { require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; }

/** Native WordPress tables for forms and their saved submissions. */
class TK_CF7_Database_List_Table extends WP_List_Table {
    private $kind;
    private $form_id;
    private $form_stats = array();

    public function __construct($kind, $form_id = 0) {
        $this->kind = $kind;
        $this->form_id = absint($form_id);
        parent::__construct(array('singular' => 'cf7-submission', 'plural' => 'cf7-submissions', 'ajax' => false));
    }

    public function get_columns() {
        if ($this->kind === 'forms') {
            return array('form' => __('Form', 'tool-kits'), 'total' => __('Message Count', 'tool-kits'), 'latest' => __('Latest Message', 'tool-kits'));
        }
        return array('submission' => __('Message', 'tool-kits'), 'submitted_at' => __('Date', 'tool-kits'),
            'read_status' => __('Read Status', 'tool-kits'), 'spam_status' => __('Spam Status', 'tool-kits'),
            'user_ip' => __('IP', 'tool-kits'), 'page_url' => __('Page', 'tool-kits'));
    }

    public function prepare_items() {
        global $wpdb;
        $this->_column_headers = array($this->get_columns(), array(), array(), $this->kind === 'forms' ? 'form' : 'submission');
        $table = tk_cf7dbc_table_name();
        $per_page = 20;
        if ($this->kind === 'forms') {
            $forms = tk_cf7dbc_get_contact_forms();
            $this->form_stats = $wpdb->get_results("SELECT form_id, COUNT(*) AS total_submissions, MAX(submitted_at) AS latest_submission FROM {$table} GROUP BY form_id", OBJECT_K);
            $this->set_pagination_args(array('total_items' => count($forms), 'per_page' => $per_page));
            $this->items = array_slice($forms, ($this->get_pagenum() - 1) * $per_page, $per_page);
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $this->form_id));
            $this->set_pagination_args(array('total_items' => $total, 'per_page' => $per_page));
            $this->items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC, id DESC LIMIT %d OFFSET %d", $this->form_id, $per_page, ($this->get_pagenum() - 1) * $per_page));
        }
    }

    public function no_items() {
        echo esc_html($this->kind === 'forms' ? __('No Contact Form 7 forms found.', 'tool-kits') : __('No messages found for this form.', 'tool-kits'));
    }

    public function column_form($form) {
        $url = add_query_arg(array('page' => tk_cf7_database_collection_slug(), 'form_id' => absint($form->ID)), admin_url('admin.php'));
        return '<strong><a class="row-title" href="' . esc_url($url) . '">' . esc_html($form->post_title ?: __('Untitled', 'tool-kits')) . '</a></strong><div class="description">ID: ' . esc_html($form->ID) . '</div>';
    }

    public function column_submission($row) {
        $url = tk_cf7dbc_detail_url($row->id, $row->form_id);
        $view = '<a class="tk-cf7dbc-view" data-submission-id="' . esc_attr($row->id) . '" href="' . esc_url($url) . '">' . esc_html__('View', 'tool-kits') . '</a>';
        $delete = wp_nonce_url(add_query_arg(array('page' => tk_cf7_database_collection_slug(), 'form_id' => absint($row->form_id), 'tk_cf7dbc_delete' => absint($row->id)), admin_url('admin.php')), 'tk_cf7dbc_delete_' . absint($row->id));
        $delete_link = '<a class="submitdelete" href="' . esc_url($delete) . '" onclick="return confirm(\'' . esc_js(__('Delete this submission?', 'tool-kits')) . '\');">' . esc_html__('Delete', 'tool-kits') . '</a>';
        return '<strong><a class="row-title tk-cf7dbc-view" data-submission-id="' . esc_attr($row->id) . '" href="' . esc_url($url) . '">#' . esc_html($row->id) . '</a></strong>' . $this->row_actions(array('view' => $view, 'delete' => $delete_link));
    }

    public function column_default($row, $column) {
        if ($this->kind === 'forms') {
            $stats = $this->form_stats[$row->ID] ?? null;
            if ($column === 'total') { return esc_html(number_format_i18n($stats ? (int) $stats->total_submissions : 0)); }
            if ($column === 'latest') { return $stats && $stats->latest_submission ? esc_html($stats->latest_submission) : '&mdash;'; }
        } else {
            if ($column === 'read_status') { return tk_cf7dbc_get_read_status_badge($row); }
            if ($column === 'spam_status') { return tk_cf7dbc_get_spam_status_badge($row); }
            if ($column === 'page_url') { return $row->page_url ? '<a href="' . esc_url($row->page_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($row->page_url) . '</a>' : '&mdash;'; }
            if (in_array($column, array('submitted_at', 'user_ip'), true)) { return esc_html($row->$column); }
        }
        return '&mdash;';
    }

    public function single_row($row) {
        if ($this->kind === 'forms') { parent::single_row($row); return; }
        echo '<tr data-cf7-submission="' . esc_attr($row->id) . '" class="' . (tk_cf7dbc_is_unread($row) ? 'unread' : '') . '">';
        $this->single_row_columns($row);
        echo '</tr>';
    }
}
