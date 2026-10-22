# Perbaikan cache Tool Kits 2.7.27

HTML di browser sekarang harus divalidasi kembali pada setiap kunjungan. Cache
HTML di server tetap memakai TTL pengaturan Page Cache; CSS, JavaScript, gambar
dan font tetap mendapat aturan browser cache satu tahun.

## Perubahan

1. `includes/security-hardening.php` menambahkan `ExpiresByType` nol detik untuk
   `text/html`, `application/json`, `application/xml` dan `text/xml`. Pemanggil
   `tk_hardening_maybe_apply_root_server_rules()` sudah terdaftar pada `init`
   prioritas 1 ketika Server-aware Hardening aktif. Hash snippet berubah setelah
   update, sehingga blok `Tool Kits Root` di `.htaccess` ditulis ulang otomatis.
   Nginx tetap menggunakan aturan `expires 1y` hanya pada ekstensi aset, tanpa
   HTML, JSON atau XML. Snippet nginx tetap perlu diterapkan melalui konfigurasi
   server seperti sebelumnya.
2. `includes/cache-runtime.php` dipakai bersama oleh page cache normal dan drop-in.
   File HTML di disk tidak menyimpan atribut nonce. File `.html.meta.json`
   menyimpan template CSP, header keamanan, hash HTML, dan generasi purge.
   Setiap HIT memasang nonce request yang sama pada header CSP serta elemen
   script/style. MISS juga menyesuaikan header/body sebelum dikirim. Komentar,
   isi JavaScript/CSS dan teks atribut lain tidak diubah oleh penghapus nonce.
3. `includes/cache-early.php` mengelola snapshot PHP di
   `wp-content/cache/tool-kits/page-cache-config.php`, drop-in
   `wp-content/advanced-cache.php`, dan blok WP_CACHE milik Tool Kits di
   `wp-config.php`. Isi yang sama tidak ditulis ulang. Sinkronisasi berjalan pada
   `wp_loaded`, simpan setting, dan setelah perubahan opsi terkait. Snapshot
   lisensi berlaku paling lama enam jam atau sampai tanggal lisensi berakhir;
   snapshot kedaluwarsa meneruskan request ke WordPress untuk diperbarui.
4. Drop-in tidak memakai fungsi WordPress dan mengirim `HIT-EARLY` hanya untuk
   GET publik dengan file valid yang belum melewati TTL. POST, HEAD, Authorization,
   range request, query dinamis, login/password/comment/cart/session cookies,
   endpoint dinamis, dan path pengecualian meneruskan request ke WordPress.
   Query `utm_*`, `gclid`, `fbclid`, dan `msclkid` diizinkan, dengan URI asli tetap
   menjadi bagian key. Key keduanya adalah `md5(scheme|host|REQUEST_URI)`;
   scheme mengikuti `is_ssl()` termasuk perilaku `HTTPS=off` pada port 443.
   Direktori cache multisite tetap terpisah per blog.
5. Metadata header memakai daftar yang dibatasi: Content-Type, CSP/report-only,
   X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy,
   Permissions-Policy, Strict-Transport-Security, Cross-Origin-*, X-Robots-Tag,
   dan Vary. Set-Cookie tidak pernah disimpan/dikirim ulang. Respons private,
   no-store/no-cache, non-HTML, cookie, atau Vary selain Accept-Encoding tidak
   disimpan. HIT/MISS cache HTML mengirim `max-age=0, must-revalidate` dan Expires
   lampau; ETag/Last-Modified tidak dipakai ulang karena nonce berubah.

## Integrasi nonce tema

Jika tema memiliki generator nonce sendiri, berikan nonce yang sama dengan
header CSP tema. Generator harus stabil selama satu request:

```php
add_filter('tk_page_cache_csp_nonce', function ($nonce) {
    return my_theme_csp_nonce();
});
```

Early HIT membuat nonce baru tanpa memanggil tema. Kebijakan CSP yang tersimpan
menjadi template; token nonce diganti bersama atribut script/style. Jika tema
memerlukan hook WordPress tambahan pada setiap request, matikan early cache
melalui filter berikut dan gunakan fallback normal:

```php
add_filter('tk_page_cache_early_config', function ($config) {
    $config['enabled'] = false;
    return $config;
});
```

## Pemasangan dan batasan

- Aktifkan Page Cache. Tool Kits memasang drop-in hanya jika belum ada atau
  marker menunjukkan drop-in milik Tool Kits. Drop-in milik LiteSpeed Cache atau
  plugin lain tetap dipertahankan; notice menjelaskan fallback yang digunakan.
- WP_CACHE ditambahkan hanya jika belum ada definisinya di wp-config.php dan
  file writable. Definisi milik pengguna tidak ditimpa. Jika perlu, pengguna
  menetapkan `define('WP_CACHE', true);` sebelum pemanggilan wp-settings.php.
- Early cache memakai fallback normal saat multisite, HTTP authentication, atau
  firewall PHP Tool Kits aktif agar hook keamanan tetap diperiksa. Plugin lain
  yang membutuhkan hook autentikasi/keamanan per request dapat menonaktifkan
  early cache melalui filter di atas.
- Menonaktifkan Page Cache, lisensi tidak aktif, atau deaktivasi plugin menghapus
  config/drop-in milik Tool Kits dan blok WP_CACHE yang ditambahkan Tool Kits.
  Hook shutdown yang tertunda tidak memasangnya kembali saat deaktivasi.
- Perubahan timestamp file plugin membuat early cache meneruskan request ke WP
  setelah update. Perubahan versi snapshot juga membersihkan cache sebelumnya.
  Cache HTML lama tanpa metadata tidak dilayani dan akan dibuat ulang.
- Purge menghapus HTML dan metadata di bawah kunci generasi yang sama. Publikasi
  atomik dan hash mencegah pasangan HTML/header yang berbeda ikut dilayani.
- Header baru tidak dapat membatalkan salinan 30 hari yang sudah telanjur fresh
  di browser. Untuk pemeriksaan setelah update gunakan request baru/force reload
  dan bersihkan cache LiteSpeed/CDN yang masih menyimpan respons lama.

## Validasi

Tes tidak memuat wp-config.php asli, database situs, atau plugin terpasang:

```sh
php scripts/cache-upgrade-regression.php
python3 scripts/cache-http-regression.py
TK_WP_ROOT=/path/to/wordpress php scripts/performance-regression.php
php scripts/frontend-performance-regression.php
```

Tes HTTP menjalankan PHP server sementara di localhost. Bila Chrome tersedia,
tes memeriksa eksekusi dua inline script, penerapan inline CSS, dan log CSP pada
HIT-EARLY. `TK_CHROME` dapat menunjuk executable Chrome yang berbeda.

TTFB localhost mengukur fixture dengan bootstrap buatan 180 ms untuk membuktikan
bagian aplikasi dilewati. Angka tersebut bukan benchmark WordPress produksi.
Target <150 ms pada thinkegghead.com tetap harus diukur di Hostinger/LiteSpeed
setelah paket dipasang. Tidak ada deploy, commit, atau push dari pekerjaan ini.

Pemeriksaan server setelah pemasangan (gunakan GET untuk menguji early HIT):

```sh
curl -I https://thinkegghead.com/
curl -sS -D /tmp/tk-headers.txt -o /tmp/tk-page.html \
  -w 'TTFB=%{time_starttransfer}\n' https://thinkegghead.com/
# Ulangi GET; harapkan X-Tool-Kits-Cache: HIT-EARLY jika drop-in aktif.
```

Referensi semantik:
[Apache mod_expires](https://httpd.apache.org/docs/2.4/mod/mod_expires.html),
[WordPress is_ssl](https://developer.wordpress.org/reference/functions/is_ssl/).
