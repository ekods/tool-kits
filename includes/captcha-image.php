<?php
if (!defined('ABSPATH')) { exit; }

/** Never put the answer in HTML text, an SVG text node, an alt attribute, or a data attribute. */
function tk_captcha_image_uri(string $code): string {
    $width = strlen($code) * 28 + 20;
    $height = 68;
    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 238, 243, 250);
        imagefill($image, 0, 0, $background);
        for ($i = 0; $i < 8; $i++) {
            $noise = imagecolorallocate($image, random_int(160, 215), random_int(175, 225), random_int(185, 235));
            imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $noise);
        }
        foreach (str_split($code) as $index => $char) {
            $glyph = imagecreatetruecolor(13, 21);
            imagefill($glyph, 0, 0, imagecolorallocate($glyph, 238, 243, 250));
            imagestring($glyph, 5, 2, 3, $char, imagecolorallocate($glyph, random_int(15, 60), random_int(20, 65), random_int(40, 85)));
            imagecopyresampled($image, $glyph, 10 + $index * 28, random_int(5, 11), 0, 0, 26, 48, 13, 21);
            imagedestroy($glyph);
        }
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        if (is_string($png) && $png !== '') { return 'data:image/png;base64,' . base64_encode($png); }
    }
    return tk_captcha_svg_uri($code, $width, $height);
}

function tk_captcha_svg_uri(string $code, int $width, int $height): string {
    // Path-only bitmap glyphs keep the answer out of source text on hosts without GD.
    $glyphs = array(
        'A'=>array(14,17,17,31,17,17,17), 'B'=>array(30,17,17,30,17,17,30),
        'C'=>array(14,17,16,16,16,17,14), 'D'=>array(30,17,17,17,17,17,30),
        'E'=>array(31,16,16,30,16,16,31), 'F'=>array(31,16,16,30,16,16,16),
        'G'=>array(14,17,16,23,17,17,15), 'H'=>array(17,17,17,31,17,17,17),
        'I'=>array(14,4,4,4,4,4,14), 'J'=>array(7,2,2,2,18,18,12),
        'K'=>array(17,18,20,24,20,18,17), 'L'=>array(16,16,16,16,16,16,31),
        'M'=>array(17,27,21,21,17,17,17), 'N'=>array(17,25,21,19,17,17,17),
        'O'=>array(14,17,17,17,17,17,14), 'P'=>array(30,17,17,30,16,16,16),
        'Q'=>array(14,17,17,17,21,18,13), 'R'=>array(30,17,17,30,20,18,17),
        'S'=>array(15,16,16,14,1,1,30), 'T'=>array(31,4,4,4,4,4,4),
        'U'=>array(17,17,17,17,17,17,14), 'V'=>array(17,17,17,17,17,10,4),
        'W'=>array(17,17,17,21,21,21,10), 'X'=>array(17,17,10,4,10,17,17),
        'Y'=>array(17,17,10,4,4,4,4), 'Z'=>array(31,1,2,4,8,16,31),
        '0'=>array(14,17,19,21,25,17,14), '1'=>array(4,12,4,4,4,4,14),
        '2'=>array(14,17,1,2,4,8,31), '3'=>array(30,1,1,14,1,1,30),
        '4'=>array(2,6,10,18,31,2,2), '5'=>array(31,16,16,30,1,1,30),
        '6'=>array(14,16,16,30,17,17,14), '7'=>array(31,1,2,4,8,8,8),
        '8'=>array(14,17,17,14,17,17,14), '9'=>array(14,17,17,15,1,1,14),
        '!'=>array(4,4,4,4,4,0,4), '@'=>array(14,17,23,21,23,16,14),
        '#'=>array(10,10,31,10,31,10,10), '$'=>array(4,15,20,14,5,30,4),
        '%'=>array(17,2,4,4,8,16,17), '^'=>array(4,10,17,0,0,0,0),
        '&'=>array(12,18,20,8,21,18,13), '*'=>array(0,21,14,31,14,21,0),
    );
    // Cells are placed in absolute, jittered coordinates and shuffled across all glyphs, so no per-character
    // path string or transform repeats between challenges and the source cannot be matched against a glyph table.
    $cells = array();
    foreach (str_split($code) as $index => $char) {
        $angle = deg2rad(random_int(-10, 10));
        $scale = random_int(90, 110) / 100;
        $origin_x = 10 + $index * 28 + random_int(-1, 2);
        $origin_y = random_int(8, 14);
        foreach ($glyphs[$char] ?? array() as $row => $bits) {
            for ($col = 0; $col < 5; $col++) {
                if (!($bits & (1 << (4 - $col)))) { continue; }
                $x = ($col * 4 - 10) * $scale;
                $y = ($row * 6 - 21) * $scale;
                $cx = $origin_x + 10 + $x * cos($angle) - $y * sin($angle) + random_int(-30, 30) / 100;
                $cy = $origin_y + 21 + $x * sin($angle) + $y * cos($angle) + random_int(-30, 30) / 100;
                $w = round(4 * $scale + random_int(0, 40) / 100, 2);
                $h = round(6 * $scale + random_int(0, 40) / 100, 2);
                $cells[] = 'M' . round($cx, 2) . ' ' . round($cy, 2) . 'h' . $w . 'v' . $h . 'h-' . $w . 'z';
            }
        }
    }
    shuffle($cells);
    $noise = '';
    for ($i = 0; $i < 8; $i++) {
        $noise .= '<path stroke="#' . sprintf('%02x%02x%02x', random_int(150, 200), random_int(165, 210), random_int(180, 225)) . '" stroke-width="1.2" fill="none" d="M' . random_int(0, $width) . ' ' . random_int(0, $height) . 'L' . random_int(0, $width) . ' ' . random_int(0, $height) . '"/>';
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"><path fill="#eef3fa" d="M0 0h' . $width . 'v' . $height . 'H0z"/>'
        . $noise . '<path fill="#1e293b" d="' . implode('', $cells) . '"/>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg . '</svg>');
}
