=== Tool Kits ===
Contributors: toolkits
Tags: security, migrate, database, cleanup, login
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 6.6
Stable tag: 2.7.64
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tool Kits is an admin toolkit plugin for:
- DB Migrate: SQL export, serialized-safe Find & Replace, and table prefix rename.
- DB Cleanup: Clean revisions, trash, spam, transients, safely unused ACF data, and optimize tables.
- Security: Hide Login, Captcha, Anti-spam Contact (CF7), login rate limiting, Login Log, and Hardening.
- Vulnerability Scanner: review outdated WordPress core, plugins, themes, inactive components, and security-sensitive configuration.
- Brute Force Protection: dedicated login protection workflow for rate limiting, progressive lockout, bad username blocking, origin guard, honey traps, and scanner traps.
- Incident Response: investigation and malware removal tracking, post-incident blocklist removal, and post-incident search engine security cleanup.

== Installation ==
1. Upload the `tool-kits` folder to `/wp-content/plugins/`.
2. Activate the plugin from the Plugins screen.
3. Open the "Tool Kits" and "Tool Kits Security" menus.

== Notes ==
- Version 2.7.62 makes I'm not a robot a single checkbox without a code input. Clicking automatically checks the browser-bound token, minimum time, honeypot and request limits on the server before returning a one-use proof. Classic Text continues to use an image code. Checkbox checks validate the request/session; they are not a guarantee of human identity. Clear page/CDN caches after updating.
- Version 2.7.61 strengthens CAPTCHA enforcement. Protected CF7 forms reject missing CAPTCHA fields, and checkbox mode opens an image-code challenge rather than approving a click alone. Codes have at least 6 characters, expire after 5 minutes and use visitor-bound, one-use tokens. Adjust minimum solve time and attempt limits under Spam Protection > Captcha. Clear page/CDN caches after installing; old cached CAPTCHA markup and click proofs are no longer valid.
- Version 2.7.60 adds a live Contact Form 7 cooldown timer and Submission Cooldown settings under Spam Protection > Timing & Velocity. Adjust email minutes, IP seconds and the duplicate window; use 0 to disable an individual rule or turn off Submission Cooldown to disable all three. IP Rate Limiting remains a separate setting.
- Version 2.7.58 adds Meta AI (WhatsApp, Instagram, Facebook, Messenger) to GEO crawler access, Crawler Preview, AI Radar and the AI Catalog, and warns when Tool Kits firewall user-agent rules block recommended AI crawlers. A fragment such as facebook blocks every Meta crawler. Run AI Access Review again after updating to add the Meta rows to saved results.
- Version 2.7.57 adds a GEO indicator column beside the SEO score in content lists (wp-admin/edit.php). It reads the latest saved GEO Audit & Fixes results and never audits pages while the list loads; run GEO Audit to refresh scores. Hide it under SEO Settings > Editor Panels.
- Version 2.7.56 moves the FAQ GEO popup into its own Tool Kits FAQ GEO editor panel and adds an optional SEO score column to content lists (wp-admin/edit.php). Both are enabled by default and can be hidden under SEO Settings > Editor Panels. Saved FAQs, metadata and public SEO/GEO output are unchanged.
- Version 2.7.55 changes the Global SEO Content checkbox to Enable global metadata override. When checked, Tool Kits replaces existing theme/plugin head metadata with the displayed values, including matching Open Graph/Twitter descriptions and social images, and removes duplicate tags. Saved page SEO titles, descriptions and social images retain priority. Uncheck to restore theme/plugin metadata; save changes and clear external page/CDN caches after updating.
- Version 2.7.54 completes SEO image output for Twitter Cards, Image Source (image_src) and Schema.org image metadata. These use the same selected image as Open Graph. Global metadata fallbacks fill missing or empty tags while preserving nonempty theme tags; page-specific image overrides replace stale head image tags. Enable social output under SEO Settings and configure a default image under Global SEO Content or a page-specific SEO Social Image, then clear external page/CDN caches.
- Version 2.7.53 adds conditional Contact Form 7 asset loading under Tool Kits > Assets. Start with manager-only Preview, add always-load pages for popup/widget/template forms, test submissions and captcha, then enable Live. Default loading is unchanged until configured. Purge external page/CDN caches after changing Live settings or resetting.
- Version 2.7.45 labels SEO Social Image beside the existing upload/media-library controls and recommends 1200 x 630 px (approximately 1.91:1), JPG/PNG and an ideal file size below 1 MB. These are recommendations, not new upload restrictions.
- Version 2.7.44 adds the Tagline variable label and custom Open Graph image uploads to the inline SEO Content editor. Unset images continue to use the Featured Image (thumbnail), with the site's social image as a fallback. The signed license API and matching key introduced in 2.7.31 remain required; see LICENSE_SECURITY.md.
- Version 2.7.34 is a GitHub pre-release patch for Collector recheck rate limiting. Fully authenticated heartbeat requests are excluded from public Form Guard checks; ordinary forms and unauthenticated requests retain their existing protection.
- Version 2.7.33 is a GitHub pre-release and adds authenticated on-demand heartbeat replies for NexaMonitor's Check Ulang action. Deploy the matching NexaMonitor update and use the same collector token on both sides.
- Version 2.7.32 is a GitHub pre-release and inherits signed license verification from 2.7.31. Deploy the matching NexaMonitor signing API and key before installing; see LICENSE_SECURITY.md.
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
= 2.7.64 =
- GEO Setup > Custom JSON-LD: new schema check in the style of validator.schema.org. It checks the setting draft as you type, or every JSON-LD block of a live URL, against a bundled schema.org vocabulary snapshot. Results are grouped by type with error, warning and item counts and per-property messages. Tool Kits tips (recommended properties, repeated @id, references without a definition) are counted separately so the counts stay comparable with validator.schema.org.
- GEO ItemList output no longer adds inLanguage to ItemList and ListItem. schema.org does not define that property for these types, and validator.schema.org reported one warning per item.
- SMTP: an expired or revoked Google/Microsoft OAuth token now shows "Reconnect required". Failed sends explain the cause and give provider-specific advice instead of a bare "Could not authenticate". Disconnecting Google also clears its stale username, and a blank password field keeps the saved password ("Remove saved password" clears it).
- Email Deliverability: DKIM is detected from the selectors your mail host actually uses (Hostinger, Google, Microsoft, Zoho, SendGrid, Brevo), and selectors without a public key no longer count as found. The DNS panel layout is tidied.
- SMTP test log is now a searchable, sortable and paginated table that scrolls horizontally, with compact technical details. Tool Kits admin pages are now left-aligned like core WordPress screens.

= 2.7.63 =
- I'm not a robot now requires a browser proof-of-work (SHA-256) before the server issues a proof. Normal visitors still only tick the box; the default difficulty finishes in well under a second.
- Adaptive difficulty: automation user agents, missing browser headers, scripted (untrusted) clicks, navigator.webdriver and repeated failures raise the work up to 64x, without ever showing an image. New settings: Proof-of-Work Difficulty and Adaptive Difficulty.
- Each form now only accepts the CAPTCHA type it is configured with (login, comments and per-tag CF7), so a Classic form can no longer be passed with the checkbox flow.
- The SVG fallback image (hosts without GD) no longer exposes repeatable per-character paths that could be read without OCR.
- Login protection now also covers Lost Password and Registration on wp-login.php and login forms built with wp_login_form(). Wrong passwords raise the next proof-of-work. Admin and WooCommerce password resets are unaffected.

= 2.7.62 =
- Remove the image puzzle and code input from I'm not a robot mode. A single click or keyboard activation starts automatic server verification and checks the widget only after receiving a valid opaque proof.
- Preserve browser binding, server timing, honeypot, rate limits, expiry, atomic token consumption and missing-CAPTCHA rejection in CF7. Unchecked, expired, repeated and cross-browser verification requests remain rejected.
- Keep Classic Text image verification and its code settings separate from checkbox mode. Prevent repeated clicks from overlapping requests and allow a click during initial loading to continue after the challenge is ready.

= 2.7.61 =
- Fix CF7 CAPTCHA rejection: invalidate a real named form tag instead of null, require CAPTCHA based on the server-side form configuration, and block mail even on unnamed CAPTCHA-only forms.
- Render verification codes as images instead of readable HTML characters. Checkbox verification requires the real image answer and returns an opaque, short-lived proof; the previous constant click marker is rejected.
- Bind challenges to a browser cookie and user agent, with a network-peer fallback before cookies are issued; enforce server-side minimum solve time, expiry and attempt limits. Atomic consumption prevents concurrent or sequential token replay.
- Refresh challenges per visitor to support cached pages, multiple forms, validation errors and resubmission. Retire old challenges when refreshing, handle keyboard verification, and keep failure feedback visible.
- Add adjustable solve-time and attempt limits, enforce a minimum six-character code, and exclude CAPTCHA internals from spam keyword scanning.

= 2.7.60 =
- Add email and IP cooldown duration controls plus a master Submission Cooldown switch. Zero disables an individual cooldown rule, including already active records.
- Show the longest remaining cooldown as a live MM:SS countdown on Contact Form 7 forms, including after a successful send, and prevent repeated submissions until the timer ends.
- Preserve elapsed time across retries, support cooldown flags saved by older versions, and discard previous rejection messages when processing a new submission.
- Keep independent anti-spam rules and IP Rate Limiting active when Submission Cooldown is disabled.

= 2.7.59 =
- Restore the Polylang language on self-referencing canonical and og:url tags. Themes that normalize URLs with home_url('/') and then home_url($path) drop the language because Polylang only filters the bare home URL, so /id/kontak/ declared /kontak/ (which redirects to the default language) and translated pages could be treated as duplicates.
- Add the language back with Polylang's own links model, covering directory URLs with or without the /language/ base, subdomains, multiple domains and ?lang= plain permalinks, for bilingual and multilingual sites with the default language hidden or prefixed.
- Rewrite a tag only when the corrected URL is the current request, including every query argument it carries. Deliberate cross-language canonicals, foreign hosts, the hidden default language, content-language mode, and tags in scripts, comments or the body are left unchanged. An explicit Indexability canonical still takes precedence, and the corrected HTML is what the page cache stores.

Validation: 61/61 default regression cases pass on PHP 7.4 and PHP 8.4, including the new seo-language-canonical case, which mirrors Polylang 3.8 URL modes for bilingual and multilingual setups. It was not exercised on a live WordPress install.

= 2.7.58 =
- Add Meta crawlers to AI Crawler Access using Meta's documented tokens: Meta-WebIndexer (Meta AI search and citations, allow), Meta-ExternalFetcher (user-requested fetches, allow), facebookexternalhit (WhatsApp and Facebook link previews, allow) and Meta-ExternalAgent (training, policy). They are included in robots.txt, AI Visibility and Crawler Preview checks.
- Send each crawler's documented user agent in Crawler Preview where one is defined.
- Add Meta AI to AI Radar and the AI Catalog, aggregated from Meta-WebIndexer and Meta-ExternalFetcher.
- Warn on the Firewall page when blocked user-agent fragments match AI crawlers recommended for search visibility, including fragments that only match the crawler URL such as facebook.
- Add a Tool Kits firewall check to AI Access Review. When the firewall is enabled, matching crawlers are reported as blocked and AI Radar reflects that status.
- Derive AI Catalog regression positions from the platform registry instead of fixed counts.

Validation: 60/60 default regression cases pass on PHP 7.4 and PHP 8.4, including new Meta crawler and firewall checks in the crawler fixes regression and updated AI Radar and AI Catalog coverage. A live AI Access Review on WordPress 7.0 confirmed the firewall check, blocked eligibility, Meta AI partial status in AI Radar, and Firewall page warnings.

= 2.7.57 =
- Add a GEO indicator column after the SEO score in content lists for the selected SEO / GEO content types. It shows the saved GEO Audit & Fixes score with grade and issue count, a ! flag when the audit could not verify the URL, a link to run GEO Audit for unaudited URLs, a not-applicable marker for drafts and password-protected content, and Off when GEO is disabled for the content.
- Read GEO results from the stored audit report only; list views never fetch public pages.
- Add the Show GEO indicator column in content lists checkbox to SEO Settings > Editor Panels, enabled by default. The SEO and GEO columns hide independently.
- Add a content-list regression covering both columns, the per-request SEO calculation limit, escaping and the GEO not-applicable states.

Validation: 60/60 default regression cases pass on PHP 7.4 and PHP 8.4, including 21 new content-list checks. Browser E2E on WordPress 7.0 (PHP 8.2) covers column order, a saved GEO audit score with link, tooltip and styling, draft indicators, independent column toggles and saving the settings form.

= 2.7.56 =
- Move the FAQ GEO popup out of the Tool Kits SEO / GEO sidebar into a separate Tool Kits FAQ GEO panel with its own nonce and save handler. Untouched FAQs are still never rewritten; a note explains when GEO is off for the content.
- Streamline the Tool Kits SEO / GEO panel: inline SEO and GEO switches, a colour-coded content score, and the existing public page audit.
- Add an SEO score column before Date in content lists for the selected SEO / GEO content types, with a dashed badge for the latest public page audit and an Off state when SEO is disabled for the content.
- Cache content scores per post, refresh them on save or content/keyword changes, limit uncached calculations to 25 per list load, and exclude the cache from page-cache purges.
- Add SEO Settings checkboxes for Show Tool Kits FAQ GEO and Show SEO score column in content lists. Both are enabled by default.

Validation: 59/59 default regression cases pass, plus SEO panel, locale/panel-visibility, auto-signal, content-modal, output, global SEO, title, indexability and schema-modal regressions. Browser E2E on WordPress 7.0 (classic editor, PHP 8.2) covers both panels, the FAQ popup save/reload, list column placement and styling, the column toggle and the settings checkboxes.

= 2.7.55 =
- Make the checked Global SEO Content option replace existing metadata instead of only filling empty tags. Rename it Enable global metadata override and update help and SEO-plugin notices to match.
- Apply configured descriptions, authors, keywords and social images ahead of automatic excerpts, post authors and Featured Images. Keep saved page SEO title/description/image overrides first and limit the global title to the homepage; preserve the separate Meta Title Override format when enabled.
- Replace matching Open Graph, Twitter, Image Source and Schema.org image tags, remove duplicates, and wrap late theme/plugin HTML buffers. Preserve visible content, canonical/robots tags and JSON-LD.
- Keep editor previews aligned with public output and restore theme/plugin output when global override is unchecked. Retain license, post opt-out, request, content-type and HTTP-status guards.
- Center .tk-wrap with margin: 0 auto and apply padding: 32px 0 32px.

Validation: 59/59 default regression cases pass on PHP 7.4 and PHP 8.4, including 98 Global SEO checks reproducing the reported Rafida description mismatch and 67 image checks. Separate saved-content/public-output (56 checks), editor panel and title regressions pass on both runtimes.

= 2.7.54 =
- Output Twitter Cards (summary_large_image and twitter:image), Image Source (link rel=image_src), and Schema.org image metadata (meta itemprop=image) alongside the Open Graph image.
- Share the selected per-page SEO image, Featured Image, global default or site logo across image formats. Native Twitter output includes the page title and description.
- Complete missing/empty image tags in Global SEO Content fallback mode, preserving nonempty theme metadata. Explicit page overrides replace stale image tags and remove duplicates without changing page content or JSON-LD.
- Clarify the social-output setting and default-image help text. Respect the existing social-output switch and public-output guards.

Validation: 59/59 default regression cases pass on PHP 7.4 and PHP 8.4, including 63 image metadata checks. The separate saved-content/public-output regression passes 55 checks on both runtimes.

= 2.7.53 =
Conditional Contact Form 7 asset loading and a consistent, responsive Tool Kits admin interface.

- Add conditional Contact Form 7 CSS/JS loading with Default, manager-only Preview, and Live modes; detect shortcodes, native CF7 blocks and reusable blocks in queried content, and configure exact paths/page IDs that always load assets.
- Preserve shared dependencies, CF7 initialization and assets required by other queued scripts/styles. Keep preview responses uncached and provide a reset to default loading without changing forms, submissions, anti-spam or SMTP settings.
- Unify Tool Kits admin headers, cards, controls, tab navigation, tables and editor dialogs, including Overview, Diagnostics and Health Center.
- Group Asset Optimization and CF7 settings, collapse legacy grids on mobile, and improve captcha choices and responsive layouts across security, SMTP, database, SEO/GEO and system screens.
- Add named keyboard-accessible table scroll regions, including dynamically loaded reports, while preserving form values and existing controls. Scope layout enhancements to Tool Kits admin screens.
- Validate conditional CF7 loading and 12 rendered admin views across five desktop/mobile widths with isolated WordPress/CF7 fixtures.

Validation: 58/58 default regression cases and 62 CF7 checks pass on PHP 7.4 and PHP 8.4. Browser layout checks cover 12 admin views at 1440, 1024, 782, 390 and 320 pixels. ZIP structure and checksums are verified separately during release.

After updating, open Tool Kits > Assets and start CF7 loading changes in Preview. Add always-load entries for popup, widget or template forms, test captcha and submission, then enable Live and clear external page/CDN caches. Existing installations retain default CF7 loading until configured.

= 2.7.52 =
- Retain compact summaries of the latest 10 completed Health Center full scans, including scores, coverage, check statuses, and measured performance metrics with cache context.
- Compare any earlier/later retained scans to identify newly detected, resolved, worsening, improving, and unverified findings without starting another scan.
- Preserve full-scan history during individual rechecks. Unknown/removed checks do not count as resolved; changed or unrecorded diagnostic policies produce neutral status comparisons.
- Bound history storage, exclude raw HTML/SQL/headers and scan tokens, and keep history access authenticated and read-only with mobile table scrolling.

= 2.7.51 =
- Capture Current, Expected, Source, and measurement time for Health Center results. Legacy reports can capture this evidence with Recheck.
- Recheck individual saved checks through bounded, authenticated scan stages. Preserve unrelated results and the last full-scan timestamp; unavailable measurements remain Not measured.
- Configure website-specific warning/critical thresholds for TTFB, database queries, and PHP memory. Treat optional persistent object cache as a recommendation excluded from scores unless explicitly required.
- Keep diagnostic policy separate from PHP, cache, and security configuration, and request fresh measurements after policy changes. Improve mobile table scrolling and keyboard access.

= 2.7.50 =
- Show colored Passed, Warning, Critical, and Not measured badges in Health Center Check Details.
- Link warning and critical checks to relevant Tool Kits Hardening, Page Cache, Global SEO Content, Canonical, and Indexing settings. Links respect the current license and administrator access.
- Keep hosting-only findings informational and leave saved scan results unchanged when opening settings.

= 2.7.49 =
- Show expected normal page-cache mode and its active Multisite, HTTP authentication or PHP firewall requirements in Cache Status instead of repeating a warning banner.
- Preserve security-hook compatibility checks and actionable early-cache setup/cleanup warnings.

= 2.7.48 =
- Show the saved Google Tag Manager configuration status and matching container ID in the GTM panel and Tool Kits header.
- Distinguish configured, missing snippets, mismatched container IDs and unrecognised snippets without claiming live tag firing has been verified.

= 2.7.47 =
- Add separate Head and Body code editors for Google Tag Manager in the Overview page, using WordPress CodeMirror with a plain textarea fallback.
- Print saved snippets early in the page head and at the opening body hook, once per location, with CSP nonce support.
- Require unfiltered_html permission to save or import tracking scripts and preserve Google Analytics settings when saving GTM.

= 2.7.46 =
- Keep the SEO Social Image preview in sync with the current image load state, including cached images and late events from previous requests.
- Add Retry image and Open image controls when the preview fails, preserving the selected image and its original URL.
- Load preview images eagerly without sending the editor page as a referrer.

= 2.7.45 =
* Add an explicit SEO Social Image label beside the existing Upload / choose image and Use thumbnail controls.
* Recommend 1200 x 630 px (approximately 1.91:1), JPG or PNG, and an ideal file size below 1 MB. Keep important text and logos near the centre to allow for cropping.
* Associate the image controls with their label and guidance for assistive technology. Preserve uploads, image selection, thumbnail fallback and the native Save/Update workflow.

= 2.7.44 =
* Show the WordPress Tagline as Tagline in the SEO variable menu and chips, preserving the existing %%sitedesc%% token and saved templates.
* Upload or choose a page-specific Open Graph image through the WordPress Media Library. Save it with the page's Save/Update button without changing the Featured Image.
* Keep the Featured Image (thumbnail) as the default, with the site's social image as a fallback. Use thumbnail clears the custom selection and follows current thumbnail edits in Gutenberg and Classic Editor.
* Apply saved custom images to public Open Graph metadata, including image-only edits and theme-managed sites. Validate image attachments and save permissions; fall back when the custom attachment is deleted.

= 2.7.43 =
* Add Copy to locale to SEO Content when Polylang is active. Select a target locale from linked, editable translations, including sites with more than two languages. Copy current SEO titles, meta descriptions and focus keyphrases; preserve existing target values unless replacement is selected.
* Preserve dynamic variable templates and automatic defaults. Validate the request nonce, language relationship and edit permissions; copy no slugs, core content or audit data.
* Add separate SEO Settings checkboxes for showing Tool Kits SEO Content and Tool Kits SEO / GEO in the editor. Both are enabled by default; hiding panels preserves saved metadata and public output.
* Keep public metadata audits accurate on older libxml/PHP 7.4 installations when the page head contains non-rendered HTML5 examples.

= 2.7.42 =
* Apply explicitly saved page SEO titles and meta descriptions to public HTML even when the theme, another SEO plugin or the global title format also supplies metadata. Requires SEO Toolkit and the page's SEO switch to be enabled.
* Keep one title and meta description, resolve variables, align existing social text and preserve full descriptions. Leave body content, canonical, robots and schema unchanged.
* Compare saved values with fetched public metadata in Audit Public Page. Show matches, duplicates, cached-value mismatches and stale audit results with the actual title and description.
* Search previews remain illustrative: Google can select different title links and snippets after crawling the page.

= 2.7.41 =
* Show Too short, Ideal and Too long guidance for resolved SEO title and meta description lengths. Preserve complete text without enforcing a character limit.
* Add inline Facebook and LinkedIn Open Graph previews that follow live title, description, variables and URL edits.
* Use the edited page's featured image, theme-managed social image or site fallback. Follow featured-image changes in both Gutenberg and Classic Editor, with loading and empty-image states.
* Keep preview network controls usable without JavaScript, preserve the native Save/Update workflow and retain existing SEO output conflict protection.

= 2.7.40 =
* Stop cutting meta descriptions at 160 characters in editor defaults, excerpt variables and generated frontend metadata.
* Preserve complete words and Unicode text throughout the description field, live preview, native save and public output. Keep length indicators as guidance only.

= 2.7.39 =
* Rename the standalone page-editor panel to Tool Kits SEO Content.
* Remove the Edit SEO Content popup and its triggers from the page editor, dashboard and Content Audit. Stop enqueuing the retired popup script.
* Open the native WordPress editor at the SEO content panel from content audit results, retaining the inline SEO fields, preview and analysis.

= 2.7.38 =
* Fix the page-editor fatal error on PHP 8.1+ by emitting the homepage slug's readonly attribute without calling the unavailable readonly() helper.
* Render SEO variable chips in server HTML so templates do not appear as raw percent placeholders while editor scripts are delayed or blocked.
* Reuse the rendered chips for editing, preserve raw templates for native saves, and keep a plain-text fallback when JavaScript is disabled.
* Remove the obsolete helper stub from regression fixtures and verify both editable page slugs and readonly homepage slugs.

= 2.7.37 =
* Add editable variable pills to SEO title and meta description, with Insert variable menus, percent-key completion, keyboard navigation and removable tokens.
* Resolve title, page, separator, site title, primary category, excerpt and site description variables in live previews and frontend SEO output. Keep templates dynamic when saving.
* Update search preview text and length indicators immediately while editing. Preserve plain text fields when JavaScript is unavailable.
* Reuse configured theme favicons, WordPress Site Icons, existing theme favicon files or the page's favicon, with a site-initial fallback for missing or broken images.

= 2.7.36 =
* Make Mobile/Desktop search previews use native radio controls, with distinct layouts that switch even when JavaScript is unavailable.
* Populate SEO title and meta description as real input values, resolving page excerpts, content and theme/site defaults for the edited post.
* Use generic English placeholders. Preserve automatic defaults when unchanged and save explicit edits without freezing inherited values.
* Keep preview controls independent of the WordPress block editor data store and support Classic Editor when that store is unavailable.

= 2.7.35 =
* Separate Edit SEO Content from the SEO/GEO sidebar into a full-width, low-priority panel below the page editor.
* Add collapsible focus keyphrase, search appearance and SEO analysis sections with live mobile/desktop previews, SEO title, slug and meta description fields.
* Save search settings through the native WordPress Save/Update action, preserve native slug edits and duplicate resolution, and retain existing SEO output conflict protection.

= 2.7.34 =
* Prevent authenticated Collector heartbeat requests from being counted as public form submissions before REST_REQUEST is defined during WordPress init.
* Keep signature, timestamp, website, installation, replay and concurrency validation. Report the remaining heartbeat cooldown with HTTP 429 responses.

= 2.7.33 =
* Add authenticated on-demand heartbeat replies for NexaMonitor Check Ulang. Fresh, verified plugin data reconnects the Collector and updates PHP, WordPress, plugin versions and monitoring summaries.
* Bind requests and responses to the website, installation, timestamp and a random challenge. Reject replayed or concurrent requests and apply a short cooldown.
* Reuse the daily/manual heartbeat payload without adding cron jobs, callback requests or automatic security/SEO scans. Requires the matching NexaMonitor Collector update, HTTPS and matching collector tokens.

= 2.7.32 =
**Automatic SEO/GEO and Lightweight Health Center**
- Move the SEO/GEO editor panel to the sidebar. Detect schema types, references and related content automatically, and remove manual per-post and global schema mappings without deleting legacy data.
- Refine the Health Center widget with clear scores, compact status cards, collapsible metrics, and up to three priority actions linked to saved check details.
- Add a local PHP2Shell version assessment to manual Health Center and Vulnerability scans. Vendor/prerelease builds require verification; CLI and unknown branches cannot establish a patched web runtime.
- Mark saved reports for a new manual scan after 24 hours or tracked configuration changes. Show four-stage progress with distinct success and error states and bounded requests.
- Reuse the saved web PHP assessment in the existing NexaMonitor heartbeat. No new cron, automatic scans, file crawling or network requests are added.
- Pre-release: requires the signed license API and matching public-key deployment introduced in 2.7.31. This release does not update PHP or deploy the NexaMonitor server.

= 2.7.31 =
* Require server-issued RSA-SHA256 license receipts bound to the site, installation, license key, product and expiry; reject unsigned activation and legacy local HMAC credentials.
* Enforce signed expiry across feature gates and early cache, add independent license refresh, bounded outage tolerance, fresh activation challenges and strict HTTPS license transport.
* Add issuer provisioning and migration documentation in LICENSE_SECURITY.md. Deploy the matching NexaMonitor signing server before updating the plugin.

= 2.7.30 =
* Use English throughout the WordPress Health Center widget and manual scan page, including metrics, empty states, progress and error messages.
* Improve the existing HTML Injection Scanner with contextual CSS/event-handler analysis and whole-token-stream verification of approved Google Analytics/Tag Manager loaders.
* Separate exact-host recognized providers from custom destination trust; arbitrary provider paths, modified loaders, sensitive-data transports and deceptive hosts remain reviewable/detectable.
* Add risk-based REPORT/REVIEW/SKIP classification, suppression audit metadata, stable finding groups, affected pages/occurrence counts, seven report filters, and administrator restoration of suppressed groups.
* Inspect discovered local CSS in bounded batches, reuse resource analyses, flag external imports/exfiltration/executable CSS, and retain first-seen CSS hashes for integrity-change review. No site content is modified.
* Preserve legacy reports without reclassifying them as safe; new custom host entries require exact names instead of wildcards. Expand PHP 7.4/8.4 and browser security regression coverage.

= 2.7.29 =
* Add a WordPress Health Center dashboard widget and dedicated manual scan page with saved results; opening either view never starts a scan.
* Measure public homepage TTFB, profile uncached homepage database queries and slow WP HTTP requests, inspect response headers/SEO, and snapshot server/declared compatibility requirements.
* Separate overdue WP-Cron events from retained Action Scheduler failures; show unavailable measurements explicitly, with transparent category scoring and scan timestamps.
* Protect scans with administrator permissions, Tool Kits access/license checks, nonces, owner-bound expiring sessions and locks; keep scan metadata from purging page cache.

= 2.7.28 =
**HTML Injection Scanner**
- Add a read-only HTML Injection Scanner under Security, with a shortcut from Hardening. Scan post titles/content/excerpts, post meta, selected widget/theme/ACF options, and same-origin public HTML.
- Report executable URLs, inline event handlers, hidden iframes/links, active data URLs, unreviewed external resources/forms, redirects, encoded script execution and suspicious cookie/outbound patterns. Scripts stored in database content are listed for review, even when they carry a CSP nonce.
- Add trusted external hosts, source IDs/fields/line numbers, escaped evidence and edit-source links. Findings are review indicators, not proof of infection; external scripts and browser JavaScript are not executed by the scanner.
- Process bounded AJAX batches with administrator/capability/license/nonce checks, owner-scoped sessions, resume/stop, timeouts, incomplete-coverage reporting and report clearing. Do not change site content or flush frontend page caches when saving scanner progress.
- Parse JSON and supported serialized scalar/array content without calling unserialize or instantiating objects. Keep security-sensitive keys outside the selected content scan.

= 2.7.27 =
**Browser Cache, CSP Nonces and Early Page Cache**
- Expire HTML, JSON and XML immediately in Apache/LiteSpeed browser-cache rules while retaining one-year static asset caching. The existing init/hash check refreshes the managed .htaccess block after updates; nginx's one-year rule remains limited to assets.
- Store nonce-free page HTML with security-header metadata. Rebuild CSP and script/style nonces per request for normal and early hits; expose tk_page_cache_csp_nonce for theme generators. Never store Set-Cookie responses or replay Set-Cookie headers.
- Install an owned advanced-cache.php drop-in and a bounded, signed-license-derived config snapshot, with identical scheme/host/URI keys and request exclusions. Early hits skip WordPress bootstrap and emit X-Tool-Kits-Cache: HIT-EARLY.
- Preserve foreign drop-ins and user-owned WP_CACHE definitions; show setup notices when files cannot be updated. Remove owned config/drop-in/WP_CACHE on disable, inactive licensing and deactivation. Keep normal caching for multisite, HTTP authentication and the PHP firewall.
- Bind HTML and metadata with hashes and purge generations, delete metadata on purge, and keep browser HTML max-age=0 independently of server TTL. Existing nonce-bearing or metadata-free cache files regenerate on demand.

= 2.7.26 =
**Meta Title Override**
- Add an opt-in title override in SEO > Global SEO Content with a customizable separator (default: -).
- Use WordPress Site Title + separator + Tagline only on the front page, including static front pages. Use Page Title + separator + Site Title elsewhere, with archive, search, 404 and pagination support.
- Override theme and SEO plugin document titles, including hardcoded output, and keep one real head title. Preserve other metadata, social titles, schema and body markup.
- Require Enable SEO Toolkit; the title switch works independently of global metadata fallbacks and takes priority over Homepage SEO title. Saving purges Tool Kits page caches.

= 2.7.25 =
**General Performance and Multisite Cache Audit**
- Preserve 2.7.24 option-cache helpers and integration filters; invalidate parsed configuration before dynamic option callbacks and reuse it in remaining internal readers. License signatures and site matching remain active.
- Isolate page cache and deferred purges per blog, coalesce external purges, cover taxonomy relationships, and allow derived metadata/config exclusions.
- Add bounded file locks, a 30-second stale refresh window, atomic publication and purge generation checks; never cache flushed partial responses, cookies or non-HTML response types.
- Preserve explicit theme/plugin asset versions. Use file timestamps for unversioned local theme assets without hashing or transient writes; expose fallback and image-priority settings.
- Combine image dimension/priority processing, skip file reads for complete dimensions, preserve explicit loading/priority/decoding and responsive attributes, and add HTML-optimizer integration filters.
- Scope the image workspace script to Images, avoid a lazy-media bootstrap when unused, prime attachment report caches and move log geolocation misses off the login/event write path.
- Bound existing security-event retention to 1,000-row batches with scheduled continuation; keep table-presence memoization separate by multisite table.
- Keep upload optimization and existing image delivery settings. No data migration, new indexes or production deployment. See PERFORMANCE_AUDIT.md for measurements, limitations and rollback.

= 2.7.24 =
**Frontend Rendering Compatibility**
- Reuse parsed Tool Kits options during a request; invalidate on option add/update/delete and keep multisite caches separate. Signature and site checks remain active.
- Add tk_page_cache_purge_for_meta_change so integrations can exclude derived caches and analytics counters while preserving invalidation for content changes.
- Add tk_assets_version_theme_assets so themes with release-versioned URLs can skip per-file hashing and transient queries.
- Respect explicit lazy image loading instead of promoting the first content images to eager loading.

= 2.7.23 =
**Duplicate Schema Shortcuts**
- Make the Duplicate Schema count open the URL report and Auto Fix controls in GEO Audit.
- Add a direct link to schema output settings and explain which Tool Kits generators Auto Fix disables.
- Disable Auto Fix when the saved entity report has no matching enabled Tool Kits SEO/Breadcrumb output, and submit fixes through a separate nonce-protected form.

= 2.7.22 =
**Editable Generated Catalog**
- Populate Custom catalog entries (JSON) with generated website and AI Radar entries, selecting manual mode so saved edits are published.
- Regenerate from current website data even when a custom catalog is active, and label the save action Save & Enable Catalog.

= 2.7.21 =
**Website and AI Radar Catalog Generation**
- Generate the current website profile and public content together with the AI platforms from the same registry used by AI Radar.
- Include saved per-platform crawler access eligibility for the current website, using unknown when no matching report exists. Do not claim indexing, citations or product pricing.
- Add Generate Website + AI Radar to enable automatic output while preserving custom entries for manual mode.

**ARD Discovery and JSON Routing**
- Serve an ARD manifest at /.well-known/ard.json and /.well-known/ai-catalog.json before routing, 404 redirects and HTML output buffers.
- Advertise both discovery URLs through HTML links and HTTP Link headers, preserving existing Link values.
- Keep the requested version/last_updated/catalog format at /ai-catalog.json. The separate ARD manifest declares the site name and no callable resources, since this generator does not implement agents or tools.

**Editable AI Catalog Format**
- Generate version 1.0.0 documents with last_updated and catalog, including id, name, provider, category, description, website, features, pricing, tags and status for every entry.
- Add a custom JSON catalog editor, full-document import, saved preview, and the supplied ChatGPT/Claude example alongside automatic website content.
- Validate entries before saving and update catalog dates when data changes.

**Indexing Timeout Recovery**
- Increase the page request timeout from 5 to 20 seconds within a shared 25-second live-check budget; reuse the source response for self-canonical fixes.
- Explain why Fix All is unavailable and add Retry Unverified for read-only rechecks after timeouts or incomplete verification.
- Keep unverified requests from being treated as resolved findings or edited without evidence, and hide the Stop button while no bulk operation is running.

**Grouped Indexing Results**
- Display indexing results in one table with checks grouped by URL, a shared header, and page actions alongside each URL.
- Preserve individual fixes, canonical selections, Fix All and live rechecks in the grouped table.

= 2.7.20 =
**Indexing Fix All**
- Add Fix All for editable indexing findings with sequential requests, progress, a stop control, and live verification after each change.
- Preserve preferred canonical inputs during result updates and require an explicit destination for conflicting canonicals.
- Add an option to include disabling Tool Kits redirects; keep source-managed issues available for manual review.
- Continue after per-URL failures and report saved changes, verified fixes, remaining findings and pending verifications separately.

= 2.7.19 =
**Indexing Checks and Fixes**
- Automatically discover and scan the current website's homepage, published public content, and active Tool Kits redirect sources in batches, without manual URL entry.
- Add per-URL live checks for noindex meta/headers, canonical declarations, Googlebot robots.txt access, and HTTP redirects in SEO > Indexing.
- Provide reviewed, removable public-page noindex/canonical overrides, exact virtual robots.txt allow rules, and disabling of matching Tool Kits redirects.
- Recheck after fixes, show source-level guidance for server/CDN/physical-file issues, preserve intentional exclusions, and distinguish live signals from Google's indexing classifications.

**AI Catalog Generator**
- Add an ai-catalog.json tab to GEO Setup with generate, view, and download controls.
- Serve a live JSON catalog of site details and public content, excluding drafts, private posts, attachments, and password-protected content.
- Support WordPress home URLs in subfolders and keep catalog output behind its opt-in setting and the license gate.

= 2.7.18 =
**Simpler SEO Checks and Scores**
- Simplify SEO Checks & Optimization into short rows with friendly labels, concise summaries, and expandable findings and recommendations.
- Show the existing overall Content Audit score with a progress bar in the validation modal and a score beside the audit checklist title.
- Add individual scores for checks supported by stored content evidence; use Pending for unverified live checks and N/A for checks that do not apply.
- Keep the existing overall audit calculation and warning penalties; explain scoring in a collapsed help section and direct Improve buttons to the relevant inputs.
- Ask users to rerun Content Audit for individual scores in legacy saved reports. Verify scoring, details, tabs, draft preservation, field focus, close controls and mobile layout.

= 2.7.17 =
**Global SEO Content and Linked Theme Defaults**
- Add a Global SEO Content tab for homepage title, default description, author, publisher, keywords, social image and service details.
- Display supported Theme Settings and Customizer values as linked, read-only fields with source links; read current values without copying them into Tool Kits settings.
- Provide editable fallbacks for fields the theme does not supply, reuse service details in GEO, and preserve page excerpts, authors and featured images.
- Add opt-in global metadata completion that fills only missing tags in the final HTML without replacing existing theme metadata or JSON-LD. Keep third-party SEO plugin ownership and public-page guards.
- Cover inherited settings, secure saving, source changes, page priority, duplicate prevention and frontend output in regression checks; verify tabs and forms at desktop/mobile sizes.

= 2.7.16 =
**Fresh Crawler Metadata Verification**
- Make both Preview Crawler Fetch and Scan Again replace saved reports with newly fetched responses instead of reusing old missing metadata findings.
- Rescan live crawler responses after saving or removing a missing metadata fallback; report actual output rather than treating saved form values as verified metadata.
- Request cache revalidation during crawler scans, display the last scan time, and mark legacy saved reports as requiring refresh.
- Add regression coverage for stale author/publisher reports, both scan buttons, per-crawler differences, fallback save/removal verification and upstream responses that remain incomplete.

= 2.7.15 =
**Complete Missing Metadata Form**
- Show all saved crawler findings and affected crawler names in Fix Missing Metadata, including fetch failures and invalid JSON-LD that need source review.
- Offer inputs for missing title, description, author and publisher, with canonical and generated JSON-LD details when absent.
- Save reviewed author/publisher values and use them in fallback metadata and JSON-LD; preserve stored values when submitting a partial form.
- Explain saved scan results and verification through Scan Again; allow removing a fallback without completing required fields.

= 2.7.14 =
**Admin Asset Cache Refresh**
- Preserve JavaScript and CSS version parameters in WordPress admin through both hardening filters, so plugin updates load current assets instead of stale schema-editor code.
- Keep existing public version-removal behavior.
- Add regression coverage for distinct admin cache URLs after updates, both version filters, and FAQ questions with unique IDs and people without IDs.

= 2.7.13 =
**Schema Entity Review and Type Inventory**
- Stop treating repeated schema types as duplicate warnings; FAQ questions/answers, list items, and distinct images, places, addresses, or people remain normal inventory counts.
- Mark repeated @id definitions and identical top-level nodes for source review, while excluding references and shared anonymous nested data.
- Use the same entity checks in live validation, schema reports, and the duplicate detector; keep type inventory badges neutral.
- Refresh legacy type-count reports before automatic fix decisions and prompt users to rerun older saved reports.
- Add regression checks for the reported counts, distinct identities, canonical key ordering, shared nested data, references, and red marking.

= 2.7.12 =
**Content SEO Validation Modal**
- Add Validate & Edit to each editable Content SEO Audit entry, opening the selected page in a responsive modal.
- Show current score, priority, word/link counts, issues, and all SEO checks & optimization findings with status badges and recommended actions.
- Validate saved content without writing changes or discarding unsaved inputs, using the audit rules and bounded cross-page duplicate comparison.
- Separate SEO checks and content improvements into accessible tabs, with field shortcuts, close controls, and Save & Refresh Audit.
- Add response guards, timeout/retry handling, and regression checks for validation consistency, access restrictions, drafts, and modal navigation.

= 2.7.11 =
**Schema Modal Response and Tab Fixes**
- Handle missing or incomplete live JSON-LD responses without JavaScript errors and keep Retry Live Check available.
- Give live inspection a dedicated AJAX action and uncached requests, with a fresh script URL for updated installations.
- Isolate the schema dialog from the GEO settings form and its tabs; stabilize modal height when switching panels.
- Add regression checks for incomplete responses, tab navigation, dedicated live routing, and access restrictions.

= 2.7.10 =
**SEO/GEO Modal Improvements**
- Organize schema validation, JSON editing, and missing schema inputs into accessible tabs, with responsive layouts and persistent modal headers and footers.
- Highlight repeated schema nodes and JSON lines in red; add draft node removal, undo, missing-property completion, and verified WebSite, Organization, and ImageObject inputs.
- Load custom JSON-LD independently from live inspection; add request timeouts, retry, session/server error handling, and cancellation when closing a loading dialog.
- Add cross close buttons to SEO/GEO modals and align FAQ GEO styling.
- Move the SEO/GEO panel to the main editor area, finish English audit labels, and correct the page selector used by the content modal.

= 2.7.9 =
**Content SEO and Schema Editors**
- Add Content SEO Audit Fix All for missing excerpts and attachment-based image alt restoration, with progress and refreshed audit results.
- Add a dashboard and page-editor modal for additional content, internal links, excerpts, focus keywords, client context, and project outcomes.
- Move Target Location from the sidebar into the content modal and use English text in the FAQ GEO popup.
- Add Validate & Edit to Duplicate Schema reports with live JSON-LD inspection, identity checks, and site-wide Tool Kits custom JSON-LD editing.
- Guard modal saves with permissions, nonces, input validation, and stale-edit detection.

= 2.7.8 =
**Dashboard SEO/GEO Scores and Emoji Fix**
- Add a WordPress dashboard widget showing the latest saved SEO and GEO audit scores, checked pages, audit timestamps, partial audit status, and links to audit details.
- Keep dashboard requests lightweight by reading saved reports without starting scans.
- Preserve the emoji SVG URL during fingerprint reduction so Twemoji flags use the correct assets/svg/ path.

= 2.7.7 =
**PHP 7.4 Compatibility**
- Support activation and operation on PHP 7.4 by replacing the remaining PHP 8-only syntax.
- Declare and enforce PHP 7.4 as the minimum version with a clear admin warning on older servers.

= 2.7.6 =
**GEO Audit Fix Verification**
- Replace stale Applied status with an immediate live re-scan after a database fix, updating issues, score, grade, and report totals from the rendered result.
- Add Bulk Fix All to apply every safe automatic GEO fix across the current report, purge caches once, and re-scan every affected URL.
- Treat LocalBusiness as a valid organization entity and require only universal entity identity fields instead of penalizing optional Organization properties.
- Add supplementary schema fixes when valid JSON-LD already exists but required WebSite, WebPage, entity, or freshness nodes are missing.
- Verify automatic fixes against rendered frontend HTML; preserve existing schema and prevent duplicate supplementary graphs on cached or repeated output.
- Run Bulk Fix All as a resumable one-URL-per-step AJAX queue with progress, frontend verification, external-cache guidance, a capped audit history, and per-URL or global rollback controls.
- Generate Article or Product fallback nodes for matching post types while accepting equivalent page-level schema types during validation.

= 2.7.5 =
**Crawler and AI Visibility Fixes**
- Add AI Search Radar with per-URL Entity Analysis, Content Readiness, E-E-A-T Signals, Tech Foundation scores, and prioritized strategic recommendations.
- Add AI Radar with a site-wide readiness score and eligibility summaries for Google AI, ChatGPT Search, Claude, Perplexity, and Common Crawl.
- Show a specific recommended solution beneath every issue in the GEO Audit results table, including previously saved reports.
- Add priority, score penalty, Needs Review labels, and one-click database-backed fixes for safe GEO Audit findings; persist an audit trail and output connected Organization, WebSite, and WebPage schema.
- Split GEO into dedicated GEO Setup and GEO Audit & Fixes admin menus, with scan and fix actions returning to the audit workspace.
- Detect missing title, meta description, author, publisher, canonical URL, H1, and JSON-LD in Crawler Preview and AI Visibility reports; add a missing fallback H1 to HTML source with the hidden attribute so it is not visually displayed.
- Output author and publisher meta tags from the post author and configured organization/site identity, and include them in reviewed per-URL fixes when absent.
- Add reviewed per-URL fallback fixes that fill only missing metadata without replacing existing theme or SEO-plugin output.
- Add Scan Again and Remove URL Fix controls, cache invalidation, same-site URL validation, nonce and capability checks, and protection for private, preview, password-protected, and non-HTML responses.
- Stop spoofing crawler identities from the site server, which caused false 502/403 results when CDNs verified crawler IP ranges; report eligibility from robots.txt and normal page fetchability instead.
- Apply the same eligibility model to AI Crawler Accessibility Review, remove contradictory per-agent fetch issues, and make Run AI Access Review replace stale saved evidence.
- Guard the deferred Google Analytics loader globally and remove interaction listeners and fallback timers after the first load, preventing duplicate initialization during partial or repeated head renders.
- Remove repeated frontend bootstrap work by versioning database-table installation, skipping completed option upgrades, and applying root server rules only when their generated configuration changes.
- Normalize malformed jsDelivr Twemoji base URLs from `/assets/` to `/assets/svg/`, while preserving valid or disabled emoji providers.
- Show targeted recommendations for robots meta, X-Robots-Tag, robots.txt, verified-bot/CDN checks, HTTP failures, and invalid existing JSON-LD.
- Add regression coverage for escaping, duplicate prevention, idempotence, authorization, URL scope, removal, response guards, and fresh scans.

= 2.7.4 =
**CSP Inline Compatibility Fix**
- Fix inline scripts and styles being blocked immediately after activation by removing conflicting CSP nonces from Lite and Balanced policies.
- Keep inline styles compatible in Hardened mode while retaining nonce-based script protection; preserve Strict mode nonce requirements.
- Generate CSP nonces only for Hardened and Strict modes.
- Add regression coverage for all four CSP modes and disabled security headers.
- Clear page/CDN caches after updating to remove cached CSP headers and markup.

= 2.7.3 =
**FAQ Locale Controls and GEO Overview Polish**
- Add Remove Locale controls to the GEO FAQPage editor and per-post FAQ dialog, with confirmation and default-locale protection.
- Order existing FAQ locale tabs using the configured Polylang or WPML bilingual language order while keeping manual locales afterward.
- Keep unconfigured bilingual locales hidden until they are added explicitly.
- Rebuild GEO overview metrics as a consistent responsive status grid with clearer success, warning, error, and inactive states.

= 2.7.2 =
**GEO FAQ Import and Reporting Improvements**
- Use the WordPress default locale as the initial FAQ language and add other locales only when requested.
- Add target-locale selection and merge, append, or locale-replace modes when importing FAQPage JSON-LD.
- Update answers for matching locale and question pairs while preserving and adding previously unseen questions.
- Retry Prompt Preview requests for the same URL, use a 20-second timeout, and distinguish fetch failures from missing metadata.
- Clarify duplicate schema types that remain outside the automatic Tool Kits SEO/Breadcrumb fix.
- Add GEO Output, FAQ locale, audit score, and duplicate-schema summary widgets to the GEO overview.

= 2.7.1 =
**Database Maintenance and WP-Cron Monitoring**
- Limit post revisions to five by default while respecting an explicit `WP_POST_REVISIONS` value.
- Track scheduled WP-Cron duration and memory growth, then report heavy or overdue hooks to NexaMonitor.
- Detect unused ACF postmeta and options while ACF is active by validating registered field keys.
- Before removing inactive ACF data, scan active theme/plugin source, field groups, local JSON, blocks, and shortcodes; block cleanup if usage is found or the scan is incomplete.
- Report revision and ACF cleanup results through the NexaMonitor heartbeat while preserving SEO/GEO, operations, and delivery summaries.

= 2.7.0 =
**Operations and Delivery Health**
- Add cached WP-Cron and background queue diagnostics with safe schedule repair controls.
- Add manual SPF, DKIM, DMARC, and MX checks for the configured sender domain.
- Include cached operations and email-delivery summaries in NexaMonitor heartbeat payloads.
- Add hierarchy-aware BreadcrumbList schema with duplicate-safe independent controls.

= 2.6.0 =
**Lightweight SEO/GEO Delivery**
- Add background IndexNow delivery with a bounded queue and no remote requests during frontend page loads.
- Add schema mapping by public post type so pages no longer default to Article markup.
- Include cached SEO/GEO health summaries in NexaMonitor heartbeat payloads without running audits during heartbeat.

= 2.5.76 =
**Schema Duplicate Auto Fix Refresh**
- Force a fresh duplicate-schema scan after Auto Fix instead of reusing the saved report.
- Clear frontend caches before verification so disabled Tool Kits schema is no longer read from stale HTML.
- Make the manual duplicate detector always perform a new scan.

= 2.5.75 =
**Google AI Overviews Clarity in GEO**
- Add `Googlebot` to the AI crawler accessibility review, since AI Overviews and AI Mode are grounded from the regular Google Search index and have no dedicated crawler.
- Flag a blocked `Googlebot` as an issue so the crawler that actually gates AI Overviews eligibility is not overlooked.
- Correct the `Google-Extended` expectation: blocking it limits Gemini and Vertex AI training and grounding only, and does not remove the site from AI Overviews.
- Explain the real opt-out paths in the AI Access panel, linking Search Console > Settings > Search generative AI and noting the `nosnippet` / `max-snippet` trade-off.

= 2.5.74 =
**SEO Optimization Tabs**
- Organize the SEO Optimization page into Settings, Redirects, Canonical, Indexing, Broken Links, and Content Audit tabs, matching other Tool Kits screens.
- Return to the originating tab after saving settings, managing redirects or indexing items, and running or clearing audits.
- Show third-party and theme-managed SEO warnings above the tabs and remove the duplicated page title.

= 2.5.73 =
**Captcha Shortcode Modes**
- Add robot and classic captcha mode parameters to the `[toolkits_captcha]` shortcode and CF7 tag, including the `clasic` compatibility spelling.
- Preserve the selected captcha mode when refreshing and validating each challenge.
- Keep the first hide-login page visit informational and only apply CAPTCHA/error obfuscation to actual login submissions.
- Show CAPTCHA instructions and validation messages in Indonesian or English based on the active WordPress locale.
- Add an optional Duplicate Page/Post row action under General Settings, creating editable drafts with taxonomy and custom-field data preserved.
- Preserve the source language when duplicating Polylang or WPML content without copying its existing translation-group relationship.
- Import FAQPage JSON-LD in replace or append mode and assign each question to its declared locale, with the WordPress locale as fallback.
- Submit generated sitemaps to Google Search Console and Bing Webmaster Tools using stored OAuth access tokens, with status tracking.

= 2.5.72 =
**Mobile FAQ Typography**
- Set FAQ question text to 18px and answer paragraphs to 14px on viewports up to 767px.
- Preserve existing desktop typography.

= 2.5.71 =
**On-Demand GEO Result Reuse**
- Reuse saved URL audit results, including failures, unless Retry Failed is explicitly requested.
- Preserve existing results across all batch modes and reuse saved GEO review/preview reports.
- Add Clear All Saved GEO Results for an explicit fresh review.
- Limit preloader request duration to a twelve-second budget, stop on the first failure and apply a five-minute cooldown.

= 2.5.70 =
**Preserve Page Cache During Audits**
- Avoid full page-cache purges when only admin GEO/SEO/authority reports change in tk_options.
- Continue invalidating page cache for real settings and FAQ changes, including mixed report/settings updates.
- Add regression coverage for report updates, report removals and content changes.

= 2.5.69 =
**Lazy Media Reliability and Development Cleanup**
- Observe rendered video/picture containers instead of non-rendered source elements and restore child sources before loading video.
- Initialize lazy media after DOM readiness, prevent duplicate initialization, and observe dynamically inserted media.
- Preserve explicit eager/high-priority images and eager iframes without disabling below-fold lazy loading.
- Consolidate standalone regression tests into one runner and historical release notes into RELEASE.md.

= 2.5.68 =
**GEO Audit Cache-Safe Refresh**
- Keep a dedicated build parameter on the audit script when Ghost Mode strips version strings.
- Navigate to a fresh report URL after completion and retain the GEO Audit tab.

= 2.5.67 =
**GEO Audit Report Refresh**
- Automatically reload the page after the audit batch completes to display saved results.
- Preserve the original cURL timeout details for server connectivity diagnosis.
- Normalize the displayed timeout to the actual request limit.

= 2.5.66 =
**GEO Report Button Feedback**
- Initialize batch controls after the page DOM is ready.
- Display audit progress, selection warnings and request errors directly beside report actions.
- Add UI regression tests for report buttons and AJAX execution.

= 2.5.65 =
**GEO Report Bulk Retry**
- Add per-URL checkboxes and select-all controls to the GEO Audit report table.
- Add Run Selected and Retry Failed actions for existing report URLs.
- Merge retried results by URL while preserving successful results for other pages.
- Restrict retries to eligible public targets and add regression checks for failure selection and result preservation.

= 2.5.64 =
**Bulk GEO Audit**
- Add Run All and Run Selected actions with public URL checkboxes, progress, per-URL results, and a Stop control.
- Process one URL per AJAX request with a full 20-second timeout and continue after individual connection failures.
- Save results incrementally in the GEO Audit report and restrict audit sessions to the authenticated user.
- Add regression checks for target selection, full timeouts, failure continuation, and saved progress.

= 2.5.63 =
**GEO Audit Timeout Handling**
- Increase the GEO Audit per-URL timeout to 20 seconds within a 25-second request-loop budget.
- Stop the audit on connection failures and show an incomplete-audit notice when targets remain unchecked.
- Mark connection failures as unverified instead of grade F and exclude unverified pages from average scores.
- Add regression checks for timeout configuration, early stopping, and score exclusion.

= 2.5.62 =
**Plugins-Page GitHub Update Checks**
- Limit remote GitHub update checks to the Installed Plugins page and authenticated manual checks.
- Preserve stored update offers without remote checks on frontend, cron, AJAX, or other admin pages.
- Retain the one-hour release cache and add a five-minute retry cooldown to reduce repeated failed requests.
- Add regression checks for request scope, stored offers, and retry cooldown behavior.

= 2.5.61 =
**CPU Workload Optimizations and Authority Timeout**
- Cache theme asset content hashes for 60 seconds using file metadata keys to avoid repeated full-file hashing.
- Limit editor SEO scoring to the current entry and retain cross-page duplicate checks in full Content Audit.
- Reduce realtime monitoring polling frequency to 15 seconds and cache error-rate readings for 30 seconds.
- Consolidate automatic page cache purges into one shutdown action per request and exclude internal audit metadata and editor locks.
- Add a configurable External Authority timeout of 5-30 seconds with a 20-second default and clearer timeout results.
- Add regression checks for hash caching, editor query scope, error-rate caching, and deferred cache invalidation.

= 2.5.60 =
**FAQ Language Tab Empty State Fix**
- Show an initial question/answer row when selecting an empty language tab in the global FAQ editor or per-content popup.
- Automatically assign the selected language to the new row and ignore completely empty popup rows when applying changes.

= 2.5.59 =
**Global FAQ Language Tabs**
- Group the GEO settings FAQPage editor into ID, EN, Default, and additional language tabs.
- Assign newly added questions to the selected language and retain all groups when saving.
- Preserve existing untagged FAQs under Default and add language code validation.

= 2.5.58 =
**FAQ Popup Language Tabs**
- Group per-content FAQ popup entries into ID, EN, and additional language tabs.
- Add language groups using validated language codes and assign new FAQ rows to the active tab.
- Preserve untagged entries in a Default tab and retain the 50-entry total limit.
- Support arrow-key tab navigation and reveal the relevant language group when validation fails.

= 2.5.57 =
**Multilingual FAQ Entries**
- Add language code inputs to global FAQ rows and per-content FAQ popups.
- Filter FAQ shortcode and schema entries by page language using existing Polylang/WPML language detection, with site language as fallback.
- Keep entries without language codes available in all languages and retain the 50-row total limit.
- Normalize language tags, validate popup language inputs, and add language-selection regression checks.

= 2.5.56 =
**llms.txt Markdown Links**
- Generate Markdown links for site, sitemap, important URLs, and curated content instead of plain URLs.
- Include the homepage in the important URL list even when no other review URLs are available.
- Escape Markdown link labels and URL parentheses to preserve valid link syntax.

= 2.5.55 =
**External Authority Source Checks**
- Add an External Authority menu to manage a brand name and up to 50 external source URLs.
- Check each saved URL for HTTP availability, brand mentions, and links to the website domain, including link rel attributes.
- Store per-source evidence and check timestamps; inaccessible or unsupported sources remain unverified.
- Use nonce-protected actions, feature access checks, and safe HTTP requests; reject same-site source URLs.
- Add regression checks for source origins, mention matching, domain links, and false positives.

= 2.5.54 =
**Share Description Shortcode Fix**
- Strip registered shortcodes from excerpts and content before generating Tool Kits meta descriptions and Open Graph descriptions.
- Retain the site description fallback when removing shortcodes leaves no readable text.
- Add regression checks for shortcode-free content, excerpt, and fallback descriptions.

= 2.5.53 =
**Global FAQ Add Question Fix**
- Align the global FAQ Add Question button with the 50-row storage limit.
- Disable the button at the limit, enable it again after removing a row, and focus newly added questions.

= 2.5.52 =
**FAQ Accordion and Increased Limit**
- Increase local and global FAQ storage limits to 50 and update the per-content popup limit.
- Render the FAQ shortcode as a native details/summary accordion with collapsed answers, toggle indicators, and keyboard focus styling.
- Remove the shortcode H2 title while retaining FAQ structured data attributes.
- Add regression checks for FAQ counts above 20 and the 50-item storage limit.

= 2.5.51 =
**SEO / GEO Content Type Selection**
- Add content type checkboxes in SEO settings for pages, posts, and registered custom post types with editor interfaces.
- Exclude section-only and non-public types by default while allowing explicit selection.
- Apply selected types to editor panels, FAQ editor assets, per-content controls, and content audits.
- Preserve existing per-content data when a type is disabled and reject public audit requests for excluded types.

= 2.5.50 =
**Per-Page FAQ Editor and Content Scores**
- Add a GEO FAQ popup with dynamic question/answer rows, add/remove controls, validation, and apply/cancel actions for pages, posts, and public custom post types.
- Save local FAQ items per content entry and use them for FAQ schema and shortcode output, respecting the GEO toggle.
- Display saved-content SEO scores and findings in the editor, including draft content.
- Add an on-demand public-page audit with a separate rendered SEO score, HTML findings, text samples, and content-change notices.
- Add regression checks for FAQ persistence, nonce and edit permissions, and rendered HTML parsing.

= 2.5.49 =
**Theme Asset Version Hardening Compatibility**
- Add theme CSS/JS content hashes after Ghost Mode removes platform version strings so automatic cache invalidation remains active.

= 2.5.48 =
**Per-Content SEO and GEO Toggles**
- Add separate SEO and GEO on/off controls for pages, posts, and public custom post types in the Tool Kits SEO / GEO editor panel.
- Apply per-content controls to Tool Kits SEO metadata and GEO schema output while retaining global settings and existing SEO conflict protection.
- Keep existing content enabled by default and preserve archive behavior; disabling output does not add noindex or change third-party SEO output.

= 2.5.47 =
**Automatic Asset Versions and Expanded SEO Audit**
- Version local admin CSS and JavaScript automatically using file modification times.
- Version enqueued theme CSS and JavaScript using content hashes so updated assets receive new URLs after deployment.
- Expand Content Audit with ten SEO categories, per-page findings, optimization recommendations, and review links.
- Add focus keyword and Jakarta/Singapore target location fields to the content editor.
- Detect exact duplicate stored content and potential placeholder wording within the latest 200 published entries; mark rendered-page checks for live review.

= 2.5.46 =
**Health Monitor Asset Cache Fix**
- Rename the Monitoring controller and shared admin stylesheet so browser, proxy, and CDN caches cannot combine the new canvas markup with the legacy SVG renderer.
- Use new WordPress asset handles and retain file-versioned Monitoring JavaScript loading.
- Remove ToolKits diagnostic messages from production browser consoles.
- Add browser regression coverage for current asset URLs and a clean ToolKits console.

= 2.5.45 =
**Chart.js Health Monitor**
- Replace the custom SVG chart renderer with locally bundled Chart.js 4.5.1 line charts.
- Add responsive canvas rendering, automatic high-DPI scaling, tooltips, stable axes, and native chart animations.
- Keep CPU and memory data in a 30-sample rolling window with unused startup slots left empty.
- Load Chart.js only on the Monitoring page and retain loading, retry, visibility-aware polling, and tab persistence.

= 2.5.44 =
**Page Heading Card Fix**
- Add the tk-card class directly to the shared tk-hero tk-page-heading component.
- Strengthen the white background, border, radius, and shadow rules so legacy hero styles cannot hide the card surface.

= 2.5.43 =
**Real-Time Chart Layout Fix**
- Rebuild the CPU and memory chart layout with stable card dimensions, aligned axes, compact legends, and matching desktop heights.
- Keep early samples anchored to the right side of the 30-sample rolling window so startup data no longer stretches across the full chart.
- Use five metric columns on wide screens and a compact two-column layout on mobile, with the final metric spanning the row.
- Prevent chart, label, and marker clipping at narrow WordPress admin widths.

= 2.5.42 =
**Monitoring Load Performance and Header Cards**
- Restore tk-hero tk-page-heading as a bordered card with responsive spacing.
- Respect the existing license validation cache during menu rendering; preserve signature checks and explicit activation validation.
- Move page-triggered file integrity alerts to WP-Cron and omit unused plugin directory scans from chart requests.
- Cache CPU capacity detection and add chart loading, unavailable, timeout, and retry states.
- Prevent overlapping polling, cancel requests on hidden pages or inactive tabs, and retain the selected Monitoring tab.
- Show the first sample immediately, animate chart updates with reduced-motion support, and align chart scales on mobile and desktop.

= 2.5.41 =
**Admin UI and Image Optimizer Workspace**
- Refresh Tool Kits page headers, navigation, buttons, form controls, tables, and overview scores with a restrained responsive layout.
- Organize Image Optimizer into Settings, Media Library, Compression Report, and Maintenance tabs with keyboard navigation and tab persistence.
- Add aligned toggle settings, synchronized quality controls, Hi-Res resize states, report summaries, image thumbnails, and empty states.
- Improve processing overlay accessibility and allow interrupted optimization to resume from the last completed batch.

= 2.5.40 =
**Hi-Res Image Optimization and Validation**
- Add Hi-Res mode: preserve original pixel dimensions, ignore legacy resize limits, and apply a minimum JPEG quality of 95.
- Encode from original uploads in one pass, preserve ICC profiles, apply DPI before encoding, and reject larger or invalid results.
- Validate JPEG quality and lossless PNG/WebP pixel fidelity with Imagick; preserve source files when validation cannot pass.
- Serve only validated generated copies with source fallback and cache revisions. Frontend requests no longer encode images.
- Fix full-resolution and lazy-load source selection, legacy thumbnail WebP resolution, and single-candidate srcset widths.
- Restrict local image paths to uploads, retain original files, and safely remove obsolete thumbnail derivatives and their reports.
- Prevent concurrent background workers, reduce batch size, respect queue stop/restart, and fix Auto WebP metadata filter arguments.

= 2.5.39 =
**Performance, GEO / AI Visibility, and Monitoring**
- Add AI Visibility Score per URL with crawler visibility, robots access, metadata, and JSON-LD checks.
- Add llms.txt section builder, prompt preview, and schema validation report per post.
- Add safe image optimizer maintenance cleanup for stale `-tkopt` derivatives.
- Add Monitoring summary cards for Performance & Images, GEO / AI Visibility, and system health.

= 2.5.38 =
**OTP Hide Login Compatibility**
- Ensure OTP challenge URLs explicitly use the custom Hide Login slug when Hide Login is enabled.
- Keep OTP verification routes compatible with hidden login URLs instead of direct `wp-login.php` links.

= 2.5.37 =
**OTP Login**
- Add Security > OTP Login with email-based one-time codes after valid username/password authentication.
- Support protected roles, expiry, max attempts, resend cooldown, optional trusted devices, editable email templates, and OTP audit logs.
- Add emergency bypass support with `TOOLKITS_DISABLE_OTP`.

= 2.5.36 =
**Image Optimizer Original URL Rewrite**
- Resolve frontend thumbnail URLs like `-768x549` back to their full-size original before generating optimized frontend URLs when original-only srcset is enabled.
- Clean up unused optimized thumbnail derivatives across image extensions, including stale `-tkopt.webp` files.

= 2.5.35 =
**Image Optimizer Derivative Cleanup**
- When original-only frontend srcset is enabled, regenerate only the full-size optimized copy.
- Delete old optimized `-tkopt` files for unused intermediate thumbnail sizes during image regeneration.

= 2.5.34 =
**Image Optimizer Srcset & Quality**
- Add an original-only frontend srcset option so browsers use the full-size image candidate instead of many generated thumbnail sizes.
- Raise the image optimizer quality preset to 92 and WebP quality fallback to 90 to reduce visible compression artifacts.
- Migrate existing installs using previous default quality values to the sharper preset.

= 2.5.33 =
**Admin Card Spacing**
- Remove the global adjacent card top margin so card spacing is controlled by each page layout.

= 2.5.32 =
**Overview Score Card Alignment**
- Match SEO and GEO score card heights on the Welcome overview page.
- Keep score cards stretched evenly across the grid for a cleaner layout.

= 2.5.31 =
**Overview Score Card Styling**
- Render SEO and GEO overview score widgets as Tool Kits cards with admin CSS fallback styling.
- Improve cache busting for the updated overview score widget layout.

= 2.5.30 =
**Admin Tab Persistence**
- Keep the current Tool Kits tab active after saving settings, running actions, or page refreshes.
- Restore tab state per admin page using browser session storage with hash links still taking priority.

= 2.5.29 =
**Overview SEO & GEO Scores**
- Add SEO Score and GEO Score widgets to the Welcome to Tool Kits overview.
- Add sidebar SEO & GEO audit checklist with links to the relevant configuration sections.
- Calculate overview SEO/GEO scores from configured Tool Kits options and stored crawler review results.

= 2.5.28 =
**Image Optimizer No-Resize Default**
- Change image optimization defaults to TinyJPG-like compression without resizing pixel dimensions.
- Keep max width and max height disabled by default; existing installs using the old 2560px defaults are migrated to no-resize.
- Preserve optional resize caps for users who intentionally want oversized images resized.

= 2.5.27 =
**Image Optimizer Sharpness**
- Raise the default image compression quality from 78 to 86 for sharper frontend optimized assets.
- Add a Preserve Sharpness after resize option using Imagick unsharp masking when resized images are post-processed.
- Migrate installs still using the previous default quality to the sharper quality preset.

= 2.5.26 =
**Image Optimizer Processing Overlay**
- Add a processing overlay while existing media library image optimization batches are running.
- Show live batch progress text inside the overlay and hide it automatically when processing completes or fails.

= 2.5.25 =
**GEO Report Export & Select2**
- Add horizontal scrolling to Crawler Preview and AI crawler review report tables.
- Add print-to-PDF export actions for Crawler Preview and AI Crawler Accessibility Review reports.
- Enhance ItemList post selection with Select2 multiselect on the GEO admin page.

= 2.5.24 =
**GEO UI Improvements**
- Split GEO settings into feature tabs for output, Custom JSON-LD, FAQPage, ItemList, llms.txt, duplicate detector, crawler preview, AI access review, and preview.
- Add a Custom JSON-LD draft generator button for editable WebSite and Organization schema.
- Add FAQPage question rows with add and remove controls.
- Update Crawler Preview to test all known AI crawler user agents and list which ones can visibly fetch the page.

= 2.5.23 =
**Updater Session Fix**
- Stop forcing logout after every Tool Kits plugin update.
- Invalidate sessions only when Hide Login enabled state or custom slug changes.
- Make successful updater completion notices one-time by clearing completed status before rendering and avoiding persistent completed status storage.

= 2.5.22 =
**GEO & Image Optimization**
- Add GEO admin menu for custom JSON-LD, FAQPage structured content, ItemList generation, and editable virtual llms.txt output.
- Add AI crawler accessibility review, crawler fetch checklist, crawler preview, and schema duplicate detector.
- Add safe image optimizer derivatives with `-tkopt` filenames, frontend fallback to originals, DPI metadata normalization, metadata stripping, and dimension caps.
- Add compression report and WP-Cron background queue for large media library optimization.

= 2.5.21 =
**GEO Security Events**
- Validate GEO lookup flow for security alerts and dashboard country reporting.
- Resolve missing security event locations automatically when an event has a public IP.
- Add lightweight maintenance backfill for older security events with missing location/country data.

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
