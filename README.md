# Tool Kits

Plugin admin toolkit untuk WordPress: database, keamanan, optimasi, monitoring, cache, dan utilitas operasional dalam satu dashboard.

---

## Bagian 1: Bahasa Indonesia

### Deskripsi Singkat

Tool Kits membantu admin WordPress mengelola 5 area utama:
- Keamanan situs.
- Optimasi performa.
- Migrasi dan perawatan database.
- Monitoring kesehatan situs.
- Kontrol akses operasional plugin.

### Fitur Utama

- Database export/import SQL.
- Preload export dengan serialized-safe find/replace.
- DB cleanup (revisi, trash, spam comments, transients, optimize table).
- Hide Login, Minify, Auto WebP, Lazy Load, Asset Optimization.
- Upload limits dan User ID changer.
- Captcha dan anti-spam Contact Form 7.
- Rate limit login berbasis IP + unblock panel.
- Login log (success/failed) dengan retensi.
- Hardening (XML-RPC, headers, WAF basic, HTTP Auth, CORS).
- SMTP (preset provider + test email + log).
- Monitoring (checks, realtime, 404 monitor, healthcheck, heartbeat).
- Cache tools (page cache, object flush, opcache reset, fragment flush).
- Theme checker (summary, largest file, duplicate PHP, risky functions).
- Tool Kits Access (role/IP access, alerts, audit log, owner mode, license).

### Pembaruan Terbaru (Hardening)

- HSTS header sekarang tersedia sebagai toggle dan default direkomendasikan aktif.
- Opsi CSP strict ditambahkan (tanpa `unsafe-inline`/`unsafe-eval`).
- Opsi hide server signature (`X-Powered-By`/`expose_php`) ditambahkan.
- Opsi force `HttpOnly`/`Secure` pada cookie response ditambahkan.
- Opsi disable WP-Cron (`DISABLE_WP_CRON`) ditambahkan dari panel hardening.
- Opsi URL Parameter Guard ditambahkan untuk memblokir query string mencurigakan.
- Opsi HTTP methods filtering ditambahkan (allowlist method + allowlist path).
- Opsi block dangerous HTTP methods ditambahkan (default: PUT, DELETE, TRACE, CONNECT).
- Opsi harden `robots.txt` ditambahkan (minimal policy).
- Opsi block unwanted files ditambahkan (dengan daftar filename custom).
- Check risiko MySQL publik (port 3306) ditambahkan di monitoring hardening.
- Catatan: plugin tidak bisa menutup port 3306 langsung; mitigasi final tetap di firewall/security group server.

### Struktur Menu

- `Tool Kits`
- `Tool Kits > Database`
- `Tool Kits > Optimization`
- `Tool Kits > Spam Protection`
- `Tool Kits > Rate Limit`
- `Tool Kits > Login Log`
- `Tool Kits > Hardening`
- `Tool Kits > SMTP`
- `Tool Kits > Monitoring`
- `Tool Kits > Cache`
- `Tool Kits > Themes Checker`
- `Tools > Tool Kits Access`

Catatan: sebagian menu bergantung pada status lisensi.

### Penjelasan Modul (Lebih Jelas)

#### 1) Database

Untuk backup, migrasi, dan maintenance data.
- `Export Database`: unduh dump SQL penuh.
- `Export Download (Preload)`: hasil SQL.gz dengan pair find/replace yang aman untuk data serialized.
- `Import Database`: impor `.sql` atau `.sql.gz` ke DB aktif.
- `Change Prefix`: rename prefix tabel + update key terkait, termasuk backup otomatis sebelum proses.
- `DB Cleanup`: bersihkan data tidak perlu agar DB lebih ringan.

Kapan dipakai:
- Pindah domain/staging ke production.
- Backup sebelum perubahan besar.
- Membersihkan sampah data periodik.

#### 2) Optimization

Untuk percepatan loading dan pengurangan beban frontend.
- `Hide Login`: ubah URL login default.
- `Minify`: kompres HTML/inline CSS/inline JS.
- `Auto WebP`: konversi image otomatis + generate untuk media lama.
- `Lazy Load`: tunda loading image/iframe/video.
- `Assets`: critical CSS, defer/preload CSS, preload font, font-display swap.
- `Uploads`: batasi ukuran file image.
- `User ID`: ubah ID user tertentu (aksi sensitif).

#### 3) Spam Protection

Untuk mengurangi bot submit/form abuse.
- `Captcha`: aktif/nonaktif captcha dan opsi di login form.
- `Anti-spam Contact`: honeypot + minimum submit delay untuk CF7.

#### 4) Rate Limit

Untuk membatasi brute force login.
- Atur window, jumlah percobaan, durasi lockout.
- Opsi block IP permanen saat gagal.
- Whitelist IP aman.
- Unblock IP via panel admin.

#### 5) Login Log

Untuk audit login.
- Catat login berhasil/gagal.
- Simpan waktu, IP, user agent.
- Filter status + clear log.
- Atur masa simpan log.

#### 6) Hardening

Untuk menurunkan attack surface WordPress.
- Disable file editor.
- Disable XML-RPC atau blok method berisiko.
- Disable REST user enumeration.
- Tambahkan security headers (termasuk HSTS).
- CSP strict mode (opsional).
- Sembunyikan signature header server/PHP.
- Force HttpOnly/Secure untuk cookie response.
- Disable WP-Cron dari pengaturan.
- URL parameter guard.
- HTTP methods filtering + block dangerous methods.
- Blok eksekusi PHP di uploads.
- WAF basic berbasis path/method.
- HTTP Basic Auth scope frontend/backend.
- CORS allowlist custom.
- Harden robots.txt.
- Block akses file tidak diinginkan.
- Check risiko DB host publik (indikasi eksposur MySQL 3306).

#### 7) SMTP

Untuk keandalan pengiriman email WordPress.
- Preset Gmail/Microsoft 365/Custom.
- Setting host, port, secure mode, auth.
- Kirim email test dan lihat log hasil.

#### 8) Monitoring

Untuk visibilitas operasional dan deteksi dini masalah.
- Configuration checks.
- Quick actions (cache clear, toggle update, wp-config permission).
- Realtime health monitor.
- 404 monitor + exclude rules.
- Healthcheck endpoint + secret key.
- Heartbeat terjadwal ke collector eksternal.

#### 9) Cache

Untuk kontrol cache dari satu tempat.
- Page cache file-based untuk pengunjung anonim.
- TTL dan path exclude.
- Purge page cache.
- Flush object cache.
- Reset OPcache.
- Flush fragment cache keys.

#### 10) Themes Checker

Untuk audit kualitas tema aktif.
- Ringkasan ukuran file dan aset.
- Daftar file terbesar.
- Deteksi duplicate PHP.
- Deteksi risky function pattern.

#### 11) Tool Kits Access

Untuk kontrol siapa yang boleh akses plugin.
- Role allowlist.
- IP allowlist.
- Lock settings.
- Security alerts via email.
- Audit log perubahan.
- Owner mode + pengaturan lisensi.

### Instalasi

1. Upload folder `tool-kits` ke `wp-content/plugins/`.
2. Aktifkan plugin dari menu `Plugins`.
3. Buka `Tool Kits` dan `Tools > Tool Kits Access`.
4. Konfigurasi lisensi, role akses, dan email alert sebelum dipakai di production.

### Best Practice

- Selalu backup sebelum `Import DB`, `Change Prefix`, atau `Change User ID`.
- Uji fitur sensitif (Hide Login, WAF, HTTP Auth, CORS, block IP) di staging.
- Simpan URL login custom jika Hide Login aktif.
- Gunakan SMTP test setelah ganti provider.

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
- SMTP presets + test email + test logs.
- Monitoring (checks, realtime health, 404 monitor, healthcheck, heartbeat).
- Cache controls (page cache, object flush, OPcache reset, fragment flush).
- Theme checker (summary, largest files, duplicate PHP, risky functions).
- Access controls (roles/IP allowlist, alerts, audit log, owner mode, license).

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
- `Tool Kits > SMTP`
- `Tool Kits > Monitoring`
- `Tool Kits > Cache`
- `Tool Kits > Themes Checker`
- `Tools > Tool Kits Access`

Note: some menus depend on license state.

### Module Breakdown (Clearer)

#### 1) Database

Used for backup, migration, and data maintenance.
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
- `Uploads`: image upload size limits.
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
