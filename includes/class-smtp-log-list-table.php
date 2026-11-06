<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('WP_List_Table')) require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/** Core table rendering, server-side search, sorting and paging for SMTP attempts. */
class TK_SMTP_Log_List_Table extends WP_List_Table {
    private $entries;
    public function __construct($entries) {
        $this->entries = $entries;
        parent::__construct(array('singular' => 'smtp-attempt', 'plural' => 'smtp-attempts', 'ajax' => false));
    }
    private function query($key) {
        return isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
    }
    public function get_columns() {
        return array('time' => __('Time', 'tool-kits'), 'recipient' => __('Sender & Recipient', 'tool-kits'),
            'status' => __('Status', 'tool-kits'), 'message' => __('Reason / Message', 'tool-kits'),
            'details' => __('Technical details', 'tool-kits'), 'action' => __('Action', 'tool-kits'));
    }
    protected function get_sortable_columns() {
        return array('time' => array('time', true), 'recipient' => array('recipient', false),
            'status' => array('status', false), 'message' => array('message', false));
    }
    public function prepare_items() {
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns(), 'recipient');
        $query = $this->query('s');
        $entries = array_values(array_filter($this->entries, static function($entry) use ($query) {
            return $query === '' || stripos(wp_json_encode($entry), $query) !== false;
        }));
        $orderby = $this->query('orderby');
        if (!in_array($orderby, array('time', 'recipient', 'status', 'message'), true)) $orderby = 'time';
        $direction = strtolower($this->query('order')) === 'asc' ? 1 : -1;
        usort($entries, static function($a, $b) use ($orderby, $direction) {
            $result = $orderby === 'time' ? ((int) ($a['time'] ?? 0) <=> (int) ($b['time'] ?? 0))
                : strnatcasecmp((string) ($a[$orderby] ?? ''), (string) ($b[$orderby] ?? ''));
            return $result * $direction;
        });
        $this->set_pagination_args(array('total_items' => count($entries), 'per_page' => 10));
        $this->items = array_slice($entries, ($this->get_pagenum() - 1) * 10, 10);
    }
    public function no_items() { echo esc_html__('No SMTP attempts match your search.', 'tool-kits'); }
    public function column_default($entry, $column_name) {
        $entry_time = isset($entry['time']) ? (int) $entry['time'] : 0;
        $entry_status = isset($entry['status']) ? (string) $entry['status'] : 'unknown';
        $entry_sender = isset($entry['sender']) ? (string) $entry['sender'] : '';
        $entry_recipient = isset($entry['recipient']) ? (string) $entry['recipient'] : '';
        $entry_message = isset($entry['message']) ? (string) $entry['message'] : '';
        $entry_reason = isset($entry['reason']) ? (string) $entry['reason'] : '';
        $entry_details = isset($entry['details']) && is_array($entry['details']) ? $entry['details'] : array();
        $status_class = $entry_status === 'success' ? 'tk-on' : ($entry_status === 'fail' ? 'tk-warn' : '');
        $status_label = ucfirst($entry_status);
        ob_start();
        switch ($column_name) {
            case 'time': ?>
                <div><?php echo esc_html(date_i18n('M d, Y', $entry_time)); ?></div>
                <div class="description"><?php echo esc_html(date_i18n('H:i:s', $entry_time)); ?></div>
                <?php break;
            case 'recipient': ?>
                <div style="font-size:12px;"><span style="color:var(--tk-muted);">From:</span> <code><?php echo esc_html($entry_sender); ?></code></div>
                <div style="font-size:12px; margin-top:4px;"><span style="color:var(--tk-muted);">To:</span> <code><?php echo esc_html($entry_recipient); ?></code></div>
                <?php break;
            case 'status': ?>
                <span class="tk-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                <?php break;
            case 'message': ?>
                <?php if ($entry_reason !== '') : ?>
                    <div style="color:#e74c3c; font-weight:600; font-size:11px; margin-bottom:4px;"><?php echo esc_html($entry_reason); ?></div>
                <?php endif; ?>
                <div style="font-size:11px; color:var(--tk-muted); font-style:italic;"><?php echo esc_html(wp_trim_words($entry_message, 15, '...')); ?></div>
                <?php break;
            case 'details': ?>
                <?php
                $details_formatted = tk_smtp_test_log_get_details_array($entry_details);
                $details_summary = trim(implode(' · ', array_filter(array(
                    trim((string) ($entry_details['smtp_host'] ?? '') . (!empty($entry_details['smtp_port']) ? ':' . $entry_details['smtp_port'] : ''), ':'),
                    strtoupper((string) ($entry_details['smtp_secure'] ?? '')),
                    (string) ($entry_details['smtp_auth_method'] ?? ''),
                    !empty($entry_details['smtp_user']) ? (string) $entry_details['smtp_user'] : ((($entry_details['smtp_auth'] ?? '') === 'on') ? 'no user' : ''),
                ))));
                ?>
                <?php if (!empty($details_formatted)) : ?>
                    <div class="tk-smtp-log-summary"><?php echo esc_html($details_summary !== '' ? $details_summary : '—'); ?></div>
                    <details class="tk-smtp-log-details">
                        <summary>All details</summary>
                        <dl>
                            <?php foreach ($details_formatted as $label => $val) : ?>
                                <dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($val !== '' ? (string) $val : '—'); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </details>
                <?php else : ?>
                    <span style="color:#94a3b8;">&mdash;</span>
                <?php endif; ?>
                <?php break;
            case 'action': ?>
                <?php if ($entry_recipient !== '') : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                        <?php tk_nonce_field('tk_smtp_test'); ?>
                        <input type="hidden" name="action" value="tk_smtp_test">
                        <input type="hidden" name="smtp_test_email" value="<?php echo esc_attr($entry_recipient); ?>">
                        <input type="hidden" name="smtp_test_message" value="<?php echo esc_attr($entry_message); ?>">
                        <button type="submit" class="button button-secondary button-small">Resend</button>
                    </form>
                <?php else : ?>
                    &mdash;
                <?php endif; ?>
                <?php break;
        }
        return ob_get_clean();
    }
    public function render() {
        echo '<form id="tk-smtp-log-search" method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="tool-kits-smtp"><input type="hidden" name="smtp_log" value="1">';
        $this->search_box(__('Search attempts', 'tool-kits'), 'tk-smtp-log');
        echo '</form>';
        // Keep row POST forms outside the search form.
        ob_start();
        $this->display();
        // Associate core's page-number input with the separate GET form.
        $html = str_replace("class='current-page'", "class='current-page' form='tk-smtp-log-search'", ob_get_clean());
        // Core's first-page link removes paged; keep the log tab open even on that URL.
        echo preg_replace_callback('/href=([\'"])(.*?)\1/', static function($match) {
            return 'href=' . $match[1] . $match[2] . '#log' . $match[1];
        }, $html);
    }
}
