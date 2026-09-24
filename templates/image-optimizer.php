<?php
if (!defined('ABSPATH')) { exit; }
$queue_status = sanitize_key((string) ($queue['status'] ?? 'idle'));
$queue_total = max(0, (int) ($queue['total'] ?? 0));
$queue_processed = min($queue_total, max(0, (int) ($queue['processed'] ?? 0)));
$queue_progress = $queue_total > 0 ? min(100, (int) round($queue_processed / $queue_total * 100)) : 0;
$large = isset($report['large_unoptimized']) && is_array($report['large_unoptimized']) ? $report['large_unoptimized'] : array();
$cleanup_items = isset($cleanup_report['items']) && is_array($cleanup_report['items']) ? $cleanup_report['items'] : array();
$tabs = array('image-settings' => 'Settings', 'background-queue' => 'Media Library', 'compression-report' => 'Compression Report', 'image-maintenance' => 'Maintenance');
$toggle_groups = array(
    'delivery' => array(
        array('image_opt_enabled', 'Optimize new uploads', 'JPEG, PNG and WebP', 0),
        array('image_opt_frontend_optimize', 'Serve optimized images', 'Use validated copies on the frontend', 0),
        array('image_opt_srcset_original_only', 'Full-resolution source', 'One original-size candidate in srcset', 1),
    ),
    'quality' => array(
        array('image_opt_preserve_hires', 'Preserve Hi-Res', 'Original dimensions. Minimum JPEG quality 95.', 1),
    ),
    'metadata' => array(
        array('image_opt_strip_metadata', 'Remove EXIF and comments', 'Keep the ICC color profile', 1),
        array('image_opt_sharpen_enabled', 'Sharpen resized images', 'Applied only when an image is resized', 1),
    ),
);
?>
<div class="tk-image-workspace" id="tk-image-workspace">
    <dl class="tk-image-metrics" aria-label="Image optimization summary">
        <div><dt>Estimated savings</dt><dd class="tk-image-success"><?php echo !empty($report) ? esc_html(tk_image_opt_format_bytes($report['estimated_saved_bytes'] ?? 0)) : '&mdash;'; ?></dd><span><?php echo !empty($report) ? 'Latest compression report' : 'No report yet'; ?></span></div>
        <div><dt>Optimized images</dt><dd><?php echo !empty($report) ? esc_html(number_format_i18n((int) ($report['optimized_files'] ?? 0))) : '&mdash;'; ?></dd><span><?php echo !empty($report) ? esc_html(number_format_i18n((int) ($report['scanned'] ?? 0))) . ' images scanned' : 'No report yet'; ?></span></div>
        <div><dt>Without valid copies</dt><dd><?php echo !empty($report) ? esc_html(number_format_i18n((int) ($report['missing_derivatives'] ?? 0))) : '&mdash;'; ?></dd><span>Source fallback available</span></div>
        <div><dt>Source protection</dt><dd class="tk-image-protection"><span class="dashicons dashicons-shield" aria-hidden="true"></span> Protected</dd><span>Original uploads retained</span></div>
    </dl>

    <div class="tk-image-tabs" data-image-tabs>
        <div class="tk-tabs-nav" role="tablist" aria-label="Image Optimizer">
            <?php foreach ($tabs as $id => $label) : ?>
                <button type="button" id="tk-tab-<?php echo esc_attr($id); ?>" class="tk-tabs-nav-button<?php echo $id === 'image-settings' ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $id === 'image-settings' ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr($id); ?>" tabindex="<?php echo $id === 'image-settings' ? '0' : '-1'; ?>" data-image-tab="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></button>
            <?php endforeach; ?>
        </div>

        <section id="image-settings" class="tk-image-panel" role="tabpanel" aria-labelledby="tk-tab-image-settings" tabindex="0">
            <?php if (tk_image_opt_preserve_hires() && !class_exists('Imagick')) : ?>
                <?php tk_notice('Hi-Res verification requires Imagick. Original images remain in use until Imagick is available.', 'warning'); ?>
            <?php endif; ?>
            <form id="tk-image-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_image_opt_save'); ?>
                <input type="hidden" name="action" value="tk_image_opt_save">
                <input type="hidden" name="tk_tab" value="image-opt">
                <?php foreach ($toggle_groups as $group => $toggles) : ?>
                <div class="tk-image-section">
                    <div class="tk-image-section-heading">
                        <h2><?php echo esc_html(array('delivery' => 'Image delivery', 'quality' => 'Quality & resolution', 'metadata' => 'Metadata & detail')[$group]); ?></h2>
                        <?php if ($group === 'delivery') : ?><span class="tk-badge <?php echo tk_get_option('image_opt_enabled', 0) ? 'tk-on' : 'tk-off'; ?>"><?php echo tk_get_option('image_opt_enabled', 0) ? 'Automatic uploads on' : 'Automatic uploads off'; ?></span><?php endif; ?>
                        <?php if ($group === 'quality') : ?><span class="tk-badge"><?php echo class_exists('Imagick') ? 'Imagick' : 'WordPress editor'; ?></span><?php endif; ?>
                    </div>
                    <div class="tk-image-section-fields">
                        <?php foreach ($toggles as $toggle) : ?>
                        <div class="tk-image-setting-row">
                            <div><label for="<?php echo esc_attr($toggle[0]); ?>"><?php echo esc_html($toggle[1]); ?></label><p id="<?php echo esc_attr($toggle[0]); ?>-hint"><?php echo esc_html($toggle[2]); ?></p></div>
                            <label class="tk-switch" for="<?php echo esc_attr($toggle[0]); ?>">
                                <input type="checkbox" role="switch" id="<?php echo esc_attr($toggle[0]); ?>" name="<?php echo esc_attr($toggle[0]); ?>" value="1" aria-describedby="<?php echo esc_attr($toggle[0]); ?>-hint" <?php checked(1, tk_get_option($toggle[0], $toggle[3])); ?>>
                                <span class="tk-slider" aria-hidden="true"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($group === 'quality') : ?>
                        <div class="tk-image-setting-row tk-image-quality-row">
                            <div><label for="image_opt_quality">JPEG quality</label><p>Effective quality: <output id="tk-effective-quality">95</output> / 100</p></div>
                            <div class="tk-image-quality-control">
                                <input type="range" id="tk-quality-range" min="30" max="100" value="<?php echo esc_attr((string) tk_get_option('image_opt_quality', 95)); ?>" aria-label="JPEG quality slider">
                                <input type="number" id="image_opt_quality" name="image_opt_quality" min="30" max="100" required value="<?php echo esc_attr((string) tk_get_option('image_opt_quality', 95)); ?>">
                            </div>
                        </div>
                        <div class="tk-image-setting-row">
                            <div><label for="image_opt_target_dpi">DPI metadata</label><p>Print density. Pixel dimensions remain unchanged.</p></div>
                            <div class="tk-image-unit-input"><input type="number" id="image_opt_target_dpi" name="image_opt_target_dpi" min="0" max="600" required value="<?php echo esc_attr((string) tk_get_option('image_opt_target_dpi', 72)); ?>"><span>DPI</span></div>
                        </div>
                        <details class="tk-image-advanced">
                            <summary>Resize limits <span id="tk-resize-state">Inactive in Hi-Res</span></summary>
                            <div class="tk-image-dimensions">
                                <?php foreach (array('width' => 'Maximum width', 'height' => 'Maximum height') as $dimension => $label) : ?>
                                <div><label for="image_opt_max_<?php echo esc_attr($dimension); ?>"><?php echo esc_html($label); ?></label><div class="tk-image-unit-input"><input type="number" id="image_opt_max_<?php echo esc_attr($dimension); ?>" name="image_opt_max_<?php echo esc_attr($dimension); ?>" min="0" max="12000" value="<?php echo esc_attr((string) tk_get_option('image_opt_max_' . $dimension, 0)); ?>" required><span>px</span></div></div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="tk-image-footer">
                    <span class="tk-image-muted" id="tk-image-save-state" role="status">JPEG compression is lossy. Hi-Res PNG/WebP uses lossless encoding.</span>
                    <button type="submit" class="button button-primary"><span class="dashicons dashicons-saved" aria-hidden="true"></span>Save settings</button>
                </div>
            </form>
        </section>

        <section id="background-queue" class="tk-image-panel tk-image-library" role="tabpanel" aria-labelledby="tk-tab-background-queue" tabindex="0" hidden>
            <div class="tk-processing-overlay" id="tk-image-opt-overlay" aria-hidden="true"><div class="tk-processing-box"><span class="tk-processing-spinner" aria-hidden="true"></span><strong>Optimizing images</strong><span id="tk-image-opt-overlay-text" role="status">Preparing batch...</span></div></div>
            <div class="tk-image-panel-heading"><h2>Media library</h2><span class="tk-badge <?php echo $queue_status === 'complete' ? 'tk-on' : ($queue_status === 'running' ? 'tk-warn' : 'tk-off'); ?>"><?php echo esc_html(ucfirst($queue_status)); ?></span></div>
            <div class="tk-image-job">
                <div class="tk-image-job-title"><span class="dashicons dashicons-update" aria-hidden="true"></span><h3>Background queue</h3></div>
                <div class="tk-image-progress-label"><span><?php echo esc_html(number_format_i18n($queue_processed) . ' / ' . number_format_i18n($queue_total)); ?> images processed</span><strong><?php echo esc_html((string) $queue_progress); ?>%</strong></div>
                <div class="tk-progress" role="progressbar" aria-label="Background queue progress" aria-valuenow="<?php echo esc_attr((string) $queue_progress); ?>" aria-valuemin="0" aria-valuemax="100"><div class="tk-progress-bar" style="width:<?php echo esc_attr((string) $queue_progress); ?>%"></div></div>
                <div class="tk-image-footer"><span class="tk-image-muted">Saved <?php echo esc_html(tk_image_opt_format_bytes($queue['saved'] ?? 0)); ?><?php echo !empty($queue['updated_at']) ? ' / ' . esc_html(wp_date('M j, H:i', (int) $queue['updated_at'])) : ''; ?></span>
                    <div class="tk-image-actions">
                        <?php if ($queue_status === 'running') : ?>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_image_opt_queue_stop'), 'tk_image_opt_queue_stop')); ?>"><span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>Stop queue</a>
                        <a class="button tk-icon-button" href="<?php echo esc_url(admin_url('admin.php?page=tool-kits-image-opt') . '#background-queue'); ?>" aria-label="Refresh queue status" title="Refresh queue status"><span class="dashicons dashicons-update" aria-hidden="true"></span></a>
                        <?php else : ?>
                        <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_image_opt_queue_start'), 'tk_image_opt_queue_start')); ?>"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span><?php echo $queue_status === 'idle' ? 'Start queue' : 'Run queue again'; ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($queue['last_error'])) : ?><p class="tk-image-error" role="alert"><?php echo esc_html((string) $queue['last_error']); ?></p><?php endif; ?>
            </div>
            <div class="tk-image-job">
                <div class="tk-image-panel-heading"><h3>Optimize in this session</h3><button type="button" class="button" id="tk-image-opt-batch" data-nonce="<?php echo esc_attr(wp_create_nonce('tk_image_opt_batch')); ?>" <?php disabled($queue_status, 'running'); ?>><span class="dashicons dashicons-images-alt2" aria-hidden="true"></span>Optimize images</button></div>
                <div class="tk-image-progress-label"><span id="tk-image-opt-status" role="status"><?php echo $queue_status === 'running' ? 'Background queue is running.' : 'Ready'; ?></span><strong id="tk-image-opt-progress-text">0%</strong></div>
                <div class="tk-progress" role="progressbar" aria-label="Current session progress" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"><div class="tk-progress-bar" id="tk-image-opt-progress-bar" style="width:0%"></div></div>
            </div>
        </section>

        <section id="compression-report" class="tk-image-panel" role="tabpanel" aria-labelledby="tk-tab-compression-report" tabindex="0" hidden>
            <div class="tk-image-panel-heading"><div><h2>Compression report</h2><p class="tk-image-muted"><?php echo !empty($report['scanned_at']) ? 'Updated ' . esc_html(wp_date('M j, Y / H:i', (int) $report['scanned_at'])) : 'No report generated'; ?></p></div><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_image_opt_report'), 'tk_image_opt_report')); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span>Refresh report</a></div>
            <?php if (!empty($report)) : ?>
                <dl class="tk-image-report-totals"><div><dt>Source size</dt><dd><?php echo esc_html(tk_image_opt_format_bytes($report['original_optimized_source_bytes'] ?? 0)); ?></dd></div><div><dt>Optimized size</dt><dd><?php echo esc_html(tk_image_opt_format_bytes($report['optimized_bytes'] ?? 0)); ?></dd></div><div><dt>Estimated saved</dt><dd class="tk-image-success"><?php echo esc_html(tk_image_opt_format_bytes($report['estimated_saved_bytes'] ?? 0)); ?></dd></div></dl>
                <h3>Large images without valid copies</h3>
                <?php if (!empty($large)) : ?>
                <div class="tk-table-scroll" tabindex="0" role="region" aria-label="Image compression results"><table class="widefat tk-table tk-image-table"><thead><tr><th scope="col">Image</th><th scope="col">Source size</th><th scope="col">Source URL</th><th scope="col"><span class="screen-reader-text">Open image</span></th></tr></thead><tbody>
                    <?php foreach ($large as $item) : ?>
                    <tr><td><div class="tk-image-file"><img src="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" width="40" height="40" loading="lazy" alt=""><strong><?php echo esc_html((string) ($item['title'] ?? 'Untitled image')); ?></strong></div></td><td><?php echo esc_html(tk_image_opt_format_bytes($item['size'] ?? 0)); ?></td><td><span class="tk-image-file-url"><?php echo esc_html((string) ($item['url'] ?? '')); ?></span></td><td><a class="button tk-icon-button" href="<?php echo esc_url((string) ($item['url'] ?? '')); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr('Open image: ' . ($item['title'] ?? 'Untitled image')); ?>" title="Open original image"><span class="dashicons dashicons-external" aria-hidden="true"></span></a></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <?php else : ?><div class="tk-image-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><h3>No large images awaiting copies</h3><p>Based on the latest scan of <?php echo esc_html((string) ($report['scanned'] ?? 0)); ?> images.</p></div><?php endif; ?>
            <?php else : ?><div class="tk-image-empty"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><h3>No compression report yet</h3><p>Image counts and file savings are unavailable.</p></div><?php endif; ?>
        </section>

        <section id="image-maintenance" class="tk-image-panel" role="tabpanel" aria-labelledby="tk-tab-image-maintenance" tabindex="0" hidden>
            <div class="tk-image-panel-heading"><div><h2>Optimized file maintenance</h2><p class="tk-image-muted">Original uploads are retained.</p></div><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tk_image_opt_cleanup'), 'tk_image_opt_cleanup')); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span>Clean unused copies</a></div>
            <?php if (!empty($cleanup_report['error'])) : ?><p class="tk-image-error" role="alert"><?php echo esc_html((string) $cleanup_report['error']); ?></p><?php endif; ?>
            <?php if (!empty($cleanup_report)) : ?>
                <dl class="tk-image-report-totals"><div><dt>Scanned copies</dt><dd><?php echo esc_html(number_format_i18n((int) ($cleanup_report['scanned'] ?? 0))); ?></dd></div><div><dt>Removed copies</dt><dd><?php echo esc_html(number_format_i18n((int) ($cleanup_report['removed'] ?? 0))); ?></dd></div><div><dt>Space released</dt><dd class="tk-image-success"><?php echo esc_html(tk_image_opt_format_bytes($cleanup_report['bytes_removed'] ?? 0)); ?></dd></div></dl>
                <?php if (!empty($cleanup_items)) : ?>
                <div class="tk-table-scroll" tabindex="0" role="region" aria-label="Removed optimized files"><table class="widefat tk-table tk-image-table"><thead><tr><th scope="col">Removed file</th><th scope="col">Size</th><th scope="col">Reason</th></tr></thead><tbody>
                    <?php foreach ($cleanup_items as $item) : ?><tr><td><code><?php echo esc_html((string) ($item['file'] ?? '')); ?></code></td><td><?php echo esc_html(tk_image_opt_format_bytes($item['size'] ?? 0)); ?></td><td><?php echo esc_html((string) ($item['reason'] ?? '')); ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <?php else : ?><div class="tk-image-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><h3>No unused copies removed</h3></div><?php endif; ?>
                <p class="tk-image-muted tk-image-timestamp"><?php echo !empty($cleanup_report['scanned_at']) ? 'Last cleanup: ' . esc_html(wp_date('M j, Y / H:i', (int) $cleanup_report['scanned_at'])) : ''; ?></p>
            <?php else : ?><div class="tk-image-empty"><span class="dashicons dashicons-media-archive" aria-hidden="true"></span><h3>No cleanup history</h3><p>No maintenance report is available.</p></div><?php endif; ?>
        </section>
    </div>
</div>
