<?php
if (!defined('ABSPATH')) { exit; }

add_action('admin_post_tk_ps_diag_run', 'tk_ps_diag_run_handler');

function tk_ps_diag_last_result_key(): string {
    $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    return 'tk_ps_diag_last_result_' . $uid;
}

function tk_ps_diag_cache_key(string $url, string $strategy): string {
    $strategy = strtolower($strategy) === 'desktop' ? 'desktop' : 'mobile';
    return 'tk_ps_diag_cache_' . md5(strtolower(trim($url)) . '|' . $strategy);
}

function tk_ps_diag_get_last_result(): array {
    $cached = get_transient(tk_ps_diag_last_result_key());
    return is_array($cached) ? $cached : array();
}

function tk_ps_diag_fetch_pagespeed(string $url, string $strategy = 'mobile'): array {
    $strategy = strtolower($strategy) === 'desktop' ? 'desktop' : 'mobile';
    $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    $query = array(
        'url' => $url,
        'strategy' => $strategy,
        'category' => 'performance',
    );
    $api_key = trim((string) tk_get_option('ps_diag_api_key', ''));
    if ($api_key !== '') {
        $query['key'] = $api_key;
    }

    $request_url = add_query_arg($query, $endpoint);
    $response = wp_remote_get($request_url, array('timeout' => 25, 'redirection' => 2));
    if (is_wp_error($response)) {
        return array('ok' => false, 'code' => 0, 'message' => $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code < 200 || $code >= 300) {
        $message = 'Google PageSpeed API returned HTTP ' . $code;
        $data = is_string($body) ? json_decode($body, true) : null;
        if (is_array($data) && isset($data['error']['message']) && is_string($data['error']['message']) && $data['error']['message'] !== '') {
            $message .= ': ' . $data['error']['message'];
        } elseif ($code === 429) {
            $message = 'Google PageSpeed quota exceeded (HTTP 429). Tambahkan API key atau coba lagi beberapa saat.';
        }
        return array('ok' => false, 'code' => $code, 'message' => $message);
    }
    if (!is_string($body) || $body === '') {
        return array('ok' => false, 'code' => $code, 'message' => 'Empty response from Google PageSpeed API.');
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return array('ok' => false, 'code' => $code, 'message' => 'Invalid JSON response from Google PageSpeed API.');
    }

    $lhr = isset($data['lighthouseResult']) && is_array($data['lighthouseResult']) ? $data['lighthouseResult'] : array();
    $categories = isset($lhr['categories']) && is_array($lhr['categories']) ? $lhr['categories'] : array();
    $performance = isset($categories['performance']) && is_array($categories['performance']) ? $categories['performance'] : array();
    $score_raw = isset($performance['score']) ? (float) $performance['score'] : 0.0;
    $score = (int) round($score_raw * 100);

    $audits = isset($lhr['audits']) && is_array($lhr['audits']) ? $lhr['audits'] : array();
    $metric_map = array(
        'first-contentful-paint' => 'FCP',
        'largest-contentful-paint' => 'LCP',
        'speed-index' => 'Speed Index',
        'total-blocking-time' => 'TBT',
        'cumulative-layout-shift' => 'CLS',
        'interactive' => 'TTI',
    );
    $metrics = array();
    foreach ($metric_map as $key => $label) {
        if (!isset($audits[$key]) || !is_array($audits[$key])) {
            continue;
        }
        $metrics[] = array(
            'label' => $label,
            'value' => isset($audits[$key]['displayValue']) ? (string) $audits[$key]['displayValue'] : '-',
        );
    }

    $opportunities = array();
    foreach ($audits as $audit) {
        if (!is_array($audit)) {
            continue;
        }
        $mode = isset($audit['scoreDisplayMode']) ? (string) $audit['scoreDisplayMode'] : '';
        $audit_score = isset($audit['score']) ? (float) $audit['score'] : 1.0;
        if ($mode !== 'numeric' || $audit_score >= 0.9) {
            continue;
        }
        $title = isset($audit['title']) ? (string) $audit['title'] : '';
        if ($title === '') {
            continue;
        }
        $opportunities[] = array(
            'title' => $title,
            'value' => isset($audit['displayValue']) ? (string) $audit['displayValue'] : '',
            'score' => (int) round($audit_score * 100),
        );
    }
    usort($opportunities, function($a, $b) {
        $sa = isset($a['score']) ? (int) $a['score'] : 100;
        $sb = isset($b['score']) ? (int) $b['score'] : 100;
        return $sa <=> $sb;
    });
    $opportunities = array_slice($opportunities, 0, 5);

    return array(
        'ok' => true,
        'tested_url' => $url,
        'strategy' => $strategy,
        'score' => max(0, min(100, $score)),
        'metrics' => $metrics,
        'opportunities' => $opportunities,
        'tested_at' => current_time('mysql'),
    );
}

function tk_ps_diag_run_handler() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_ps_diag_run');

    $url = trim((string) tk_post('ps_test_url', home_url('/')));
    $strategy = sanitize_key((string) tk_post('ps_test_strategy', 'mobile'));
    $strategy = $strategy === 'desktop' ? 'desktop' : 'mobile';
    $api_key = trim((string) tk_post('ps_api_key', ''));
    tk_update_option('ps_diag_api_key', $api_key);

    if ($url === '' || !wp_http_validate_url($url)) {
        wp_redirect(add_query_arg(array(
            'page' => 'tool-kits-optimization',
            'tk_tab' => 'diagnostics',
            'tk_ps_status' => 'fail',
            'tk_ps_msg' => 'URL tidak valid.',
        ), admin_url('admin.php')));
        exit;
    }

    $cache_key = tk_ps_diag_cache_key($url, $strategy);
    $cached_result = get_transient($cache_key);
    if (is_array($cached_result) && !empty($cached_result['ok'])) {
        set_transient(tk_ps_diag_last_result_key(), $cached_result, MINUTE_IN_SECONDS * 30);
        wp_redirect(add_query_arg(array(
            'page' => 'tool-kits-optimization',
            'tk_tab' => 'diagnostics',
            'tk_ps_status' => 'ok',
            'tk_ps_msg' => 'PageSpeed test loaded from cache (15 menit).',
        ), admin_url('admin.php')));
        exit;
    }

    $result = tk_ps_diag_fetch_pagespeed($url, $strategy);
    if (empty($result['ok'])) {
        $message = isset($result['message']) ? (string) $result['message'] : 'Gagal mengambil data PageSpeed.';
        $code = isset($result['code']) ? (int) $result['code'] : 0;
        if ($code === 429 && !empty(tk_ps_diag_get_last_result())) {
            wp_redirect(add_query_arg(array(
                'page' => 'tool-kits-optimization',
                'tk_tab' => 'diagnostics',
                'tk_ps_status' => 'warn',
                'tk_ps_msg' => $message . ' Menampilkan hasil terakhir yang tersimpan.',
            ), admin_url('admin.php')));
            exit;
        }
        wp_redirect(add_query_arg(array(
            'page' => 'tool-kits-optimization',
            'tk_tab' => 'diagnostics',
            'tk_ps_status' => 'fail',
            'tk_ps_msg' => $message,
        ), admin_url('admin.php')));
        exit;
    }

    set_transient(tk_ps_diag_last_result_key(), $result, MINUTE_IN_SECONDS * 30);
    set_transient($cache_key, $result, MINUTE_IN_SECONDS * 15);
    wp_redirect(add_query_arg(array(
        'page' => 'tool-kits-optimization',
        'tk_tab' => 'diagnostics',
        'tk_ps_status' => 'ok',
        'tk_ps_msg' => 'PageSpeed test selesai.',
    ), admin_url('admin.php')));
    exit;
}

function tk_ps_diag_parse_list($raw): array {
    if (!is_string($raw)) {
        return array();
    }
    $parts = preg_split('/[\r\n,]+/', $raw);
    if (!is_array($parts)) {
        return array();
    }
    $out = array();
    foreach ($parts as $part) {
        $item = sanitize_key(trim((string) $part));
        if ($item === '') {
            continue;
        }
        $out[] = $item;
    }
    return array_values(array_unique($out));
}

function tk_ps_diag_build_report(): array {
    $active = array();
    $conflicts = array();

    $page_cache = (int) tk_get_option('page_cache_enabled', 0) === 1;
    $minify_html = (int) tk_get_option('minify_html_enabled', 0) === 1;
    $minify_inline_js = (int) tk_get_option('minify_inline_js', 1) === 1;
    $lazy_load = (int) tk_get_option('lazy_load_enabled', 0) === 1;
    $lazy_eager = max(0, (int) tk_get_option('lazy_load_eager_images', 2));
    $lazy_script_delay = tk_ps_diag_parse_list((string) tk_get_option('lazy_load_script_delay', ''));
    $assets_critical = (int) tk_get_option('assets_critical_css_enabled', 0) === 1;
    $assets_critical_css = trim((string) tk_get_option('assets_critical_css', ''));
    $assets_defer_css = tk_ps_diag_parse_list((string) tk_get_option('assets_defer_css_handles', ''));
    $assets_preload_css = tk_ps_diag_parse_list((string) tk_get_option('assets_preload_css_handles', ''));
    $assets_font_preload = trim((string) tk_get_option('assets_preload_fonts', ''));
    $assets_js_delay_enabled = (int) tk_get_option('assets_js_delay_enabled', 0) === 1;
    $assets_js_delay_handles = tk_ps_diag_parse_list((string) tk_get_option('assets_js_delay_handles', ''));
    $assets_lcp_bg_preload = (int) tk_get_option('assets_lcp_bg_preload_enabled', 1) === 1;
    $assets_preconnect = (int) tk_get_option('assets_preconnect_auto_enabled', 1) === 1;
    $webp_convert = (int) tk_get_option('webp_convert_enabled', 0) === 1;
    $image_opt = (int) tk_get_option('image_opt_enabled', 0) === 1;

    if ($page_cache) {
        $active[] = array('name' => 'Page cache', 'detail' => 'ON (anonymous page cache active)');
    }
    if ($minify_html) {
        $active[] = array('name' => 'HTML minify', 'detail' => 'ON');
    }
    if ($minify_inline_js) {
        $active[] = array('name' => 'Inline JS minify', 'detail' => 'ON');
    }
    if ($lazy_load) {
        $active[] = array('name' => 'Lazy load', 'detail' => 'ON (eager images: ' . $lazy_eager . ')');
    }
    if ($assets_critical) {
        $active[] = array('name' => 'Critical CSS', 'detail' => 'ON (' . strlen($assets_critical_css) . ' chars)');
    }
    if (!empty($assets_defer_css)) {
        $active[] = array('name' => 'Defer CSS handles', 'detail' => implode(', ', $assets_defer_css));
    }
    if (!empty($assets_preload_css)) {
        $active[] = array('name' => 'Preload CSS handles', 'detail' => implode(', ', $assets_preload_css));
    }
    if ($assets_font_preload !== '') {
        $active[] = array('name' => 'Font preload list', 'detail' => 'Configured');
    }
    if ($assets_js_delay_enabled) {
        $active[] = array('name' => 'Assets JS delay', 'detail' => !empty($assets_js_delay_handles) ? implode(', ', $assets_js_delay_handles) : 'Enabled (all non-protected handles)');
    }
    if (!empty($lazy_script_delay)) {
        $active[] = array('name' => 'Lazy script delay', 'detail' => implode(', ', $lazy_script_delay));
    }
    if ($assets_lcp_bg_preload) {
        $active[] = array('name' => 'Auto LCP background preload', 'detail' => 'ON');
    }
    if ($assets_preconnect) {
        $active[] = array('name' => 'Auto preconnect', 'detail' => 'ON');
    }
    if ($webp_convert) {
        $active[] = array('name' => 'Auto WebP conversion', 'detail' => 'ON');
    }
    if ($image_opt) {
        $active[] = array('name' => 'Image optimizer', 'detail' => 'ON');
    }

    if (!empty($assets_preload_css) && !$assets_js_delay_enabled && empty($assets_js_delay_handles) && empty($lazy_script_delay)) {
        $conflicts[] = array(
            'severity' => 'medium',
            'title' => 'Preload aktif, script berat belum ditunda',
            'detail' => 'Ada CSS preload tapi tidak ada delay script dari Assets maupun Lazy Load. Main-thread masih bisa berat saat first paint.',
        );
    }

    if (!empty($assets_defer_css) && !empty($assets_preload_css)) {
        $same_handles = array_values(array_intersect($assets_defer_css, $assets_preload_css));
        if (!empty($same_handles)) {
            $conflicts[] = array(
                'severity' => 'medium',
                'title' => 'Handle CSS dipakai di defer + preload sekaligus',
                'detail' => 'Handle: ' . implode(', ', $same_handles) . '. Gunakan salah satu strategi per handle agar tidak dobel.',
            );
        }
    }

    if ($minify_html && !$minify_inline_js && (!empty($assets_preload_css) || $assets_lcp_bg_preload)) {
        $conflicts[] = array(
            'severity' => 'medium',
            'title' => 'Minify HTML aktif tanpa minify inline JS',
            'detail' => 'Dengan preload aktif, inline script besar masih berpotensi menambah blocking time.',
        );
    }

    if ($lazy_load && $lazy_eager > 6) {
        $conflicts[] = array(
            'severity' => 'info',
            'title' => 'Eager images terlalu tinggi',
            'detail' => 'Nilai eager image saat ini: ' . $lazy_eager . '. Terlalu tinggi dapat menurunkan manfaat lazy load.',
        );
    }

    if (!$page_cache && ($minify_html || $lazy_load || $assets_critical || $webp_convert || $image_opt || !empty($assets_preload_css))) {
        $conflicts[] = array(
            'severity' => 'info',
            'title' => 'Optimasi frontend aktif, page cache OFF',
            'detail' => 'Banyak optimasi aktif tapi cache halaman belum diaktifkan. Potensi TTFB/CPU tetap tinggi.',
        );
    }

    if ($assets_js_delay_enabled && !empty($lazy_script_delay)) {
        $conflicts[] = array(
            'severity' => 'info',
            'title' => 'Dua mekanisme delay script aktif',
            'detail' => 'Assets JS delay dan Lazy Load script delay berjalan bersamaan. Valid, tapi cek agar tidak over-delay script penting.',
        );
    }

    if ($assets_critical && $assets_critical_css === '') {
        $conflicts[] = array(
            'severity' => 'medium',
            'title' => 'Critical CSS ON tapi konten kosong',
            'detail' => 'Fitur aktif tanpa isi CSS kritikal. Gunakan Generate Critical CSS atau isi manual.',
        );
    }

    $score = 60;
    if ($page_cache) { $score += 10; }
    if ($lazy_load) { $score += 7; }
    if ($assets_critical && $assets_critical_css !== '') { $score += 6; }
    if (!empty($assets_preload_css)) { $score += 4; }
    if ($assets_js_delay_enabled || !empty($lazy_script_delay)) { $score += 6; }
    if ($webp_convert || $image_opt) { $score += 4; }
    if ($minify_html && $minify_inline_js) { $score += 3; }

    foreach ($conflicts as $issue) {
        $severity = isset($issue['severity']) ? (string) $issue['severity'] : 'info';
        if ($severity === 'medium') {
            $score -= 10;
            continue;
        }
        $score -= 4;
    }

    if ($score < 0) { $score = 0; }
    if ($score > 100) { $score = 100; }

    $score_label = 'Needs work';
    if ($score >= 85) {
        $score_label = 'Excellent';
    } elseif ($score >= 70) {
        $score_label = 'Good';
    } elseif ($score >= 50) {
        $score_label = 'Fair';
    }

    return array(
        'active' => $active,
        'conflicts' => $conflicts,
        'score' => $score,
        'score_label' => $score_label,
    );
}

function tk_render_page_speed_diagnostics_panel() {
    $report = tk_ps_diag_build_report();
    $active = isset($report['active']) && is_array($report['active']) ? $report['active'] : array();
    $conflicts = isset($report['conflicts']) && is_array($report['conflicts']) ? $report['conflicts'] : array();
    $score = isset($report['score']) ? (int) $report['score'] : 0;
    $score_label = isset($report['score_label']) ? (string) $report['score_label'] : 'Needs work';
    $score_badge = 'tk-badge';
    if ($score >= 85) {
        $score_badge = 'tk-badge tk-on';
    } elseif ($score >= 70) {
        $score_badge = 'tk-badge tk-on';
    } elseif ($score >= 50) {
        $score_badge = 'tk-badge tk-warn';
    } else {
        $score_badge = 'tk-badge tk-warn';
    }
    $ps_status = isset($_GET['tk_ps_status']) ? sanitize_key((string) $_GET['tk_ps_status']) : '';
    $ps_msg = isset($_GET['tk_ps_msg']) ? sanitize_text_field((string) $_GET['tk_ps_msg']) : '';
    $ps_api_key = (string) tk_get_option('ps_diag_api_key', '');
    $ps_result = tk_ps_diag_get_last_result();
    $ps_test_url = home_url('/');
    if (!empty($ps_result['tested_url']) && is_string($ps_result['tested_url'])) {
        $ps_test_url = $ps_result['tested_url'];
    }
    $ps_strategy = !empty($ps_result['strategy']) && $ps_result['strategy'] === 'desktop' ? 'desktop' : 'mobile';
    ?>
    <div class="tk-card">
        <h2>Page Speed Diagnostics</h2>
        <p>Ringkasan setting performa yang aktif dan potensi konflik konfigurasi.</p>
        <p>
            <span class="<?php echo esc_attr($score_badge); ?>">Health Score: <?php echo esc_html((string) $score); ?>/100 (<?php echo esc_html($score_label); ?>)</span>
            <span class="tk-badge tk-on"><?php echo esc_html((string) count($active)); ?> Active</span>
            <span class="tk-badge <?php echo !empty($conflicts) ? 'tk-warn' : 'tk-on'; ?>"><?php echo esc_html((string) count($conflicts)); ?> Potential Conflicts</span>
        </p>

        <h3 style="margin-top:20px;">Active Settings</h3>
        <?php if (empty($active)) : ?>
            <p class="description">Belum ada setting optimasi performa yang aktif.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:35%;">Setting</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active as $item) : ?>
                        <tr>
                            <td><strong><?php echo esc_html((string) $item['name']); ?></strong></td>
                            <td><?php echo esc_html((string) $item['detail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 style="margin-top:20px;">Potential Conflicts</h3>
        <?php if (empty($conflicts)) : ?>
            <p><span class="tk-badge tk-on">No issue detected</span></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:12%;">Severity</th>
                        <th style="width:33%;">Issue</th>
                        <th>Recommendation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conflicts as $item) : ?>
                        <?php
                        $severity = isset($item['severity']) ? (string) $item['severity'] : 'info';
                        $badge_class = $severity === 'medium' ? 'tk-warn' : '';
                        ?>
                        <tr>
                            <td>
                                <span class="tk-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html(strtoupper($severity)); ?></span>
                            </td>
                            <td><strong><?php echo esc_html((string) $item['title']); ?></strong></td>
                            <td><?php echo esc_html((string) $item['detail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p class="description" style="margin-top:12px;">
            Shortcut: <a href="<?php echo esc_url(tk_admin_url('tool-kits-cache')); ?>">Cache</a>,
            <a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#minify'); ?>">Minify</a>,
            <a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#lazy-load'); ?>">Lazy Load</a>,
            <a href="<?php echo esc_url(tk_admin_url('tool-kits-optimization') . '#assets'); ?>">Assets</a>
        </p>

        <hr style="margin:20px 0;">
        <h3>Google PageSpeed Test</h3>
        <p class="description">Jalankan analisa live seperti Google PageSpeed Insights untuk URL yang dipilih.</p>
        <?php if ($ps_msg !== '') : ?>
            <?php
            $notice_type = 'error';
            if ($ps_status === 'ok') {
                $notice_type = 'success';
            } elseif ($ps_status === 'warn') {
                $notice_type = 'warning';
            }
            tk_notice($ps_msg, $notice_type);
            ?>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
            <?php tk_nonce_field('tk_ps_diag_run'); ?>
            <input type="hidden" name="action" value="tk_ps_diag_run">
            <input type="hidden" name="tk_tab" value="diagnostics">
            <p>
                <label><strong>URL</strong></label><br>
                <input class="large-text" type="url" name="ps_test_url" value="<?php echo esc_attr($ps_test_url); ?>" placeholder="https://example.com" required>
            </p>
            <p>
                <label><strong>Strategy</strong></label><br>
                <select name="ps_test_strategy">
                    <option value="mobile" <?php selected('mobile', $ps_strategy); ?>>Mobile</option>
                    <option value="desktop" <?php selected('desktop', $ps_strategy); ?>>Desktop</option>
                </select>
            </p>
            <p>
                <label><strong>Google API Key (optional)</strong></label><br>
                <input class="large-text" type="text" name="ps_api_key" value="<?php echo esc_attr($ps_api_key); ?>" placeholder="AIza...">
                <small>Disarankan isi API key agar limit lebih stabil dan mengurangi HTTP 429.</small>
            </p>
            <p><button class="button button-primary">Run PageSpeed Test</button></p>
        </form>
        <?php if (!empty($ps_result)) : ?>
            <?php
            $live_score = isset($ps_result['score']) ? (int) $ps_result['score'] : 0;
            $live_label = $live_score >= 90 ? 'Excellent' : ($live_score >= 70 ? 'Good' : ($live_score >= 50 ? 'Fair' : 'Needs work'));
            $live_badge = $live_score >= 70 ? 'tk-on' : 'tk-warn';
            $tested_at = isset($ps_result['tested_at']) ? (string) $ps_result['tested_at'] : '-';
            ?>
            <p>
                <span class="tk-badge <?php echo esc_attr($live_badge); ?>">Google Score: <?php echo esc_html((string) $live_score); ?>/100 (<?php echo esc_html($live_label); ?>)</span>
                <span class="tk-badge"><?php echo esc_html(strtoupper($ps_strategy)); ?></span>
                <span class="description">Tested at: <?php echo esc_html($tested_at); ?></span>
            </p>
            <?php if (!empty($ps_result['metrics']) && is_array($ps_result['metrics'])) : ?>
                <table class="widefat striped" style="margin-top:10px;">
                    <thead><tr><th style="width:30%;">Metric</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($ps_result['metrics'] as $metric) : ?>
                        <tr>
                            <td><strong><?php echo esc_html((string) ($metric['label'] ?? '')); ?></strong></td>
                            <td><?php echo esc_html((string) ($metric['value'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if (!empty($ps_result['opportunities']) && is_array($ps_result['opportunities'])) : ?>
                <h4 style="margin-top:14px;">Top Opportunities</h4>
                <ul class="tk-list">
                <?php foreach ($ps_result['opportunities'] as $op) : ?>
                    <li>
                        <strong><?php echo esc_html((string) ($op['title'] ?? '')); ?></strong>
                        <?php if (!empty($op['value'])) : ?> - <?php echo esc_html((string) $op['value']); ?><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

function tk_render_optimization_page($forced_tab = '') {
    if (!tk_is_admin_user()) return;
    $allowed_tabs = array('diagnostics', 'hide-login', 'minify', 'webp', 'image-opt', 'seo', 'lazy-load', 'assets', 'uploads', 'user-id');
    $requested = isset($_GET['tk_tab']) ? sanitize_key($_GET['tk_tab']) : '';
    $active_tab = in_array($requested, $allowed_tabs, true) ? $requested : 'diagnostics';
    if ($forced_tab !== '' && in_array($forced_tab, $allowed_tabs, true)) {
        $active_tab = $forced_tab;
    }
    $saved = isset($_GET['tk_saved']) ? sanitize_key($_GET['tk_saved']) : '';
    $progress = isset($_GET['tk_webp_progress']) ? sanitize_text_field(wp_unslash($_GET['tk_webp_progress'])) : '';
    $done = isset($_GET['tk_webp_done']) ? sanitize_key($_GET['tk_webp_done']) : '';
    ?>
    <div class="wrap tk-wrap">
        <h1>Optimization</h1>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('Settings saved.', 'success'); ?>
        <?php endif; ?>
        <?php if ($progress !== '') : ?>
            <?php tk_notice('WebP generation: ' . esc_html($progress), 'info'); ?>
        <?php endif; ?>
        <?php if ($done === '1') : ?>
            <?php tk_notice('WebP generation completed.', 'success'); ?>
        <?php endif; ?>
        <div class="tk-tabs tk-optimization-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'diagnostics' ? ' is-active' : ''; ?>" data-panel="diagnostics">Diagnostics</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'hide-login' ? ' is-active' : ''; ?>" data-panel="hide-login">Hide Login</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'minify' ? ' is-active' : ''; ?>" data-panel="minify">Minify</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'webp' ? ' is-active' : ''; ?>" data-panel="webp">Auto WebP</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'image-opt' ? ' is-active' : ''; ?>" data-panel="image-opt">Image Optimizer</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'seo' ? ' is-active' : ''; ?>" data-panel="seo">SEO</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'lazy-load' ? ' is-active' : ''; ?>" data-panel="lazy-load">Lazy Load</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'assets' ? ' is-active' : ''; ?>" data-panel="assets">Assets</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'uploads' ? ' is-active' : ''; ?>" data-panel="uploads">Uploads</button>
                <button type="button" class="tk-tabs-nav-button<?php echo $active_tab === 'user-id' ? ' is-active' : ''; ?>" data-panel="user-id">User ID</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-tab-panel<?php echo $active_tab === 'diagnostics' ? ' is-active' : ''; ?>" data-panel-id="diagnostics">
                    <?php tk_render_page_speed_diagnostics_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'hide-login' ? ' is-active' : ''; ?>" data-panel-id="hide-login">
                    <?php tk_render_hide_login_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'minify' ? ' is-active' : ''; ?>" data-panel-id="minify">
                    <?php tk_render_minify_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'webp' ? ' is-active' : ''; ?>" data-panel-id="webp">
                    <?php tk_render_webp_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'image-opt' ? ' is-active' : ''; ?>" data-panel-id="image-opt">
                    <?php tk_render_image_opt_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'seo' ? ' is-active' : ''; ?>" data-panel-id="seo">
                    <?php tk_render_seo_opt_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'lazy-load' ? ' is-active' : ''; ?>" data-panel-id="lazy-load">
                    <?php tk_render_lazy_load_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'assets' ? ' is-active' : ''; ?>" data-panel-id="assets">
                    <?php tk_render_assets_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'uploads' ? ' is-active' : ''; ?>" data-panel-id="uploads">
                    <?php tk_render_upload_limits_panel(); ?>
                </div>
                <div class="tk-tab-panel<?php echo $active_tab === 'user-id' ? ' is-active' : ''; ?>" data-panel-id="user-id">
                    <?php tk_render_user_id_change_panel(); ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var wrapper = document.querySelector('.tk-optimization-tabs');
            if (!wrapper) { return; }
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
            wrapper.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    if (panelId) {
                        window.location.hash = panelId;
                        activateTab(panelId);
                    }
                });
            });
            var initial = getPanelFromHash();
            if (initial && wrapper.querySelector('.tk-tab-panel[data-panel-id="' + initial + '"]')) {
                activateTab(initial);
            }
        })();
        </script>
    </div>
    <?php
}
