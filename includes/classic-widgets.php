<?php
if (!defined('ABSPATH')) { exit; }

function tk_classic_widgets_init(): void {
    if (!tk_classic_widgets_enabled()) {
        return;
    }

    add_filter('use_widgets_block_editor', '__return_false', 100);
    add_filter('gutenberg_use_widgets_block_editor', '__return_false', 100);
}

function tk_classic_widgets_enabled(): bool {
    return (int) tk_get_option('classic_widgets_enabled', 0) === 1;
}
