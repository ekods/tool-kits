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

Definition of done:

- Release packaging is validated automatically before publishing.
- Core helper behavior is covered by repeatable checks.

## Release Checklist

1. Bump version in `tool-kits.php`.
2. Update `Stable tag` and changelog in `readme.txt`.
3. Build the release ZIP.
4. Validate the ZIP structure.
5. Publish Git tag and GitHub release.
6. Attach `tool-kits.zip`.
7. Test update flow from the previous public version.
