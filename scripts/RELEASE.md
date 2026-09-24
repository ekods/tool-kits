# Tool Kits Release Notes

Versioned historical notes are consolidated here. Current release history is maintained in `../readme.txt`.

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
