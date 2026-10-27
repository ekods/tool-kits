# Contact Form 7 asset loading

Open **Tool Kits > Assets > Contact Form 7 Asset Loading**. Contact Form 7 must
remain active. This feature follows Tool Kits access controls and its active-license
gate. It changes frontend asset queues; it does not change form definitions,
submission handling, anti-spam rules, SMTP configuration or mail delivery.

## Setup

1. Start with **Preview — Tool Kits managers only**. Default mode is **Default**,
   which leaves CF7 loading unchanged for existing installations.
2. In **Always-load pages**, enter one exact path or post/page ID per line.
   Examples: `/contact/`, `/products/`, `123`. `/` matches the homepage. Include
   a site subfolder when applicable, such as `/company/contact/`. `*` preserves
   assets sitewide for a shared footer, popup or template form. Up to 200 entries
   are accepted. Full URLs, query strings, fragments and path wildcards are rejected.
3. Open the website while logged in as a Tool Kits manager. The admin toolbar
   shows whether CF7 assets were kept or skipped. Preview responses set
   `DONOTCACHEPAGE` and no-cache headers. Anonymous visitors and other users keep
   CF7's normal behavior. URL parameters cannot enable preview.
4. Test pages with and without forms, popup forms, required-field validation,
   captcha, mobile behavior and a successful email submission.
5. Select **Live — load only where needed** after testing. Purge any external
   page/CDN cache after saving or resetting. Tool Kits' existing configuration
   change hook invalidates its own page cache.

**Reset to CF7 Defaults** disables conditional loading and clears this module's
always-load list. CF7 form data and other Tool Kits settings are preserved.

## Detection and dependency handling

The module detects `[contact-form-7]`, the native
`contact-form-7/contact-form-selector` block and referenced reusable blocks in the
main query's loaded content. It does not execute shortcodes, render pages, scan
the database for forms, or add frontend HTTP requests. Inspection is bounded to
50 queried posts, 256 KiB per content string, 200 content/block steps and eight
reference levels; uncertain or incomplete inspection keeps assets loaded.

Popup, widget, theme-template, page-builder metadata and dynamically injected
forms are not exhaustively detected. Add their pages to the always-load list;
use `*` for a form that can appear on every page. Themes may also return `true`
from the `tk_cf7_assets_should_load` filter to declare template-level forms.

After normal CF7 registration and initialization, the module dequeues unused
`contact-form-7`, its HTML5 fallback, the CF7 reCAPTCHA integration, and CF7/RTL
styles. It never deregisters assets or directly dequeues shared Google reCAPTCHA,
WordPress, jQuery or SWV handles. Direct or transitive dependencies of other queued
assets keep the required CF7 handles and their original inline initialization.
Third-party code that enqueues assets after this module may still load them.
CF7's own or other plugins' explicit asset-disable policies are not overridden.

No automatic changes are made on admin, AJAX, REST, feed, trackback or robots
requests, while CF7 is absent, or without an active Tool Kits license.

## Verification

Set `TK_WP_ROOT` to local WordPress source and `TK_CF7_ROOT` to local CF7 source:

```sh
TK_WP_ROOT=/path/to/wordpress TK_CF7_ROOT=/path/to/contact-form-7 \
  php scripts/cf7-assets-regression.php
```

Alternatively use `node scripts/regression-tests.cjs cf7-assets` with those same
environment variables. The isolated fixture runs WordPress's actual Hook,
Dependency, Shortcode and Block APIs plus CF7's enqueue controller without
loading wp-config, connecting to a database, sending mail or making HTTP requests.
It covers default/live/preview loading, exceptions, reusable blocks, dependency
preservation, localization, bounds, save/reset guards and escaped settings output.
Real browser submission and delivery tests remain site-specific manual checks.
