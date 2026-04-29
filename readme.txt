=== Tool Kits ===
Contributors: toolkits
Tags: security, migrate, database, cleanup, login
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 2.3.0
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
- License and heartbeat configuration are now aligned around a single collector-based flow with explicit derived URLs, connection diagnostics, and reachability checks.
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
= 2.3.0 =
**System Monitoring — Real-Time Health Monitor Enhancements**
- Add CPU Load (1m) metric card with live progress bar and color-coded indicator (green / yellow / red).
- Add CPU Load History line chart with animated SVG — zone bands, gradient area fill, moving dot, and dynamic Y-axis labels that auto-scale.
- Add chart legend showing Normal (< 2.0), Moderate (2–4), and High (> 4.0) zones.
- Refactor `monitoring-tabs.js` into a dedicated `drawCpuChart()` helper for clean separation of chart rendering logic.
- Memory progress bar now animates smoothly with CSS transition on value change.
- Add Page Load Time metric populated via browser Performance API (client-side).
- Add analysis layer to identify whether slowness originates from the server (high CPU/memory) or website code (slow AJAX RTT).

**Monitoring Page — UI / UX Improvements**
- Move "Send Heartbeat Now" button into the hero section (`tk-hero-content`) for better visibility as a primary action.
- Refactor `tk_render_page_hero()` to accept a 4th `$action_html` parameter for hero-level call-to-action injection.
- Add Heartbeat/Collector status indicator (Online / Offline / Not Connected) to the global `tk-header-branding` bar.

**Heartbeat — Configuration Simplification**
- Simplify `tk_heartbeat_collector_url()`: now returns `TK_HEARTBEAT_URL` constant directly if defined, with a hardcoded fallback to `https://nexamonitor.theteamtheteam.com/api/toolkits/heartbeat`.
- Remove legacy multi-step URL derivation from license server URL, reducing potential points of failure.

**Asset Optimization — Critical CSS Generator**
- Change Critical CSS generation strategy to only read the **newest modified** stylesheet (by `filemtime`) from the homepage `<head>`, instead of concatenating all stylesheets.
- Ensures generated Critical CSS always reflects the most recent compiled theme file.
- Update UI description to accurately reflect the new behavior.

= 2.2.1 =
- Align license and heartbeat configuration around a shared collector URL and centralized helper fallbacks.
- Add connection status diagnostics for collector, heartbeat, and license endpoints, including last success/failure timestamps and error details.
- Add heartbeat and license reachability test actions before activation.
- Hide invalid monitoring data for load average, next cron, and disk metrics instead of showing misleading placeholders.
- Improve realtime cache reporting with explicit Configured, Off, and Unknown states.

= 2.1.9 =
- Harden the GitHub updater download flow to avoid installing HTML error pages returned by hosting or redirects.
- Validate downloaded update packages before install by checking HTTP status, file size, and ZIP signature.
- Improve updater failure messages when GitHub release downloads return 404 or non-ZIP responses.

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
