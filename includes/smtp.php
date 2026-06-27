<?php
if (!defined('ABSPATH')) exit;

function tk_smtp_init() {
    add_action('admin_post_tk_smtp_save', 'tk_smtp_save');
    add_action('admin_post_tk_smtp_test', 'tk_smtp_test_send');
    add_action('admin_post_tk_smtp_test_log_clear', 'tk_smtp_test_log_clear');
    add_action('admin_post_tk_smtp_google_callback', 'tk_smtp_google_callback');
    add_action('admin_post_tk_smtp_google_disconnect', 'tk_smtp_google_disconnect');
    add_action('admin_post_tk_smtp_microsoft_callback', 'tk_smtp_microsoft_callback');
    add_action('admin_post_tk_smtp_microsoft_disconnect', 'tk_smtp_microsoft_disconnect');
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

function tk_smtp_is_phpmailer_compatible($phpmailer): bool {
    return is_object($phpmailer)
        && method_exists($phpmailer, 'isSMTP')
        && method_exists($phpmailer, 'setFrom')
        && method_exists($phpmailer, 'addReplyTo');
}

function tk_smtp_is_core_mail_flow(): bool {
    if (defined('DOING_CRON') && DOING_CRON) {
        return true;
    }

    if (!function_exists('wp_doing_cron')) {
        return false;
    }

    return wp_doing_cron();
}

function tk_smtp_normalize_email(string $email): string {
    $email = trim(strtolower($email));
    return is_email($email) ? $email : '';
}

function tk_smtp_force_from_address(string $current_from = ''): string {
    $force_from = (int) tk_get_option('smtp_force_from', 1) === 1;
    $from = tk_smtp_normalize_email((string) tk_get_option('smtp_from_email', ''));
    $username = tk_smtp_normalize_email((string) tk_get_option('smtp_username', ''));
    $current_from = tk_smtp_normalize_email($current_from);

    if ($force_from && $username !== '') {
        if ($from === '' || $from !== $username) {
            return $username;
        }
    }

    if ($from !== '') {
        return $from;
    }

    return $current_from;
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
            'label' => 'Other / Custom SMTP',
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

function tk_smtp_google_redirect_uri(): string {
    return admin_url('admin-post.php?action=tk_smtp_google_callback');
}

function tk_smtp_google_is_connected(): bool {
    return (string) tk_get_option('smtp_gmail_refresh_token', '') !== ''
        && is_email((string) tk_get_option('smtp_gmail_email', ''));
}

function tk_smtp_google_auth_url(): string {
    $client_id = trim((string) tk_get_option('smtp_gmail_client_id', ''));
    $client_secret = trim((string) tk_get_option('smtp_gmail_client_secret', ''));
    if ($client_id === '' || $client_secret === '') {
        return '';
    }

    return add_query_arg(array(
        'client_id' => $client_id,
        'redirect_uri' => tk_smtp_google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'https://mail.google.com/ openid email',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => wp_create_nonce('tk_smtp_google_oauth'),
    ), 'https://accounts.google.com/o/oauth2/v2/auth');
}

function tk_smtp_google_admin_redirect(string $status): void {
    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-smtp',
        'tk_google_auth' => sanitize_key($status),
    ), admin_url('admin.php')));
    exit;
}

function tk_smtp_google_token_request(array $body) {
    $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
        'timeout' => 20,
        'headers' => array('Accept' => 'application/json'),
        'body' => $body,
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['access_token'])) {
        $message = is_array($data) && !empty($data['error_description'])
            ? sanitize_text_field((string) $data['error_description'])
            : __('Google did not return a valid OAuth access token.', 'tool-kits');
        return new WP_Error('tk_smtp_google_token_error', $message);
    }

    return $data;
}

function tk_smtp_google_store_tokens(array $tokens): void {
    if (!empty($tokens['access_token'])) {
        tk_update_option('smtp_gmail_access_token', sanitize_text_field((string) $tokens['access_token']));
    }
    if (!empty($tokens['refresh_token'])) {
        tk_update_option('smtp_gmail_refresh_token', sanitize_text_field((string) $tokens['refresh_token']));
    }
    $expires_in = isset($tokens['expires_in']) ? max(60, (int) $tokens['expires_in']) : 3600;
    tk_update_option('smtp_gmail_token_expires_at', time() + $expires_in - 60);
}

function tk_smtp_google_fetch_email(string $access_token): string {
    $response = wp_remote_get('https://www.googleapis.com/oauth2/v3/userinfo', array(
        'timeout' => 15,
        'headers' => array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
        ),
    ));
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return '';
    }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($data) && !empty($data['email']) ? sanitize_email((string) $data['email']) : '';
}

function tk_smtp_google_callback(): void {
    if (!tk_is_admin_user()) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }

    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    if ($state === '' || !wp_verify_nonce($state, 'tk_smtp_google_oauth')) {
        tk_smtp_google_admin_redirect('invalid_state');
    }
    if (!empty($_GET['error'])) {
        tk_smtp_google_admin_redirect('denied');
    }

    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
    $client_id = trim((string) tk_get_option('smtp_gmail_client_id', ''));
    $client_secret = trim((string) tk_get_option('smtp_gmail_client_secret', ''));
    if ($code === '' || $client_id === '' || $client_secret === '') {
        tk_smtp_google_admin_redirect('missing_credentials');
    }

    $tokens = tk_smtp_google_token_request(array(
        'code' => $code,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => tk_smtp_google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ));
    if (is_wp_error($tokens)) {
        tk_log('Gmail OAuth callback failed: ' . $tokens->get_error_message());
        tk_smtp_google_admin_redirect('token_error');
    }

    tk_smtp_google_store_tokens($tokens);
    $email = tk_smtp_google_fetch_email((string) $tokens['access_token']);
    if ($email === '') {
        tk_smtp_google_admin_redirect('email_error');
    }

    tk_update_option('smtp_gmail_email', $email);
    tk_update_option('smtp_username', $email);
    if ((int) tk_get_option('smtp_force_from', 1) === 1 || !is_email((string) tk_get_option('smtp_from_email', ''))) {
        tk_update_option('smtp_from_email', $email);
    }
    tk_smtp_google_admin_redirect('connected');
}

function tk_smtp_google_disconnect(): void {
    tk_require_admin_post('tk_smtp_google_disconnect');
    tk_update_option('smtp_gmail_access_token', '');
    tk_update_option('smtp_gmail_refresh_token', '');
    tk_update_option('smtp_gmail_token_expires_at', 0);
    tk_update_option('smtp_gmail_email', '');
    tk_smtp_google_admin_redirect('disconnected');
}

function tk_smtp_google_access_token() {
    $access_token = trim((string) tk_get_option('smtp_gmail_access_token', ''));
    $expires_at = (int) tk_get_option('smtp_gmail_token_expires_at', 0);
    if ($access_token !== '' && $expires_at > time()) {
        return $access_token;
    }

    $refresh_token = trim((string) tk_get_option('smtp_gmail_refresh_token', ''));
    $client_id = trim((string) tk_get_option('smtp_gmail_client_id', ''));
    $client_secret = trim((string) tk_get_option('smtp_gmail_client_secret', ''));
    if ($refresh_token === '' || $client_id === '' || $client_secret === '') {
        return new WP_Error('tk_smtp_google_not_connected', __('Gmail OAuth is not connected.', 'tool-kits'));
    }

    $tokens = tk_smtp_google_token_request(array(
        'refresh_token' => $refresh_token,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'refresh_token',
    ));
    if (is_wp_error($tokens)) {
        return $tokens;
    }
    tk_smtp_google_store_tokens($tokens);
    return (string) $tokens['access_token'];
}

function tk_smtp_oauth_provider(string $email, string $access_token) {
    if (
        !interface_exists('PHPMailer\\PHPMailer\\OAuthTokenProvider')
        && defined('ABSPATH')
        && defined('WPINC')
    ) {
        $interface_file = ABSPATH . WPINC . '/PHPMailer/OAuthTokenProvider.php';
        if (is_readable($interface_file)) {
            require_once $interface_file;
        }
    }
    if (!interface_exists('PHPMailer\\PHPMailer\\OAuthTokenProvider')) {
        return null;
    }

    return new class($email, $access_token) implements \PHPMailer\PHPMailer\OAuthTokenProvider {
        private $email;
        private $access_token;

        public function __construct(string $email, string $access_token) {
            $this->email = $email;
            $this->access_token = $access_token;
        }

        public function getOauth64() {
            return base64_encode('user=' . $this->email . "\001auth=Bearer " . $this->access_token . "\001\001");
        }
    };
}

function tk_smtp_microsoft_tenant(): string {
    $tenant = trim((string) tk_get_option('smtp_microsoft_tenant_id', 'common'));
    return $tenant !== '' ? preg_replace('/[^a-zA-Z0-9._-]/', '', $tenant) : 'common';
}

function tk_smtp_microsoft_redirect_uri(): string {
    return admin_url('admin-post.php?action=tk_smtp_microsoft_callback');
}

function tk_smtp_microsoft_is_connected(): bool {
    return (string) tk_get_option('smtp_microsoft_refresh_token', '') !== ''
        && is_email((string) tk_get_option('smtp_microsoft_email', ''));
}

function tk_smtp_microsoft_auth_url(): string {
    $client_id = trim((string) tk_get_option('smtp_microsoft_client_id', ''));
    $client_secret = trim((string) tk_get_option('smtp_microsoft_client_secret', ''));
    $email = sanitize_email((string) tk_get_option('smtp_microsoft_email', ''));
    if ($client_id === '' || $client_secret === '' || $email === '') {
        return '';
    }

    return add_query_arg(array(
        'client_id' => $client_id,
        'redirect_uri' => tk_smtp_microsoft_redirect_uri(),
        'response_type' => 'code',
        'response_mode' => 'query',
        'scope' => 'openid email offline_access https://outlook.office.com/SMTP.Send',
        'prompt' => 'select_account',
        'login_hint' => $email,
        'state' => wp_create_nonce('tk_smtp_microsoft_oauth'),
    ), 'https://login.microsoftonline.com/' . rawurlencode(tk_smtp_microsoft_tenant()) . '/oauth2/v2.0/authorize');
}

function tk_smtp_microsoft_token_request(array $body) {
    $response = wp_remote_post(
        'https://login.microsoftonline.com/' . rawurlencode(tk_smtp_microsoft_tenant()) . '/oauth2/v2.0/token',
        array(
            'timeout' => 20,
            'headers' => array('Accept' => 'application/json'),
            'body' => $body,
        )
    );
    if (is_wp_error($response)) {
        return $response;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['access_token'])) {
        $message = is_array($data) && !empty($data['error_description'])
            ? sanitize_text_field((string) $data['error_description'])
            : __('Microsoft did not return a valid OAuth access token.', 'tool-kits');
        return new WP_Error('tk_smtp_microsoft_token_error', $message);
    }
    return $data;
}

function tk_smtp_microsoft_store_tokens(array $tokens): void {
    if (!empty($tokens['access_token'])) {
        tk_update_option('smtp_microsoft_access_token', sanitize_text_field((string) $tokens['access_token']));
    }
    if (!empty($tokens['refresh_token'])) {
        tk_update_option('smtp_microsoft_refresh_token', sanitize_text_field((string) $tokens['refresh_token']));
    }
    $expires_in = isset($tokens['expires_in']) ? max(60, (int) $tokens['expires_in']) : 3600;
    tk_update_option('smtp_microsoft_token_expires_at', time() + $expires_in - 60);
}

function tk_smtp_microsoft_admin_redirect(string $status): void {
    wp_safe_redirect(add_query_arg(array(
        'page' => 'tool-kits-smtp',
        'tk_microsoft_auth' => sanitize_key($status),
    ), admin_url('admin.php')));
    exit;
}

function tk_smtp_microsoft_callback(): void {
    if (!tk_is_admin_user()) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    if ($state === '' || !wp_verify_nonce($state, 'tk_smtp_microsoft_oauth')) {
        tk_smtp_microsoft_admin_redirect('invalid_state');
    }
    if (!empty($_GET['error'])) {
        tk_smtp_microsoft_admin_redirect('denied');
    }

    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
    $client_id = trim((string) tk_get_option('smtp_microsoft_client_id', ''));
    $client_secret = trim((string) tk_get_option('smtp_microsoft_client_secret', ''));
    if ($code === '' || $client_id === '' || $client_secret === '') {
        tk_smtp_microsoft_admin_redirect('missing_credentials');
    }

    $tokens = tk_smtp_microsoft_token_request(array(
        'code' => $code,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => tk_smtp_microsoft_redirect_uri(),
        'grant_type' => 'authorization_code',
        'scope' => 'openid email offline_access https://outlook.office.com/SMTP.Send',
    ));
    if (is_wp_error($tokens)) {
        tk_log('Microsoft OAuth callback failed: ' . $tokens->get_error_message());
        tk_smtp_microsoft_admin_redirect('token_error');
    }
    tk_smtp_microsoft_store_tokens($tokens);
    $email = sanitize_email((string) tk_get_option('smtp_microsoft_email', ''));
    tk_update_option('smtp_username', $email);
    if ((int) tk_get_option('smtp_force_from', 1) === 1 || !is_email((string) tk_get_option('smtp_from_email', ''))) {
        tk_update_option('smtp_from_email', $email);
    }
    tk_smtp_microsoft_admin_redirect('connected');
}

function tk_smtp_microsoft_disconnect(): void {
    tk_require_admin_post('tk_smtp_microsoft_disconnect');
    tk_update_option('smtp_microsoft_access_token', '');
    tk_update_option('smtp_microsoft_refresh_token', '');
    tk_update_option('smtp_microsoft_token_expires_at', 0);
    tk_smtp_microsoft_admin_redirect('disconnected');
}

function tk_smtp_microsoft_access_token() {
    $access_token = trim((string) tk_get_option('smtp_microsoft_access_token', ''));
    $expires_at = (int) tk_get_option('smtp_microsoft_token_expires_at', 0);
    if ($access_token !== '' && $expires_at > time()) {
        return $access_token;
    }

    $refresh_token = trim((string) tk_get_option('smtp_microsoft_refresh_token', ''));
    $client_id = trim((string) tk_get_option('smtp_microsoft_client_id', ''));
    $client_secret = trim((string) tk_get_option('smtp_microsoft_client_secret', ''));
    if ($refresh_token === '' || $client_id === '' || $client_secret === '') {
        return new WP_Error('tk_smtp_microsoft_not_connected', __('Microsoft OAuth is not connected.', 'tool-kits'));
    }
    $tokens = tk_smtp_microsoft_token_request(array(
        'refresh_token' => $refresh_token,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'refresh_token',
        'scope' => 'openid email offline_access https://outlook.office.com/SMTP.Send',
    ));
    if (is_wp_error($tokens)) {
        return $tokens;
    }
    tk_smtp_microsoft_store_tokens($tokens);
    return (string) $tokens['access_token'];
}

function tk_smtp_phpmailer_init($phpmailer = null) {
    if (!tk_smtp_enabled()) {
        return;
    }

    if (!tk_smtp_is_phpmailer_compatible($phpmailer)) {
        tk_log('SMTP hook received an incompatible mailer instance; skipping override.');
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
        $forced_from = tk_smtp_force_from_address($original_from);
        if ($forced_from !== '' && tk_smtp_normalize_email($original_from) !== $forced_from) {
            $phpmailer->setFrom($config['username'], $phpmailer->FromName, false);
            if (
                is_email($original_from)
                && method_exists($phpmailer, 'getReplyToAddresses')
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
        if (in_array($config['provider'], array('gmail', 'office365'), true)) {
            $access_token = $config['provider'] === 'gmail'
                ? tk_smtp_google_access_token()
                : tk_smtp_microsoft_access_token();
            if (is_wp_error($access_token)) {
                tk_log(ucfirst($config['provider']) . ' OAuth token error: ' . $access_token->get_error_message());
                $phpmailer->AuthType = 'XOAUTH2';
            } else {
                $oauth_provider = tk_smtp_oauth_provider($config['username'], $access_token);
                if ($oauth_provider !== null && method_exists($phpmailer, 'setOAuth')) {
                    $phpmailer->AuthType = 'XOAUTH2';
                    $phpmailer->setOAuth($oauth_provider);
                } else {
                    tk_log('SMTP OAuth requires PHPMailer OAuthTokenProvider support.');
                    $phpmailer->AuthType = 'XOAUTH2';
                }
            }
        } elseif ($config['password'] !== '') {
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
    $from = tk_smtp_force_from_address((string) $current);
    if ($from !== '') {
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
        'smtp_gmail_client_id' => tk_get_option('smtp_gmail_client_id', ''),
        'smtp_gmail_email' => tk_get_option('smtp_gmail_email', ''),
        'smtp_microsoft_client_id' => tk_get_option('smtp_microsoft_client_id', ''),
        'smtp_microsoft_tenant_id' => tk_get_option('smtp_microsoft_tenant_id', 'common'),
        'smtp_microsoft_email' => tk_get_option('smtp_microsoft_email', ''),
    );

    $presets = tk_smtp_provider_presets();
    $from_username_aligned = tk_smtp_from_username_match();
    $last_failure_reason = tk_smtp_test_log_last_failure_reason();
    $provider_notes = array(
        'gmail' => '<ul class="tk-note-list"><li>' . __('Create a Web application OAuth client in Google Cloud Console.', 'tool-kits') . '</li><li>' . __('Add the Authorized redirect URI shown below, save the settings, then authorize your Google account.', 'tool-kits') . '</li><li>' . __('No Gmail password or app password is stored.', 'tool-kits') . '</li></ul>',
        'office365' => '<ul class="tk-note-list"><li>' . __('Register a Web application in Microsoft Entra ID and add the redirect URI shown below.', 'tool-kits') . '</li><li>' . __('Enable Authenticated SMTP for the mailbox and grant the delegated SMTP.Send permission.', 'tool-kits') . '</li><li>' . __('Tool Kits uses OAuth 2.0; the mailbox password is not stored.', 'tool-kits') . '</li></ul>',
        'custom' => '<ul class="tk-note-list"><li>' . __('Use the SMTP host, port, encryption, username, and password supplied by your provider.', 'tool-kits') . '</li><li>' . __('Custom SMTP uses password authentication because authorization endpoints and scopes differ between providers.', 'tool-kits') . '</li></ul>',
    );
    $saved = isset($_GET['tk_saved']) ? sanitize_key($_GET['tk_saved']) : '';
    $test_status = isset($_GET['tk_smtp_test']) ? sanitize_key($_GET['tk_smtp_test']) : '';
    $test_email = isset($_GET['tk_smtp_test_email']) ? sanitize_email(wp_unslash($_GET['tk_smtp_test_email'])) : '';
    $smtp_test_log = tk_smtp_test_log_get();
    $log_cleared = isset($_GET['tk_smtp_log_cleared']) ? sanitize_key($_GET['tk_smtp_log_cleared']) : '';
    $transport_warning = tk_smtp_transport_warning($opts);
    $google_auth_status = isset($_GET['tk_google_auth']) ? sanitize_key(wp_unslash($_GET['tk_google_auth'])) : '';
    $google_auth_url = tk_smtp_google_auth_url();
    $microsoft_auth_status = isset($_GET['tk_microsoft_auth']) ? sanitize_key(wp_unslash($_GET['tk_microsoft_auth'])) : '';
    $microsoft_auth_url = tk_smtp_microsoft_auth_url();
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('SMTP Delivery', 'tool-kits'), __('Ensure reliable email delivery for your WordPress site using professional SMTP services.', 'tool-kits'), 'dashicons-email-alt'); ?>
        <?php if ($saved === '1') : ?>
            <?php tk_notice('SMTP settings saved.', 'success'); ?>
        <?php endif; ?>
        <?php if ($log_cleared === '1') : ?>
            <?php tk_notice('SMTP test log cleared.', 'success'); ?>
        <?php endif; ?>
        <?php if ($google_auth_status === 'connected') : ?>
            <?php tk_notice('Google account connected successfully.', 'success'); ?>
        <?php elseif ($google_auth_status === 'disconnected') : ?>
            <?php tk_notice('Google OAuth connection removed.', 'success'); ?>
        <?php elseif ($google_auth_status !== '') : ?>
            <?php tk_notice('Google authorization failed (' . esc_html($google_auth_status) . '). Check the Client ID, Client Secret, redirect URI, and OAuth consent screen.', 'error'); ?>
        <?php endif; ?>
        <?php if ($microsoft_auth_status === 'connected') : ?>
            <?php tk_notice('Microsoft 365 account connected successfully.', 'success'); ?>
        <?php elseif ($microsoft_auth_status === 'disconnected') : ?>
            <?php tk_notice('Microsoft OAuth connection removed.', 'success'); ?>
        <?php elseif ($microsoft_auth_status !== '') : ?>
            <?php tk_notice('Microsoft authorization failed (' . esc_html($microsoft_auth_status) . '). Check the App ID, Client Secret, tenant, redirect URI, and delegated SMTP.Send permission.', 'error'); ?>
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
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid var(--tk-border-soft);">
                        <div style="background:var(--tk-primary); color:#fff; width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center;">
                            <span class="dashicons dashicons-email-alt" style="font-size:20px; width:20px; height:20px;"></span>
                        </div>
                        <div>
                            <h2 style="margin:0; font-size:18px;">SMTP Configuration</h2>
                            <p style="margin:0; color:var(--tk-muted); font-size:13px;">Reliable email delivery for your WordPress site.</p>
                        </div>
                    </div>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tk-modern-form">
                        <?php tk_nonce_field('tk_smtp_save'); ?>
                        <input type="hidden" name="action" value="tk_smtp_save">
                        
                        <div class="tk-form-section" style="margin-bottom:30px;">
                            <h3 style="font-size:14px; margin-bottom:16px; color:var(--tk-primary);">1. Global Override</h3>
                            <?php tk_render_switch('smtp_enabled', 'Enable SMTP Delivery', 'Route all outgoing emails through the SMTP server configured below.', $opts['smtp_enabled']); ?>
                        </div>

                        <div class="tk-form-section" style="margin-bottom:30px; background:var(--tk-bg-soft); padding:20px; border-radius:12px; border:1px solid var(--tk-border-soft);">
                            <h3 style="font-size:14px; margin-bottom:16px; color:var(--tk-primary); margin-top:0;">2. Provider Details</h3>
                            
                            <div class="tk-form-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                                <div class="tk-form-group">
                                    <label class="tk-form-label"><strong>SMTP Provider</strong></label>
                                    <select name="smtp_provider" id="tk-smtp-provider" class="tk-input regular-text" style="width: 100%;">
                                        <?php foreach ($presets as $key => $preset) : ?>
                                            <?php $note = isset($provider_notes[$key]) ? $provider_notes[$key] : ''; ?>
                                            <option value="<?php echo esc_attr($key); ?>" data-host="<?php echo esc_attr($preset['host']); ?>" data-port="<?php echo esc_attr($preset['port']); ?>" data-secure="<?php echo esc_attr($preset['secure']); ?>" data-note="<?php echo esc_attr($note); ?>" <?php selected($key, $opts['smtp_provider']); ?>><?php echo esc_html($preset['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="tk-form-group">
                                    <label class="tk-form-label">Encryption</label>
                                    <select name="smtp_secure" id="tk-smtp-secure" class="tk-input regular-text" style="width: 100%;">
                                        <option value="tls" <?php selected('tls', $opts['smtp_secure']); ?>>TLS (Recommended)</option>
                                        <option value="ssl" <?php selected('ssl', $opts['smtp_secure']); ?>>SSL</option>
                                        <option value="tssl" <?php selected('tssl', $opts['smtp_secure']); ?>>TSSL (Auto)</option>
                                        <option value="none" <?php selected('none', $opts['smtp_secure']); ?>>None</option>
                                    </select>
                                </div>
                                <div class="tk-form-group">
                                    <label class="tk-form-label">SMTP Host</label>
                                    <input type="text" id="tk-smtp-host" name="smtp_host" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_host']); ?>" placeholder="smtp.example.com">
                                </div>
                                <div class="tk-form-group">
                                    <label class="tk-form-label">SMTP Port</label>
                                    <input type="number" id="tk-smtp-port" name="smtp_port" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_port']); ?>">
                                </div>
                            </div>
                            <div id="tk-smtp-provider-note" class="tk-alert tk-alert-info" style="margin-top:20px; font-size:12px;">
                                <?php echo wp_kses_post($default_note ?? ''); ?>
                            </div>
                        </div>

                        <div class="tk-form-section" style="margin-bottom:30px;">
                            <h3 style="font-size:14px; margin-bottom:16px; color:var(--tk-primary);">3. Authentication</h3>
                            <div id="tk-smtp-gmail-auth" style="<?php echo $opts['smtp_provider'] === 'gmail' ? '' : 'display:none;'; ?> background:var(--tk-bg-soft); padding:20px; border-radius:12px; border:1px solid var(--tk-border-soft);">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                                    <div class="tk-form-group">
                                        <label class="tk-form-label">Google Client ID</label>
                                        <input type="text" name="smtp_gmail_client_id" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_gmail_client_id']); ?>" autocomplete="off" placeholder="000000000000-xxxx.apps.googleusercontent.com">
                                    </div>
                                    <div class="tk-form-group">
                                        <label class="tk-form-label">Google Client Secret</label>
                                        <input type="password" name="smtp_gmail_client_secret" class="tk-input" style="width:100%;" value="" autocomplete="new-password" placeholder="<?php echo esc_attr(tk_get_option('smtp_gmail_client_secret', '') !== '' ? 'Saved — leave blank to keep' : 'Enter client secret'); ?>">
                                    </div>
                                </div>
                                <div class="tk-form-group" style="margin-top:18px;">
                                    <label class="tk-form-label">Authorized Redirect URI</label>
                                    <div style="display:flex; gap:8px; align-items:center;">
                                        <input type="text" id="tk-smtp-google-redirect" class="tk-input" style="width:100%;" readonly value="<?php echo esc_attr(tk_smtp_google_redirect_uri()); ?>" onfocus="this.select();">
                                        <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('tk-smtp-google-redirect').value)">Copy</button>
                                    </div>
                                    <p class="tk-input-help">Add this exact URL to Google Cloud Console → OAuth client → Authorized redirect URIs.</p>
                                </div>
                                <div style="margin-top:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <?php if (tk_smtp_google_is_connected()) : ?>
                                        <span class="tk-badge tk-on">Connected</span>
                                        <span><?php echo esc_html((string) $opts['smtp_gmail_email']); ?></span>
                                        <a class="button" href="<?php echo esc_url(add_query_arg(array('action' => 'tk_smtp_google_disconnect', '_tk_nonce' => wp_create_nonce('tk_smtp_google_disconnect')), admin_url('admin-post.php'))); ?>">Remove OAuth Connection</a>
                                    <?php elseif ($google_auth_url !== '') : ?>
                                        <a class="button button-primary" href="<?php echo esc_url($google_auth_url); ?>">Allow Tool Kits to send email using Google</a>
                                        <span class="description">Save Client ID and Client Secret before authorizing.</span>
                                    <?php else : ?>
                                        <span class="description">Enter and save Client ID and Client Secret to generate the Google authorization URL.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div id="tk-smtp-microsoft-auth" style="<?php echo $opts['smtp_provider'] === 'office365' ? '' : 'display:none;'; ?> background:var(--tk-bg-soft); padding:20px; border-radius:12px; border:1px solid var(--tk-border-soft);">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                                    <div class="tk-form-group">
                                        <label class="tk-form-label">Microsoft Application (Client) ID</label>
                                        <input type="text" name="smtp_microsoft_client_id" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_microsoft_client_id']); ?>" autocomplete="off" placeholder="00000000-0000-0000-0000-000000000000">
                                    </div>
                                    <div class="tk-form-group">
                                        <label class="tk-form-label">Microsoft Client Secret</label>
                                        <input type="password" name="smtp_microsoft_client_secret" class="tk-input" style="width:100%;" value="" autocomplete="new-password" placeholder="<?php echo esc_attr(tk_get_option('smtp_microsoft_client_secret', '') !== '' ? 'Saved — leave blank to keep' : 'Enter client secret value'); ?>">
                                    </div>
                                    <div class="tk-form-group">
                                        <label class="tk-form-label">Directory (Tenant) ID</label>
                                        <input type="text" name="smtp_microsoft_tenant_id" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_microsoft_tenant_id']); ?>" autocomplete="off" placeholder="common or tenant UUID">
                                    </div>
                                    <div class="tk-form-group">
                                        <label class="tk-form-label">Microsoft 365 Mailbox</label>
                                        <input type="email" name="smtp_microsoft_email" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_microsoft_email']); ?>" autocomplete="email" placeholder="user@company.com">
                                    </div>
                                </div>
                                <div class="tk-form-group" style="margin-top:18px;">
                                    <label class="tk-form-label">Authorized Redirect URI</label>
                                    <div style="display:flex; gap:8px; align-items:center;">
                                        <input type="text" id="tk-smtp-microsoft-redirect" class="tk-input" style="width:100%;" readonly value="<?php echo esc_attr(tk_smtp_microsoft_redirect_uri()); ?>" onfocus="this.select();">
                                        <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('tk-smtp-microsoft-redirect').value)">Copy</button>
                                    </div>
                                    <p class="tk-input-help">Add this exact URL in Microsoft Entra ID → App registrations → Authentication → Web redirect URI.</p>
                                </div>
                                <div style="margin-top:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <?php if (tk_smtp_microsoft_is_connected()) : ?>
                                        <span class="tk-badge tk-on">Connected</span>
                                        <span><?php echo esc_html((string) $opts['smtp_microsoft_email']); ?></span>
                                        <a class="button" href="<?php echo esc_url(add_query_arg(array('action' => 'tk_smtp_microsoft_disconnect', '_tk_nonce' => wp_create_nonce('tk_smtp_microsoft_disconnect')), admin_url('admin-post.php'))); ?>">Remove OAuth Connection</a>
                                    <?php elseif ($microsoft_auth_url !== '') : ?>
                                        <a class="button button-primary" href="<?php echo esc_url($microsoft_auth_url); ?>">Allow Tool Kits to send email using Microsoft</a>
                                        <span class="description">Save the Microsoft settings before authorizing.</span>
                                    <?php else : ?>
                                        <span class="description">Enter and save the App ID, Client Secret, tenant, and mailbox to generate the Microsoft authorization URL.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div id="tk-smtp-password-auth" style="display:<?php echo $opts['smtp_provider'] === 'custom' ? 'grid' : 'none'; ?>; grid-template-columns:1fr 1fr; gap:20px;">
                                <div class="tk-form-group">
                                    <label class="tk-form-label">Username</label>
                                    <input type="text" id="tk-smtp-username" name="smtp_username" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_username']); ?>" placeholder="user@example.com">
                                    <p class="tk-input-help">Your full email address.</p>
                                </div>
                                <div class="tk-form-group">
                                    <label class="tk-form-label">Password / App Password</label>
                                    <input type="password" id="tk-smtp-password" name="smtp_password" class="tk-input" style="width:100%;" autocomplete="new-password" placeholder="••••••••••••••••">
                                    <p class="tk-input-help">Use the credentials supplied by your mail provider.</p>
                                </div>
                            </div>
                        </div>

                        <div class="tk-form-section" style="margin-bottom:30px;">
                            <h3 style="font-size:14px; margin-bottom:16px; color:var(--tk-primary);">4. Sender Identity</h3>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:16px;">
                                <div class="tk-form-group">
                                    <label class="tk-form-label">From Email</label>
                                    <input type="email" id="tk-smtp-from-email" name="smtp_from_email" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_from_email']); ?>" placeholder="admin@example.com">
                                </div>
                                <div class="tk-form-group">
                                    <label class="tk-form-label">From Name</label>
                                    <input type="text" id="tk-smtp-from-name" name="smtp_from_name" class="tk-input" style="width:100%;" value="<?php echo esc_attr($opts['smtp_from_name']); ?>" placeholder="<?php echo esc_attr((string)get_option('blogname')); ?>">
                                </div>
                            </div>

                            <div style="display:flex; flex-direction:column; gap:16px; background:var(--tk-bg-soft); padding:16px; border-radius:12px; border:1px solid var(--tk-border-soft);">
                                <?php 
                                tk_render_switch('smtp_force_from', 'Force From Email', 'Ensure the "From" address matches your SMTP username.', $opts['smtp_force_from']);
                                tk_render_switch('smtp_return_path', 'Set Return-Path', 'Match return-path to from address for better SPF alignment.', $opts['smtp_return_path']);
                                ?>
                            </div>
                        </div>

                        <div style="margin-top:40px; padding-top:20px; border-top:1px solid var(--tk-border-soft); display:flex; justify-content:flex-end;">
                            <button class="button button-primary button-hero" style="height:44px; padding:0 30px;">Save SMTP Configuration</button>
                        </div>
                    </form>
                </div>
                <div class="tk-card tk-tab-panel" data-panel-id="test">
                    <h2>Send test email</h2>
                    <p>Use this to confirm SMTP is working with the configured credentials.</p>
                    <p class="description">A successful result here means the message was accepted by the configured SMTP server. It does not guarantee delivery to the recipient inbox.</p>
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
                            <?php tk_notice('Test email was accepted by the SMTP server for ' . esc_html($test_email) . '. Inbox delivery still depends on the receiving provider.', 'success'); ?>
                        <?php elseif ($test_status === 'warning') : ?>
                            <?php tk_notice('Test email was accepted, but the final transport did not match the configured SMTP settings. Check the SMTP log details and message headers.', 'warning'); ?>
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
                    <p><small><strong>Success</strong> means the SMTP server accepted the message. Gmail, Outlook, or another provider can still spam-filter, defer, or reject the message later.</small></p>
                    <?php if (!empty($smtp_test_log)) : ?>
                        <div class="tk-table-scroll">
                        <table class="widefat striped tk-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Sender & Recipient</th>
                                    <th>Status</th>
                                    <th>Reason / Message</th>
                                    <th>Technical Details</th>
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
                                        <td>
                                            <div style="font-weight:500; font-size:13px;"><?php echo esc_html(date_i18n('M d, Y', $entry_time)); ?></div>
                                            <div style="font-size:11px; color:var(--tk-muted);"><?php echo esc_html(date_i18n('H:i:s', $entry_time)); ?></div>
                                        </td>
                                        <td>
                                            <div style="font-size:12px;"><span style="color:var(--tk-muted);">From:</span> <code><?php echo esc_html($entry_sender); ?></code></div>
                                            <div style="font-size:12px; margin-top:4px;"><span style="color:var(--tk-muted);">To:</span> <code><?php echo esc_html($entry_recipient); ?></code></div>
                                        </td>
                                        <td><span class="tk-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                                        <td>
                                            <?php if ($entry_reason !== '') : ?>
                                                <div style="color:#e74c3c; font-weight:600; font-size:11px; margin-bottom:4px;"><?php echo esc_html($entry_reason); ?></div>
                                            <?php endif; ?>
                                            <div style="font-size:11px; color:var(--tk-muted); font-style:italic;"><?php echo esc_html(wp_trim_words($entry_message, 15, '...')); ?></div>
                                        </td>
                                        <td>
                                            <div class="tk-log-details">
                                                <table class="tk-mini-table" style="width:100%; border-collapse:collapse; font-size:10px;">
                                                    <?php 
                                                    $details_formatted = tk_smtp_test_log_get_details_array($entry_details);
                                                    if (!empty($details_formatted)) : 
                                                        foreach ($details_formatted as $label => $val) : ?>
                                                        <tr>
                                                            <td style="font-weight:700; width:90px; padding:3px 0; color:#1e293b;"><?php echo esc_html($label); ?>:</td>
                                                            <td style="padding:3px 0; color:#64748b;"><?php echo esc_html($val); ?></td>
                                                        </tr>
                                                    <?php endforeach; else : ?>
                                                        <tr><td style="color:#94a3b8;">&mdash;</td></tr>
                                                    <?php endif; ?>
                                                </table>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($entry_recipient !== '') : ?>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                                    <?php tk_nonce_field('tk_smtp_test'); ?>
                                                    <input type="hidden" name="action" value="tk_smtp_test">
                                                    <input type="hidden" name="smtp_test_email" value="<?php echo esc_attr($entry_recipient); ?>">
                                                    <input type="hidden" name="smtp_test_message" value="<?php echo esc_attr($entry_message); ?>">
                                                    <button type="submit" class="button button-secondary button-small" style="font-size:11px;">Resend</button>
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
        var gmailAuth = document.getElementById('tk-smtp-gmail-auth');
        var microsoftAuth = document.getElementById('tk-smtp-microsoft-auth');
        var passwordAuth = document.getElementById('tk-smtp-password-auth');
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
                if (gmailAuth) {
                    gmailAuth.style.display = select.value === 'gmail' ? 'block' : 'none';
                }
                if (microsoftAuth) {
                    microsoftAuth.style.display = select.value === 'office365' ? 'block' : 'none';
                }
                if (passwordAuth) {
                    passwordAuth.style.display = select.value === 'custom' ? 'grid' : 'none';
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
    </div>
    <?php
}

function tk_smtp_save() {
    tk_require_admin_post('tk_smtp_save');

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

    $old_client_id = (string) tk_get_option('smtp_gmail_client_id', '');
    $old_client_secret = (string) tk_get_option('smtp_gmail_client_secret', '');
    $client_id = isset($_POST['smtp_gmail_client_id']) ? sanitize_text_field(wp_unslash($_POST['smtp_gmail_client_id'])) : $old_client_id;
    $posted_client_secret = isset($_POST['smtp_gmail_client_secret']) ? trim((string) wp_unslash($_POST['smtp_gmail_client_secret'])) : '';
    $client_secret = $posted_client_secret !== '' ? $posted_client_secret : $old_client_secret;
    $oauth_credentials_changed = $client_id !== $old_client_id
        || ($posted_client_secret !== '' && !hash_equals($old_client_secret, $posted_client_secret));
    tk_update_option('smtp_gmail_client_id', $client_id);
    if ($posted_client_secret !== '') {
        tk_update_option('smtp_gmail_client_secret', $posted_client_secret);
    }
    if ($oauth_credentials_changed) {
        tk_update_option('smtp_gmail_access_token', '');
        tk_update_option('smtp_gmail_refresh_token', '');
        tk_update_option('smtp_gmail_token_expires_at', 0);
        tk_update_option('smtp_gmail_email', '');
    }

    $old_ms_client_id = (string) tk_get_option('smtp_microsoft_client_id', '');
    $old_ms_client_secret = (string) tk_get_option('smtp_microsoft_client_secret', '');
    $old_ms_tenant = (string) tk_get_option('smtp_microsoft_tenant_id', 'common');
    $old_ms_email = (string) tk_get_option('smtp_microsoft_email', '');
    $ms_client_id = isset($_POST['smtp_microsoft_client_id']) ? sanitize_text_field(wp_unslash($_POST['smtp_microsoft_client_id'])) : $old_ms_client_id;
    $posted_ms_secret = isset($_POST['smtp_microsoft_client_secret']) ? trim((string) wp_unslash($_POST['smtp_microsoft_client_secret'])) : '';
    $ms_tenant = isset($_POST['smtp_microsoft_tenant_id']) ? sanitize_text_field(wp_unslash($_POST['smtp_microsoft_tenant_id'])) : $old_ms_tenant;
    $ms_tenant = $ms_tenant !== '' ? $ms_tenant : 'common';
    $ms_email = isset($_POST['smtp_microsoft_email']) ? sanitize_email(wp_unslash($_POST['smtp_microsoft_email'])) : $old_ms_email;
    $ms_credentials_changed = $ms_client_id !== $old_ms_client_id
        || $ms_tenant !== $old_ms_tenant
        || $ms_email !== $old_ms_email
        || ($posted_ms_secret !== '' && !hash_equals($old_ms_client_secret, $posted_ms_secret));
    tk_update_option('smtp_microsoft_client_id', $ms_client_id);
    tk_update_option('smtp_microsoft_tenant_id', $ms_tenant);
    tk_update_option('smtp_microsoft_email', $ms_email);
    if ($posted_ms_secret !== '') {
        tk_update_option('smtp_microsoft_client_secret', $posted_ms_secret);
    }
    if ($ms_credentials_changed) {
        tk_update_option('smtp_microsoft_access_token', '');
        tk_update_option('smtp_microsoft_refresh_token', '');
        tk_update_option('smtp_microsoft_token_expires_at', 0);
    }

    if ($provider === 'gmail') {
        $username = sanitize_email((string) tk_get_option('smtp_gmail_email', ''));
    } elseif ($provider === 'office365') {
        $username = $ms_email;
    } else {
        $username = isset($_POST['smtp_username']) ? sanitize_text_field(wp_unslash($_POST['smtp_username'])) : '';
    }
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
    wp_safe_redirect($redirect);
    exit;
}

function tk_smtp_test_send() {
    tk_require_admin_post('tk_smtp_test');

    $recipient = isset($_POST['smtp_test_email']) ? sanitize_email(wp_unslash($_POST['smtp_test_email'])) : '';
    if ($recipient === '' || !is_email($recipient)) {
        $redirect = add_query_arg(array(
            'page' => 'tool-kits-smtp',
            'tk_smtp_test' => 'invalid',
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect);
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
        $reply_to = $original_from;
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
        'smtp_auth_method' => in_array($config['provider'], array('gmail', 'office365'), true) ? 'XOAUTH2' : 'password',
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
    if ($reply_to !== '') {
        $headers[] = 'Reply-To: ' . $reply_to;
    }
    $sent = wp_mail($recipient, $subject, $message, $headers);
    $transport = tk_smtp_last_transport();
    $details['transport_mailer'] = isset($transport['mailer']) ? (string) $transport['mailer'] : '';
    $details['transport_host'] = isset($transport['host']) ? (string) $transport['host'] : '';
    $status = $sent ? 'success' : 'fail';
    $reason = $status === 'fail' ? tk_smtp_test_log_get_error() : '';
    if ($status === 'success') {
        $mismatch_reason = tk_smtp_transport_mismatch_reason($config, $transport);
        if ($mismatch_reason !== '') {
            $reason = $mismatch_reason;
            $status = 'warning';
        }
    }
    tk_log(sprintf('SMTP test email to %s %s', $recipient, $status));
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

function tk_smtp_test_log_error_helper(?string $value = null): string {
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

function tk_smtp_capture_last_transport($phpmailer): void {
    if (!tk_smtp_is_phpmailer_compatible($phpmailer)) {
        return;
    }
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
    if (!tk_smtp_is_phpmailer_compatible($phpmailer)) {
        if (tk_smtp_is_core_mail_flow()) {
            tk_log('SMTP transport observer skipped because WordPress provided an incompatible mailer instance.');
        }
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
    $last = tk_smtp_last_transport();
    if (empty($last)) {
        return 'No transport data yet. Send a test email first to verify the actual delivery path.';
    }
    $provider = isset($opts['smtp_provider']) ? (string) $opts['smtp_provider'] : 'custom';
    $presets = tk_smtp_provider_presets();
    $mailer = isset($last['mailer']) ? strtolower((string) $last['mailer']) : '';
    $host = isset($last['host']) ? strtolower((string) $last['host']) : '';
    $expected_host = '';
    if ($provider !== 'custom' && isset($presets[$provider]['host'])) {
        $expected_host = strtolower((string) $presets[$provider]['host']);
    } elseif (!empty($opts['smtp_host'])) {
        $expected_host = strtolower(trim((string) $opts['smtp_host']));
    }
    if ($mailer !== 'smtp') {
        return sprintf(
            'Last detected transport is Mailer=%s Host=%s. Email is not leaving through SMTP and can cause spam or unverified sender issues.',
            $mailer !== '' ? $mailer : '(empty)',
            $host !== '' ? $host : '(empty)'
        );
    }
    if ($expected_host !== '' && $host !== $expected_host) {
        return sprintf(
            'Last detected transport is Mailer=%s Host=%s, expected Host=%s. This mismatch can cause spam or unverified sender issues.',
            $mailer !== '' ? $mailer : '(empty)',
            $host !== '' ? $host : '(empty)',
            $expected_host
        );
    }
    return '';
}

function tk_smtp_transport_mismatch_reason(array $opts, array $transport = array()): string {
    if ((int) $opts['enabled'] !== 1) {
        return '';
    }
    $mailer = isset($transport['mailer']) ? strtolower((string) $transport['mailer']) : '';
    $host = isset($transport['host']) ? strtolower((string) $transport['host']) : '';
    $expected_host = '';
    $provider = isset($opts['provider']) ? (string) $opts['provider'] : 'custom';
    $presets = tk_smtp_provider_presets();
    if ($provider !== 'custom' && isset($presets[$provider]['host'])) {
        $expected_host = strtolower((string) $presets[$provider]['host']);
    } elseif (!empty($opts['host'])) {
        $expected_host = strtolower(trim((string) $opts['host']));
    }
    if ($mailer !== 'smtp') {
        return sprintf(
            'Configured SMTP was not the final transport. Last detected Mailer=%s Host=%s.',
            $mailer !== '' ? $mailer : '(empty)',
            $host !== '' ? $host : '(empty)'
        );
    }
    if ($expected_host !== '' && $host !== '' && $host !== $expected_host) {
        return sprintf(
            'Configured SMTP host mismatch. Last detected Host=%s, expected Host=%s.',
            $host,
            $expected_host
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

function tk_smtp_test_log_get_details_array(array $details): array {
    if (empty($details)) {
        return array();
    }
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
        'smtp_auth_method' => 'Auth Method',
        'smtp_user' => 'User',
        'force_from' => 'Force From',
    );
    $out = array();
    foreach ($details as $k => $v) {
        $label = isset($map[$k]) ? $map[$k] : ucfirst(str_replace('_', ' ', $k));
        $out[$label] = $v;
    }
    return $out;
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
        'smtp_auth_method' => 'Auth Method',
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
