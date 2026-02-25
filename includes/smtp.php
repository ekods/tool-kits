<?php
if (!defined('ABSPATH')) exit;

function tk_smtp_init() {
    add_action('admin_post_tk_smtp_save', 'tk_smtp_save');
    add_action('admin_post_tk_smtp_test', 'tk_smtp_test_send');
    add_action('admin_post_tk_smtp_test_log_clear', 'tk_smtp_test_log_clear');
    // Run late so this SMTP config wins if other plugins also hook phpmailer_init.
    add_action('phpmailer_init', 'tk_smtp_phpmailer_init', 99999);
    add_filter('wp_mail_from', 'tk_smtp_mail_from', 20);
    add_filter('wp_mail_from_name', 'tk_smtp_mail_from_name', 20);
    add_action('wp_mail_failed', 'tk_smtp_test_log_capture_wp_mail_error');
    // Capture final PHPMailer transport after all plugins finish mutating it.
    add_action('phpmailer_init', 'tk_smtp_capture_transport_observer', 1000000);
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
    $force_from = (int) tk_get_option('smtp_force_from', 1) === 1;
    $return_path = (int) tk_get_option('smtp_return_path', 1) === 1;

    return array(
        'enabled' => tk_smtp_enabled(),
        'provider' => sanitize_key($provider),
        'host' => is_string($host) ? trim($host) : '',
        'port' => $port > 0 ? $port : 587,
        'secure' => in_array($secure, array('tls','ssl','tssl','none'), true) ? $secure : 'tls',
        'username' => tk_get_option('smtp_username', ''),
        'password' => tk_get_option('smtp_password', ''),
        'from_email' => tk_get_option('smtp_from_email', ''),
        'from_name' => tk_get_option('smtp_from_name', ''),
        'force_from' => $force_from,
        'return_path' => $return_path,
    );
}

function tk_smtp_phpmailer_init($phpmailer = null) {
    if (!tk_smtp_enabled()) {
        return;
    }

    if (!($phpmailer instanceof PHPMailer)) {
        return;
    }

    $config = tk_smtp_get_config();
    if ($config['host'] === '') {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host = $config['host'];
    $phpmailer->Port = $config['port'];

    if ($config['force_from'] && is_email($config['username'])) {
        $original_from = $phpmailer->From;
        $original_from_name = $phpmailer->FromName;
        $from_domain = tk_smtp_email_domain($original_from);
        $username_domain = tk_smtp_email_domain($config['username']);
        if ($from_domain === '' || $username_domain === '' || $from_domain !== $username_domain) {
            $phpmailer->setFrom($config['username'], $phpmailer->FromName, false);
            if (
                is_email($original_from)
                && method_exists($phpmailer, 'getReplyToAddresses')
                && $from_domain !== ''
                && $from_domain === $username_domain
            ) {
                $reply_to = $phpmailer->getReplyToAddresses();
                if (empty($reply_to)) {
                    $phpmailer->addReplyTo($original_from, $original_from_name);
                }
            }
        }
    }

    $secure_setting = $config['secure'];
    if ($secure_setting === 'none') {
        $phpmailer->SMTPSecure = '';
    } else {
        $phpmailer->SMTPSecure = $secure_setting === 'tssl' ? 'tls' : $secure_setting;
    }

    $phpmailer->SMTPAutoTLS = true;
    $phpmailer->SMTPAuth = $config['username'] !== '';

    if ($phpmailer->SMTPAuth) {
        $phpmailer->Username = $config['username'];
        if ($config['password'] !== '') {
            $phpmailer->Password = $config['password'];
        }
    }

    if ($config['return_path'] && is_email($phpmailer->From)) {
        $phpmailer->Sender = $phpmailer->From;
    }

    tk_smtp_capture_last_transport($phpmailer);
}

function tk_smtp_mail_from($current) {
    if (!tk_smtp_enabled()) {
        return $current;
    }
    $force_from = (int) tk_get_option('smtp_force_from', 1) === 1;
    $from = tk_get_option('smtp_from_email', '');
    $username = tk_get_option('smtp_username', '');
    if ($force_from && is_email($username)) {
        if ($from === '') {
            return $username;
        }
        $from_domain = tk_smtp_email_domain($from);
        $username_domain = tk_smtp_email_domain($username);
        if ($from_domain === '' || $username_domain === '' || $from_domain !== $username_domain) {
            return $username;
        }
    }
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
        'smtp_force_from' => tk_get_option('smtp_force_from', 1),
        'smtp_return_path' => tk_get_option('smtp_return_path', 1),
    );

    $presets = tk_smtp_provider_presets();
    $from_username_aligned = tk_smtp_from_username_match();
    $last_failure_reason = tk_smtp_test_log_last_failure_reason();
    $provider_notes = array(
        'gmail' => '<ul class="tk-note-list"><li>' . __('Create an app password in your Google account.', 'tool-kits') . '</li><li>' . __('Use the app password instead of your regular Google password.', 'tool-kits') . '</li><li>' . __('Ensure the Mail scope is allowed and two-factor auth is enabled.', 'tool-kits') . '</li></ul>',
        'office365' => '<ul class="tk-note-list"><li>' . __('Confirm the username is a licensed mailbox with an active mailbox plan.', 'tool-kits') . '</li><li>' . __('Enable SMTP AUTH for that mailbox in the Microsoft 365 admin center.', 'tool-kits') . '</li><li>' . __('If your tenant blocks basic auth globally, allow SMTP AUTH for that user.', 'tool-kits') . '</li></ul>',
        'custom' => '<p>' . __('Use the credentials provided by your SMTP service and verify any required ports or TLS/SSL settings.', 'tool-kits') . '</p>',
    );
    $saved = isset($_GET['tk_saved']) ? sanitize_key($_GET['tk_saved']) : '';
    $test_status = isset($_GET['tk_smtp_test']) ? sanitize_key($_GET['tk_smtp_test']) : '';
    $test_email = isset($_GET['tk_smtp_test_email']) ? sanitize_email(wp_unslash($_GET['tk_smtp_test_email'])) : '';
    $smtp_test_log = tk_smtp_test_log_get();
    $log_cleared = isset($_GET['tk_smtp_log_cleared']) ? sanitize_key($_GET['tk_smtp_log_cleared']) : '';
    $transport_warning = tk_smtp_transport_warning($opts);
    ?>
    <div class="wrap tk-wrap">
        <h1>SMTP</h1>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('SMTP settings saved.', 'success'); ?>
        <?php endif; ?>
        <?php if ($log_cleared === '1') : ?>
            <?php tk_notice('SMTP test log cleared.', 'success'); ?>
        <?php endif; ?>
        <?php if ($transport_warning !== '') : ?>
            <?php tk_notice($transport_warning, 'warning'); ?>
        <?php endif; ?>
        <div class="tk-tabs">
            <div class="tk-tabs-nav">
                <button type="button" class="tk-tabs-nav-button is-active" data-panel="settings">Settings</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="test">Send test email</button>
                <button type="button" class="tk-tabs-nav-button" data-panel="log">Log</button>
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
                                <option value="tssl" <?php selected('tssl', $opts['smtp_secure']); ?>>TSSL (TLS/SSL)</option>
                                <option value="none" <?php selected('none', $opts['smtp_secure']); ?>>None</option>
                            </select>
                            <span class="description">TSSL lets PHPMailer negotiate TLS/SSL automatically, which helps with providers that accept either protocol.</span>
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
                        <?php if ($opts['smtp_from_email'] !== '' && !$from_username_aligned) : ?>
                            <p class="description"><strong><?php esc_html_e('Recommendation:', 'tool-kits'); ?></strong> <?php esc_html_e('Use the same domain for the From email and the SMTP login so receivers can verify the sender.', 'tool-kits'); ?></p>
                        <?php elseif ($opts['smtp_from_email'] === '' && is_email($opts['smtp_username'])) : ?>
                            <p class="description"><?php esc_html_e('Blank From email will default to the authenticated SMTP account, keeping the domain aligned.', 'tool-kits'); ?></p>
                        <?php endif; ?>
                        <p>
                            <label><input type="checkbox" name="smtp_force_from" value="1" <?php checked(1, $opts['smtp_force_from']); ?>> <?php esc_html_e('Force From email to match SMTP login domain', 'tool-kits'); ?></label><br>
                            <span class="description"><?php esc_html_e('This helps prevent unverified sender warnings in Outlook and Gmail.', 'tool-kits'); ?></span>
                        </p>
                        <p>
                            <label><input type="checkbox" name="smtp_return_path" value="1" <?php checked(1, $opts['smtp_return_path']); ?>> <?php esc_html_e('Set return-path to From email', 'tool-kits'); ?></label><br>
                            <span class="description"><?php esc_html_e('Improves SPF alignment by matching the envelope sender to the From address.', 'tool-kits'); ?></span>
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
                    <?php if ($last_failure_reason !== '') : ?>
                        <p class="description"><strong><?php esc_html_e('Last failure:', 'tool-kits'); ?></strong> <?php echo esc_html($last_failure_reason); ?></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                        <?php tk_nonce_field('tk_smtp_test'); ?>
                        <input type="hidden" name="action" value="tk_smtp_test">
                        <p>
                            <label for="tk-smtp-test-email">Recipient email</label><br>
                            <input id="tk-smtp-test-email" class="regular-text" type="email" name="smtp_test_email" value="<?php echo esc_attr($test_email); ?>" placeholder="<?php echo esc_attr((string)get_option('admin_email')); ?>">
                        </p>
                        <p>
                            <label for="tk-smtp-test-message">Message (optional)</label><br>
                            <textarea id="tk-smtp-test-message" name="smtp_test_message" class="large-text" rows="3">Hello,

This is a delivery check message from the website mail system.

Regards,
Mail Service</textarea>
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
                <div class="tk-card tk-tab-panel" data-panel-id="log">
                    <h2>SMTP test log</h2>
                    <p><small>Recent test attempts (successes/failures) are recorded here.</small></p>
                    <?php if (!empty($smtp_test_log)) : ?>
                        <div class="tk-table-scroll">
                        <table class="widefat striped tk-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Sender</th>
                                    <th>Recipient</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                    <th>Message</th>
                                    <th>Details</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($smtp_test_log as $entry) :
                                    $entry_time = isset($entry['time']) ? (int) $entry['time'] : 0;
                                    $entry_status = isset($entry['status']) ? (string) $entry['status'] : 'unknown';
                                    $entry_sender = isset($entry['sender']) ? (string) $entry['sender'] : '';
                                    $entry_recipient = isset($entry['recipient']) ? (string) $entry['recipient'] : '';
                                    $entry_message = isset($entry['message']) ? (string) $entry['message'] : '';
                                    $entry_reason = isset($entry['reason']) ? (string) $entry['reason'] : '';
                                    $entry_details = isset($entry['details']) && is_array($entry['details']) ? $entry['details'] : array();
                                    $status_class = $entry_status === 'success' ? 'tk-on' : ($entry_status === 'fail' ? 'tk-warn' : '');
                                    $status_label = ucfirst($entry_status);
                                ?>
                                    <tr>
                                        <td><?php echo esc_html(date_i18n('Y-m-d H:i', $entry_time)); ?></td>
                                        <td><?php echo esc_html($entry_sender); ?></td>
                                        <td><?php echo esc_html($entry_recipient); ?></td>
                                        <td><span class="tk-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                                        <td><?php echo esc_html($entry_reason !== '' ? wp_trim_words($entry_reason, 20, '...') : '-'); ?></td>
                                        <td><?php echo esc_html(wp_trim_words($entry_message, 20, '...')); ?></td>
                                        <td><?php echo esc_html(tk_smtp_test_log_format_details($entry_details)); ?></td>
                                        <td>
                                            <?php if ($entry_recipient !== '') : ?>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                                    <?php tk_nonce_field('tk_smtp_test'); ?>
                                                    <input type="hidden" name="action" value="tk_smtp_test">
                                                    <input type="hidden" name="smtp_test_email" value="<?php echo esc_attr($entry_recipient); ?>">
                                                    <input type="hidden" name="smtp_test_message" value="<?php echo esc_attr($entry_message); ?>">
                                                    <button type="submit" class="button button-secondary button-small">Resend</button>
                                                </form>
                                            <?php else : ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php else : ?>
                        <p><small>No SMTP test log entries yet.</small></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                        <?php tk_nonce_field('tk_smtp_test_log_clear'); ?>
                        <input type="hidden" name="action" value="tk_smtp_test_log_clear">
                        <button class="button button-secondary">Clear log</button>
                    </form>
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
        function markSecureAuto(value) {
            if (!secure) { return; }
            secure.setAttribute('data-tk-smtp-autosecure', value);
        }
        function shouldSyncSecure(presetValue) {
            if (!secure) { return false; }
            var autoValue = secure.getAttribute('data-tk-smtp-autosecure');
            if (!autoValue) {
                return secure.value === '' || secure.value === presetValue;
            }
            return secure.value === autoValue;
        }
        if (secure) {
            secure.addEventListener('change', function(){
                secure.removeAttribute('data-tk-smtp-autosecure');
            });
        }
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
                    if (presetSecure && secure && shouldSyncSecure(presetSecure)) {
                        secure.value = presetSecure;
                        markSecureAuto(presetSecure);
                    }
                } else if (secure) {
                    secure.removeAttribute('data-tk-smtp-autosecure');
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
    if (!in_array($secure, array('tls','ssl','tssl','none'), true)) {
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
    if ($from_email === '' && is_email($username)) {
        $from_email = $username;
    }
    tk_update_option('smtp_from_email', $from_email);
    $from_name = isset($_POST['smtp_from_name']) ? sanitize_text_field(wp_unslash($_POST['smtp_from_name'])) : '';
    tk_update_option('smtp_from_name', $from_name);
    tk_update_option('smtp_force_from', !empty($_POST['smtp_force_from']) ? 1 : 0);
    tk_update_option('smtp_return_path', !empty($_POST['smtp_return_path']) ? 1 : 0);

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
        $message = sprintf(
            "Hello,\n\nThis message confirms outbound email delivery from %s.\nSent at: %s\n\nRegards,\nMail Service",
            home_url('/'),
            wp_date('Y-m-d H:i:s T')
        );
    }

    tk_smtp_test_log_clear_error();

    $site_name = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    if ($site_name === '') {
        $site_name = parse_url(home_url('/'), PHP_URL_HOST) ?: 'Website';
    }
    $subject = sprintf('Message delivery check - %s', $site_name);

    $config = tk_smtp_get_config();
    $original_from = (string) get_option('admin_email');
    $from_email = tk_smtp_mail_from($original_from);
    $from_name = tk_smtp_mail_from_name((string) get_option('blogname'));
    $reply_to = '';
    if ($config['force_from'] && is_email($original_from) && $from_email !== $original_from) {
        $from_domain = tk_smtp_email_domain($from_email);
        $original_domain = tk_smtp_email_domain($original_from);
        if ($from_domain !== '' && $from_domain === $original_domain) {
            $reply_to = $original_from;
        }
    }
    $details = array(
        'from' => $from_email,
        'from_name' => $from_name,
        'reply_to' => $reply_to,
        'return_path' => $config['return_path'] ? $from_email : '',
        'content_type' => 'text/plain; charset=UTF-8',
        'smtp_host' => $config['host'],
        'smtp_port' => $config['port'],
        'smtp_secure' => $config['secure'],
        'smtp_autotls' => 'on',
        'smtp_auth' => $config['username'] !== '' ? 'on' : 'off',
        'smtp_user' => $config['username'],
        'force_from' => $config['force_from'] ? 'on' : 'off',
        'return_path_enabled' => $config['return_path'] ? 'on' : 'off',
        'auth_check' => tk_smtp_auth_check_summary($from_email),
    );

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'Auto-Submitted: auto-generated',
        'X-Auto-Response-Suppress: All',
        'X-Mailer: Tool Kits SMTP',
    );
    $sent = wp_mail($recipient, $subject, $message, $headers);
    $transport = tk_smtp_last_transport();
    $details['transport_mailer'] = isset($transport['mailer']) ? (string) $transport['mailer'] : '';
    $details['transport_host'] = isset($transport['host']) ? (string) $transport['host'] : '';
    $status = $sent ? 'success' : 'fail';
    tk_log(sprintf('SMTP test email to %s %s', $recipient, $status));
    $reason = $status === 'fail' ? tk_smtp_test_log_get_error() : '';
    tk_smtp_test_log_record($recipient, $status, $message, $reason, $details);

    $redirect_args = array(
        'page' => 'tool-kits-smtp',
        'tk_smtp_test' => $status,
        'tk_smtp_test_email' => $recipient,
    );
    $redirect = add_query_arg($redirect_args, admin_url('admin.php'));
    wp_redirect($redirect);
    exit;
}

function tk_smtp_test_log_get(): array {
    $log = tk_get_option('smtp_test_log', array());
    if (!is_array($log)) {
        return array();
    }
    return array_values($log);
}

function tk_smtp_test_log_record(string $recipient, string $status, string $message, string $reason = '', array $details = array()): void {
    if ($status === 'fail' && $reason === '') {
        $reason = tk_smtp_test_log_default_reason();
    }
    $log = tk_smtp_test_log_get();
    array_unshift($log, array(
        'time' => current_time('timestamp', 1),
        'recipient' => $recipient,
        'status' => $status,
        'message' => $message,
        'reason' => $reason,
        'details' => $details,
        'sender' => tk_smtp_test_log_format_sender(),
    ));
    $log = array_slice($log, 0, 50);
    tk_update_option('smtp_test_log', $log);
}

function tk_smtp_test_log_default_reason(): string {
    return __('No SMTP error message was recorded.', 'tool-kits');
}

function tk_smtp_test_log_error_helper(string $value = null): string {
    static $last_error = '';
    if (func_num_args() > 0) {
        $last_error = $value !== null ? (string) $value : '';
    }
    return $last_error;
}

function tk_smtp_test_log_clear_error(): void {
    tk_smtp_test_log_error_helper('');
}

function tk_smtp_test_log_get_error(): string {
    return tk_smtp_test_log_error_helper();
}

function tk_smtp_test_log_capture_wp_mail_error(WP_Error $wp_error): void {
    if (!is_wp_error($wp_error)) {
        return;
    }
    $message = trim($wp_error->get_error_message());
    tk_smtp_test_log_error_helper($message);
}

function tk_smtp_email_domain(string $value): string {
    if (!is_email($value)) {
        return '';
    }
    $parts = explode('@', $value);
    if (count($parts) !== 2) {
        return '';
    }
    return strtolower($parts[1]);
}

function tk_smtp_dns_txt_records(string $host): array {
    $host = trim(strtolower($host));
    if ($host === '') {
        return array();
    }
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_TXT);
        if (is_array($records)) {
            return $records;
        }
    }
    return array();
}

function tk_smtp_dns_txt_contains(string $host, string $needle): bool {
    $needle = strtolower($needle);
    $records = tk_smtp_dns_txt_records($host);
    if (!empty($records)) {
        foreach ($records as $record) {
            $txt = '';
            if (isset($record['txt']) && is_string($record['txt'])) {
                $txt = $record['txt'];
            } elseif (isset($record['entries']) && is_array($record['entries'])) {
                $txt = implode('', array_map('strval', $record['entries']));
            }
            if ($txt !== '' && strpos(strtolower($txt), $needle) !== false) {
                return true;
            }
        }
        return false;
    }
    if (function_exists('checkdnsrr')) {
        return @checkdnsrr($host, 'TXT');
    }
    return false;
}

function tk_smtp_dkim_exists(string $domain): bool {
    $selectors = array('selector1', 'selector2', 'default', 'google', 'k1', 'dkim');
    foreach ($selectors as $selector) {
        $host = $selector . '._domainkey.' . $domain;
        if (tk_smtp_dns_txt_contains($host, 'v=dkim1') || tk_smtp_dns_txt_contains($host, ' p=')) {
            return true;
        }
    }
    if (function_exists('checkdnsrr')) {
        $domainkey = '_domainkey.' . $domain;
        if (@checkdnsrr($domainkey, 'NS') || @checkdnsrr($domainkey, 'CNAME') || @checkdnsrr($domainkey, 'TXT')) {
            return true;
        }
    }
    return false;
}

function tk_smtp_auth_check_summary(string $from_email): string {
    $domain = tk_smtp_email_domain($from_email);
    if ($domain === '') {
        return 'not available';
    }
    $spf_ok = tk_smtp_dns_txt_contains($domain, 'v=spf1');
    $dmarc_ok = tk_smtp_dns_txt_contains('_dmarc.' . $domain, 'v=dmarc1');
    $dkim_ok = tk_smtp_dkim_exists($domain);

    $parts = array(
        'SPF: ' . ($spf_ok ? 'ok' : 'missing'),
        'DKIM: ' . ($dkim_ok ? 'ok' : 'missing'),
        'DMARC: ' . ($dmarc_ok ? 'ok' : 'missing'),
    );
    return implode(', ', $parts);
}

function tk_smtp_capture_last_transport(PHPMailer $phpmailer): void {
    $data = array(
        'time' => time(),
        'mailer' => isset($phpmailer->Mailer) ? (string) $phpmailer->Mailer : '',
        'host' => isset($phpmailer->Host) ? (string) $phpmailer->Host : '',
        'port' => isset($phpmailer->Port) ? (int) $phpmailer->Port : 0,
        'secure' => isset($phpmailer->SMTPSecure) ? (string) $phpmailer->SMTPSecure : '',
        'auth' => !empty($phpmailer->SMTPAuth) ? 'on' : 'off',
    );
    tk_update_option('smtp_last_transport', $data);
}

function tk_smtp_capture_transport_observer($phpmailer = null): void {
    if (!($phpmailer instanceof PHPMailer)) {
        return;
    }
    tk_smtp_capture_last_transport($phpmailer);
}

function tk_smtp_last_transport(): array {
    $data = tk_get_option('smtp_last_transport', array());
    return is_array($data) ? $data : array();
}

function tk_smtp_transport_warning(array $opts): string {
    if ((int) $opts['smtp_enabled'] !== 1) {
        return '';
    }
    if (!isset($opts['smtp_provider']) || (string) $opts['smtp_provider'] !== 'office365') {
        return '';
    }
    $last = tk_smtp_last_transport();
    if (empty($last)) {
        return 'No transport data yet. Send a test email first to verify the actual delivery path.';
    }
    $mailer = isset($last['mailer']) ? strtolower((string) $last['mailer']) : '';
    $host = isset($last['host']) ? strtolower((string) $last['host']) : '';
    if ($mailer !== 'smtp' || $host !== 'smtp.office365.com') {
        return sprintf(
            'Last detected transport is Mailer=%s Host=%s. This is not Office365 SMTP and can cause Junk/Unverified.',
            $mailer !== '' ? $mailer : '(empty)',
            $host !== '' ? $host : '(empty)'
        );
    }
    return '';
}

function tk_smtp_from_username_match(): bool {
    $from = tk_get_option('smtp_from_email', '');
    $username = tk_get_option('smtp_username', '');
    $from_domain = tk_smtp_email_domain($from);
    $username_domain = tk_smtp_email_domain($username);
    return $from_domain !== '' && $from_domain === $username_domain;
}

function tk_smtp_test_log_last_entry(): ?array {
    $log = tk_smtp_test_log_get();
    return !empty($log) ? $log[0] : null;
}

function tk_smtp_test_log_last_failure_reason(): string {
    $last = tk_smtp_test_log_last_entry();
    if ($last && isset($last['status']) && $last['status'] === 'fail' && !empty($last['reason'])) {
        return (string) $last['reason'];
    }
    return '';
}

function tk_smtp_test_log_format_sender(): string {
    $email = tk_get_option('smtp_from_email', '');
    $name = tk_get_option('smtp_from_name', '');
    if ($email === '') {
        return __('(default)', 'tool-kits');
    }
    if ($name === '') {
        return $email;
    }
    return sprintf('%s <%s>', $name, $email);
}

function tk_smtp_test_log_format_details(array $details): string {
    $map = array(
        'from' => 'From',
        'reply_to' => 'Reply-To',
        'return_path' => 'Return-Path',
        'content_type' => 'Content-Type',
        'smtp_host' => 'SMTP Host',
        'smtp_port' => 'Port',
        'smtp_secure' => 'Secure',
        'smtp_autotls' => 'AutoTLS',
        'smtp_auth' => 'Auth',
        'smtp_user' => 'User',
        'force_from' => 'Force From',
        'return_path_enabled' => 'Return-Path Enabled',
        'auth_check' => 'SPF/DKIM/DMARC',
        'transport_mailer' => 'Transport',
        'transport_host' => 'Transport Host',
    );
    $parts = array();
    foreach ($map as $key => $label) {
        if (!isset($details[$key]) || $details[$key] === '') {
            continue;
        }
        $parts[] = $label . ': ' . $details[$key];
    }
    return implode(' | ', $parts);
}

function tk_smtp_test_log_clear() {
    if (!tk_is_admin_user()) {
        wp_die('Forbidden');
    }
    tk_check_nonce('tk_smtp_test_log_clear');
    tk_update_option('smtp_test_log', array());
    $redirect = add_query_arg(array(
        'page' => 'tool-kits-smtp',
        'tk_smtp_log_cleared' => '1',
    ), admin_url('admin.php'));
    wp_redirect($redirect);
    exit;
}
