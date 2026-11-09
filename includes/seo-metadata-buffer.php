<?php
if (!defined('ABSPATH')) { exit; }

/** Adjacent global/page policies share one traversal; intervening third-party buffers retain their order. */
function tk_seo_metadata_buffer(string $source, array $values, bool $social): void {
    $state = $GLOBALS['tk_seo_metadata_buffer'] ?? null;
    if (!$state || $state->level !== ob_get_level()) {
        $state = (object) array('global' => array(), 'content' => array(), 'social' => $social, 'level' => ob_get_level() + 1);
        $GLOBALS['tk_seo_metadata_buffer'] = $state;
        ob_start(function ($html, $phase) use ($state) {
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) && ($GLOBALS['tk_seo_metadata_buffer'] ?? null) === $state) {
                unset($GLOBALS['tk_seo_metadata_buffer']);
            }
            $status = http_response_code();
            if ($status && $status !== 200) { return $html; }
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
            }
            return tk_seo_content_replace_html($html, array_replace($state->global, $state->content), $state->social);
        });
    }
    $state->{$source} = $values;
    $state->social = $social;
}
