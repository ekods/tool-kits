=== Tool Kits ===
Contributors: toolkits
Tags: security, migrate, database, cleanup, login
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 2.5.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tool Kits is an admin toolkit plugin for:
- DB Migrate: SQL export, serialized-safe Find & Replace, and table prefix rename.
- DB Cleanup: Clean revisions, trash, spam, transients, and optimize tables.
- Security: Hide Login, Captcha, Anti-spam Contact (CF7), login rate limiting, Login Log, and Hardening.

== Installation ==
1. Upload the `tool-kits` folder to `/wp-content/plugins/`.
2. Activate the plugin from the Plugins screen.
3. Open the "Tool Kits" and "Tool Kits Security" menus.

== Notes ==
- Change DB Prefix: the plugin renames tables and updates related meta keys, but you still need to update `$table_prefix` in wp-config.php manually.
- Export SQL: best-effort via WPDB. For large databases, use phpMyAdmin or WP-CLI.
- The update checker retrieves releases from GitHub. The `tool-kits.zip` release asset is recommended for automatic installation.
- Added heartbeat collector integration improvements: heartbeat payload now includes hide-login slug/URL and collector dashboard surfaces those fields alongside the license data.
- License and heartbeat configuration are now aligned around a single collector-based flow with explicit derived URLs, connection diagnostics, and reachability checks.
- Hardening update: added HSTS toggle, strict CSP, server signature hide, cookie HttpOnly/Secure enforcement, WP-Cron disable toggle, URL parameter guard, HTTP methods filtering, dangerous method block, robots.txt hardening, and unwanted file access block.
- Monitoring checks now include risky public DB host detection (possible MySQL port 3306 exposure indicator).
- Important: the plugin cannot close port 3306 directly; DB access restrictions must still be enforced in the server firewall or security group.

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
= 2.5.6 =
**Cache**
- Add dashboard cache status widget with cached file count, cache size, and one-click page cache purge.
- Detect server/CDN cache layers from response headers and known WordPress cache integrations.
- Add server cache debug headers and refresh detection action to the Cache Status page.
- Purge supported plugin/server cache layers when clearing Tool Kits page cache.
- Auto-purge page cache on content, meta, term, menu, customizer, theme, and relevant option changes.
- Add optional auto-preload after purge for homepage and configured critical URLs.

= 2.3.9 =
**Compatibility**
- Bypass Tool Kits request-level security modules for WordPress AJAX requests so frontend `admin-ajax.php` handlers are not blocked by Tool Kits.

= 2.3.8 =
**Database Tools**
- Prevent search/replace from mutating user email fields and common email option values.
- Keep normal URL/content replacements active for fields such as post content, home, and siteurl.

**Cache**
- Skip page cache generation for dynamic requests such as sessions, carts, checkout, account pages, search, REST/API URLs, and private/no-cache responses.
- Prevent fragment cache helpers from creating cache entries when Page Cache is disabled.
- Clarify cache status messaging for static anonymous page caching.

= 2.3.7 =
**Release**
- Sync plugin metadata for the 2.3.7 package.

= 2.3.6 =
**Access Control**
- Enforce Role Management menu restrictions on direct admin URL access.
- Add Hide Tool Kits Menu control to the Tool Kits Access page.

= 2.3.5 =
**GitHub Updater**
- Align the update/install flow with Custom Fields Framework Pro.
- Let WordPress handle package downloads and normalize the extracted plugin root during installation.
- Rebuild release packaging from the plugin directory and exclude development metadata from the ZIP.

= 2.3.4 =
**GitHub Updater**
- Validate downloaded release ZIP structure before WordPress starts installation.
- Require the update package to contain the `tool-kits/tool-kits.php` plugin root.
- Force plugin update cleanup options so stale extracted folders do not block installation.
- Surface package validation problems through the Tool Kits updater status instead of only showing the generic WordPress install failure.

= 2.3.3 =
**Role Management**
- Show custom post types in the capability builder.
- Add explicit CRUD-oriented labels for post type capabilities.
- Keep shared WordPress primitive capabilities visible per post type while saving the correct underlying capability.

**Release Packaging**
- Rebuild the ZIP builder around `git archive` and `.gitattributes` export rules.
- Include current working tree changes in the release package through a temporary Git index.
- Exclude development files such as scripts, README, roadmap, Git metadata, and macOS metadata from the release archive.

= 2.3.2 =
**License-Free Database Module**
- Allow the complete Database page and all database actions without license activation or a Collector Token.
- Keep Tool Kits role/IP access controls, nonces, and the settings lock enforced.

**Firewall and Malware Scanner**
- Add a Firewall control page for payload WAF, IP/CIDR allow/block rules, blocked user agents, and recent event logging.
- Add a bounded, read-only Malware Scanner for suspicious executable uploads, encoded execution, obfuscation, and known web-shell markers.
- Keep scan results review-only to avoid destructive false-positive cleanup.

**Upload Limits**
- Add separate maximum sizes for documents/PDF files and videos.
- Detect image, document, and video uploads by extension and MIME family.
- Keep category limits capped by the PHP/web-server upload maximum.

**Role Management**
- Add custom WordPress roles based on an existing non-administrator role.
- Add grouped capability controls for content, custom post types, media, comments, users, appearance, plugins, settings, and third-party modules.
- Support independent create, edit, publish, read, and delete permissions where WordPress exposes primitive capabilities.
- Configure the visible dashboard sidebar menus for each Tool Kits-managed role.
- Prevent deletion while users are still assigned to a managed role.

**Gmail OAuth SMTP**
- Replace Gmail app-password authentication with Google OAuth 2.0 authorization.
- Add Google Client ID/Secret settings, an Authorized Redirect URI, and connect/disconnect actions.
- Send Gmail SMTP through XOAUTH2 and refresh expired access tokens automatically.
- Add Microsoft Entra ID OAuth authorization, tenant/mailbox settings, and automatic token refresh for Microsoft 365 SMTP.
- Keep a dedicated username/password setup for custom SMTP providers.

= 2.3.1 =
**License / Collector Fixes**
- Fix derived license endpoint so collector URL `/api/toolkits/heartbeat` resolves to `/api/toolkits/license`.
- Add fallback signed requests for legacy collector tokens and license endpoint variants.
- Add safe license diagnostics for heartbeat URL, license URL, and collector token fingerprint.
- Prevent empty license notes from being sent as `null` to NexaMonitor.

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
