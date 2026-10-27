# Shared Tool Kits admin layout

The admin uses the existing white surfaces and blue accent consistently across
Overview, monitoring, security, performance, SEO/GEO, database and system tools.
Common typography, borders, spacing, controls, tabs and responsive rules live in
`assets/tool-kits-admin-ui.css`; individual modules keep their own content styles.
Editor dialogs use matching controls in `assets/editor-modals.css`.

The Overview template now carries `tk-overview-wrap` so its existing shared
overrides actually apply. Diagnostics and Health Center use the common page
heading. Health Center's WordPress dashboard widget retains its own styling.
Footer adjustments use the `tk-admin-screen` body class and do not affect other
WordPress admin screens.

## Components

- Use `tk-card`, `tk-grid` and `tk-grid-2` / `tk-grid-3` for standard surfaces.
- Add `tk-responsive-grid` to legacy inline column layouts. Mobile CSS collapses
  columns without changing the element's display/visibility state. GEO's
  dynamically inserted FAQ/llms rows receive the same responsive treatment.
- Use `tk-section-heading`, `tk-setting-section` and `tk-action-row` for settings
  layouts. `tk-settings-grid` / `tk-settings-group` provide labelled fieldsets.
- Tabs stay on one horizontally scrollable row on narrow screens. Existing tab
  controllers remain responsible for selection and panel visibility.

`assets/admin-layout.js` loads only on Tool Kits admin screens. It gives top-level
data tables a keyboard-focusable, named scroll region while preserving the actual
table node, form ownership and attached event handlers. Existing scroll containers
and accessible labels are reused. Nested tables and native WordPress form tables
are excluded. A child-list observer handles tables returned by AJAX; the script
does not request data or change settings. Reinitialization is safe.

## Verification

```sh
TK_PREVIEW_WP_ROOT=/path/to/wordpress node scripts/admin-layout-ui-regression.cjs
```

Requires the existing local Puppeteer installation and browser. The fixture renders
production templates with isolated data and real WordPress CSS, without loading
wp-config or accessing a live database. Remote requests receive local preview
responses. It checks 12 views, including a schema editor dialog, at 1440, 1024, 782, 390 and 320 pixels; preserves form
values, tests dynamic report controls and keyboard scrolling, and ensures unrelated
WordPress tables are untouched. Desktop/mobile screenshots are written to a new
temporary review directory printed by the command.

Views can be supplied as arguments to limit a visual recheck, for example
`node scripts/admin-layout-ui-regression.cjs assets captcha`.
The suite is also available as the optional `admin-layout-ui` regression case.
