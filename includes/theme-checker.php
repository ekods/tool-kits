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
        <h1>Themes Checker</h1>

        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="overview">Overview</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="summary">Summary</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="largest">Largest Files</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="duplicates">Duplicates</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="risky">Risky Functions</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="overview">
                    <h2>Active Theme</h2>
                    <table class="widefat striped tk-table">
                        <tbody>
                            <tr>
                                <th>Name</th>
                                <td><?php echo esc_html($theme->get('Name')); ?></td>
                            </tr>
                            <tr>
                                <th>Version</th>
                                <td><?php echo esc_html($theme->get('Version')); ?></td>
                            </tr>
                            <tr>
                                <th>Child Theme</th>
                                <td><?php echo $parent ? 'Yes' : 'No'; ?></td>
                            </tr>
                            <?php if ($parent) : ?>
                            <tr>
                                <th>Parent Theme</th>
                                <td><?php echo esc_html($parent->get('Name')); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if (!empty($warnings)) : ?>
                        <h2 style="margin-top:20px;">Recommendations</h2>
                        <ul class="tk-list">
                            <?php foreach ($warnings as $warning) : ?>
                                <li><?php echo esc_html($warning); ?></li>
                            <?php endforeach; ?>
                        </ul>
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
                    <h2>Largest Files</h2>
                    <?php if (empty($largest)) : ?>
                        <p><small>No files found.</small></p>
                    <?php else : ?>
                        <table class="widefat striped tk-table">
                            <thead><tr><th>File</th><th>Size</th><th>Scope</th></tr></thead>
                            <tbody>
                                <?php foreach ($largest as $item) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html($item['path']); ?></code></td>
                                        <td><?php echo esc_html(size_format((int) $item['size'])); ?></td>
                                        <td><?php echo esc_html($item['scope']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="duplicates">
                    <h2>Duplicate PHP Files</h2>
                    <p><small>Exact content matches only (whitespace-sensitive).</small></p>
                    <?php if (empty($duplicates)) : ?>
                        <p><small>No duplicates found.</small></p>
                    <?php else : ?>
                        <table class="widefat striped tk-table">
                            <thead><tr><th>Files</th><th>Total size</th><th>Paths</th></tr></thead>
                            <tbody>
                                <?php foreach ($duplicates as $group) : ?>
                                    <tr>
                                        <td><?php echo esc_html((string) $group['count']); ?></td>
                                        <td><?php echo esc_html(size_format((int) $group['size'])); ?></td>
                                        <td>
                                            <ul class="tk-list">
                                                <?php foreach ($group['paths'] as $path) : ?>
                                                    <li><code><?php echo esc_html($path); ?></code></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><small>Showing up to 20 groups (max 10 files per group).</small></p>
                    <?php endif; ?>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="risky">
                    <h2>Risky Functions</h2>
                    <p><small>Scans PHP files for usage of eval, base64_decode, and shell_exec.</small></p>
                    <?php if (empty($risky)) : ?>
                        <p><small>No risky functions found.</small></p>
                    <?php else : ?>
                        <table class="widefat striped tk-table">
                            <thead><tr><th>Function</th><th>Count</th><th>Reason</th><th>Matches</th></tr></thead>
                            <tbody>
                                <?php foreach ($risky as $row) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html($row['function']); ?></code></td>
                                        <td><?php echo esc_html((string) $row['count']); ?></td>
                                        <td><?php echo esc_html($row['reason']); ?></td>
                                        <td>
                                            <ul class="tk-list">
                                                <?php foreach ($row['matches'] as $match) : ?>
                                                    <li><code><?php echo esc_html($match); ?></code></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><small>Showing up to 20 matches per function.</small></p>
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
    $out = '<table class="widefat striped tk-table"><thead><tr><th>File</th><th>Size</th><th>Scope</th></tr></thead><tbody>';
    foreach ($list as $item) {
        $out .= '<tr>';
        $out .= '<td><code>' . esc_html($item['path']) . '</code></td>';
        $out .= '<td>' . esc_html(size_format((int) $item['size'])) . '</td>';
        $out .= '<td>' . esc_html($item['scope']) . '</td>';
        $out .= '</tr>';
    }
    $out .= '</tbody></table>';
    $out .= '<p><small>Showing up to 20 files.</small></p>';
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
        $dir = basename($file->getPath());
        if (in_array($dir, $skip_dirs, true)) {
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
            $dir = basename($file->getPath());
            if (in_array($dir, $skip_dirs, true)) {
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
            $dir = basename($file->getPath());
            if (in_array($dir, $skip_dirs, true)) {
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
