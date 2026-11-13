## Tool Kits 2.7.90

Role Management has a clearer layout. Capability and menu groups are collapsible
and searchable, each with a selected/total counter; tabs show their totals; and a
sticky save bar summarizes how many capabilities and menus the role has. The role
list highlights the role being edited and scrolls horizontally on narrow screens
instead of widening the page.

Capabilities that grant powerful access (site settings, user, plugin and theme
management, core updates, unfiltered HTML, import/export) carry a "High risk" badge.
The browser now asks for confirmation when leaving the role form with unsaved
changes; searching does not count as a change.

Saving, capability enforcement, menu rules, field names and nonces are unchanged.
No option or database migration. Styles live in the new assets/role-management.css,
loaded only on the Role Management screen.

Validation: rendered with stubbed WordPress data at 1440px and 390px (no horizontal
overflow, no JavaScript errors); search, tab switching, counters, preset sync and
the unsaved-changes prompt were exercised in a browser. Not tested against a live
WordPress install or the admin-layout regression suite.

## Tool Kits 2.7.89

SEO > Indexing gains a read-only Search Console Readiness checklist and a broader
Indexing Checks scan, so problems that make Search Console report "Some fixes
failed" can be found before clicking Validate fix.

Readiness checklist: ownership verification tag, search engine visibility,
HTTPS and a single host version, robots.txt (Googlebot access, rendering
resources, Sitemap line), sitemap validity, homepage basics (title, description,
viewport, one H1, self-canonical), JSON-LD validity and the last malware scan.
It changes nothing and does not guarantee indexing.

Indexing Checks can now scan website content, the sitemap, or a URL list pasted
from a Search Console export (up to 1000 same-site URLs). Each URL also gets a
server error / not found / soft 404 check and a comparison of the response a
Googlebot user agent receives, and the report ends with a summary by issue type.
Existing fixes and Fix All are unchanged. A scan now makes three requests per URL
instead of two. A spoofed Googlebot user agent can be challenged by bot protection
even when real Googlebot is allowed; confirm differences in server logs.

No option or database migration.

Validation: indexability PHP and UI regression suites, public-module and runtime
load checks; the checklist logic was exercised against stubbed responses for
healthy and failing sites. Not run against a live Search Console account.

## Tool Kits 2.7.88

Legacy stacked field captions now have an explicit 8px gap before their controls,
including Attack Details filters, Retention days and the Hide Login custom slug.
Bare line breaks and WordPress's negative small-input top margin no longer pull
controls against the caption. Rules stay within Tool Kits settings and exclude
inline checkbox/radio labels and native list-table controls. Five production fields
were checked at five viewport widths with unchanged submitted form values.

GA, GTM and Collector header statuses now share consistent link styling, height,
padding and centered indicators. GA and GTM open their Overview settings sections;
Collector opens Access & License's Connection tab, including when that page is
already open. Status calculations and saved IDs remain unchanged. Header links
were checked at ten viewport widths, with full IDs, keyboard focus, destination
anchors and initial/same-page Connection navigation. Analytics/GTM and request
option regression checks also pass.

Security, SEO and GEO readiness scores now appear as separate white cards with
tinted category icons, circular percentage indicators and clear link affordances.
Cards stack on smaller screens and retain visible keyboard focus and reduced-motion
support. Score calculations, score colors and existing detail destinations are
unchanged. No additional JavaScript or chart dependency is required.

The Overview protection list now aligns module names, statuses, details and actions
in consistent columns. Legacy card body height and list spacing are removed;
narrow panels stack the details and actions below the module heading. Buttons
retain WordPress styling, and mobile actions have a 44px minimum height. Settings,
configure links and the nonce-protected Hardening reset form are preserved.

CAPTCHA refresh and checkbox verification now send their action in the POST body
to the site's home endpoint. Newly generated script configuration no longer emits
`?tk_captcha_action=` URLs. Cached pages using the old query endpoints still work.
All CAPTCHA route responses send `X-Robots-Tag: noindex, nofollow` and no-cache
headers; empty, unknown or malformed actions return HTTP 400 instead of HTTP 200.
Ordinary pages retain their existing indexing behavior.

After updating, clear page/CDN caches. Existing Search Console entries may remain
until Google recrawls them. No robots.txt block is added, so crawlers can read the
response status and noindex header. No CAPTCHA settings or storage migration.

Validation: 12 HTTP routing/header cases for legacy and POST-body actions,
malformed input and unaffected content; 100 CAPTCHA security checks on PHP 7.4
and 8.4, UI flows and focused public-module/login checks. HTTP tests use isolated
WordPress stubs, not a deployed site's database or Search Console account.
The protection list was also checked in enabled/disabled states at 11 viewport
widths (2188px through 320px), with long slugs and unchanged reset form values.
Readiness cards were checked at the same 11 widths, including empty/full rings,
long labels, retained links and scores, keyboard navigation and reduced motion.

## Tool Kits 2.7.87

FAQ GEO now appears inside the Tool Kits SEO / GEO sidebar card. The Edit FAQ
popup, saved FAQ data and public schema output retain their existing behavior.
SEO Settings can hide the SEO/GEO controls and FAQ section independently; the
shared card stays available while either section is enabled. SEO Content remains
in its own main editor panel. No option or metadata migration is required.

Validation: automatic SEO/GEO signals (37), locale-copy/panel visibility (54),
FAQ persistence/security and rendered HTML audit; 61 isolated browser checks for
the merged/FAQ-only card, Apply/Cancel, submitted nonces/values and 1280/390/320px
layouts.

## Tool Kits 2.7.86

GEO audit sampling, batched settings writes, streaming CF7 Excel exports and lighter frontend SEO/GEO loading. See `readme.txt` for the complete list.

Upgrade notes:
- GEO Audit uses the homepage, all selected Pages and three latest published unprotected posts per selected post/CPT. Run All aligns older reports to this sample. Explicit saved-URL retries still work outside the sample.
- Indexing Checks continues to discover the full website; this sampling change applies to GEO Audit.
- No database schema or option format migration. Export remains XLSX with dynamic fields, form filters and formula protection.
- Metadata policies share a buffer when adjacent; intervening third-party buffers retain their order. Existing frontend metadata precedence remains in place.

Validation: 86 regression cases including the browser score-refresh case, PHP 7.4/8.4 checks, 1,201-row export fixtures, public/admin/AJAX/cron/REST/CLI/XML-RPC module loading and metadata ordering/output checks. Local synthetic frontend measurements are recorded separately and do not claim production TTFB gains.

## Tool Kits 2.7.83

Scanner false-positive fix, Google Tag Manager tabs, a new header design and a native third menu level. See `readme.txt` for the complete list.

Upgrade notes:
- Run the HTML Injection Scanner again; findings recorded for Tool Kits' own scripts before this update stay in the saved report until then.
- Header buttons on the Overview and page headings are plain WordPress buttons; their colors follow the admin color scheme.
- The Tool Kits submenu flyout no longer has its own navy panel: it uses the admin color scheme like WordPress's own submenus.

Validation: PHP 7.4.33 and 8.4 lint, regression suite 81/81 on both, browser checks for the menu flyout (39 checks) and the Google Tag Manager tabs with a real CodeMirror editor, and the header rendered over WordPress's own button and menu styles. Mobile menu behavior and admin color schemes other than "modern" were not tested.

## Tool Kits 2.7.82

Opt-in Redis object cache, server-load controls and a stand-alone CF7 Database menu. See `readme.txt` for the complete list.

Upgrade notes:
- The object cache installs `wp-content/object-cache.php` only when you enable it after a passing connection test. Tool Kits removes it on deactivation and uninstall, and never replaces another plugin's drop-in.
- Redis needs the phpredis extension. `TK_REDIS_HOST`, `TK_REDIS_PORT`, `TK_REDIS_PASSWORD` and `TK_REDIS_DATABASE` in wp-config override the saved settings.
- After a Redis outage the drop-in clears this site's keys when Redis returns, so deletes made during the outage cannot leave stale data.
- `incr`/`decr` are not atomic; concurrent increments can be lost. A lapsed license does not remove an installed drop-in (Disable stays available without a license).
- CF7 Database messages moved from `page=tool-kits-cf7-database` to the new `CF7 Database` menu (`page=tool-kits-cf7-collection`).

Validation: PHP 7.4.33 and 8.4 lint, regression suite 80/80 on both, object-cache checks 106 on both, plus a real Redis 7 server on both PHP versions for the drop-in API, flushing, outage fallback, the 30-second pause and recovery flush.

## Tool Kits 2.7.81

- Bundle CF7 Database Collection under System Tools with an enable/disable checkbox; new submissions are saved only after successful Contact Form 7 email delivery.
- Preserve existing submissions while collection is disabled, with per-form browsing, read/unread tracking, deletion and Excel export. Reuse the standalone plugin table and prevent duplicate capture when both plugins are active.
- Refresh the admin workspace with teal actions, clearer task headings, underlined tabs, consistent forms and editor dialogs, and responsive CF7 collection panels.
- Group security, SEO and GEO scores in one readiness board and present protection settings as a compact list.
- Preserve native WordPress table controls, keyboard focus and reduced-motion support.

After updating: open Tool Kits > System Tools > CF7 Database, choose Enable CF7 Database Collection and save. Collection is off by default. Disabling it keeps saved messages. Contact Form 7 and an active Tool Kits license are required for new collection.

Validation: 34 CF7 collection checks, 26 request-loading checks, 75 Tool Kits layout/form checks across ten views, 128 native-table checks, and isolated WordPress save/resend and desktop/mobile review.

## Tool Kits 2.7.80

Security, correctness and performance fixes from a full runtime audit. See `readme.txt` for the complete list.

Upgrade notes:
- Prepared DB exports can be downloaded once; undownloaded ones (e.g. a prefix-change backup) are kept for a day.
- IPs auto-blocked for failed logins before this update stay blocked until unblocked manually; new auto-blocks last 24 hours.
- New WebP conversions are named `foo.jpg.webp`; existing `foo.webp` files stay on disk and are still served when unambiguous.
- Post meta changed by visitors or cron no longer purges the page cache; those pages refresh on their TTL. WooCommerce stock changes still purge.
- DB Cleanup stats, Theme Audit and Dashboard cache stats can be up to 5–10 minutes old.
- Settings data moves out of `tk_options` on the first request after the update.

Validation: PHP 7.4.33 and 8.4 lint, regression suite 77/77 on both, stub tests for every fix, browser tests for cookie consent and Instant Page. The new dashboard GROUP BY queries were not run against a real MySQL server.

## Tool Kits 2.7.79

- Limit Top Countries and each Top IPs Blocked range (24 Hours, 7 Days, 30 Days) to five items in the Total Attacks Blocked dashboard widget.
- Apply the limit immediately to cached results.

## Tool Kits 2.7.78

- Align Health Center, SEO panels, editor dialogs, language controls and overview icons with the teal admin palette.
- Give success, information, warning and error feedback consistent surfaces and accessible status or alert roles.
- Retain native WordPress table styling, search and responsive details.

## Tool Kits 2.7.77

- Refresh admin headers, cards, controls and status accents with a calm teal and mint palette.
- Use WordPress widefat/striped styles for report and security lists, retaining accessible horizontal scrolling for wide reports.
- Replace the custom SMTP data table with WP_List_Table search, sorting, pagination and responsive row details; preserve nonce-protected resend actions.

# Tool Kits Release Notes

Versioned historical notes are consolidated here. Current release history is maintained in `../readme.txt`.

## Build output

Metode build sama dengan CFF. Jalankan `bash scripts/build-release-zip.sh` dari root plugin.
Di workspace NexaMonitor, output selalu `dist/tool-kits/tool-kits.zip`, disertai
`SHA256SUMS`, `VERSION`, dan `RELEASE_NOTES.md`. Build berikutnya mengganti file
tersebut setelah validasi berhasil; build gagal mempertahankan ZIP sebelumnya.
Tidak membuat folder versi baru; arsip rilis tetap tersedia di GitHub Releases.
Checkout mandiri memakai `dist/` di root plugin.

`RELEASE_SKIP_SYNC=1` (alias lama: `TK_SKIP_SYNC=1`) melewati sinkronisasi source.
`RELEASE_DIST_DIR=/path/output` mengganti folder default. Argumen path ZIP manual
masih didukung untuk CI/test dan menghasilkan `<nama-zip>.sha256` di sebelah ZIP.
Validasi output default dengan `bash scripts/validate-release-zip.sh`.

## Regression Tests

Run `node scripts/regression-tests.cjs` from the plugin directory. Use `--list` to list cases, or pass a case name to run it individually. Image-codec and browser integration cases are opt-in because they require Imagick, GD-only PHP, or Playwright/Chrome. Set `TK_PHP_BINARY` to select a PHP binary and `TK_PLAYWRIGHT_MODULE` for a custom Playwright module path.

## Tool Kits 2.5.46

Health Monitor charts now load through cache-busted asset names and the production browser console is clean.

- Rename the Monitoring controller to `health-monitor-chart.js` and the shared admin stylesheet to `tool-kits-admin-ui.css`.
- Register both files with new WordPress handles so stale browser, reverse-proxy, or CDN entries cannot combine the current canvas markup with the retired SVG renderer.
- Keep Chart.js 4.5.1 bundled locally and loaded only on the Monitoring page.
- Remove ToolKits diagnostic `console.log`, `console.warn`, and `console.error` output from production Monitoring, analytics, and CAPTCHA scripts.
- Preserve real-time CPU and memory charts, rolling samples, loading and retry states, responsive layout, request cancellation, reduced-motion support, and active-tab persistence.

Validation covers the current asset URLs, exclusion of legacy asset URLs, a clean ToolKits browser console, Chart.js canvas rendering, rolling data, responsive layouts, loading and retry behavior, and PHP/JavaScript syntax.

## Tool Kits 2.5.45

Health Monitor Real-Time now uses Chart.js instead of the custom SVG renderer.

- Bundle Chart.js 4.5.1 locally, including its MIT license. No CDN request is required in WordPress admin.
- Load Chart.js only on `page=tool-kits-monitoring` and declare it as a dependency of the Monitoring controller.
- Render CPU and memory as responsive line charts with canvas scaling, tooltips, native animation, three stable Y-axis ticks, and compact legends.
- Maintain a 30-sample rolling window. New sessions leave earlier slots empty instead of fabricating history or stretching two samples across the chart.
- Show CPU capacity and PHP memory limits as dashed reference datasets when those values are available.
- Remove the previous SVG, polyline, custom axis, marker, and legend renderer.
- Preserve loading and error overlays, retry, non-overlapping polling, request cancellation on hidden/inactive tabs, reduced-motion support, and active-tab persistence.

Validation checks Chart.js version 4.5.1, canvas creation, rolling-window data, CPU and memory scales, loading stability, errors and retry, tab cancellation, and responsive layouts at 1440 px, 390 px, and 320 px.

## Tool Kits 2.5.44

This patch makes the shared Tool Kits page heading an explicit card.

- The shared heading markup is now `tk-hero tk-page-heading tk-card`.
- A specific layout rule enforces the white card background, visible border, 8 px radius, and restrained shadow over legacy hero styles.
- The fix applies consistently to Monitoring, Security, Performance, Images, GEO, and every Tool Kits screen using the shared page-heading renderer.
- The Overview welcome header remains unchanged because it uses its own component.

Validation checks the production markup and computed browser styles, including the `tk-card` class, white background, 1 px border, and 8 px radius.

## Tool Kits 2.5.43

This patch fixes the Health Monitor Real-Time chart presentation introduced in 2.5.42.

- Rebuild CPU and memory charts as stable, matching panels with aligned headings, statistics, axes, timestamps, and legends.
- Anchor new readings to the right side of the 30-sample rolling window. The first two samples no longer stretch into a misleading full-width diagonal.
- Keep data points inside the plot boundary to avoid clipped markers and lines.
- Arrange health metrics in five columns on wide screens, three columns at medium widths, and two compact columns on mobile.
- Stack chart panels cleanly on smaller screens without horizontal overflow or overlapping labels.
- Preserve loading, retry, request cancellation, smooth updates, reduced-motion support, and active-tab persistence from 2.5.42.

Validation includes PHP and JavaScript syntax checks, isolated monitoring security tests, and Chrome checks at 1440 px, 390 px, and 320 px widths. Browser tests verify equal chart heights, non-overlapping panels, rolling-window coordinates, loading stability, retry behavior, and tab persistence.

## Tool Kits 2.5.42

## Header Cards
- Restore `tk-hero tk-page-heading` as a white card with a border, subtle shadow, and responsive spacing.
- Keep the Overview welcome header unchanged.

## Monitoring Performance
- Stop forcing a remote license request during every admin menu render. Use the existing six-hour validation cache while retaining signature and site checks. Explicit license activation and revalidation still force a request.
- Schedule page-triggered file hashing and alert delivery through WP-Cron instead of blocking the Monitoring HTML response.
- Skip plugin directory size scans in real-time chart requests, since those values are not displayed by the page.
- Cache CPU core detection for one hour instead of executing shell commands with each poll.

## Real-Time Charts
- Add fixed-height loading placeholders, unavailable states, a request timeout, and automatic/manual retry.
- Display the first actual sample immediately without inventing history, then animate subsequent chart updates.
- Poll five seconds after each request completes; prevent concurrent requests and cancel pending requests when switching tabs or hiding the page.
- Preserve the current Monitoring tab through refreshes, including redirects without a hash. Invalid hashes fall back to a valid panel.
- Respect reduced-motion preferences and improve chart scaling, axis alignment, and narrow-screen layout.

## Validation
- PHP syntax checks and JavaScript syntax checks.
- Isolated monitoring tests for license-cache integrity, cached CPU capacity, lightweight metrics, authorization, and nonces.
- Browser checks against the rendered production template for loading, failures, retry, tab persistence, cancellation, first-sample rendering, and desktop/mobile layout.
- Existing image optimizer regression tests. Image files and compression settings are unchanged by this release.

## Upgrade Notes
- Upload `tool-kits.zip`. Refresh cached admin assets if old styling remains visible.
- Background alerts require working WP-Cron or a server cron invoking WordPress scheduled events.
- Initial license validation after cache expiry can still wait for the license server. This release does not bypass validation or claim measured production load-time improvements.
- UI and regression tests use isolated fixtures, not a live WordPress database.

## Tool Kits 2.5.41

The admin interface now uses compact page headers, quieter surfaces, consistent controls, and responsive tables. Image Optimizer has a dedicated workspace with separate Settings, Media Library, Compression Report, and Maintenance tabs.

- Show saved bytes, optimized image counts, missing copies, and source protection in a compact summary.
- Align setting labels with accessible switches. Synchronize the JPEG quality slider and numeric field, show effective Hi-Res quality, and make resize limits read-only while Hi-Res is enabled.
- Preserve the selected tab on refresh and action redirects; support arrow keys, Home, and End in tab navigation.
- Add report thumbnails, horizontally scrollable tables, and explicit empty/error states.
- Keep the processing overlay and progress indicator together. Retry interrupted batches from the last completed offset.
- Refine shared Tool Kits headers, overview score cards, tab navigation, buttons, and keyboard focus states without restyling unrelated WordPress pages.

Image encoding settings and previously generated files are unchanged by this release. Clear cached admin assets after updating if the previous styles remain visible.

Validation uses PHP syntax checks, JavaScript syntax checks, a rendered production-template preview, and desktop/mobile browser interaction checks. Preview fixtures do not perform actions on a live WordPress database.

## Tool Kits 2.5.40

Image optimization now preserves high-resolution source uploads and publishes a compressed copy only after validation. Repeated generation reads the source, avoiding accumulated JPEG compression loss.

- Add **Preserve Hi-Res**, enabled by default: no resizing and minimum JPEG quality 95.
- Apply DPI and metadata changes before encoding; retain ICC color profiles and image orientation.
- Use lossless PNG/WebP encoding in Hi-Res mode. Imagick validates decoded pixels and requires at least 40 dB PSNR for lossy JPEG output. Results that fail validation or do not save bytes fall back to the source.
- Frontend delivery uses already-generated copies. It does not perform image compression during page requests.
- Invalidate outdated copies when source files or settings change, and add cache revisions to optimized URLs.
- Resolve original/full-size images for WordPress and lazy-load markup, while keeping original-only srcset descriptors accurate.
- Preserve original uploads, restrict file access to the uploads directory, and remove obsolete thumbnail copies and associated reports during regeneration.
- Process at most five attachments per batch with a time budget between attachments. Prevent overlapping background workers and preserve stop/restart commands.
- Fix Auto WebP's attachment metadata filter argument order and avoid serving older WebP variants over validated Hi-Res copies.

## After Updating

1. Keep **Preserve Hi-Res** enabled and enable **Serve validated optimized images on the frontend**.
2. Regenerate through **Background Queue** or **Optimize all JPEG/PNG/WebP images**.
3. Clear existing page/CDN caches so old HTML references are replaced.

Hi-Res verification requires Imagick. DPI is print metadata and does not determine browser sharpness or guarantee smaller files. JPEG compression remains lossy, and full-resolution delivery can use more bandwidth than responsive thumbnails. Already-degraded originals cannot have missing detail restored by recompression.

## Validation

- PHP syntax checks across the plugin.
- Standalone regression tests using real Imagick codecs and the WordPress 6.8 HTML parser, with isolated settings and attachment fixtures.
- Tests cover pixel dimensions, DPI, ICC retention, JPEG quality, transparency, source preservation, repeated generation, fallback, path traversal, symlinks, lazy-load/srcset markup, cleanup, queue locking and metadata filter behavior.
- Release metadata and ZIP structure validation.

These checks do not replace a full admin and frontend smoke test on the deployed WordPress site.
