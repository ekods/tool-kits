<?php
if (!defined('ABSPATH')) { exit; }

function tk_geo_batch_targets(): array {
    $targets = array(array('id' => 0, 'url' => home_url('/'), 'title' => 'Homepage'));
    $types = array_values(array_intersect(get_post_types(array('public' => true), 'names'), tk_seo_selected_post_types()));
    if (!$types) { return $targets; }
    $posts = get_posts(array('post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'modified', 'order' => 'DESC', 'has_password' => false));
    $seen = array(home_url('/') => true);
    foreach ($posts as $post) {
        if (!is_post_type_viewable($post->post_type)) { continue; }
        $url = get_permalink($post->ID);
        if (!$url || isset($seen[$url])) { continue; }
        $seen[$url] = true;
        $targets[] = array('id' => (int) $post->ID, 'url' => $url, 'title' => get_the_title($post));
    }
    return $targets;
}

function tk_geo_batch_assets(): void {
    if (($_GET['page'] ?? '') !== 'tool-kits-geo' || !tk_is_admin_user()) { return; }
    $version = TK_VERSION . '-' . tk_asset_version('assets/geo-audit-batch.js');
    $src = add_query_arg('tk_build', $version, TK_URL . 'assets/geo-audit-batch.js');
    wp_enqueue_script('tk-geo-batch', $src, array(), $version, true);
}

function tk_geo_batch_authorize(): void {
    check_ajax_referer('tk_geo_batch');
    if (!tk_is_admin_user() || !tk_license_features_enabled()) { wp_send_json_error(array('message' => 'Forbidden'), 403); }
}

function tk_geo_batch_report(array $items, int $total): array {
    $summary = tk_seo_geo_audit_summary($items);
    return array('scanned_at' => time(), 'checked_urls' => count($items), 'requested_urls' => $total, 'incomplete' => count($items) < $total, 'average_score' => $summary['average_score'], 'issue_count' => $summary['issue_count'], 'items' => $items);
}

function tk_geo_batch_start(): void {
    tk_geo_batch_authorize();
    $targets = tk_geo_batch_targets();
    $mode = $_POST['mode'] ?? '';
    $report = (array) tk_get_option('seo_geo_audit_report', array());
    $existing = isset($report['items']) && is_array($report['items']) ? $report['items'] : array();
    $existing_total = (int) ($report['requested_urls'] ?? count($existing));
    if (in_array($mode, array('report-selected', 'failed'), true)) {
        $report = (array) tk_get_option('seo_geo_audit_report', array());
        $existing = $report['items'] ?? array();
        $existing_total = (int) ($report['requested_urls'] ?? count($existing));
        $requested = isset($_POST['urls']) && is_array($_POST['urls']) ? array_filter($_POST['urls'], 'is_string') : array();
        $urls = array();
        foreach ($existing as $item) {
            $failed = !isset($item['score']) || (int) ($item['status'] ?? 0) < 200 || (int) ($item['status'] ?? 0) >= 400;
            if (($mode === 'failed' && $failed) || ($mode === 'report-selected' && in_array($item['url'], $requested, true))) { $urls[] = $item['url']; }
        }
        $targets = array_values(array_filter($targets, function ($target) use ($urls) { return in_array($target['url'], $urls, true); }));
    } elseif ($mode !== 'all') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', $_POST['ids']) : array();
        $targets = array_values(array_filter($targets, function ($target) use ($ids) { return in_array($target['id'], $ids, true); }));
    }
    if (!$targets) { wp_send_json_error(array('message' => 'Select at least one URL.'), 400); }
    $token = wp_generate_password(24, false, false);
    $key = 'tk_geo_batch_' . get_current_user_id() . '_' . $token;
    $state = array('targets' => $targets, 'items' => array(), 'existing' => $existing, 'existing_total' => $existing_total, 'retry' => $mode === 'failed');
    set_transient($key, $state, HOUR_IN_SECONDS);
    wp_send_json_success(array('token' => $token, 'total' => count($targets)));
}

function tk_geo_batch_step(): void {
    tk_geo_batch_authorize();
    $token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
    if (!preg_match('/^[a-zA-Z0-9]{24}$/', $token)) { wp_send_json_error(array('message' => 'Invalid audit session.'), 400); }
    $key = 'tk_geo_batch_' . get_current_user_id() . '_' . $token;
    $state = get_transient($key);
    if (!is_array($state)) { wp_send_json_error(array('message' => 'Audit session expired. Start again.'), 410); }
    $index = count($state['items']);
    if (isset($state['targets'][$index])) {
        $target = $state['targets'][$index];
        $state['items'][] = tk_seo_geo_audit_url($target['url'], 20, !empty($state['retry']));
        set_transient($key, $state, HOUR_IN_SECONDS);
        $merged = array();
        foreach (array_merge($state['existing'] ?? array(), $state['items']) as $item) { $merged[$item['url']] = $item; }
        $report_total = max(count($merged), (int) ($state['existing_total'] ?? 0), count($state['targets']));
        tk_update_option('seo_geo_audit_report', tk_geo_batch_report(array_values($merged), $report_total));
    }
    $done = count($state['items']) >= count($state['targets']);
    if ($done) { delete_transient($key); }
    wp_send_json_success(array('done' => $done, 'checked' => count($state['items']), 'total' => count($state['targets']), 'item' => $state['items'][$index] ?? null));
}

function tk_geo_batch_controls(): void {
    ?>
    <div id="tk-geo-batch" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_geo_batch')); ?>" data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        <p><label><input type="checkbox" class="tk-geo-select-all"> Select all</label></p>
        <div style="max-height:280px;overflow:auto;border:1px solid #dcdcde;padding:12px;">
            <?php foreach (tk_geo_batch_targets() as $target) : ?>
                <p><label style="overflow-wrap:anywhere;"><input type="checkbox" class="tk-geo-target" value="<?php echo esc_attr((string) $target['id']); ?>"> <?php echo esc_html($target['title']); ?> <small><?php echo esc_html($target['url']); ?></small></label></p>
            <?php endforeach; ?>
        </div>
        <p>
            <button type="button" class="button button-primary tk-geo-run-all">Run All</button>
            <button type="button" class="button tk-geo-run-selected">Run Selected</button>
            <button type="button" class="button tk-geo-stop" disabled>Stop</button>
        </p>
        <progress class="tk-geo-progress" value="0" max="1" style="width:100%;" aria-label="GEO audit progress"></progress>
        <p class="tk-geo-status" role="status" aria-live="polite"></p>
        <ul class="tk-geo-batch-results"></ul>
    </div>
    <?php
}
