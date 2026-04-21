=== Tool Kits ===
Contributors: toolkits
Tags: security, migrate, database, cleanup, login
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 2.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tool Kits adalah plugin admin toolkit untuk:
- DB Migrate: Export SQL, Find & Replace serialized-safe, dan Rename table prefix.
- DB Cleanup: Bersihkan revisions, trash, spam, transients, dan optimize tabel.
- Security: Hide Login, Captcha, Anti-spam Contact (CF7), Rate Limit login, Login Log, Hardening.

== Installation ==
1. Upload folder `tool-kits` ke `/wp-content/plugins/`
2. Activate plugin di Plugins
3. Buka menu "Tool Kits" dan "Tool Kits Security"

== Notes ==
- Change DB Prefix: plugin akan rename tabel dan update meta keys, tetapi Anda tetap harus update `$table_prefix` di wp-config.php manual.
- Export SQL: best-effort via WPDB. Untuk database besar, gunakan phpMyAdmin/CLI.
- Update checker mengambil rilis dari GitHub (release asset `tool-kits.zip` direkomendasikan untuk instalasi otomatis).
- Added heartbeat collector integration improvements: heartbeat payload now includes hide-login slug/URL and collector dashboard surfaces those fields alongside the license data.
- Hardening update: added HSTS toggle, strict CSP, server signature hide, cookie HttpOnly/Secure enforcement, WP-Cron disable toggle, URL parameter guard, HTTP methods filtering, dangerous method block, robots.txt hardening, and unwanted file access block.
- Monitoring checks now include risky public DB host detection (possible MySQL port 3306 exposure indicator).
- Penting: plugin tidak dapat menutup port 3306 secara langsung; pembatasan akses DB tetap wajib di firewall/security group server.

== Developer Notes ==
Filters to adjust CORS by environment (optional example):

    add_filter('tk_hardening_allowed_origins', function($origins) {
        if (!function_exists('wp_get_environment_type')) {
            return $origins;
        }
        $env = wp_get_environment_type(); // production, staging, development, local
        if ($env === 'staging') {
            $origins[] = 'https://staging.example.com';
        } elseif ($env === 'production') {
            $origins[] = 'https://app.example.com';
        }
        return array_unique($origins);
    });

    add_filter('tk_hardening_allowed_cors_methods', function($methods, $origin) {
        if (!function_exists('wp_get_environment_type')) {
            return $methods;
        }
        $env = wp_get_environment_type();
        if ($env === 'production') {
            return array('GET', 'POST', 'OPTIONS');
        }
        return array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS');
    }, 10, 2);

    add_filter('tk_hardening_allowed_cors_headers', function($headers, $origin) {
        $headers[] = 'X-Custom-Header';
        return array_unique($headers);
    }, 10, 2);


== Changelog ==
= 2.1.8 =
- Add server-side captcha validation for Contact Form 7 submissions.
- Add stronger anti-spam contact protections: random-pattern detection, duplicate submission blocking, email cooldown, and IP cooldown.
- Add a global form guard for public POST requests, suspicious user agents, comment honeypot/timing checks, and optional comment captcha enforcement.

= 2.1.5 =
- Sync plugin metadata with the current plugin version.
- Improve GitHub updater diagnostics and release-package validation workflow.

= 2.1.4 =
- Add heartbeat collector integration improvements with hide-login slug/URL reporting.
- Add hardening controls for HSTS, strict CSP, server signature suppression, secure cookies, WP-Cron, URL parameter guard, HTTP method filtering, robots.txt, and unwanted-file blocking.
- Add monitoring check for risky public DB host exposure indicators.

= 2.1.3 =
- Fix updater version parsing for release tags that start with uppercase `V`.
- Continue preferring clean tag archives and clean packaged ZIP assets for updates.

= 2.1.1 =
- Fix GitHub updater package selection to prefer clean tag archives.
- Exclude macOS metadata and build artifacts from release packaging.

= 2.0.4 =
- Sync plugin metadata with the current release version.
- Prefer tagged GitHub release archives when no `tool-kits.zip` asset is attached.
= 1.0.1 =
- Tambahkan pemeriksaan update otomatis dari GitHub.
= 1.0.0 =
- Initial release.
