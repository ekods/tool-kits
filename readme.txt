=== Tool Kits ===
Contributors: toolkits
Tags: security, migrate, database, cleanup, login
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tool Kits adalah plugin admin toolkit untuk:
- DB Migrate: Export SQL, Find & Replace serialized-safe, dan Rename table prefix.
- DB Cleanup: Bersihkan revisions, trash, spam, transients, dan optimize tabel.
- Security: Hide Login, Captcha, Anti-spam Contact (CF7), Rate Limit login, Login Log, Hardening.

== Installation ==
1. Upload folder `tool-kits` ke `/wp-content/plugins/`
2. Activate plugin di Plugins
3. Buka menu "Tool Kits" dan "Tool Kits Security"

== Notes ==
- Change DB Prefix: plugin akan rename tabel dan update meta keys, tetapi Anda tetap harus update `$table_prefix` di wp-config.php manual.
- Export SQL: best-effort via WPDB. Untuk database besar, gunakan phpMyAdmin/CLI.

== Changelog ==
= 1.0.0 =
- Initial release.