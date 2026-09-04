=== Tool Kits ===
Contributors: toolkits
Tags: security, migrate, database, cleanup, login
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 2.5.20
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tool Kits is an admin toolkit plugin for:
- DB Migrate: SQL export, serialized-safe Find & Replace, and table prefix rename.
- DB Cleanup: Clean revisions, trash, spam, transients, and optimize tables.
- Security: Hide Login, Captcha, Anti-spam Contact (CF7), login rate limiting, Login Log, and Hardening.
- Vulnerability Scanner: review outdated WordPress core, plugins, themes, inactive components, and security-sensitive configuration.
- Brute Force Protection: dedicated login protection workflow for rate limiting, progressive lockout, bad username blocking, origin guard, honey traps, and scanner traps.
- Incident Response: investigation and malware removal tracking, post-incident blocklist removal, and post-incident search engine security cleanup.

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
- Stealth hardening update: reduce public WordPress fingerprint by removing discovery links, REST link headers, feed links, generator output, script versions, emoji traces, and common scanner-readable root files.
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
= 2.5.20 =
**Malware Scanner**
- Skip hidden iframe signature matching inside theme files to reduce false positives from legitimate theme embeds.
- Skip SVG files inside themes from malware scanning when SVG is included through scanner extension filters.
- Keep executable upload, encoded execution, request execution, web-shell, and obfuscation signatures active for theme PHP/JS/HTML files.

= 2.5.19 =
**Malware Scanner**
- Store line number, reason, and a short code snippet for each matched malware signature.
- Include findings, reasons, and code snippets in scheduled malware alert emails.
- Show evidence details in the Malware Scanner report table.

= 2.5.18 =
**Vulnerability Scanner**
- Add Vulnerability Scanner under Tool Kits Security.
- Scan WordPress core, plugins, and themes using WordPress update metadata.
- Flag inactive plugins and themes for removal review.
- Flag HTTPS configuration risk when WordPress does not detect SSL.

**Brute Force Protection**
- Add dedicated Brute Force Protection menu entry.
- Reuse the existing login protection engine for IP throttling, progressive lockout, attacker username blocking, origin guard, honey traps, scanner traps, and unblock workflows.

= 2.5.17 =
**Incident Response**
- Add Incident Response workflow under Tool Kits Security.
- Add Investigation and Malware Removal checklist with case status, notes, removal log, and investigation snapshot.
- Add Post-incident Blocklist Removal tracking with external vendor review links.
- Add Post-incident Search Engine Security Cleanup tracking for spam URL cleanup, sitemap/indexing repair, Search Console/Bing review, and recrawl follow-up.
- Add exportable plain-text incident response report.

= 2.5.16 =
**HTTP Authentication**
- Treat the active Hide Login custom slug as an admin/login request for HTTP Authentication scope matching.
- Ensure HTTP Authentication appears on the custom Hide Login URL when the scope is set to `Admin and login only`.

= 2.5.15 =
**Update Security**
- Force all administrators and users to re-login after Tool Kits is updated to a newer version.
- Invalidate all WordPress session tokens on plugin version upgrade and clear the current auth cookie during the update request.

= 2.5.14 =
**Hide Login**
- Rewrite WordPress core `wp-login.php` form/action URLs to the configured custom login slug.
- Keep the login honey trap from blocking the active custom login slug, even when the slug matches a configured trap path.
- Direct `/wp-login.php` hits continue to be blocked and redirected to the homepage.

= 2.5.13 =
**Login Protection**
- Add generic login error obfuscation to reduce username discovery.
- Add common attacker username protection for non-existing usernames such as admin, administrator, root, test, demo, and wpadmin.
- Add same-site Origin/Referer guard for login POST requests.
- Add configurable 404 scanner trap for sensitive probe paths such as .env, wp-config backups, debug logs, adminer, phpinfo, and backup dumps.
- Add Rate Limit dashboard controls for login shield and scanner trap settings.

= 2.5.12 =
**Security Dashboard**
- Count active Rate Limit blocked IPs and Firewall IP/CIDR blocklist rules in the Firewall Summary Blocklist column.
- Add a dashboard note clarifying that Blocklist includes currently blocked IP/rule entries.

= 2.5.11 =
**Login Protection**
- Add login honey trap paths for common bot targets when Hide Login is enabled.
- Redirect honey trap hits to the homepage and temporarily lock the source IP.
- Add progressive lockout steps for repeated login abuse.
- Add Rate Limit settings for progressive lockout and honey trap path configuration.

= 2.5.10 =
**Hide Login**
- Redirect direct `/wp-login.php` hits to the homepage when Hide Login is enabled.
- Continue recording blocked direct login hits as `brute_force` security events.

= 2.5.9 =
**Hide Login**
- Block direct `/wp-login.php` requests when Hide Login is enabled instead of allowing POST login attempts through the default endpoint.
- Keep custom login slug flow working for login, logout, lost password, and register URLs.
- Record blocked direct login hits as `brute_force` security events with reason `direct_wp_login_blocked`.

= 2.5.8 =
**Stealth Hardening**
- Add WordPress fingerprint reduction for public head tags, REST discovery headers, feed links, emoji traces, and author redirect signals.
- Enable safer stealth defaults through Auto Hardening for existing sites.
- Expand unwanted file blocking and server rule snippets for common WordPress/dev files such as readme.html, license.txt, wp-config-sample.php, composer files, and package manifests.
- Include fingerprint reduction in hardening score, active protections, recommendations, and security tamper checks.

= 2.5.7 =
**Security Dashboard**
- Add a Wordfence-style dashboard widget with attacks blocked charts, firewall summary, top countries, and top blocked IPs.
- Add drilldown links from country and IP summaries to a persistent Attack Details page.
- Add persistent `tk_security_events` storage for blocked request metrics with indexes for event, category, IP, and country queries.
- Add one-time backfill from legacy login and firewall logs into persistent security events.
- Add configurable retention cleanup for security events with daily maintenance and a manual Run Maintenance action.

**Login Protection**
- Add failed-login reason tracking and show the reason in login activity details.
- Record failed logins, firewall blocks, WAF blocks, and auto-block actions as security events.
- Add configurable auto-block rules for repeated failed logins and bot-like login user agents.

**Role Management**
- Generate custom role slugs automatically from the display name.
- Keep custom role slugs immutable after creation while showing a live slug preview during role creation.

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
