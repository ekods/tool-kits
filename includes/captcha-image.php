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
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="68" viewBox="0 0 ' . $width . ' 68"><path fill="#eef3fa" d="M0 0h' . $width . 'v68H0z"/>';
    foreach (str_split($code) as $index => $char) {
        $path = '';
        foreach ($glyphs[$char] ?? array() as $row => $bits) {
            for ($col = 0; $col < 5; $col++) {
                if ($bits & (1 << (4 - $col))) { $path .= 'M' . ($col * 4) . ' ' . ($row * 6) . 'h4v6h-4z'; }
            }
        }
        $svg .= '<path fill="#1e293b" transform="translate(' . (10 + $index * 28) . ' ' . random_int(8, 14) . ') rotate(' . random_int(-8, 8) . ' 10 21)" d="' . $path . '"/>';
    }
    return 'data:image/svg+xml;base64,' . base64_encode($svg . '</svg>');
}
