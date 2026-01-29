=== Tool Kits ===
Contributors: toolkits
Tags: security, migrate, database, cleanup, login
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 1.0.3
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
= 1.0.1 =
- Tambahkan pemeriksaan update otomatis dari GitHub.
= 1.0.0 =
- Initial release.
