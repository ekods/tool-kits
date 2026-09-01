# Tool Kits

WordPress admin toolkit for database work, security, optimization, monitoring, cache, and operational utilities in one dashboard.

---

## Section 1: Overview

### Short Description

Tool Kits helps WordPress administrators manage five main areas:
- Site security.
- Performance optimization.
- Database migration and maintenance.
- Site health monitoring.
- Operational plugin access control.

### Key Features

- Database export/import SQL.
- Preload export with serialized-safe find/replace.
- DB cleanup for revisions, trash, spam comments, transients, and table optimization.
- Hide Login, Minify, Auto WebP, Lazy Load, Asset Optimization.
- Upload limits and User ID changer.
- Captcha and anti-spam Contact Form 7.
- IP-based login rate limiting with an unblock panel.
- Login log for successful and failed attempts with retention.
- Hardening (XML-RPC, headers, WAF basic, HTTP Auth, CORS).
- Firewall with payload WAF, IP/CIDR allowlist/blocklist, user-agent rules, and event log.
- Signature-based Malware Scanner for executable uploads, obfuscation, encoded payloads, and web-shell markers.
- SMTP (preset provider + test email + log).
- Monitoring (checks, realtime, 404 monitor, healthcheck, heartbeat).
- Cache tools (page cache, object flush, opcache reset, fragment flush).
- Theme checker (summary, largest file, duplicate PHP, risky functions).
- Tool Kits Access (role/IP access, alerts, audit log, owner mode, license).
- Role Management for custom roles, per-module capabilities including custom post type CRUD, and visible dashboard menus per role.

### Latest Updates (Access, License, Monitoring)

- Collector, heartbeat, and license configuration now follow one shared configuration flow.
- The `Tool Kits Access > License` tab shows `Collector URL`, auto-derived `Heartbeat URL` and `License server URL`, plus `Configured/Missing` badges.
- Added `Test Heartbeat` and `Test License Reachability` before license activation.
- Diagnostics show connection status, last success, last failure, last error, and the latest heartbeat/license endpoint.
- Monitoring only shows valid healthcheck data to avoid misleading output on local or shared hosting.

### Latest Updates (Hardening)

- Direct `/wp-login.php` requests are now redirected to the homepage when Hide Login is enabled; only the configured custom login slug can reach the WordPress login flow.
- Login honey trap and progressive lockout added to reduce repeated bot hits against common login URLs.
- WordPress fingerprint reduction added for public discovery links, REST link headers, feed links, emoji traces, author redirect signals, and common scanner-readable root files.
- HSTS header is now available as a toggle and recommended on by default.
- Strict CSP option added without `unsafe-inline` or `unsafe-eval`.
- Hide server signature option added for `X-Powered-By`/`expose_php`.
- Force `HttpOnly`/`Secure` on response cookies option added.
- Disable WP-Cron (`DISABLE_WP_CRON`) option added from the hardening panel.
- URL Parameter Guard option added to block suspicious query strings.
- HTTP methods filtering added with method and path allowlists.
- Dangerous HTTP method blocking added. Default methods: PUT, DELETE, TRACE, CONNECT.
- Harden `robots.txt` option added with a minimal policy.
- Unwanted file blocking added with a custom filename list.
- Public MySQL risk check for port 3306 added to hardening monitoring.
- Note: the plugin cannot close port 3306 directly; final mitigation must be handled in the server firewall or security group.

### Struktur Menu

- `Tool Kits`
- `Tool Kits > Database`
- `Tool Kits > Optimization`
- `Tool Kits > Spam Protection`
- `Tool Kits > Rate Limit`
- `Tool Kits > Login Log`
- `Tool Kits > Hardening`
- `Tool Kits > Firewall`
- `Tool Kits > Malware Scanner`
- `Tool Kits > SMTP`
- `Tool Kits > Monitoring`
- `Tool Kits > Cache`
- `Tool Kits > Themes Checker`
- `Tools > Tool Kits Access`
- `Tool Kits > Role Management`

Note: some menus depend on license status.

### Build Release ZIP

Run from the workspace root:

```bash
bash plugins/tool-kits/scripts/build-release-zip.sh
```

The default output is created at:

```text
plugins/tool-kits.zip
```

To choose a custom output path:

```bash
bash plugins/tool-kits/scripts/build-release-zip.sh /tmp/tool-kits.zip
```

### Module Details

#### 1) Database

For backups, migrations, and data maintenance.
- The full Database module can be used without license activation or Collector Token.
- `Export Database`: download a full SQL dump.
- `Export Download (Preload)`: generate a SQL.gz file with serialized-safe find/replace pairs.
- `Import Database`: import `.sql` or `.sql.gz` into the active database.
- `Change Prefix`: rename table prefixes and update related keys, including an automatic backup before the process.
- `DB Cleanup`: clean unnecessary data to keep the database lighter.

When to use it:
- Moving domains or staging to production.
- Backing up before major changes.
- Periodic cleanup of redundant data.

#### 2) Optimization

For faster loading and reduced frontend overhead.
- `Hide Login`: change the default login URL.
- `Minify`: compress HTML, inline CSS, and inline JS.
- `Auto WebP`: automatically convert images and generate WebP for existing media.
- `Lazy Load`: defer image, iframe, and video loading.
- `Assets`: critical CSS, deferred/preloaded CSS, font preload, and font-display swap.
- `Uploads`: configure separate size limits for images, documents/PDFs, and videos.
- `User ID`: change a specific user ID. This is a sensitive action.

#### 3) Spam Protection

To reduce bot submissions and form abuse.
- `Captcha`: enable/disable captcha and login form options.
- `Anti-spam Contact`: honeypot and minimum submit delay for CF7.

#### 4) Rate Limit

To limit brute-force login attempts.
- Configure the window, attempt count, and lockout duration.
- Optional permanent IP block on failure.
- Safe IP whitelist.
- Unblock IPs from the admin panel.

#### 5) Login Log

For login auditing.
- Record successful and failed login attempts.
- Store time, IP, and user agent.
- Filter status + clear log.
- Configure log retention.

#### 6) Hardening

To reduce the WordPress attack surface.
- Disable file editor.
- Disable XML-RPC or block risky methods.
- Disable REST user enumeration.
- Add security headers, including HSTS.
- Strict CSP mode (optional).
- Hide server/PHP signature headers.
- Force HttpOnly/Secure for response cookies.
- Disable WP-Cron from settings.
- URL parameter guard.
- HTTP methods filtering + block dangerous methods.
- Block PHP execution in uploads.
- Basic WAF based on path/method rules.
- HTTP Basic Auth scope frontend/backend.
- CORS allowlist custom.
- Harden robots.txt.
- Block unwanted file access.
- Check public DB host risk as an indicator of MySQL port 3306 exposure.

#### 7) SMTP

For reliable WordPress email delivery.
- Preset Gmail/Microsoft 365/Custom.
- Setting host, port, secure mode, auth.
- Send a test email and review the result log.

#### 8) Monitoring

For operational visibility and early issue detection.
- Configuration checks.
- Quick actions (cache clear, toggle update, wp-config permission).
- Realtime health monitor.
- 404 monitor + exclude rules.
- Healthcheck endpoint + secret key.
- Scheduled heartbeat to an external collector.
- Summary of collector/heartbeat status and latest heartbeat result.

#### 9) Cache

- For cache control from one place.
- File-based page cache for anonymous visitors.
- TTL dan path exclude.
- Purge page cache.
- Flush object cache.
- Reset OPcache.
- Flush fragment cache keys.

#### 10) Themes Checker

For active theme quality auditing.
- File and asset size summary.
- Largest file list.
- Duplicate PHP detection.
- Risky function pattern detection.

#### 11) Tool Kits Access

For controlling who can access the plugin.
- Role allowlist.
- IP allowlist.
- Lock settings.
- Security alerts via email.
- Change audit log.
- Owner mode and license settings.
- Collector/heartbeat/license connection status, token status, and latest check result.

### Installation

1. Upload the `tool-kits` folder to `wp-content/plugins/`.
2. Activate the plugin from the `Plugins` screen.
3. Open `Tool Kits` and `Tools > Tool Kits Access`.
4. Configure the license, access roles, and alert email before using it in production.

### Best Practice

- Always back up before `Import DB`, `Change Prefix`, or `Change User ID`.
- Test sensitive features such as Hide Login, WAF, HTTP Auth, CORS, and IP blocking on staging.
- Save the custom login URL if Hide Login is active.
- Use the SMTP test after changing providers.

---

## Section 2: English

### Short Description

Tool Kits is an all-in-one WordPress admin toolkit focused on:
- Security hardening.
- Performance optimization.
- Database migration and cleanup.
- Site monitoring and health visibility.
- Operational access control for plugin features.

### Core Features

- SQL database export/import.
- Preloaded export with serialized-safe find/replace pairs.
- Database cleanup (revisions, trash, spam comments, transients, optimize table).
- Hide Login, Minify, Auto WebP, Lazy Load, Asset Optimization.
- Upload limits and User ID changer.
- Captcha and Contact Form 7 anti-spam.
- IP-based login rate limiting with unblock panel.
- Login logs with retention.
- Hardening options (XML-RPC, headers, WAF basic, HTTP Auth, CORS).
- Firewall with payload WAF, IP/CIDR allow/block rules, user-agent rules, and an event log.
- Signature-based Malware Scanner for executable uploads, obfuscation, encoded payloads, and web-shell markers.
- SMTP presets + test email + test logs.
- Monitoring (checks, realtime health, 404 monitor, healthcheck, heartbeat).
- Cache controls (page cache, object flush, OPcache reset, fragment flush).
- Theme checker (summary, largest files, duplicate PHP, risky functions).
- Access controls (roles/IP allowlist, alerts, audit log, owner mode, license).
- Role Management for creating custom roles, configuring capabilities by module, and choosing visible dashboard menus per role.

### Recent Updates (Access, License, Monitoring)

- Collector, heartbeat, and license configuration now follow one shared setup flow.
- `Tool Kits Access > License` now shows explicit collector input, derived heartbeat/license URLs, and `Configured/Missing` status badges.
- Added `Test Heartbeat` and `Test License Reachability` actions before license activation.
- Diagnostics now surfaces connection status, last success, last failure, last error, and last checked endpoint for heartbeat/license flows.
- Monitoring now hides invalid healthcheck values instead of displaying misleading placeholders.

### Recent Updates (Hardening)

- HSTS header toggle is available and recommended defaults are enabled.
- Strict CSP mode added (without `unsafe-inline`/`unsafe-eval`).
- Server signature hiding option added (`X-Powered-By`/`expose_php`).
- Force `HttpOnly`/`Secure` cookie response flags added.
- WP-Cron disable option (`DISABLE_WP_CRON`) added in hardening settings.
- URL Parameter Guard added for suspicious query strings.
- HTTP methods filtering added (method allowlist + path allowlist).
- Dangerous HTTP methods blocking added (default: PUT, DELETE, TRACE, CONNECT).
- `robots.txt` hardening option added (minimal policy).
- Unwanted file access blocking added with custom filename list.
- MySQL public exposure risk check (port 3306 indicator) added in monitoring checks.
- Note: the plugin cannot close port 3306 directly; final mitigation must be done via server firewall/security groups.

### Menu Structure

- `Tool Kits`
- `Tool Kits > Database`
- `Tool Kits > Optimization`
- `Tool Kits > Spam Protection`
- `Tool Kits > Rate Limit`
- `Tool Kits > Login Log`
- `Tool Kits > Hardening`
- `Tool Kits > Firewall`
- `Tool Kits > Malware Scanner`
- `Tool Kits > SMTP`
- `Tool Kits > Monitoring`
- `Tool Kits > Cache`
- `Tool Kits > Themes Checker`
- `Tools > Tool Kits Access`
- `Tool Kits > Role Management`

Note: some menus depend on license state.

### Module Breakdown (Clearer)

#### 1) Database

Used for backup, migration, and data maintenance.
- The complete Database module can be used without license activation or a Collector Token.
- `Export Database`: full SQL dump download.
- `Export Download (Preload)`: temporary SQL.gz with serialized-safe replacement pairs.
- `Import Database`: import `.sql` or `.sql.gz` into the active database.
- `Change Prefix`: rename table prefix and related keys with automatic backup before execution.
- `DB Cleanup`: remove unnecessary data to reduce database bloat.

Typical use cases:
- Domain/environment migration.
- Pre-change backup.
- Routine cleanup.

#### 2) Optimization

Focused on frontend speed and payload reduction.
- `Hide Login`: move login endpoint from default URL.
- `Minify`: compress HTML, inline CSS, and inline JS.
- `Auto WebP`: convert images on upload and generate WebP for existing media.
- `Lazy Load`: defer images/iframes/videos.
- `Assets`: critical CSS, defer/preload CSS, preload fonts, font-display swap.
- `Uploads`: separate upload limits for images, documents/PDF files, and videos.
- `User ID`: sensitive utility to change a user ID.

#### 3) Spam Protection

Reduces automated form abuse.
- `Captcha`: enable/disable captcha and login form protection.
- `Anti-spam Contact`: honeypot + minimum submit delay for CF7.

#### 4) Rate Limit

Protects against login brute force.
- Configure window, attempts, and lockout duration.
- Optional permanent IP block on failed login.
- IP allowlist support.
- Admin unblock UI.

#### 5) Login Log

Tracks authentication activity.
- Success and failed login records.
- Includes IP, timestamp, and user agent.
- Status filters, clear log action, retention settings.

#### 6) Hardening

Reduces WordPress attack surface.
- Disable file editor.
- Disable XML-RPC or block risky methods.
- Disable REST user enumeration.
- Apply security headers (including HSTS).
- Optional strict CSP mode.
- Hide server/PHP signature headers.
- Force HttpOnly/Secure cookie response flags.
- Disable WP-Cron from settings.
- URL parameter guard.
- HTTP methods filtering + dangerous methods block.
- Block PHP execution in uploads.
- Basic request filtering via WAF options.
- HTTP Basic Auth for frontend/backend scope.
- Custom CORS allowlist.
- Harden robots.txt output.
- Block direct access to unwanted files.
- Check public DB host risk (possible MySQL 3306 exposure).

#### 7) SMTP

Improves outbound email reliability.
- Presets for Gmail, Microsoft 365, and custom SMTP.
- Host/port/security/auth settings.
- Send test email and inspect logs.

#### 8) Monitoring

Provides operational visibility and early detection.
- Configuration checks.
- Quick maintenance actions.
- Realtime health overview.
- 404 monitor with exclusions.
- Healthcheck endpoint with key.
- Scheduled heartbeat to external collector.
- Collector/heartbeat connection summary and latest heartbeat result.

#### 9) Cache

Central cache management.
- File-based page cache for anonymous visitors.
- TTL and excluded paths.
- Manual purge actions.
- Object cache flush and OPcache reset.
- Fragment cache key flush.

#### 10) Themes Checker

Audits the active theme footprint and risks.
- Size and file summary.
- Largest file list.
- Duplicate PHP detection.
- Risky function pattern scan.

#### 11) Tool Kits Access

Controls who can manage plugin features.
- Role allowlist.
- IP allowlist.
- Lock mode.
- Email security alerts.
- Audit log.
- Owner mode and license settings.
- Collector/heartbeat/license diagnostics, token state, and latest check results.

### Installation

1. Upload `tool-kits` to `wp-content/plugins/`.
2. Activate it from `Plugins`.
3. Open `Tool Kits` and `Tools > Tool Kits Access`.
4. Configure license, access roles, and alert email before production use.

### Best Practices

- Always back up before `DB Import`, `Prefix Change`, or `User ID Change`.
- Test sensitive controls in staging first.
- Store your custom login URL securely if Hide Login is enabled.
- Run SMTP test after provider or credential changes.
