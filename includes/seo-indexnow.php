<?php
if (!defined('ABSPATH')) { exit; }

function tk_indexnow_init(): void {
    add_action('admin_post_tk_indexnow_save', 'tk_indexnow_save');
    add_action('admin_post_tk_indexnow_send', 'tk_indexnow_send_now');
    add_action('transition_post_status', 'tk_indexnow_on_transition', 10, 3);
    add_action('tk_indexnow_process_queue', 'tk_indexnow_process_queue');
    add_action('init', 'tk_indexnow_maybe_render_key', 0);
}

function tk_indexnow_enabled(): bool {
    return tk_license_features_enabled() && (int) tk_get_option('indexnow_enabled', 0) === 1;
}

function tk_indexnow_key(): string {
    $key = preg_replace('/[^a-zA-Z0-9-]/', '', (string) tk_get_option('indexnow_key', ''));
    return is_string($key) ? substr($key, 0, 128) : '';
}

function tk_indexnow_ensure_key(): string {
    $key = tk_indexnow_key();
    if (strlen($key) < 8) {
        $key = wp_generate_password(32, false, false);
        tk_update_option('indexnow_key', $key);
    }
    return $key;
}

function tk_indexnow_key_url(): string {
    $key = tk_indexnow_key();
    return $key !== '' ? home_url('/' . rawurlencode($key) . '.txt') : '';
}

function tk_indexnow_maybe_render_key(): void {
    if (!tk_indexnow_enabled() || is_admin()) {
        return;
    }
    $key = tk_indexnow_key();
    if ($key === '') {
        return;
    }
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (untrailingslashit($path) !== '/' . $key . '.txt') {
        return;
    }
    status_header(200);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo esc_html($key);
    exit;
}

function tk_indexnow_queue(): array {
    $queue = tk_get_option('indexnow_queue', array());
    return is_array($queue) ? $queue : array();
}

function tk_indexnow_enqueue_url(string $url, string $change = 'updated'): void {
    if (!tk_indexnow_enabled()) {
        return;
    }
    $url = esc_url_raw($url);
    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if ($url === '' || $home_host === '' || $url_host !== $home_host) {
        return;
    }
    $queue = tk_indexnow_queue();
    $queue[hash('sha256', $url)] = array(
        'url' => $url,
        'change' => in_array($change, array('created', 'updated', 'deleted'), true) ? $change : 'updated',
        'queued_at' => time(),
    );
    if (count($queue) > 500) {
        uasort($queue, function($a, $b) {
            return (int) ($a['queued_at'] ?? 0) <=> (int) ($b['queued_at'] ?? 0);
        });
        $queue = array_slice($queue, -500, null, true);
    }
    tk_update_option('indexnow_queue', $queue);
    if (!wp_next_scheduled('tk_indexnow_process_queue')) {
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'tk_indexnow_process_queue');
    }
}

function tk_indexnow_on_transition(string $new_status, string $old_status, $post): void {
    if (!is_object($post) || wp_is_post_revision((int) $post->ID) || wp_is_post_autosave((int) $post->ID)) {
        return;
    }
    $post_type = get_post_type_object((string) $post->post_type);
    if (!$post_type || !$post_type->public || $post->post_type === 'attachment') {
        return;
    }
    if ($new_status === 'publish') {
        tk_indexnow_enqueue_url((string) get_permalink($post), $old_status === 'publish' ? 'updated' : 'created');
    } elseif ($old_status === 'publish' && in_array($new_status, array('trash', 'draft', 'private'), true)) {
        tk_indexnow_enqueue_url((string) get_permalink($post), 'deleted');
    }
}

function tk_indexnow_submit(array $items): array {
    $key = tk_indexnow_ensure_key();
    $urls = array_values(array_unique(array_filter(array_map(function($item) {
        return is_array($item) ? esc_url_raw((string) ($item['url'] ?? '')) : '';
    }, $items))));
    $urls = array_slice($urls, 0, 100);
    if (empty($urls)) {
        return array('ok' => true, 'code' => 0, 'submitted' => 0, 'message' => 'Queue is empty.');
    }
    $payload = array(
        'host' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
        'key' => $key,
        'keyLocation' => tk_indexnow_key_url(),
        'urlList' => $urls,
    );
    $response = wp_safe_remote_post('https://api.indexnow.org/indexnow', array(
        'timeout' => 8,
        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
        'body' => wp_json_encode($payload),
        'data_format' => 'body',
    ));
    if (is_wp_error($response)) {
        return array('ok' => false, 'code' => 0, 'submitted' => 0, 'message' => $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    return array(
        'ok' => in_array($code, array(200, 202), true),
        'code' => $code,
        'submitted' => in_array($code, array(200, 202), true) ? count($urls) : 0,
        'message' => in_array($code, array(200, 202), true) ? 'IndexNow accepted the URL batch.' : 'IndexNow returned HTTP ' . $code . '.',
        'urls' => $urls,
    );
}

function tk_indexnow_process_queue(): void {
    if (!tk_indexnow_enabled()) {
        return;
    }
    $queue = tk_indexnow_queue();
    if (empty($queue)) {
        return;
    }
    $batch = array_slice($queue, 0, 100, true);
    $result = tk_indexnow_submit($batch);
    $result['checked_at'] = time();
    tk_update_option('indexnow_last_result', $result);
    if (!empty($result['ok'])) {
        foreach (array_keys($batch) as $key) {
            unset($queue[$key]);
        }
        tk_update_option('indexnow_queue', $queue);
    }
    if (!empty($queue) && !wp_next_scheduled('tk_indexnow_process_queue')) {
        wp_schedule_single_event(time() + (!empty($result['ok']) ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS), 'tk_indexnow_process_queue');
    }
}

function tk_indexnow_save(): void {
    tk_require_admin_post('tk_indexnow_save');
    $enabled = !empty($_POST['indexnow_enabled']) ? 1 : 0;
    tk_update_option('indexnow_enabled', $enabled);
    if ($enabled) {
        tk_indexnow_ensure_key();
    } else {
        $timestamp = wp_next_scheduled('tk_indexnow_process_queue');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'tk_indexnow_process_queue');
        }
    }
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_indexnow_saved' => 1), admin_url('admin.php')) . '#indexnow');
    exit;
}

function tk_indexnow_send_now(): void {
    tk_require_admin_post('tk_indexnow_send');
    tk_indexnow_process_queue();
    wp_safe_redirect(add_query_arg(array('page' => 'tool-kits-seo', 'tk_indexnow_sent' => 1), admin_url('admin.php')) . '#indexnow');
    exit;
}

function tk_indexnow_render_panel(): void {
    $enabled = tk_indexnow_enabled();
    $queue = tk_indexnow_queue();
    $last = tk_get_option('indexnow_last_result', array());
    $last = is_array($last) ? $last : array();
    ?>
    <div class="tk-card tk-tab-panel" data-panel-id="indexnow">
        <h3>IndexNow</h3>
        <p>Notify participating search engines after public content changes. URLs are queued on save and sent later by WP-Cron, so editor and frontend requests stay fast.</p>
        <?php if (isset($_GET['tk_indexnow_saved'])) { tk_notice('IndexNow settings saved.', 'success'); } ?>
        <?php if (isset($_GET['tk_indexnow_sent'])) { tk_notice('IndexNow queue processed.', !empty($last['ok']) ? 'success' : 'warning'); } ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_indexnow_save'); ?>
            <input type="hidden" name="action" value="tk_indexnow_save">
            <?php tk_render_switch('indexnow_enabled', 'Enable background IndexNow', 'Queue at most 500 changed URLs and submit batches of 100 outside page requests.', $enabled ? 1 : 0); ?>
            <p><strong>Verification URL:</strong> <?php echo tk_indexnow_key_url() !== '' ? '<code>' . esc_html(tk_indexnow_key_url()) . '</code>' : 'Generated when enabled.'; ?></p>
            <p><button class="button button-primary">Save IndexNow Settings</button></p>
        </form>
        <hr>
        <p><strong>Queued URLs:</strong> <?php echo esc_html((string) count($queue)); ?></p>
        <?php if (!empty($last)) : ?>
            <p class="description">Last delivery: <?php echo !empty($last['checked_at']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $last['checked_at'])) : '-'; ?> | HTTP <?php echo esc_html((string) ($last['code'] ?? 0)); ?> | <?php echo esc_html((string) ($last['message'] ?? '')); ?></p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php tk_nonce_field('tk_indexnow_send'); ?>
            <input type="hidden" name="action" value="tk_indexnow_send">
            <button class="button" <?php disabled(!$enabled || empty($queue)); ?>>Process Queue Now</button>
        </form>
    </div>
    <?php
}
