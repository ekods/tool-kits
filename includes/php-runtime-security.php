<?php
if (!defined('ABSPATH')) { exit; }

/** Local version assessment only: no HTTP, shell commands, filesystem scan or DB writes. */
function tk_php2shell_assessment(?string $version = null, ?string $sapi = null): array {
    $version = $version ?? PHP_VERSION;
    $sapi = $sapi ?? PHP_SAPI;
    $result = array(
        'status' => 'unknown', 'version' => $version, 'sapi' => $sapi,
        'source' => in_array($sapi, array('cli', 'phpdbg'), true) ? 'cli' : 'web',
        'checked_at' => time(), 'fixed_version' => '',
        'advisory' => 'https://www.php.net/archive/2026.php',
        'message' => 'PHP build is not covered by the known fixed-version list. Verify the vendor security advisory.',
    );
    if ($result['source'] === 'cli') {
        $result['message'] = 'CLI PHP does not establish the version serving web requests. Run a manual scan from WordPress admin.';
        return $result;
    }
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(.*)$/D', $version, $parts)) { return $result; }
    $branch = $parts[1] . '.' . $parts[2];
    // Source: PHP security advisory, 21 October 2026. Unknown/new branches never pass by inference.
    $fixed = array('8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12');
    $result['fixed_version'] = $fixed[$branch] ?? '';
    if ($parts[4] !== '') {
        $result['status'] = 'verify_vendor';
        $result['message'] = 'Vendor or prerelease PHP build: confirm fixes for CVE-2026-109618 and CVE-2026-109619 with the vendor. Backports cannot be verified from the version string.';
    } elseif (isset($fixed[$branch])) {
        $result['status'] = version_compare($version, $fixed[$branch], '>=') ? 'patched' : 'affected';
        $result['message'] = $result['status'] === 'patched'
            ? 'This runtime reports a PHP version containing the PHP2Shell fixes. This is a version assessment, not a compromise scan.'
            : 'PHP version is below the first PHP2Shell fix. Update to ' . $fixed[$branch] . ' or later in this branch, restart PHP workers, and verify the web runtime. Confirm any vendor backport separately.';
    } elseif (version_compare($version, '5.4.0', '>=') && version_compare($version, '8.2.0', '<')) {
        $result['status'] = 'affected';
        $result['message'] = 'This legacy PHP branch requires a vendor-provided PHP2Shell fix or migration to a supported patched branch. Official upstream security support has ended; confirm any vendor backport separately.';
    }
    return $result;
}
