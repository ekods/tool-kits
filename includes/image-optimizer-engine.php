<?php
if (!defined('ABSPATH')) { exit; }

function tk_image_opt_preserve_hires(): bool {
    return (int) tk_get_option('image_opt_preserve_hires', 1) === 1;
}

function tk_image_opt_recipe($quality): array {
    $hires = tk_image_opt_preserve_hires();
    return array(
        'engine' => 3,
        'quality' => max($hires ? 95 : 30, min(100, (int) $quality)),
        'width' => $hires ? 0 : max(0, (int) tk_get_option('image_opt_max_width', 0)),
        'height' => $hires ? 0 : max(0, (int) tk_get_option('image_opt_max_height', 0)),
        'dpi' => max(0, min(600, (int) tk_get_option('image_opt_target_dpi', 72))),
        'strip' => (int) tk_get_option('image_opt_strip_metadata', 1),
        'sharpen' => (int) tk_get_option('image_opt_sharpen_enabled', 1),
        'hires' => $hires,
    );
}

function tk_image_opt_signature(string $source, $quality): string {
    clearstatcache(true, $source);
    return hash('sha256', wp_json_encode(array(
        realpath($source) ?: $source, @filesize($source), @filemtime($source), tk_image_opt_recipe($quality),
    )));
}

function tk_image_opt_valid_derivative(string $source, string $target, $quality): bool {
    if (!is_file($source) || !is_file($target) || !is_file($target . '.json')) {
        return false;
    }
    $report = json_decode((string) @file_get_contents($target . '.json'), true);
    clearstatcache(true, $target);
    return is_array($report)
        && ($report['signature'] ?? '') === tk_image_opt_signature($source, $quality)
        && (int) ($report['bytes'] ?? 0) === (int) filesize($target)
        && (int) ($report['mtime'] ?? 0) === (int) filemtime($target);
}

function tk_image_opt_forget_derivative_report(string $target): void {
    if (preg_match('/-tkopt\.(jpe?g|png|webp)$/i', $target)
        && tk_image_opt_path_to_upload_url($target) !== '' && is_file($target . '.json')) {
        @unlink($target . '.json');
    }
}

function tk_image_opt_source_file($attachment_id, $metadata = null): string {
    $file = get_attached_file($attachment_id);
    if (!is_string($file) || !is_file($file)) {
        return '';
    }
    // Only undo WordPress big-image scaling, never a deliberate media edit/crop.
    if (tk_image_opt_preserve_hires() && preg_match('/-scaled\.[^.]+$/i', $file)) {
        if (!is_array($metadata)) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }
        if (!empty($metadata['original_image']) && basename($metadata['original_image']) === $metadata['original_image']) {
            $original = dirname($file) . '/' . $metadata['original_image'];
            if (is_file($original)) {
                return $original;
            }
        }
    }
    return $file;
}

function tk_image_opt_pixel_signature(Imagick $image, int $depth = 8): string {
    $pixels = clone $image;
    try {
        // Compare decoded channel bytes, independent of ImageMagick HDRI precision.
        $pixels->setImageFormat('RGBA');
        $pixels->setImageDepth($depth);
        return hash('sha256', $pixels->getImageBlob());
    } finally {
        $pixels->clear();
    }
}

function tk_image_opt_encode(string $source, string $target, $quality): array {
    $failed = array('optimized' => false, 'saved' => 0, 'path' => '', 'reason' => 'Unsupported or unavailable source.');
    $source_real = realpath($source);
    $target_dir = realpath(dirname($target));
    $upload = wp_get_upload_dir();
    $root = !empty($upload['basedir']) ? realpath($upload['basedir']) : false;
    if (!$source_real || !$target_dir || !$root || $source_real === $target || realpath($target) === $source_real || is_link($target)
        || strpos($source_real, $root . DIRECTORY_SEPARATOR) !== 0
        || strpos($target_dir . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR) !== 0
        || !preg_match('/-tkopt\.(jpe?g|png|webp)$/i', $target)
        || preg_match('/-tkopt\.(jpe?g|png|webp)$/i', $source)) {
        return $failed;
    }
    $info = @getimagesize($source);
    $mime = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    $format = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    if (!$info || !isset($mime[$format]) || !in_array($info['mime'], $mime, true)) {
        return $failed;
    }
    if (is_file($target) && function_exists('attachment_url_to_postid')
        && attachment_url_to_postid(tk_image_opt_path_to_upload_url($target))) {
        $failed['reason'] = 'Target filename belongs to an original attachment.';
        return $failed;
    }
    $recipe = tk_image_opt_recipe($quality);
    if ($recipe['hires'] && !class_exists('Imagick')) {
        $failed['reason'] = 'Hi-Res verification requires Imagick; source image preserved.';
        return $failed;
    }
    $failed['reason'] = 'Image encoder could not preserve this source.';
    $signature = tk_image_opt_signature($source, $quality);
    $temp = tempnam($target_dir, '.tkopt-');
    if ($temp === false) {
        return $failed;
    }
    $image = null;
    $decoded = null;
    $psnr = null;
    $pixel_exact = null;
    $expected = array((int) $info[0], (int) $info[1]);
    $engine = 'WordPress';
    try {
        if (class_exists('Imagick')) {
            $engine = 'Imagick';
            $image = new Imagick($source);
            // Flattening animation would destroy content. Leave it on its source URL.
            if ($image->getNumberImages() !== 1) {
                return $failed;
            }
            if ($format === 'webp' && ($image->getImageDepth() > 8
                || !in_array($image->getImageColorspace(), array(Imagick::COLORSPACE_RGB, Imagick::COLORSPACE_SRGB), true))) {
                return $failed;
            }
            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            } elseif ($image->getImageOrientation() > Imagick::ORIENTATION_TOPLEFT) {
                return $failed;
            }
            $expected = array($image->getImageWidth(), $image->getImageHeight());
            $icc = $image->getImageProfiles('icc', true);
            if ($recipe['strip']) {
                $image->stripImage();
                foreach ($icc as $name => $profile) {
                    $image->profileImage($name, $profile);
                }
            }
            $scale = min(
                1,
                $recipe['width'] > 0 ? $recipe['width'] / $expected[0] : 1,
                $recipe['height'] > 0 ? $recipe['height'] / $expected[1] : 1
            );
            if ($scale < 1) {
                $expected = array(max(1, (int) round($expected[0] * $scale)), max(1, (int) round($expected[1] * $scale)));
                $image->resizeImage($expected[0], $expected[1], Imagick::FILTER_LANCZOS, 1);
                if ($recipe['sharpen']) {
                    $image->unsharpMaskImage(0, 0.5, 0.65, 0.02);
                }
            }
            if ($recipe['dpi'] > 0) {
                $image->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
                $image->setImageResolution($recipe['dpi'], $recipe['dpi']);
            }
            $image->setImageFormat($format === 'jpg' ? 'jpeg' : $format);
            $image->setImageCompressionQuality($recipe['quality']);
            if ($mime[$format] === 'image/jpeg') {
                $image->setSamplingFactors(array('1x1', '1x1', '1x1'));
                $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            } elseif ($format === 'webp') {
                // Hi-Res avoids WebP chroma subsampling, including JPEG source detail.
                $image->setOption('webp:lossless', $recipe['hires'] || $info['mime'] !== 'image/jpeg' ? 'true' : 'false');
                $image->setOption('webp:method', '6');
                $image->setOption('webp:exact', 'true');
            } elseif ($format === 'png') {
                $image->setOption('png:compression-level', '9');
            }
            if (!$image->writeImage($temp)) {
                return $failed;
            }
        } else {
            // GD cannot preserve ICC profiles, animated WebP, or guarantee lossless WebP.
            $header = (string) file_get_contents($source, false, null, 0, 1048576);
            if ($info['mime'] === 'image/webp' || strpos($header, 'ICC_PROFILE') !== false
                || strpos($header, 'iCCP') !== false || ($format === 'webp' && $info['mime'] === 'image/png')) {
                return $failed;
            }
            $editor = wp_get_image_editor($source);
            if (is_wp_error($editor) || is_wp_error($editor->set_quality($recipe['quality']))) {
                return $failed;
            }
            if (method_exists($editor, 'maybe_exif_rotate') && is_wp_error($editor->maybe_exif_rotate())) {
                return $failed;
            }
            if (($recipe['width'] || $recipe['height']) && is_wp_error($editor->resize($recipe['width'] ?: null, $recipe['height'] ?: null, false))) {
                return $failed;
            }
            $size = $editor->get_size();
            $expected = array((int) $size['width'], (int) $size['height']);
            $saved = $editor->save($temp . '.' . $format, $mime[$format]);
            if (is_wp_error($saved)) {
                return $failed;
            }
            @unlink($temp);
            $temp = $saved['path'];
        }
        clearstatcache(true, $temp);
        $output = @getimagesize($temp);
        $bytes = (int) @filesize($temp);
        if (!$output || array((int) $output[0], (int) $output[1]) !== $expected || $output['mime'] !== $mime[$format]
            || $bytes <= 0 || $bytes >= filesize($source) || $signature !== tk_image_opt_signature($source, $quality)) {
            $failed['reason'] = 'Output validation failed or output is not smaller than source.';
            return $failed;
        }
        if ($image instanceof Imagick && $recipe['hires']) {
            $decoded = new Imagick($temp);
            if ($format === 'webp' || ($info['mime'] !== 'image/jpeg' && $mime[$format] !== 'image/jpeg')) {
                $depth = $format === 'webp' ? 8 : $image->getImageDepth();
                $pixel_exact = tk_image_opt_pixel_signature($image, $depth) === tk_image_opt_pixel_signature($decoded, $depth);
                if (!$pixel_exact) {
                    $failed['reason'] = 'Decoded pixel validation failed.';
                    $failed['rmse'] = $image->getImageDistortion($decoded, Imagick::METRIC_ROOTMEANSQUAREDERROR);
                    return $failed;
                }
            } else {
                $rmse = $image->getImageDistortion($decoded, Imagick::METRIC_ROOTMEANSQUAREDERROR);
                $psnr = $rmse > 0 ? -20 * log10($rmse) : 100;
                if (!is_finite($psnr) || $psnr < 40) {
                    $failed['reason'] = 'Image quality is below the Hi-Res threshold.';
                    return $failed;
                }
            }
        }
        // Readers see either the old complete file or the new complete file.
        if (!@rename($temp, $target)) {
            return $failed;
        }
        @chmod($target, fileperms($source) & 0666);
        clearstatcache(true, $target);
        $report = array(
            'signature' => $signature, 'bytes' => $bytes, 'mtime' => filemtime($target),
            'width' => $expected[0], 'height' => $expected[1], 'engine' => $engine,
            'quality' => $recipe['quality'], 'generated_at' => time(),
            'dpi_applied' => $engine === 'Imagick' && $recipe['dpi'] > 0,
            'psnr_db' => $psnr !== null ? round($psnr, 2) : null,
            'pixel_exact' => $pixel_exact,
        );
        $manifest = tempnam($target_dir, '.tkopt-');
        if ($manifest === false) {
            return $failed;
        }
        $written = file_put_contents($manifest, wp_json_encode($report));
        if (!$written || !@rename($manifest, $target . '.json')) {
            @unlink($manifest);
            return $failed;
        }
        @chmod($target . '.json', fileperms($source) & 0666);
        return array('optimized' => true, 'saved' => (int) filesize($source) - $bytes, 'path' => $target);
    } catch (Throwable $e) {
        $failed['reason'] = $e->getMessage();
        return $failed;
    } finally {
        if ($image instanceof Imagick) {
            $image->clear();
        }
        if ($decoded instanceof Imagick) {
            $decoded->clear();
        }
        if (is_file($temp)) {
            @unlink($temp);
        }
    }
}

function tk_image_opt_generate_copies(string $source, $quality): array {
    $target = tk_image_opt_derivative_path($source);
    $result = tk_image_opt_valid_derivative($source, $target, $quality)
        ? array('optimized' => true, 'saved' => filesize($source) - filesize($target), 'path' => $target)
        : tk_image_opt_create_optimized_derivative($source, $target, $quality);
    if (tk_get_option('webp_serve_enabled', 0) && preg_match('/\.(jpe?g|png)$/i', $source)) {
        // Include the source extension to avoid collisions between photo.jpg and photo.png.
        $webp = $source . '-tkopt.webp';
        $webp_result = tk_image_opt_valid_derivative($source, $webp, $quality)
            ? array('optimized' => true, 'saved' => filesize($source) - filesize($webp), 'path' => $webp)
            : tk_image_opt_encode($source, $webp, $quality);
        if (!$webp_result['optimized']) {
            tk_image_opt_forget_derivative_report($webp);
        }
        if ($webp_result['optimized'] && (!$result['optimized'] || $webp_result['saved'] > $result['saved'])) {
            $result = $webp_result;
        }
    }
    return $result;
}
