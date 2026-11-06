# Tool Kits Roadmap

## v2.1.6

Priority: release safety and update visibility.

- Stabilize the GitHub updater flow for existing installs.
- Add an admin-facing diagnostics surface for updater state.
- Keep release metadata in sync across:
  - `tool-kits.php`
  - `readme.txt`
  - GitHub release tag
  - GitHub release asset name
- Validate packaged ZIP contents before publishing a release.

Definition of done:

- Release asset is named `tool-kits.zip`.
- ZIP root is exactly `tool-kits/`.
- `tool-kits/tool-kits.php` exists in the archive.
- Updater stores the latest install result in WordPress.

## v2.1.7

Priority: safety pass for destructive features.

- Add clearer confirmations for DB import, DB cleanup, and prefix change.
- Add stronger recovery guidance for hide-login and hardening settings.
- Audit capability checks and nonces across admin actions.
- Improve result summaries for database and hardening operations.

Definition of done:

- Every destructive action has a visible confirmation step.
- Every admin action path enforces capability checks and nonces consistently.
- Sensitive modules expose a clear recovery path.

## v2.1.8

Priority: form spam protection hardening.

- Add server-side captcha validation for Contact Form 7.
- Add stronger anti-spam heuristics for randomized contact-form payloads.
- Add duplicate submission blocking plus cooldowns per email and per IP.
- Add a global public form guard for suspicious user agents and repeated POST abuse.
- Extend comment form protection with honeypot, timing checks, and optional captcha.

Definition of done:

- Contact form submissions are validated before mail is sent.
- Replayed or repeated spam payloads are rejected consistently.
- Public form abuse is throttled across login, comment, and contact flows.

## v2.1.9

Priority: updater download reliability.

- Add a custom GitHub package downloader for Tool Kits updates.
- Prefer GitHub release asset API downloads when the exact `tool-kits.zip` asset is available.
- Validate HTTP status, downloaded file size, and ZIP signature before install.
- Improve failure messages for 404 HTML responses and non-ZIP downloads.

Definition of done:

- Updater does not pass HTML error pages to the plugin installer.
- Failed downloads produce actionable diagnostics instead of raw server HTML.
- Release metadata remains synchronized for the patch release.

## v2.2.0

Priority: structure and maintainability.

- Reduce direct bootstrap wiring in `tool-kits.php`.
- Introduce a consistent module registration/init pattern.
- Centralize option defaults and option migrations.
- Separate admin diagnostics from operational tools.

Definition of done:

- Module initialization is no longer managed as a long flat list in the plugin root file.
- Option defaults and migrations are versioned and centralized.
- New modules can be added without editing many unrelated files.

## v2.2.1

Priority: regression prevention.

- Add automated checks for updater package resolution.
- Add smoke checks for release ZIP contents.
- Add tests for serialized-safe replacement helpers where practical.
- Add targeted tests for hardening option normalization.
- Align license and heartbeat configuration status reporting across Access, Monitoring, and Diagnostics.
- Add explicit connection diagnostics and reachability checks before license activation.
- Hide invalid monitoring values instead of showing misleading placeholders.

Definition of done:

- Release packaging is validated automatically before publishing.
- Core helper behavior is covered by repeatable checks.
- Access and monitoring configuration diagnostics are consistent and support-friendly.

## Open items from earlier releases (as of 2.7.79)

Checked against the code; items not listed here were not re-verified.

- v2.2.0: module initialization is still a flat list in `tool-kits.php` (`$tk_modules`).
- v2.2.1: no tests yet for serialized-safe replacement helpers (DB Migrate).
- v2.2.1: no tests yet for hardening option normalization.

## v2.7.80

Priority: fixes from the runtime audit.

- Stop calling `date_default_timezone_set()`; the Server Time panel now sets the WordPress `timezone_string` instead.
- Apply recommended hardening defaults only once, so administrators can turn every hardening option off.
- Write the recommended hardening defaults with one `tk_options` update instead of sixteen.
- Allow the WP-Cron loopback (matching `doing_cron` token) through the public `wp-cron.php` block, so cron keeps running behind Cloudflare, CDNs and NAT.
- Protect prepared DB exports: random file name, `.htaccess` deny and `index.php` in `uploads/tool-kits-db-export/`, delete the file after download, prune leftovers older than a day.
- Mask passwords, client secrets, OAuth tokens and API/license keys in the Tool Kits audit log.
- Keep `toolkits_owner_user_id` in sync when the owner's user ID is changed.
- Failed-login auto-block now locks the IP for 24 hours instead of blocking it permanently, and ignores retries rejected by an active lockout.
- Firewall and captcha rate limits use `tk_get_ip()` instead of raw `REMOTE_ADDR`, so they work behind Cloudflare and trusted proxies.
- Form Guard skips server-to-server callbacks: payment gateway IPNs (`?wc-api=`) and the WP-Cron loopback.
- Cookie consent drives Google Consent Mode v2 (denied by default, updated from the stored choice in the browser, so it works on cached pages); the preferences button now appears and the modal works after a choice was made.
- The URL-shortener spam check matches whole host names, so `t.co` no longer matches microsoft.com, att.com or pinterest.com.
- The five-revision default only replaces WordPress's unlimited value; limits set by wp-config, post-type support or other plugins are kept. Heartbeat reports the limit source correctly.
- Page cache no longer purges on revisions, autosaves, auto-drafts, spam/held comments, or post meta written by visitors and cron (view counters); WooCommerce stock changes still purge.
- DB Migrate: serialized-safe replace (nested values, no class instantiation, corrupt values untouched); chunked table reads and streamed export; live replace keys on every primary-key column and skips unchanged rows; export/replace limited to the site's own tables (multisite aware); mysqldump imports no longer report "Query was empty"; uploaded SQL moved to the protected export dir and removed on shutdown; upload limits no longer apply to the DB import.
- DB Cleanup: ACF usage scan once per job; stats panel cached for 5 minutes.
- Front-end: indexability/GEO fixes stored in cached pages (also for `utm_*`/`gclid`/`fbclid`/`msclkid` visits); Image Optimizer memoizes per render; CSS minify keeps `.a :hover` and quoted strings; faster inline-JS minify (byte-identical output); WebP files named `foo.jpg.webp`/`foo.png.webp`; Instant Page prefetch works; lazy load leaves `data-loading` alone; fragment cache keys moved out of `tk_options`; pages are not cached when CSP nonce templating fails.
- Login and forms: dashboard comment replies skip the captcha; `Origin: null` falls back to the Referer check; the Login Log switch saves; OTP failures counted per user across resends; comment/CF7 forms use signed timestamps instead of a write per render; blocked-IP list capped at 5,000; non-Latin filter allows accented Latin and typographic punctuation; Role Management uses the Tool Kits access check; restricted roles can open their profile; settings saves write once; Hide Login fixes (wp-login globals and 200 status, `/admissions` no longer redirected, rewrite flush order).
- License and updates: license checks throttled when the host differs from the licensed URL; unexpected 4xx keeps the signed receipt until its original expiry; an expired receipt triggers revalidation; cached release used from cron; installs keep their folder name.
- Security and monitoring: firewall log throttled per IP; tamper baseline refreshed after Tool Kits updates and alerts sent once per change set; strict CIDR prefix parsing and binary IPv6 comparison; one alert per new administrator; IndexNow stops hourly retries on permanent 4xx; killswitch only rolls back `hardening_*` keys.
- Performance: SMTP transport/log, vulnerability report, IndexNow queue and tamper hashes moved out of `tk_options` (migrated on the next version change); dashboard chart uses grouped queries and cached cache stats; heartbeat cleanup only when something is scheduled; malware scanner keeps partial reports, rotates its start point and only skips top-level `wp-content` cache/upgrade/backup folders; theme checker walks once, prunes, caps and caches; duplicated posts keep backslashes in object meta.

- Updater: WordPress's twice-daily update cron may fetch the latest GitHub release (cached 1 hour, 5-minute failure cooldown), so background auto-updates find new versions.
- Theme checker skips Composer `vendor/` directories.

Decided not to change:

- No mobile page-cache variant.
- Manually blocked IPs do not expire.

Still open:

- Captcha challenge created at render for no-JavaScript visitors (one extra write per login page view).
- Making `tk_options` autoloaded (its size is not bounded yet).
- `tk_hidden_admin_submenus` and `tk_login_logs` are missing from the uninstall cleanup list (pre-existing).

Definition of done:

- PHP's default timezone stays UTC on every request.
- Turning all hardening options off persists across requests.
- Scheduled events run on proxied sites while public `wp-cron.php` requests without the token stay blocked.
- No database dump stays publicly reachable or on disk after it has been downloaded.
- No secret value appears in the audit log.
- An owner who changes their own user ID keeps access in owner-only mode.
- A shared IP recovers from an auto-block without admin action.
- Payment webhooks and WP-Cron are never rejected by Form Guard.
- After "Reject", GA4/GTM run with analytics and ad storage denied.
- A theme view counter or an open editor no longer empties the page cache.

## v2.8.0

Priority: test coverage for security modules that run on every request.

- Add regression tests for `security-hardening.php`: default application, option normalization, WP-Cron and XML-RPC request blocking.
- Add regression tests for the firewall: IP/CIDR allow and block lists, blocked user agents, allowlist precedence.
- Add regression tests for rate limiting and brute-force lockouts, including the honey trap and admin unlock.
- Add regression tests for serialized-safe search/replace in DB Migrate.
- Add an option to allow an external system cron (IP allowlist or secret token) when public `wp-cron.php` is blocked.

Definition of done:

- Each module above has a regression script registered in `scripts/regression-tests.cjs`.
- A site using `DISABLE_WP_CRON` with an external cron can run it without disabling the hardening rule.

## Later

- Replace the flat `$tk_modules` list with a module registration pattern (v2.2.0 goal).
- Measure output-buffer cost on uncached requests (logged-in users, cart/checkout) and merge HTML passes where it pays off.

## Release Checklist

1. Bump version in `tool-kits.php`.
2. Update `Stable tag` and changelog in `readme.txt`.
3. Build the release ZIP.
4. Validate the ZIP structure.
5. Publish Git tag and GitHub release.
6. Attach `tool-kits.zip`.
7. Test update flow from the previous public version.
