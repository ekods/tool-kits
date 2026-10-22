# License security

Tool Kits memverifikasi bukti aktivasi dari NexaMonitor menggunakan RSA 3072 bit
dan SHA-256. WordPress hanya membawa public key. `AUTH_KEY`, collector token,
`license_status=valid`, dan signature HMAC versi lama tidak dapat menjadi bukti
aktivasi pada versi ini. Sistem tidak mengandalkan penyamaran kode PHP.

## Model perlindungan

- Receipt terikat ke produk `tool-kits`, SHA-256 license key, URL situs (termasuk
  subdirektori), ID instalasi, jenis lisensi, dan tanggal kedaluwarsa lisensi.
- Tiap aktivasi/refresh mengirim challenge acak 256 bit. Server memasukkannya
  ke payload bertanda tangan; respons lama tidak dapat memenuhi challenge baru.
- Masa berlaku receipt maksimal 24 jam, dibatasi juga oleh kedaluwarsa lisensi.
  Clock skew ke depan maksimal lima menit. Memajukan `license_last_checked`
  atau memodifikasi tanggal/status lokal tidak memperpanjang receipt.
- Refresh normal setiap enam jam, melalui WP-Cron per jam dan akses admin.
  Cron ini terpisah dari opsi heartbeat. Saat koneksi gagal, HTTP 429 atau 5xx,
  receipt yang sudah sah boleh tetap dipakai sampai tenggat aslinya; retry setelah
  lima menit ketika validasi berikutnya berjalan. Tidak ada aktivasi offline baru.
- Penolakan lisensi atau respons aktivasi tanpa signature sah mematikan fitur.
  Tidak ada fallback ke HMAC lokal, public key dari respons, atau key dari database.
- Semua modul yang sebelumnya mengandalkan status mentah memakai pemeriksaan
  terpusat. Pengecualian alat database yang sudah tersedia tetap mengikuti aturan
  aksesnya sendiri. Request dengan host berbeda dari URL situs ditolak untuk fitur.
- Early cache dibatasi `min(enam jam, kedaluwarsa receipt)` dan dihapus saat
  lisensi tidak sah. Revocation membutuhkan refresh ke server; hasil tidak instan
  ketika situs hanya melayani early-cache HIT. Batas offline terburuk adalah
  masa berlaku receipt, maksimal 24 jam. WP-Cron harus dijalankan secara andal.
- Endpoint lisensi wajib HTTPS, verifikasi sertifikat aktif, tanpa redirect,
  respons maksimal 64 KiB, serta host dan path issuer yang disetujui. Constant
  lama `TK_ALLOW_INSECURE_LICENSE_SSL` / `TK_ALLOW_CUSTOM_LICENSE_SERVER` tidak
  membebaskan pemeriksaan ini. Constant `TK_LICENSE_SERVER_URL` boleh menetapkan
  issuer HTTPS sendiri, tetapi public key-nya tetap harus dipin lewat berkas tepercaya.
- API membatasi 30 request per menit per IP (termasuk percobaan gagal, seluruh
  alias endpoint), mengunci baris lisensi pada transaksi aktivasi, dan memakai
  `Cache-Control: private, no-store`. Shared collector token hanya autentikasi
  request; token itu tidak dapat menandatangani receipt.
- License key baru memakai 192 bit acak. Key lama tetap berlaku.

Pemilik akses tulis file PHP, administrator server issuer, atau pemegang private
key tetap dapat mengubah/menerbitkan lisensi. PHP yang berjalan di server milik
pengguna tidak bisa dibuat mustahil dimodifikasi. SQL injection tetap dapat
merusak WordPress; perubahan ini membatasi pemalsuan status lisensi, bukan
menggantikan perbaikan kerentanan aplikasi dan perlindungan server.

## Urutan deployment (server terlebih dahulu)

1. Deploy `ToolkitsLicenseSigner`, `ToolkitsLicenseKey`, controller API, middleware,
   route lisensi dan `config/toolkits.php` dari NexaMonitor. Pertahankan perubahan
   aplikasi lain yang sedang dikerjakan. Tidak perlu migrasi database baru.
2. Provision signing key **satu kali**. Default lokasi di luar webroot adalah
   `storage/app/private/toolkits/license-signing.pem`, mode `0600` dan direktori
   baru `0700`. Dapat dioverride dengan `TOOLKITS_LICENSE_PRIVATE_KEY_PATH`.
   Backup secara aman; jangan upload key ke repo, ZIP plugin, database WordPress,
   atau folder `public/`. Lindungi juga backup, akun hosting dan akses server.

   ```sh
   php artisan toolkits:license-key --public-output=/secure/export/license-public-keys.php
   ```

   Command tidak mengganti private key yang sudah ada. Public output berisi
   public key saja. Pastikan direktori output sudah ada. Jangan mengandalkan
   key yang baru dihasilkan di server produksi untuk plugin yang sudah dikemas
   dengan public key lain.
3. Salin public output ke `includes/license-public-keys.php` di sumber plugin,
   lalu kemas plugin. Public key ID harus sama dengan keluaran command server.
   Untuk deployment lokal yang sudah disiapkan pada pengerjaan ini, private key
   berada di default lokasi di atas dan public key ID adalah
   `aa96b7945bf211f4`. Private key ini belum disalin/deploy ke server produksi.
   Pilih salah satu: transfer key lokal lewat kanal privat yang aman ke server,
   atau provision di server lalu ganti pin plugin dengan public output server
   **sebelum** build. Jangan menjalankan keduanya tanpa mencocokkan key.
4. Perbarui config cache Laravel sesuai prosedur deployment dan reload worker
   yang menyimpan konfigurasi lama. Uji aktivasi protocol 1 dengan challenge
   baru menggunakan lisensi uji terpisah. Pastikan respons `receipt` terverifikasi,
   bukan hanya HTTP 200. Server tanpa signing key mengembalikan 503 untuk protocol 1.
5. Baru distribusikan plugin. Lisensi HMAC lama diverifikasi ulang saat admin
   membuka Tool Kits atau job refresh berjalan. Premium features hanya aktif
   setelah receipt server diterima. Jika WP-Cron dinonaktifkan, jalankan cron
   WordPress dari scheduler server. Purge full-page cache/CDN saat rollout agar
   pengunjung tidak menerima HTML lama dari cache pihak ketiga.

Server tetap melayani klien lama tanpa `protocol` untuk migrasi bertahap; klien
tersebut belum mendapatkan proteksi receipt. Plugin baru selalu meminta protocol
1 dan menolak respons tanpa receipt. Endpoint reachability hanya mengetes koneksi,
bukan membuktikan aktivasi atau kelengkapan signing key.

## Rotasi key

Distribusikan pin public key baru bersama pin lama terlebih dahulu (array key ID
ke PEM), kemudian pindahkan signer server ke private key baru. Simpan pin lama
sampai semua receipt lama habis dan klien memakai paket yang memuat pin baru.
Jika private key bocor, hapus pin lama lewat pembaruan plugin; jangan menunggu
receipt habis. Plugin lama yang belum membawa pin baru perlu diperbarui dahulu.

Instalasi terkelola dapat menetapkan `TK_LICENSE_PUBLIC_KEYS_FILE` di wp-config
untuk menunjuk file PHP public pins. Hanya operator dengan akses file konfigurasi
yang boleh mengubahnya; tidak ada pengaturan pin lewat admin form atau filter WP.

## Validasi

- `node scripts/regression-tests.cjs license-security`: serangan terhadap receipt,
  state lokal, timestamp, replay, metadata unsigned, endpoint dan kegagalan server.
- Suite penuh juga menguji scanner dan Health Center; UI widget tetap English.
- `scripts/cache-upgrade-regression.php`: early cache tidak melewati tenggat receipt.
- Laravel `tests/Feature/ToolkitsLicenseSecurityTest.php`: signer server diverifikasi
  oleh verifier plugin yang sebenarnya, termasuk expiry, URL, ID instalasi,
  autentikasi, nonce, kegagalan signer, revocation dan rate limit.
- Uji PHP 7.4 dan PHP 8.4 untuk plugin; aplikasi NexaMonitor memakai PHP 8.3+.

Implementasi memakai API resmi [PHP OpenSSL signing](https://www.php.net/openssl-sign),
[PHP OpenSSL verification](https://www.php.net/openssl-verify) dan
[WordPress safe HTTP](https://developer.wordpress.org/reference/functions/wp_safe_remote_post/).
