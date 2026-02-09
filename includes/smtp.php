<?php
if (!defined('ABSPATH')) exit;

function tk_smtp_init() {
    add_action('admin_post_tk_smtp_save', 'tk_smtp_save');
    add_action('admin_post_tk_smtp_test', 'tk_smtp_test_send');
    add_action('phpmailer_init', 'tk_smtp_phpmailer_init');
    add_filter('wp_mail_from', 'tk_smtp_mail_from', 20);
    add_filter('wp_mail_from_name', 'tk_smtp_mail_from_name', 20);
}

function tk_smtp_enabled() {
    return (int) tk_get_option('smtp_enabled', 0) === 1;
}

function tk_smtp_provider_presets() {
    return array(
        'gmail' => array(
            'label' => 'Gmail (smtp.gmail.com)',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'secure' => 'tls',
        ),
        'office365' => array(
            'label' => 'Microsoft 365 / Office 365 (smtp.office365.com)',
            'host' => 'smtp.office365.com',
            'port' => 587,
            'secure' => 'tls',
        ),
        'custom' => array(
            'label' => 'Custom',
            'host' => '',
            'port' => 587,
            'secure' => 'tls',
        ),
    );
}

function tk_smtp_get_config() {
    $provider = tk_get_option('smtp_provider', 'gmail');
    $secure = tk_get_option('smtp_secure', 'tls');
    $host = tk_get_option('smtp_host', 'smtp.gmail.com');
    $port = (int) tk_get_option('smtp_port', 587);

    return array(
        'enabled' => tk_smtp_enabled(),
        'provider' => sanitize_key($provider),
        'host' => is_string($host) ? trim($host) : '',
        'port' => $port > 0 ? $port : 587,
        'secure' => in_array($secure, array('tls','ssl','none'), true) ? $secure : 'tls',
        'username' => tk_get_option('smtp_username', ''),
        'password' => tk_get_option('smtp_password', ''),
        'from_email' => tk_get_option('smtp_from_email', ''),
        'from_name' => tk_get_option('smtp_from_name', ''),
    );
}

function tk_smtp_phpmailer_init(PHPMailer $phpmailer) {
    if (!tk_smtp_enabled()) {
        return;
    }

    $config = tk_smtp_get_config();
    if ($config['host'] === '') {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host = $config['host'];
    $phpmailer->Port = $config['port'];

    if ($config['secure'] !== 'none') {
        $phpmailer->SMTPSecure = $config['secure'];
    } else {
        $phpmailer->SMTPSecure = '';
    }

    $phpmailer->SMTPAutoTLS = true;
    $phpmailer->SMTPAuth = $config['username'] !== '';

    if ($phpmailer->SMTPAuth) {
        $phpmailer->Username = $config['username'];
        if ($config['password'] !== '') {
            $phpmailer->Password = $config['password'];
        }
    }
}

function tk_smtp_mail_from($current) {
    if (!tk_smtp_enabled()) {
        return $current;
    }
    $from = tk_get_option('smtp_from_email', '');
    if (is_email($from)) {
        return $from;
    }
    return $current;
}

function tk_smtp_mail_from_name($current) {
    if (!tk_smtp_enabled()) {
        return $current;
    }
    $name = tk_get_option('smtp_from_name', '');
    return $name !== '' ? $name : $current;
}

function tk_render_smtp_page() {
    if (!tk_is_admin_user()) {
        return;
    }

    $opts = array(
        'smtp_enabled' => tk_get_option('smtp_enabled', 0),
        'smtp_provider' => tk_get_option('smtp_provider', 'gmail'),
        'smtp_host' => tk_get_option('smtp_host', 'smtp.gmail.com'),
        'smtp_port' => tk_get_option('smtp_port', 587),
        'smtp_secure' => tk_get_option('smtp_secure', 'tls'),
        'smtp_username' => tk_get_option('smtp_username', ''),
        'smtp_from_email' => tk_get_option('smtp_from_email', ''),
        'smtp_from_name' => tk_get_option('smtp_from_name', ''),
    );

    $presets = tk_smtp_provider_presets();
    $provider_notes = array(
        'gmail' => '<ul class="tk-note-list"><li>' . __('Create an app password in your Google account.', 'tool-kits') . '</li><li>' . __('Use the app password instead of your regular Google password.', 'tool-kits') . '</li><li>' . __('Ensure the Mail scope is allowed and two-factor auth is enabled.', 'tool-kits') . '</li></ul>',
        'office365' => '<ul class="tk-note-list"><li>' . __('Confirm the username is a licensed mailbox with an active mailbox plan.', 'tool-kits') . '</li><li>' . __('Enable SMTP AUTH for that mailbox in the Microsoft 365 admin center.', 'tool-kits') . '</li><li>' . __('If your tenant blocks basic auth globally, allow SMTP AUTH for that user.', 'tool-kits') . '</li></ul>',
        'custom' => '<p>' . __('Use the credentials provided by your SMTP service and verify any required ports or TLS/SSL settings.', 'tool-kits') . '</p>',
    );
    $saved = isset($_GET['tk_saved']) ? sanitize_key($_GET['tk_saved']) : '';
    $test_status = isset($_GET['tk_smtp_test']) ? sanitize_key($_GET['tk_smtp_test']) : '';
    $test_email = isset($_GET['tk_smtp_test_email']) ? sanitize_email(wp_unslash($_GET['tk_smtp_test_email'])) : '';
    ?>
    <div class="wrap tk-wrap">
        <h1>SMTP</h1>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('SMTP settings saved.', 'success'); ?>
        <?php endif; ?>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="settings">Settings</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="test">Send test email</button>
            </div>
            <div class="tk-tabs-content">
                <div class="tk-card tk-tab-panel is-active" data-panel-id="settings">
                    <p>Route WordPress emails through a trusted SMTP provider. Gmail and Microsoft 365 are pre-configured; you can also select "Custom" to enter your own server credentials.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php tk_nonce_field('tk_smtp_save'); ?>
                        <input type="hidden" name="action" value="tk_smtp_save">
                        <p><label><input type="checkbox" name="smtp_enabled" value="1" <?php checked(1, $opts['smtp_enabled']); ?>> Enable SMTP delivery override</label></p>
                        <p>
                            <label for="tk-smtp-provider"><strong>Provider</strong></label><br>
                            <select name="smtp_provider" id="tk-smtp-provider" class="regular-text">
                                <?php foreach ($presets as $key => $preset) : ?>
                                    <?php $note = isset($provider_notes[$key]) ? $provider_notes[$key] : ''; ?>
                                    <option value="<?php echo esc_attr($key); ?>" data-host="<?php echo esc_attr($preset['host']); ?>" data-port="<?php echo esc_attr($preset['port']); ?>" data-secure="<?php echo esc_attr($preset['secure']); ?>" data-note="<?php echo esc_attr($note); ?>" <?php selected($key, $opts['smtp_provider']); ?>><?php echo esc_html($preset['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="tk-smtp-host">SMTP host</label><br>
                            <input type="text" id="tk-smtp-host" name="smtp_host" class="regular-text" value="<?php echo esc_attr($opts['smtp_host']); ?>" placeholder="smtp.example.com">
                        </p>
                        <p>
                            <label for="tk-smtp-port">Port</label><br>
                            <input type="number" id="tk-smtp-port" name="smtp_port" class="small-text" value="<?php echo esc_attr($opts['smtp_port']); ?>">
                        </p>
                        <p>
                            <label for="tk-smtp-secure">Encryption</label><br>
                            <select name="smtp_secure" id="tk-smtp-secure" class="regular-text">
                                <option value="tls" <?php selected('tls', $opts['smtp_secure']); ?>>TLS</option>
                                <option value="ssl" <?php selected('ssl', $opts['smtp_secure']); ?>>SSL</option>
                                <option value="none" <?php selected('none', $opts['smtp_secure']); ?>>None</option>
                            </select>
                        </p>
                        <p>
                            <label for="tk-smtp-username">Username</label><br>
                            <input type="text" id="tk-smtp-username" name="smtp_username" class="regular-text" value="<?php echo esc_attr($opts['smtp_username']); ?>">
                        </p>
                        <p>
                            <label for="tk-smtp-password">Password / App password</label><br>
                            <input type="password" id="tk-smtp-password" name="smtp_password" class="regular-text" autocomplete="new-password" placeholder="Leave blank to keep current password">
                        </p>
                        <p>
                            <label for="tk-smtp-from-email">From email (optional)</label><br>
                            <input type="email" id="tk-smtp-from-email" name="smtp_from_email" class="regular-text" value="<?php echo esc_attr($opts['smtp_from_email']); ?>">
                        </p>
                        <p>
                            <label for="tk-smtp-from-name">From name (optional)</label><br>
                            <input type="text" id="tk-smtp-from-name" name="smtp_from_name" class="regular-text" value="<?php echo esc_attr($opts['smtp_from_name']); ?>">
                        </p>
                        <div id="tk-smtp-provider-note" class="description">
                            <?php
                            $default_note = isset($provider_notes[$opts['smtp_provider']]) ? $provider_notes[$opts['smtp_provider']] : '';
                            echo wp_kses_post($default_note);
                            ?>
                        </div>
                        <p><button class="button button-primary">Save SMTP Settings</button></p>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="test">
                    <h2>Send test email</h2>
                    <p>Use this to confirm SMTP is working with the configured credentials.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                        <?php tk_nonce_field('tk_smtp_test'); ?>
                        <input type="hidden" name="action" value="tk_smtp_test">
                        <p>
                            <label for="tk-smtp-test-email">Recipient email</label><br>
                            <input id="tk-smtp-test-email" class="regular-text" type="email" name="smtp_test_email" value="<?php echo esc_attr($test_email); ?>" placeholder="<?php echo esc_attr((string)get_option('admin_email')); ?>">
                        </p>
                        <p>
                            <label for="tk-smtp-test-message">Message (optional)</label><br>
                            <textarea id="tk-smtp-test-message" name="smtp_test_message" class="large-text" rows="3">This is a test email sent via Tool Kits SMTP.</textarea>
                        </p>
                        <p><button class="button button-secondary">Send test email</button></p>
                    </form>
                    <?php if ($test_status !== '') : ?>
                        <?php if ($test_status === 'success') : ?>
                            <?php tk_notice('Test email sent successfully to ' . esc_html($test_email), 'success'); ?>
                        <?php elseif ($test_status === 'invalid') : ?>
                            <?php tk_notice('Invalid recipient email for test message.', 'error'); ?>
                        <?php else : ?>
                            <?php tk_notice('Failed to send test email. Check SMTP settings and server logs.', 'error'); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var select = document.getElementById('tk-smtp-provider');
        var host = document.getElementById('tk-smtp-host');
        var port = document.getElementById('tk-smtp-port');
        var secure = document.getElementById('tk-smtp-secure');
        var providerNote = document.getElementById('tk-smtp-provider-note');
        if (select) {
            function applyPreset() {
                var option = select.options[select.selectedIndex];
                if (!option) { return; }
                var note = option.getAttribute('data-note') || '';
                if (select.value !== 'custom') {
                    var presetHost = option.getAttribute('data-host');
                    var presetPort = option.getAttribute('data-port');
                    var presetSecure = option.getAttribute('data-secure');
                    if (presetHost && host) { host.value = presetHost; }
                    if (presetPort && port) { port.value = presetPort; }
                    if (presetSecure && secure) { secure.value = presetSecure; }
                }
                if (providerNote) {
                    providerNote.innerHTML = note;
                }
            }
            select.addEventListener('change', applyPreset);
            applyPreset();
        }
    })();
    (function(){
        var wrapper = document.querySelector('.tk-tabs');
        if (!wrapper) { return; }
        var buttons = wrapper.querySelectorAll('.tk-tabs-nav-button');
        var panels = wrapper.querySelectorAll('.tk-tab-panel');
        function activate(panelId) {
            panels.forEach(function(panel){
                panel.classList.toggle('is-active', panel.getAttribute('data-panel-id') === panelId);
            });
            buttons.forEach(function(button){
                button.classList.toggle('is-active', button.getAttribute('data-panel') === panelId);
            });
        }
        buttons.forEach(function(button){
            button.addEventListener('click', function(){
                var panelId = button.getAttribute('data-panel');
                if (panelId) {
                    activate(panelId);
                    window.location.hash = panelId;
                }
            });
        });
        function initialPanel() {
            var hash = window.location.hash ? window.location.hash.replace('#','') : '';
            return hash || 'settings';
        }
        activate(initialPanel());
    })();
    </script>
    <?php
}

function tk_smtp_save() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_smtp_save');

    $presets = tk_smtp_provider_presets();
    $provider = isset($_POST['smtp_provider']) ? sanitize_key(wp_unslash($_POST['smtp_provider'])) : 'gmail';
    if (!array_key_exists($provider, $presets)) {
        $provider = 'custom';
    }
    tk_update_option('smtp_enabled', !empty($_POST['smtp_enabled']) ? 1 : 0);
    tk_update_option('smtp_provider', $provider);

    $host = isset($_POST['smtp_host']) ? sanitize_text_field(wp_unslash($_POST['smtp_host'])) : '';
    $port = isset($_POST['smtp_port']) ? (int) $_POST['smtp_port'] : 0;
    $secure = isset($_POST['smtp_secure']) ? sanitize_key(wp_unslash($_POST['smtp_secure'])) : 'tls';
    if (!in_array($secure, array('tls','ssl','none'), true)) {
        $secure = 'tls';
    }

    if ($provider !== 'custom') {
        $defaults = $presets[$provider];
        if ($host === '') {
            $host = $defaults['host'];
        }
        if ($port <= 0) {
            $port = (int) $defaults['port'];
        }
        if ($secure === 'none') {
            $secure = $defaults['secure'];
        }
    }

    tk_update_option('smtp_host', $host);
    tk_update_option('smtp_port', $port);
    tk_update_option('smtp_secure', $secure);

    $username = isset($_POST['smtp_username']) ? sanitize_text_field(wp_unslash($_POST['smtp_username'])) : '';
    tk_update_option('smtp_username', $username);

    $password = isset($_POST['smtp_password']) ? wp_unslash($_POST['smtp_password']) : '';
    if (is_string($password) && $password !== '') {
        tk_update_option('smtp_password', $password);
    }

    $from_email = isset($_POST['smtp_from_email']) ? sanitize_email(wp_unslash($_POST['smtp_from_email'])) : '';
    tk_update_option('smtp_from_email', $from_email);
    $from_name = isset($_POST['smtp_from_name']) ? sanitize_text_field(wp_unslash($_POST['smtp_from_name'])) : '';
    tk_update_option('smtp_from_name', $from_name);

    $redirect = add_query_arg(array('page'=>'tool-kits-smtp','tk_saved'=>1), admin_url('admin.php'));
    wp_redirect($redirect);
    exit;
}

function tk_smtp_test_send() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_smtp_test');

    $recipient = isset($_POST['smtp_test_email']) ? sanitize_email(wp_unslash($_POST['smtp_test_email'])) : '';
    if ($recipient === '' || !is_email($recipient)) {
        $redirect = add_query_arg(array(
            'page' => 'tool-kits-smtp',
            'tk_smtp_test' => 'invalid',
        ), admin_url('admin.php'));
        wp_redirect($redirect);
        exit;
    }

    $message = isset($_POST['smtp_test_message']) ? sanitize_textarea_field(wp_unslash($_POST['smtp_test_message'])) : '';
    if ($message === '') {
        $message = sprintf('This is a test email sent from %s via Tool Kits SMTP.', home_url('/'));
    }

    $subject = 'Tool Kits SMTP test';

    $sent = wp_mail($recipient, $subject, $message);
    $status = $sent ? 'success' : 'fail';

    $redirect_args = array(
        'page' => 'tool-kits-smtp',
        'tk_smtp_test' => $status,
        'tk_smtp_test_email' => $recipient,
    );
    $redirect = add_query_arg($redirect_args, admin_url('admin.php'));
    wp_redirect($redirect);
    exit;
}
