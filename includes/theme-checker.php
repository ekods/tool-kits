<?php
if (!defined('ABSPATH')) { exit; }

function tk_render_theme_checker_page() {
    if (!tk_is_admin_user()) return;

    $theme = wp_get_theme();
    $parent = $theme->parent();
    $child_root = get_stylesheet_directory();
    $parent_root = get_template_directory();

    $has_child = $parent_root !== $child_root;
    $child_summary = $has_child ? tk_theme_checker_scan_dir($child_root, 'Child theme') : null;
    $parent_summary = $has_child ? tk_theme_checker_scan_dir($parent_root, 'Parent theme') : tk_theme_checker_scan_dir($parent_root, 'Theme');

    $largest = array();
    if (is_array($child_summary) && !empty($child_summary['largest'])) {
        $largest = array_merge($largest, $child_summary['largest']);
    }
    if (is_array($parent_summary) && !empty($parent_summary['largest'])) {
        $largest = array_merge($largest, $parent_summary['largest']);
    }
    usort($largest, function($a, $b) {
        return $b['size'] <=> $a['size'];
    });
    $largest = array_slice($largest, 0, 10);

    $warnings = tk_theme_checker_warnings($child_summary, $parent_summary);
    $roots = array();
    if ($has_child) {
        $roots[] = array('root' => $child_root, 'scope' => 'Child theme');
        $roots[] = array('root' => $parent_root, 'scope' => 'Parent theme');
    } else {
        $roots[] = array('root' => $parent_root, 'scope' => 'Theme');
    }
    $duplicates = tk_theme_checker_duplicate_files($roots);
    $risky = tk_theme_checker_risky_functions($roots);
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Theme Audit', 'tool-kits'), __('Analyze your active theme for security vulnerabilities and performance bottlenecks.', 'tool-kits'), 'dashicons-layout'); ?>

        <div class="tk-toolbar">
            <button type="button" class="button button-primary" id="tk-theme-checker-recheck">
                <?php esc_html_e('Recheck theme', 'tool-kits'); ?>
            </button>
            <span class="tk-toolbar-note">
                <?php esc_html_e('Re-run the scans to refresh all panels.', 'tool-kits'); ?>
            </span>
        </div>

        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="overview"><?php _e('Profile', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button" data-panel="summary"><?php _e('Summary', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button" data-panel="largest"><?php _e('Largest Files', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button" data-panel="duplicates"><?php _e('Duplicates', 'tool-kits'); ?></button>
                <button type="button" class="tk-tabs-nav-button" data-panel="risky"><?php _e('Risky Code', 'tool-kits'); ?></button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="overview">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                        <h2 style="margin:0;">Active Theme Profile</h2>
                        <div style="display:flex; gap:8px;">
                            <span class="tk-badge <?php echo $parent ? 'tk-on' : 'tk-off'; ?>"><?php echo $parent ? 'Child Theme' : 'Parent Theme'; ?></span>
                            <?php if (!empty($warnings)) : ?>
                                <span class="tk-badge tk-adv"><?php echo count($warnings); ?> Recommendations</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 200px 1fr; gap:24px; background:var(--tk-bg-soft); padding:24px; border-radius:16px; border:1px solid var(--tk-border-soft);">
                        <div style="width:200px; height:150px; border-radius:12px; overflow:hidden; border:1px solid var(--tk-border-soft); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                            <?php 
                            $screenshot = $theme->get_screenshot();
                            if ($screenshot) : ?>
                                <img src="<?php echo esc_url($screenshot); ?>" style="width:100%; height:100%; object-fit:cover;" alt="Theme Screenshot">
                            <?php else : ?>
                                <div style="width:100%; height:100%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; color:#94a3b8;">
                                    <span class="dashicons dashicons-format-image" style="font-size:40px; width:40px; height:40px;"></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 style="margin:0; font-size:24px; font-weight:800; color:var(--tk-text);"><?php echo esc_html($theme->get('Name')); ?></h3>
                            <div style="margin-top:8px; display:flex; gap:16px; font-size:13px; color:var(--tk-muted);">
                                <span><strong style="color:var(--tk-text);">Version:</strong> <?php echo esc_html($theme->get('Version')); ?></span>
                                <span><strong style="color:var(--tk-text);">Author:</strong> <?php echo $theme->get('Author'); ?></span>
                            </div>
                            <div style="margin-top:16px; font-size:13px; line-height:1.6; color:var(--tk-text);">
                                <?php echo wp_kses_post($theme->get('Description')); ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($warnings)) : ?>
                        <h2 style="margin-top:32px; display:flex; align-items:center; gap:8px;"><span class="dashicons dashicons-lightbulb" style="color:#f39c12;"></span> Recommendations</h2>
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-top:16px;">
                            <?php foreach ($warnings as $warning) : ?>
                                <div style="background:#fffbeb; border:1px solid #fef3c7; border-left:4px solid #f39c12; padding:12px 16px; border-radius:8px; display:flex; align-items:center; gap:12px;">
                                    <span class="dashicons dashicons-warning" style="color:#f39c12; font-size:18px; width:18px; height:18px;"></span>
                                    <span style="font-size:13px; color:#92400e; font-weight:500;"><?php echo esc_html($warning); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div style="margin-top:32px; background:rgba(39, 174, 96, 0.1); border:1px solid #27ae60; padding:16px; border-radius:12px; display:flex; align-items:center; gap:12px; color:#27ae60;">
                            <span class="dashicons dashicons-yes-alt" style="font-size:20px; width:20px; height:20px;"></span>
                            <span style="font-weight:600;">Your theme follows all analyzed best practices.</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="summary">
                    <h2>Theme Summary</h2>
                    <table class="widefat striped tk-table">
                        <thead><tr><th>Scope</th><th>Total size</th><th>Files</th><th>CSS</th><th>JS</th><th>CSS @import</th></tr></thead>
                        <tbody>
                            <?php if (is_array($child_summary)) : ?>
                                <?php echo tk_theme_checker_summary_row($child_summary); ?>
                            <?php endif; ?>
                            <?php if (is_array($parent_summary)) : ?>
                                <?php echo tk_theme_checker_summary_row($parent_summary); ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <h3 style="margin-top:20px;">CSS files</h3>
                    <?php echo tk_theme_checker_render_asset_list($child_summary, $parent_summary, 'css'); ?>

                    <h3 style="margin-top:20px;">JS files</h3>
                    <?php echo tk_theme_checker_render_asset_list($child_summary, $parent_summary, 'js'); ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="largest">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                        <h2 style="margin:0;">Largest Files</h2>
                        <span class="tk-badge tk-adv">Top 10</span>
                    </div>
                    <p class="description">Overview of the most resource-heavy files within your active theme.</p>
                    
                    <?php if (empty($largest)) : ?>
                        <p><em>No theme files found.</em></p>
                    <?php else : 
                        $max_size = 0;
                        foreach ($largest as $item) {
                            if ($item['size'] > $max_size) $max_size = $item['size'];
                        }
                    ?>
                        <div style="margin-top:20px;">
                            <?php foreach ($largest as $item) : 
                                $p = $max_size > 0 ? round(($item['size'] / $max_size) * 100) : 0;
                            ?>
                                <div style="margin-bottom:16px; background:var(--tk-bg-soft); padding:12px; border-radius:10px; border:1px solid var(--tk-border-soft);">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                        <div style="display:flex; align-items:center; gap:8px; max-width:75%;">
                                            <span class="dashicons dashicons-media-text" style="font-size:16px; color:var(--tk-muted);"></span>
                                            <code style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px; background:none; padding:0;"><?php echo esc_html($item['path']); ?></code>
                                        </div>
                                        <div style="text-align:right;">
                                            <span style="font-weight:700; font-size:13px; color:var(--tk-text);"><?php echo esc_html(size_format((int) $item['size'])); ?></span>
                                            <div style="font-size:10px; color:var(--tk-muted); text-transform:uppercase; letter-spacing:0.05em;"><?php echo esc_html($item['scope']); ?></div>
                                        </div>
                                    </div>
                                    <div class="tk-progress" style="height:6px; background:rgba(0,0,0,0.05);"><div class="tk-progress-bar" style="width:<?php echo $p; ?>%; background:linear-gradient(90deg, #1d4ed8, #60a5fa); opacity:<?php echo 0.5 + ($p/200); ?>;"></div></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="duplicates">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                        <h2 style="margin:0;">Duplicate PHP Files</h2>
                        <span class="tk-badge tk-medium">Redundancy Check</span>
                    </div>
                    <p class="description">Identical PHP files found in your theme (whitespace-sensitive). Cleaning these can improve maintainability.</p>
                    
                    <?php if (empty($duplicates)) : ?>
                        <p><em>No duplicate PHP files detected. Great job!</em></p>
                    <?php else : 
                        $total_wasted = 0;
                        $total_groups = count($duplicates);
                        foreach ($duplicates as $group) {
                            // Wasted = (count - 1) * individual_size
                            $individual_size = $group['size'] / $group['count'];
                            $total_wasted += ($group['count'] - 1) * $individual_size;
                        }
                    ?>
                        <div class="tk-rt-grid" style="margin-bottom:24px;">
                            <div class="tk-rt-card" style="padding:15px; border-left:4px solid #e67e22;">
                                <span class="dashicons dashicons-database-remove" style="font-size:20px; color:#e67e22;"></span>
                                <h4 style="font-size:10px;">Wasted Space</h4>
                                <div class="tk-rt-value" style="font-size:18px; color:#e67e22;"><?php echo size_format($total_wasted); ?></div>
                            </div>
                            <div class="tk-rt-card" style="padding:15px;">
                                <span class="dashicons dashicons-images-alt" style="font-size:20px;"></span>
                                <h4 style="font-size:10px;">Duplicate Groups</h4>
                                <div class="tk-rt-value" style="font-size:18px;"><?php echo number_format($total_groups); ?></div>
                            </div>
                        </div>

                        <div style="margin-top:20px;">
                            <?php foreach ($duplicates as $group) : 
                                $ind_size = $group['size'] / $group['count'];
                            ?>
                                <div class="tk-card" style="margin-bottom:16px; border-left:1px solid var(--tk-border-soft);">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                                        <div>
                                            <h4 style="margin:0; font-size:14px; color:var(--tk-text);">Duplicate Group</h4>
                                            <p style="margin:4px 0 0; font-size:11px; color:var(--tk-muted);"><?php echo (int) $group['count']; ?> identical copies detected</p>
                                        </div>
                                        <div style="text-align:right;">
                                            <div style="font-size:13px; font-weight:700;"><?php echo size_format($ind_size); ?> <span style="font-weight:400; font-size:11px; color:var(--tk-muted);">per file</span></div>
                                            <div style="font-size:11px; color:#e67e22; font-weight:600;">Saving: <?php echo size_format($ind_size * ($group['count'] - 1)); ?></div>
                                        </div>
                                    </div>
                                    <ul class="tk-list" style="margin-top:10px; font-size:12px; background:var(--tk-bg-soft); padding:10px; border-radius:6px;">
                                        <?php foreach ($group['paths'] as $path) : ?>
                                            <li style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                                                <span class="dashicons dashicons-arrow-right-alt2" style="font-size:14px; width:14px; height:14px; color:var(--tk-muted);"></span>
                                                <code><?php echo esc_html($path); ?></code>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description" style="font-size:11px;">Showing up to 20 groups (max 10 files per group).</p>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="risky">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                        <h2 style="margin:0;">Risky Functions</h2>
                        <span class="tk-badge tk-adv">Security Audit</span>
                    </div>
                    <p class="description">Scanning PHP files for functions often used in malware or obfuscated code.</p>
                    
                    <?php if (empty($risky)) : ?>
                        <div style="background:rgba(39, 174, 96, 0.1); border:1px solid #27ae60; padding:20px; border-radius:12px; text-align:center; margin-top:20px;">
                            <span class="dashicons dashicons-shield-alt" style="font-size:40px; width:40px; height:40px; color:#27ae60; margin-bottom:12px;"></span>
                            <h3 style="margin:0; color:#27ae60;">No Risky Functions Found</h3>
                            <p style="margin:8px 0 0; color:#27ae60;">Your theme does not appear to use eval, base64_decode, or shell_exec.</p>
                        </div>
                    <?php else : 
                        $total_risky = 0;
                        foreach ($risky as $r) $total_risky += $r['count'];
                    ?>
                        <div class="tk-rt-grid" style="margin-bottom:24px;">
                            <?php foreach ($risky as $row) : 
                                $is_critical = in_array($row['function'], array('eval', 'shell_exec'));
                            ?>
                                <div class="tk-rt-card" style="padding:15px; border-top:3px solid <?php echo $is_critical ? '#e74c3c' : '#f39c12'; ?>;">
                                    <h4 style="font-size:10px;"><?php echo esc_html($row['function']); ?></h4>
                                    <div class="tk-rt-value" style="font-size:20px; color:<?php echo $is_critical ? '#e74c3c' : '#f39c12'; ?>;"><?php echo number_format($row['count']); ?></div>
                                    <div style="font-size:10px; margin-top:4px; font-weight:600; color:<?php echo $is_critical ? '#e74c3c' : '#f39c12'; ?>;"><?php echo $is_critical ? 'CRITICAL' : 'WARNING'; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top:20px;">
                            <?php foreach ($risky as $row) : 
                                $is_critical = in_array($row['function'], array('eval', 'shell_exec'));
                            ?>
                                <div class="tk-card" style="margin-bottom:20px; border-left:4px solid <?php echo $is_critical ? '#e74c3c' : '#f39c12'; ?>;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                                        <div>
                                            <h3 style="margin:0; font-size:16px;"><code><?php echo esc_html($row['function']); ?>()</code></h3>
                                            <p style="margin:5px 0 0; font-size:12px; color:var(--tk-muted);"><?php echo esc_html($row['reason']); ?></p>
                                        </div>
                                        <span class="tk-badge <?php echo $is_critical ? 'tk-adv' : 'tk-medium'; ?>"><?php echo (int) $row['count']; ?> Matches</span>
                                    </div>
                                    <div style="background:var(--tk-bg-soft); padding:12px; border-radius:8px; border:1px solid var(--tk-border-soft); margin-top:10px;">
                                        <h4 style="margin:0 0 8px; font-size:11px; text-transform:uppercase; letter-spacing:0.05em;">Found in:</h4>
                                        <ul class="tk-list" style="font-size:12px; margin:0;">
                                            <?php foreach ($row['matches'] as $match) : ?>
                                                <li style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                                                    <span class="dashicons dashicons-warning" style="font-size:14px; width:14px; height:14px; color:<?php echo $is_critical ? '#e74c3c' : '#f39c12'; ?>;"></span>
                                                    <code><?php echo esc_html($match); ?></code>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if ($row['count'] > 20) : ?>
                                            <p style="margin-top:8px; font-size:10px; font-style:italic; color:var(--tk-muted);">Showing first 20 occurrences.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            function activateTab(panelId) {
                document.querySelectorAll('.tk-tab-panel').forEach(function(panel){
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
                });
                document.querySelectorAll('.tk-tabs-nav-button').forEach(function(btn){
                    btn.classList.toggle('is-active', btn.getAttribute('data-panel') === panelId);
                });
            }
            function getPanelFromHash() {
                var hash = window.location.hash || '';
                if (!hash) { return ''; }
                return hash.replace('#', '');
            }
            document.querySelectorAll('.tk-tabs-nav-button').forEach(function(button){
                button.addEventListener('click', function(){
                    var panelId = button.getAttribute('data-panel');
                    if (panelId) {
                        window.location.hash = panelId;
                        activateTab(panelId);
                    }
                });
            });
            var initial = getPanelFromHash();
            if (initial) {
                activateTab(initial);
            }
        })();
        (function(){
            var button = document.getElementById('tk-theme-checker-recheck');
            if (!button) {
                return;
            }
            button.addEventListener('click', function(){
                button.disabled = true;
                button.textContent = '<?php echo esc_js(__('Rechecking...', 'tool-kits')); ?>';
                window.location.reload();
            });
        })();
        </script>
    </div>
    <?php
}

function tk_theme_checker_summary_row($summary) {
    $css_text = $summary['css_count'] . ' files (' . size_format((int) $summary['css_size']) . ')';
    $js_text = $summary['js_count'] . ' files (' . size_format((int) $summary['js_size']) . ')';
    $imports = $summary['css_imports'];
    $imports_text = $imports > 0 ? (string) $imports : '-';
    $row = '<tr>';
    $row .= '<td>' . esc_html($summary['scope']) . '</td>';
    $row .= '<td>' . esc_html(size_format((int) $summary['size'])) . '</td>';
    $row .= '<td>' . esc_html((string) $summary['files']) . '</td>';
    $row .= '<td>' . esc_html($css_text) . '</td>';
    $row .= '<td>' . esc_html($js_text) . '</td>';
    $row .= '<td>' . esc_html($imports_text) . '</td>';
    $row .= '</tr>';
    return $row;
}

function tk_theme_checker_render_asset_list($child_summary, $parent_summary, $type) {
    $list = array();
    if (isset($child_summary[$type . '_files']) && is_array($child_summary[$type . '_files'])) {
        $list = array_merge($list, $child_summary[$type . '_files']);
    }
    if (is_array($parent_summary) && isset($parent_summary[$type . '_files']) && is_array($parent_summary[$type . '_files'])) {
        $list = array_merge($list, $parent_summary[$type . '_files']);
    }
    if (empty($list)) {
        return '<p><small>No ' . esc_html($type) . ' files found.</small></p>';
    }
    usort($list, function($a, $b) {
        return $b['size'] <=> $a['size'];
    });
    $list = array_slice($list, 0, 20);
    
    $max_size = 0;
    foreach ($list as $item) {
        if ($item['size'] > $max_size) $max_size = $item['size'];
    }

    $out = '<div style="margin-top:15px; display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">';
    foreach ($list as $item) {
        $p = $max_size > 0 ? round(($item['size'] / $max_size) * 100) : 0;
        $icon = ($type === 'css') ? 'dashicons-editor-code' : 'dashicons-media-spreadsheet';
        
        $out .= '<div style="background:var(--tk-bg-soft); padding:10px; border-radius:8px; border:1px solid var(--tk-border-soft);">';
        $out .= '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">';
        $out .= '<div style="display:flex; align-items:center; gap:6px; max-width:70%;">';
        $out .= '<span class="dashicons ' . esc_attr($icon) . '" style="font-size:14px; width:14px; height:14px; color:var(--tk-muted);"></span>';
        $out .= '<code style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:11px; background:none; padding:0;">' . esc_html($item['path']) . '</code>';
        $out .= '</div>';
        $out .= '<div style="text-align:right;">';
        $out .= '<span style="font-weight:600; font-size:12px;">' . esc_html(size_format((int) $item['size'])) . '</span>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '<div class="tk-progress" style="height:4px;"><div class="tk-progress-bar" style="width:' . $p . '%; opacity:' . (0.4 + ($p/200)) . ';"></div></div>';
        $out .= '<div style="font-size:9px; color:var(--tk-muted); text-transform:uppercase; margin-top:4px; letter-spacing:0.05em;">' . esc_html($item['scope']) . '</div>';
        $out .= '</div>';
    }
    $out .= '</div>';
    $out .= '<p class="description" style="font-size:11px; margin-top:10px;">Showing up to 20 files.</p>';
    return $out;
}

function tk_theme_checker_scan_dir($root, $scope) {
    $summary = array(
        'scope' => $scope,
        'size' => 0,
        'files' => 0,
        'css_count' => 0,
        'css_size' => 0,
        'js_count' => 0,
        'js_size' => 0,
        'css_imports' => 0,
        'css_files' => array(),
        'js_files' => array(),
        'largest' => array(),
    );
    if (!is_dir($root)) {
        return $summary;
    }

    $skip_dirs = array('node_modules', '.git', '.svn');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (!is_string($path) || $path === '') {
            continue;
        }
        $normalized_path = wp_normalize_path($path);
        $is_excluded = false;
        foreach ($skip_dirs as $skip) {
            if (strpos($normalized_path, '/' . $skip . '/') !== false) {
                $is_excluded = true;
                break;
            }
        }
        if ($is_excluded) {
            continue;
        }

        $size = (int) $file->getSize();
        $summary['files']++;
        $summary['size'] += $size;

        $ext = strtolower($file->getExtension());
        if ($ext === 'css') {
            $summary['css_count']++;
            $summary['css_size'] += $size;
            $summary['css_files'][] = array(
                'path' => str_replace(wp_normalize_path($root), '', wp_normalize_path($path)),
                'size' => $size,
                'scope' => $scope,
            );
            if ($size > 0 && $size < 200 * 1024) {
                $contents = @file_get_contents($path);
                if (is_string($contents) && strpos($contents, '@import') !== false) {
                    $summary['css_imports']++;
                }
            }
        } elseif ($ext === 'js') {
            $summary['js_count']++;
            $summary['js_size'] += $size;
            $summary['js_files'][] = array(
                'path' => str_replace(wp_normalize_path($root), '', wp_normalize_path($path)),
                'size' => $size,
                'scope' => $scope,
            );
        }

        $summary['largest'][] = array(
            'path' => str_replace(wp_normalize_path($root), '', wp_normalize_path($path)),
            'size' => $size,
            'scope' => $scope,
        );
    }

    usort($summary['largest'], function($a, $b) {
        return $b['size'] <=> $a['size'];
    });
    $summary['largest'] = array_slice($summary['largest'], 0, 10);
    return $summary;
}

function tk_theme_checker_warnings($child_summary, $parent_summary) {
    $warnings = array();
    $total = 0;
    if (is_array($child_summary)) {
        $total += $child_summary['size'];
    }
    if (is_array($parent_summary)) {
        $total += $parent_summary['size'];
    }
    if ($total > 10 * 1024 * 1024) {
        $warnings[] = 'Theme assets exceed 10 MB. Consider pruning unused assets.';
    }
    if ($child_summary['css_imports'] > 0 || (is_array($parent_summary) && $parent_summary['css_imports'] > 0)) {
        $warnings[] = 'CSS @import detected. This can add extra render-blocking requests.';
    }
    if ($child_summary['css_size'] > 1024 * 1024 || (is_array($parent_summary) && $parent_summary['css_size'] > 1024 * 1024)) {
        $warnings[] = 'Total CSS exceeds 1 MB. Consider splitting critical CSS.';
    }
    if ($child_summary['js_size'] > 1024 * 1024 || (is_array($parent_summary) && $parent_summary['js_size'] > 1024 * 1024)) {
        $warnings[] = 'Total JS exceeds 1 MB. Consider deferring non-critical scripts.';
    }
    return $warnings;
}

function tk_theme_checker_duplicate_files($roots) {
    $groups = array();
    if (!is_array($roots)) {
        return array();
    }
    $skip_dirs = array('node_modules', '.git', '.svn');
    foreach ($roots as $entry) {
        $root = isset($entry['root']) ? (string) $entry['root'] : '';
        $scope = isset($entry['scope']) ? (string) $entry['scope'] : '';
        if ($root === '' || !is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!is_string($path) || $path === '') {
                continue;
            }
            $normalized_path = wp_normalize_path($path);
            $is_excluded = false;
            foreach ($skip_dirs as $skip) {
                if (strpos($normalized_path, '/' . $skip . '/') !== false) {
                    $is_excluded = true;
                    break;
                }
            }
            if ($is_excluded) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $size = (int) $file->getSize();
            if ($size <= 0 || $size > 512 * 1024) {
                continue;
            }
            $contents = @file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                continue;
            }
            $hash = sha1($contents);
            if (!isset($groups[$hash])) {
                $groups[$hash] = array(
                    'size' => 0,
                    'paths' => array(),
                    'count' => 0,
                );
            }
            $rel = str_replace(wp_normalize_path($root), '', wp_normalize_path($path));
            $groups[$hash]['paths'][] = $scope . ':' . $rel;
            $groups[$hash]['size'] += $size;
            $groups[$hash]['count']++;
        }
    }
    $duplicates = array();
    foreach ($groups as $group) {
        if ($group['count'] < 2) {
            continue;
        }
        $group['paths'] = array_slice($group['paths'], 0, 10);
        $duplicates[] = $group;
    }
    usort($duplicates, function($a, $b) {
        return $b['size'] <=> $a['size'];
    });
    return array_slice($duplicates, 0, 20);
}

function tk_theme_checker_risky_functions($roots) {
    $functions = array(
        'eval' => 'Executes dynamic PHP code at runtime.',
        'base64_decode' => 'Often used to obfuscate payloads; review carefully.',
        'shell_exec' => 'Executes OS commands on the server.',
    );
    $results = array();
    foreach ($functions as $fn => $reason) {
        $results[$fn] = array(
            'function' => $fn,
            'reason' => $reason,
            'count' => 0,
            'matches' => array(),
        );
    }
    if (!is_array($roots)) {
        return array();
    }
    $skip_dirs = array('node_modules', '.git', '.svn');
    foreach ($roots as $entry) {
        $root = isset($entry['root']) ? (string) $entry['root'] : '';
        $scope = isset($entry['scope']) ? (string) $entry['scope'] : '';
        if ($root === '' || !is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!is_string($path) || $path === '') {
                continue;
            }
            $normalized_path = wp_normalize_path($path);
            $is_excluded = false;
            foreach ($skip_dirs as $skip) {
                if (strpos($normalized_path, '/' . $skip . '/') !== false) {
                    $is_excluded = true;
                    break;
                }
            }
            if ($is_excluded) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $size = (int) $file->getSize();
            if ($size <= 0 || $size > 512 * 1024) {
                continue;
            }
            $contents = @file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                continue;
            }
            $lines = preg_split('/\r\n|\r|\n/', $contents);
            if (!is_array($lines)) {
                $lines = array($contents);
            }
            foreach ($functions as $fn => $reason) {
                if (stripos($contents, $fn . '(') === false) {
                    continue;
                }
                $line_num = 0;
                foreach ($lines as $line) {
                    $line_num++;
                    if (!preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $line)) {
                        continue;
                    }
                    $results[$fn]['count']++;
                    $rel = str_replace(wp_normalize_path($root), '', wp_normalize_path($path));
                    $results[$fn]['matches'][] = $scope . ':' . $rel . ':' . $line_num;
                }
            }
        }
    }
    $rows = array();
    foreach ($results as $row) {
        if ($row['count'] <= 0) {
            continue;
        }
        $row['matches'] = array_slice(array_unique($row['matches']), 0, 20);
        $rows[] = $row;
    }
    return $rows;
}
