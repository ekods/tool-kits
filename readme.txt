=== Tool Kits ===
Contributors: toolkits
Tags: security, migrate, database, cleanup, login
Requires at least: 5.8
Tested up to: 6.6
<<<<<<< Updated upstream
Stable tag: 2.1.3
=======
Stable tag: 2.1.4
>>>>>>> Stashed changes
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
= 2.1.3 =
- Fix updater version parsing for release tags that start with uppercase `V`.
- Continue preferring clean tag archives and clean packaged ZIP assets for updates.

= 2.1.1 =
- Fix GitHub updater package selection to prefer clean tag archives.
- Exclude macOS metadata and build artifacts from release packaging.

= Unreleased =
- Hardening: tambah HSTS toggle, strict CSP, hide server signature header, force HttpOnly/Secure cookies.
- Hardening: tambah disable WP-Cron, URL parameter guard, HTTP method filtering, dan block dangerous methods (PUT/DELETE/TRACE/CONNECT).
- Hardening: tambah robots.txt minimal policy dan block direct access unwanted filenames.
- Monitoring: tambah check risiko DB host publik (indikasi eksposur MySQL port 3306).
= 2.0.4 =
- Sync plugin metadata with the current release version.
- Prefer tagged GitHub release archives when no `tool-kits.zip` asset is attached.
= 1.0.1 =
- Tambahkan pemeriksaan update otomatis dari GitHub.
= 1.0.0 =
- Initial release.
