# Rencana Tes Regresi Tool Kits (target v2.8.0)

Status: rencana, belum dikerjakan. Dibuat 6 November 2026 setelah rilis 2.7.80.

## Kenapa

Audit 2.7.80 menemukan bug serius hampir semuanya di modul yang tidak punya tes: hardening, firewall, rate limit,
login log, OTP, Hide Login, DB Migrate, dan helper IP. Suite bawaan (77 kasus) kuat di SEO, GEO, cache, captcha,
dan lisensi, tetapi modul keamanan yang berjalan di setiap request hanya diuji di bagian pinggir
(mis. `tk_firewall_blocked_ai_crawlers`, `tk_hardening_normalize_twemoji_svg_url`).

Modul tanpa satu pun panggilan dari `scripts/` saat ini:

| Area | Fungsi/modul tanpa tes |
| --- | --- |
| Login & akses | `tk_rate_limit_*`, `tk_otp_*`, `tk_hide_login_*`, `tk_toolkits_ip_allowed`, role-management, user-id-change |
| IP & firewall inti | `tk_ip_in_ranges`, pencocokan allow/blocklist firewall |
| Data | `tk_maybe_unserialize_replace`, seluruh db-migrate (export, import, live replace, ganti prefix) |
| Monitoring | `tk_tamper_*`, `tk_killswitch_*`, plugin-shield, seo-indexnow, malware/theme scanner |
| Front-end | cookie-consent, minify CSS, nama WebP |

## Prinsip

1. **Setiap tes harus gagal pada bug lamanya.** Buktikan dengan menjalankan kasus baru terhadap tag `v2.7.79`
   (`git -C <repo rilis> worktree add /tmp/tk-2779 v2.7.79`), lalu lulus pada kode sekarang.
2. **Ikuti pola suite yang ada:** satu file `scripts/<modul>-regression.php`, didaftarkan di
   `scripts/regression-tests.cjs`, satu proses per kasus, tanpa jaringan dan tanpa database.
3. **Lulus di PHP 7.4 dan 8.4** (`TK_PHP_BINARY=…/php74 node scripts/regression-tests.cjs`).
4. **Kasus browser dan MySQL masuk daftar `optional`**, bukan suite bawaan.
5. Uji perilaku yang terlihat (redirect, 403, nilai tersimpan, isi output), bukan detail implementasi.

## Fase 0: fondasi (ukuran S)

- **Fixture stub bersama** `scripts/wp-stub-fixture.php`: options dan transient di memori, penangkap `add_action`/`add_filter`,
  `wp_die` dan `wp_safe_redirect` yang melempar exception agar bisa di-assert, `$wpdb` palsu yang bisa diprogram,
  pengatur `$_SERVER`/`$_GET`/`$_POST`, dan `check($kondisi, $pesan)` dengan hitungan. Saat ini setiap file tes
  menulis stub sendiri; fixture ini mencegah duplikasi pada ±15 file baru.
- **Pindahkan tes stub dari audit 2.7.80** (37 skrip, ±300 pemeriksaan, saat ini di scratchpad sesi). Ganti path lokal
  (14 skrip) dengan `dirname(__DIR__)`, gunakan fixture bersama, dan pecah per modul sesuai fase di bawah.

## Fase 1: keamanan dan akses (prioritas tertinggi, ukuran M)

Risikonya: admin terkunci, pengunjung sah diblokir, atau proteksi bocor.

| Kasus baru | Skenario minimum |
| --- | --- |
| `hardening-core` | Default rekomendasi diterapkan sekali dan bisa dimatikan semua; gerbang `wp-cron.php` (localhost lolos, token `doing_cron` cocok lolos, token salah/kosong 403); blokir XML-RPC; timezone PHP tetap UTC |
| `client-ip` | `tk_get_ip`: Cloudflare range, proxy tepercaya, XFF palsu dari klien tidak dipercaya; `tk_ip_in_ranges`: CIDR v4/v6 valid, prefix rusak (`/`, `/abc`, `/-1`, `/33`) tidak cocok apa pun |
| `firewall-core` | Allowlist mengalahkan blocklist; blokir IP, CIDR, dan user agent; IPv6 beda penulisan tetap cocok; memakai IP pengunjung di belakang proxy; log dibatasi satu per IP per menit, 403 tetap |
| `login-protection` | Lockout progresif; auto-lock 24 jam setelah ambang; percobaan saat terkunci tidak dihitung; whitelist; daftar blokir maks 5.000; honey trap hanya mengunci pada POST; `Origin: null` jatuh ke cek Referer |
| `otp-security` | Kegagalan dihitung per user lintas resend dan login ulang; lockout setelah batas; kode benar mereset hitungan |
| `form-guard` | `?wc-api=` dan `wp-cron.php` dilewati; REST anonim tetap dijaga; balasan komentar dari dashboard dan REST oleh moderator lolos; timestamp bertanda tangan menolak nilai palsu dan tidak menulis DB saat render |
| `hide-login` | Slug kustom melayani login (status 200, global wp-login tersedia); `/admissions` tidak dialihkan; urutan flush rewrite saat slug berubah |
| `access-control` | Mode owner-only; ganti user ID owner ikut memperbarui `toolkits_owner_user_id`; handler Role Management menolak user di luar aturan akses; `profile.php` tetap terbuka |
| `audit-log-masking` | Password, secret, token, dan kunci lisensi/API tersamarkan; kunci biasa tetap tercatat |

## Fase 2: integritas data (ukuran M)

Risikonya: data rusak atau bocor saat migrasi.

| Kasus baru | Skenario minimum |
| --- | --- |
| `serialized-replace` | Matriks: string biasa, array, nested/double-serialized, object (tanpa instansiasi kelas), `__PHP_Incomplete_Class`, multibyte, data rusak dibiarkan, tanpa kecocokan = byte identik |
| `db-migrate-import` | Splitter: komentar mysqldump `/*!40101 */;`, `;` dan `--` di dalam string, string multi-baris, tidak pernah mengirim statement kosong |
| `db-migrate-export` | Pembacaan per potongan (primary key tunggal, gabungan, tanpa key) menghasilkan output sama dengan baca penuh; export terlindungi `.htaccess`, nama acak, terhapus setelah diunduh, sisa >1 hari dibersihkan |
| `db-migrate-replace` | Live replace memakai semua kolom primary key; baris tanpa perubahan tidak di-update; hanya tabel milik situs (single site dan multisite non super admin) |
| `db-maintenance` | Filter revisi hanya mengganti -1; upload limit tidak berlaku untuk import DB; file SQL unggahan dipindah ke folder terlindungi dan dibersihkan |

## Fase 3: fungsi dan performa (ukuran M)

| Kasus baru | Skenario minimum |
| --- | --- |
| `page-cache-policy` | Perluas `monitoring-cache-performance`: revisi/autosave/auto-draft/spam tidak purge; meta dari pengunjung dan cron tidak purge; stok WooCommerce purge; halaman tidak disimpan bila templating nonce CSP gagal |
| `license-resilience` | 4xx tanpa vonis server menyimpan receipt sampai kedaluwarsa aslinya; vonis `ok:false` menghapusnya; throttle saat host berbeda; receipt kedaluwarsa memicu validasi ulang |
| `updater-scope` | Sudah ada; tambah: cron boleh fetch, folder instalasi bernama lain dipertahankan |
| `monitoring-alerts` | Baseline tamper direset setelah update; email sekali per set perubahan; killswitch hanya memulihkan `hardening_*`; satu alert per admin baru; IndexNow berhenti retry pada 4xx permanen |
| `frontend-rewrites` | Minify CSS mempertahankan `.a :hover` dan string; minify JS identik dengan versi referensi; nama WebP per sumber; `data-loading` tidak tertimpa; filter shortener per host; filter non-Latin |
| `cookie-consent` (optional, browser) | Default denied, update dari cookie tersimpan, tombol preferensi muncul, modal bisa simpan/tutup |
| `dashboard-mysql` (optional, MySQL) | Query `GROUP BY` grafik serangan di MySQL sungguhan menghasilkan seri yang sama dengan 84 query lama. Ini satu-satunya perubahan 2.7.80 yang belum pernah dijalankan di database nyata |

## Selesai bila

- Setiap modul pada tabel "tanpa tes" di atas punya minimal satu kasus di suite bawaan.
- Suite bawaan naik dari 77 menjadi ±95 kasus dan lulus di PHP 7.4 dan 8.4.
- Setiap kasus baru terbukti gagal pada `v2.7.79` (dicatat di deskripsi commit), kecuali kasus untuk perilaku yang memang baru.
- Total waktu suite bawaan tetap di bawah ±2 menit.

## Keputusan terbuka

- **Menjalankan suite di GitHub Actions saat rilis.** Commit `5ecf67e` sengaja melewatinya di runner GitHub. Usulan: jalankan
  hanya kasus PHP (tanpa browser) di PHP 7.4 sebelum ZIP dibangun, supaya tag dengan tes merah tidak terbit.

## Di luar cakupan

Uji end-to-end di WordPress sungguhan, uji beban, dan fitur baru (backup terjadwal, TOTP, Elementor Dynamic Tags).
