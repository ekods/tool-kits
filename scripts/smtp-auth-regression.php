<?php
// No wp-load, database, mail or network. Exercises SMTP authentication diagnostics with a fake PHPMailer.
error_reporting(E_ALL);
set_error_handler(function ($level, $message) {
    if (error_reporting() & $level) { throw new RuntimeException($message); }
    return false;
});
define('ABSPATH', __DIR__ . '/');
define('WPINC', 'missing-wp-includes');
$options = array();
$token_response = null;
$checks = 0;
$_POST = array();
function tk_get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function tk_update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function tk_log($message) { $GLOBALS['logged'][] = $message; }
function tk_require_admin_post($action) {}
function __($text, $domain = '') { return $text; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_email($value) { return trim((string) $value); }
function is_email($value) { return (bool) filter_var($value, FILTER_VALIDATE_EMAIL); }
function wp_unslash($value) { return $value; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($url) { throw new LogicException('redirect'); }
function wp_create_nonce($action) { return 'nonce'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_post($url, $args) { return $GLOBALS['token_response']; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
class WP_Error {
    private $code; private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class FakeMailer {
    public $Host = ''; public $Port = 0; public $SMTPSecure = ''; public $SMTPAutoTLS = false; public $SMTPAuth = false;
    public $Username = ''; public $Password = ''; public $AuthType = ''; public $From = 'site@example.test'; public $FromName = '';
    public $Sender = ''; public $Mailer = 'mail'; public $oauth = null;
    public function isSMTP() { $this->Mailer = 'smtp'; }
    public function setFrom($address, $name = '', $auto = true) { $this->From = $address; }
    public function addReplyTo($address, $name = '') {}
    public function getReplyToAddresses() { return array(); }
    public function setOAuth($provider) { $this->oauth = $provider; }
}
require dirname(__DIR__) . '/includes/smtp.php';
// WordPress 6.x bundles PHPMailer with OAuthTokenProvider; older cores do not.
function define_oauth_interface() {
    eval('namespace PHPMailer\\PHPMailer; interface OAuthTokenProvider { public function getOauth64(); }');
}
function check_smtp($condition, $message) { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function send_and_fail($phpmailer_error = 'SMTP Error: Could not authenticate.') {
    $mailer = new FakeMailer();
    tk_smtp_test_log_clear_error();
    tk_smtp_phpmailer_init($mailer);
    tk_smtp_test_log_capture_wp_mail_error(new WP_Error('wp_mail_failed', $phpmailer_error));
    return array($mailer, tk_smtp_test_log_get_error());
}
function gmail_connected() {
    $GLOBALS['options'] = array_merge($GLOBALS['options'], array(
        'smtp_enabled' => 1, 'smtp_provider' => 'gmail', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_secure' => 'tls',
        'smtp_username' => 'sender@gmail.com', 'smtp_gmail_email' => 'sender@gmail.com', 'smtp_gmail_refresh_token' => 'refresh',
        'smtp_gmail_client_id' => 'id', 'smtp_gmail_client_secret' => 'secret', 'smtp_gmail_access_token' => '', 'smtp_gmail_token_expires_at' => 0,
    ));
}

// 1. An expired/revoked Google refresh token is recorded, shown as "reconnect", and explained in the send error.
gmail_connected();
$token_response = array('code' => 400, 'body' => json_encode(array('error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.')));
list($mailer, $error) = send_and_fail();
check_smtp($mailer->SMTPAuth && $mailer->AuthType === 'XOAUTH2' && $mailer->oauth === null, 'Failed Google token still configured an OAuth provider');
check_smtp(tk_smtp_oauth_error('gmail') === 'Token has been expired or revoked.', 'Google refresh failure was not stored for the status badge');
check_smtp(strpos($error, 'Google OAuth token unavailable: Token has been expired or revoked.') === 0, 'Send error hides the real OAuth failure');
check_smtp(strpos($error, 'Could not authenticate') !== false && strpos($error, 'In production') !== false, 'Send error lacks the PHPMailer message or the Testing-status hint');

// On an old core without OAuthTokenProvider, the error says so instead of a bare authentication failure.
$token_response = array('code' => 200, 'body' => json_encode(array('access_token' => 'fresh', 'expires_in' => 3600)));
list($mailer, $error) = send_and_fail();
check_smtp($mailer->oauth === null && strpos($error, 'no OAuth support') !== false, 'Missing PHPMailer OAuth support was not explained');

// A successful refresh clears the reconnect state, and a later unrelated send carries no stale note.
define_oauth_interface();
$options['smtp_gmail_token_expires_at'] = 0;
list($mailer, $error) = send_and_fail('SMTP connect() failed.');
check_smtp($mailer->oauth !== null && base64_decode($mailer->oauth->getOauth64()) === "user=sender@gmail.com\001auth=Bearer fresh\001\001", 'Refreshed Google token was not handed to PHPMailer');
check_smtp(tk_smtp_oauth_error('gmail') === '' && $mailer->AuthType === 'XOAUTH2', 'Successful Google refresh did not clear the reconnect state');
check_smtp($error === 'SMTP connect() failed.', 'A non-authentication failure was given an authentication hint');

// Microsoft refresh failures are tracked separately.
$options = array('smtp_enabled' => 1, 'smtp_provider' => 'office365', 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_secure' => 'tls',
    'smtp_username' => 'box@company.test', 'smtp_microsoft_email' => 'box@company.test', 'smtp_microsoft_refresh_token' => 'refresh',
    'smtp_microsoft_client_id' => 'id', 'smtp_microsoft_client_secret' => 'secret');
$token_response = array('code' => 400, 'body' => json_encode(array('error_description' => 'AADSTS70000: consent revoked')));
list($mailer, $error) = send_and_fail();
check_smtp(tk_smtp_oauth_error('microsoft') === 'AADSTS70000: consent revoked' && tk_smtp_oauth_error('gmail') === '', 'Microsoft refresh failure was not tracked separately');
check_smtp(strpos($error, 'Microsoft OAuth token unavailable') === 0 && strpos($error, 'Authenticated SMTP') !== false, 'Microsoft failure lacks its explanation');

// 4. Disconnect removes the stale OAuth username; sending afterwards says "not connected" instead of failing silently.
$options = array();
gmail_connected();
tk_smtp_set_oauth_error('gmail', 'old failure');
try { tk_smtp_google_disconnect(); } catch (LogicException $redirect) {}
check_smtp($options['smtp_username'] === '' && $options['smtp_gmail_refresh_token'] === '' && tk_smtp_oauth_error('gmail') === '', 'Google disconnect left a stale username, token or error');
list($mailer, $error) = send_and_fail();
check_smtp($mailer->SMTPAuth && strpos($error, 'Google OAuth is not connected.') !== false, 'Sending after disconnect did not explain the missing connection');

// 3. Password SMTP hints depend on the host.
$hints = array(
    'smtp.gmail.com' => 'App Password',
    'smtp.office365.com' => 'Microsoft 365 OAuth provider',
    'mail.example.test' => 'full mailbox address',
);
foreach ($hints as $host => $expected) {
    $options = array('smtp_enabled' => 1, 'smtp_provider' => 'custom', 'smtp_host' => $host, 'smtp_port' => 587, 'smtp_secure' => 'tls',
        'smtp_username' => 'user@example.test', 'smtp_password' => 'secret');
    list($mailer, $error) = send_and_fail();
    check_smtp($mailer->AuthType === '' && $mailer->Password === 'secret' && strpos($error, $expected) !== false, 'Missing ' . $host . ' hint');
}
$options['smtp_password'] = '';
list($mailer, $error) = send_and_fail();
check_smtp(strpos($error, 'No SMTP password is saved') !== false, 'Empty password was not reported');

// 5. A blank password keeps the saved one; only the checkbox clears it.
$options = array('smtp_password' => 'kept');
$_POST = array('smtp_provider' => 'custom', 'smtp_username' => 'user@example.test', 'smtp_password' => '');
try { tk_smtp_save(); } catch (LogicException $redirect) {}
check_smtp($options['smtp_password'] === 'kept', 'A blank password field erased the saved password');
$_POST['smtp_password_clear'] = '1';
try { tk_smtp_save(); } catch (LogicException $redirect) {}
check_smtp($options['smtp_password'] === '', 'Remove saved password did not clear it');
$_POST = array('smtp_provider' => 'custom', 'smtp_username' => 'user@example.test', 'smtp_password' => 'new-secret');
try { tk_smtp_save(); } catch (LogicException $redirect) {}
check_smtp($options['smtp_password'] === 'new-secret', 'A new password was not saved');

// DKIM selector guesses follow the mail host in SPF/MX (Hostinger signs with hostingermail-a, not google).
$hostinger = tk_smtp_dkim_selector_candidates('v=spf1 include:_spf.mail.hostinger.com ~all mx1.hostinger.com');
check_smtp(array_slice($hostinger, 0, 3) === array('hostingermail-a', 'hostingermail-b', 'hostingermail-c'), 'Hostinger DKIM selectors were not tried first');
check_smtp(tk_smtp_dkim_selector_candidates('include:spf.protection.outlook.com')[0] === 'selector1' && tk_smtp_dkim_selector_candidates('include:_spf.google.com')[0] === 'google', 'Microsoft/Google selectors were not tried first');
check_smtp(count($hostinger) === count(array_unique($hostinger)) && in_array('default', tk_smtp_dkim_selector_candidates(''), true), 'Selector candidates are duplicated or miss generic selectors');

echo 'PASS: ' . $checks . " SMTP OAuth health, actionable authentication errors, disconnect cleanup, password handling and DKIM selector checks\n";
