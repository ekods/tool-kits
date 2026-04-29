<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$wpLoad = $root . '/wp-load.php';
$live = in_array('--live', $argv, true);

if (!file_exists($wpLoad)) {
    fwrite(STDERR, "wp-load.php not found at {$wpLoad}\n");
    exit(1);
}

require_once $wpLoad;

if (!function_exists('tk_github_get_diagnostics')) {
    fwrite(STDERR, "Tool Kits plugin is not loaded.\n");
    exit(1);
}

$checks = array();

$checks[] = array(
    'name' => 'github_updater',
    'ok' => function_exists('tk_github_fetch_latest_release') && function_exists('tk_github_get_diagnostics'),
    'detail' => function_exists('tk_github_fetch_latest_release') ? 'Updater hooks available.' : 'Updater functions missing.',
);

$checks[] = array(
    'name' => 'license_validate',
    'ok' => function_exists('tk_license_validate') && function_exists('tk_license_test_connection'),
    'detail' => function_exists('tk_license_validate') ? 'License helpers available.' : 'License helpers missing.',
);

$checks[] = array(
    'name' => 'heartbeat_send',
    'ok' => function_exists('tk_heartbeat_send') && function_exists('tk_heartbeat_collector_url'),
    'detail' => function_exists('tk_heartbeat_send') ? 'Heartbeat helpers available.' : 'Heartbeat helpers missing.',
);

$checks[] = array(
    'name' => 'hardening_disable_comments',
    'ok' => function_exists('tk_hardening_init') && array_key_exists('hardening_disable_comments', (array) get_option('tk_options', array())),
    'detail' => array_key_exists('hardening_disable_comments', (array) get_option('tk_options', array()))
        ? 'Hardening option exists.'
        : 'Hardening option missing.',
);

if ($live) {
    $heartbeat = tk_heartbeat_send();
    $checks[] = array(
        'name' => 'heartbeat_live',
        'ok' => !empty($heartbeat['ok']),
        'detail' => isset($heartbeat['message']) ? (string) $heartbeat['message'] : '',
    );

    $license = tk_license_test_connection();
    $checks[] = array(
        'name' => 'license_live',
        'ok' => isset($license['status']) && (string) $license['status'] === 'valid',
        'detail' => isset($license['message']) ? (string) $license['message'] : '',
    );
}

$failed = false;

foreach ($checks as $check) {
    $ok = !empty($check['ok']);
    if (!$ok) {
        $failed = true;
    }
    printf(
        "[%s] %s: %s\n",
        $ok ? 'PASS' : 'FAIL',
        (string) $check['name'],
        (string) $check['detail']
    );
}

exit($failed ? 1 : 0);
