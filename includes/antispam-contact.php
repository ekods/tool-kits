<?php
if (!defined('ABSPATH')) { exit; }

function tk_antispam_contact_init() {
    add_action('admin_post_tk_antispam_save', 'tk_antispam_save_settings');

    // Contact Form 7 integration (optional, only active if CF7 present + enabled)
    add_filter('wpcf7_form_elements', 'tk_cf7_add_honeypot_and_time', 20, 1);
    add_filter('wpcf7_validate', 'tk_cf7_validate_honeypot_and_time', 20, 2);
    add_filter('wpcf7_validate_text', 'tk_cf7_validate_honeypot_and_time', 20, 2);
    add_filter('wpcf7_validate_email', 'tk_cf7_validate_honeypot_and_time', 20, 2);
}

function tk_antispam_enabled() {
    return (int) tk_get_option('antispam_cf7_enabled', 0) === 1;
}

function tk_antispam_key() {
    return 'tk_antispam_' . md5(tk_get_ip() . '|' . tk_user_agent());
}

function tk_cf7_add_honeypot_and_time($form) {
    if (!tk_antispam_enabled()) return $form;
    if (!function_exists('wpcf7')) return $form;

    $ts = time();
    set_transient(tk_antispam_key(), $ts, 30 * MINUTE_IN_SECONDS);

    $honeypot = '<span class="tk-hp" style="position:absolute;left:-9999px;top:-9999px;height:1px;overflow:hidden;" aria-hidden="true">'
        . '<label>Leave this field empty<input type="text" name="tk_hp_field" value="" tabindex="-1" autocomplete="off"></label>'
        . '</span>';

    $timefield = '<input type="hidden" name="tk_form_ts" value="' . esc_attr($ts) . '">';

    return $form . $honeypot . $timefield;
}

function tk_cf7_validate_honeypot_and_time($result, $tags) {
    if (!tk_antispam_enabled()) return $result;
    if (!function_exists('wpcf7')) return $result;

    $hp = isset($_POST['tk_hp_field']) ? trim(wp_unslash($_POST['tk_hp_field'])) : '';
    if ($hp !== '') {
        $result->invalidate(null, __('Spam detected.', 'tool-kits'));
        return $result;
    }

    $min = (int) tk_get_option('antispam_min_seconds', 3);
    $posted = isset($_POST['tk_form_ts']) ? (int) $_POST['tk_form_ts'] : 0;
    $stored = (int) get_transient(tk_antispam_key());

    // If no timestamp, be strict but still allow (some caching might strip fields); we only enforce if both present.
    if ($posted > 0 && $stored > 0) {
        $elapsed = time() - $stored;
        if ($elapsed < $min) {
            $result->invalidate(null, __('Form submitted too quickly. Please try again.', 'tool-kits'));
            return $result;
        }
    }
    return $result;
}

function tk_render_antispam_contact_page() {
    if (!tk_is_admin_user()) return;

    $enabled = (int) tk_get_option('antispam_cf7_enabled', 0);
    $min_seconds = (int) tk_get_option('antispam_min_seconds', 3);
    $cf7_installed = function_exists('wpcf7');

    ?>
    <div class="wrap tk-wrap">
        <h1>Anti-spam Contact</h1>

        <div class="tk-card">
            <p>This module adds a <strong>honeypot</strong> and <strong>minimum submit time</strong> to Contact Form 7 submissions.</p>
            <p>CF7 status: <?php echo $cf7_installed ? '<span class="tk-badge tk-on">Detected</span>' : '<span class="tk-badge">Not Installed</span>'; ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_antispam_save'); ?>
                <input type="hidden" name="action" value="tk_antispam_save">

                <label><input type="checkbox" name="enabled" value="1" <?php checked(1, $enabled); ?>> Enable for Contact Form 7</label>

                <label><strong>Minimum seconds before submit</strong></label>
                <input class="small-text" type="number" min="0" name="min_seconds" value="<?php echo esc_attr($min_seconds); ?>"> seconds

                <p><button class="button button-primary">Save</button></p>
            </form>

            <p class="description">If CF7 is not installed, the module stays passive and safe.</p>
        </div>
    </div>
    <?php
}

function tk_antispam_save_settings() {
    if (!tk_is_admin_user()) wp_die('Forbidden');
    tk_check_nonce('tk_antispam_save');

    tk_update_option('antispam_cf7_enabled', !empty($_POST['enabled']) ? 1 : 0);
    tk_update_option('antispam_min_seconds', max(0, (int) tk_post('min_seconds', 3)));

    wp_redirect(add_query_arg(array('page'=>'tool-kits-security-antispam','tk_saved'=>1), admin_url('admin.php')));
    exit;
}
