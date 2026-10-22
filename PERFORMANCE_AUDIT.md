# Audit performa Tool Kits 2.7.25

Tanggal: 17 Oktober 2026 (Asia/Jakarta). Implementasi disiapkan di folder kerja saja; tidak ada push, sinkronisasi ke instalasi WordPress, atau deploy produksi.

## Baseline yang diperiksa

Folder kerja awal masih 2.7.23. Repo rilis lokal yang bersih sudah 2.7.24, HEAD `48016b104a9d52f5b195662da141cb9a299179a1`, setelah commit `793f0d2`. Kedua commit diperiksa dan API-nya dipertahankan. Semua perbandingan akhir memakai snapshot **2.7.24 terbaru**, bukan folder kerja lama. Baseline lulus 44/44 kasus bawaan, 12 pemeriksaan request-options, dan 9 pemeriksaan frontend-performance.

2.7.24 sudah memiliki cache konfigurasi per blog selama request, filter pengecualian metadata, filter versi asset, deferred monitoring, dan pembatasan remote updater. Pengurangan 1.000 pembacaan konfigurasi menjadi satu sudah ada di baseline dan tidak diklaim sebagai hasil baru.

## Penyebab, perubahan, dan dampak

| Area | Temuan | Perbaikan dan dampak |
| --- | --- | --- |
| Konfigurasi | Invalidasi generik priority 10 terlambat untuk pembaca pada dynamic option hooks; beberapa pembaca internal masih memakai get_option langsung | Invalidasi setelah cache WordPress berubah, pada dynamic add/update/delete dan hook generik lebih awal. Pembaca internal memakai tk_get_options. Nilai tersaring/hasil write gagal mengikuti WordPress. Helper publik 2.7.24 tetap tersedia; validasi signature dan URL situs tetap dilakukan. |
| Multisite | Flag purge request tunggal dan direktori bersama tidak memisahkan blog | Antrean blog ID, switch/restore context saat shutdown, direktori page/blog-ID, dan blog ID pada key. Tidak memindai semua situs pada frontend. Memoisasi keberadaan tabel juga memakai nama tabel per blog. |
| Invalidasi | Beberapa perubahan komentar/taxonomy tidak tercakup; laporan/statistik dapat memicu purge | Tambah hook komentar, relasi taxonomy, dan term metadata. Filter lama tetap bekerja, dengan daftar metadata/config turunan yang dapat diperluas. FAQ, pengaturan render dan konten asli tetap memicu purge. External purge digabung per blog/request, termasuk perlindungan pemanggilan berulang dari integrasi. |
| Regenerasi | Semua miss dapat menulis file sementara yang sama; render lama dapat terbit setelah purge | Lock file atomik dengan maksimum 256 shard per blog; hanya satu writer per shard. Pembaca boleh memakai stale maksimal 30 detik saat writer aktif. File sementara unik, rename atomik, dan generation guard untuk purge selama render. Flush/clean parsial tidak disimpan. Set-Cookie dan Content-Type non-HTML mencegah penyimpanan. |
| Asset | Versi theme diganti hash bahkan ketika pemilik sudah memberi versi; cold hash menulis transient per file | Pertahankan ver eksplisit, termasuk versi terdaftar yang kebetulan sama dengan versi WordPress. Fallback asset theme tanpa versi memakai mtime + ukuran file, dicache per request. Tidak ada hashing/transient pada jalur otomatis tersebut. Filter tk_assets_version_theme_assets dipertahankan dan setting fallback tersedia. Helper hash lama tetap tersedia demi kompatibilitas. |
| HTML/gambar | CLS dan LCP memindai seluruh HTML terpisah; file gambar dibaca walau width/height lengkap; prioritas eksplisit dapat tertimpa | Satu traversal untuk dimension/priority hints, memo ukuran gambar, tanpa akses file bila dimensi lengkap. Hormati loading, fetchpriority, decoding, srcset, sizes dan marker lazy pihak lain. Tidak lagi menambah preload src pertama yang dapat bertentangan dengan responsive image. Prioritas untuk gambar tanpa atribut tetap diberikan. |
| Integrasi optimizer | Optimizer lain perlu bisa mengambil alih HTML tanpa mematikan optimasi upload | Tambahkan filter per proses HTML. Lewati buffer asset jika seluruh pemrosesnya mati. Hook upload dan encoding tidak diubah. Native image delivery dan opsi original-only srcset yang sudah ada tetap berlaku sesuai pengaturan. |
| Admin/frontend | Script Image Optimizer dimuat di setiap halaman Tool Kits; bootstrap lazy ikut dimuat saat hanya native image loading digunakan | Script workspace hanya pada Images. Bootstrap lazy tidak dimuat bila lazy iframe/video dan daftar delay script tidak diperlukan. CSS admin bersama tetap dipertahankan karena dipakai integrasi menu. |
| Query | Laporan maksimal 500 attachment dapat mengambil post/meta satu per satu | Prime post dan metadata cache sekaligus sebelum loop. Batas laporan tetap 500 dan total library tetap akurat; tidak mengubah pagination/queue. |
| Log/HTTP | Login/event logging dapat menunggu geolocation sampai dua endpoint sinkron ketika cache kosong | Logging hanya membaca geolocation yang sudah dicache. Security-event enrichment tetap pada maintenance terjadwal; loop diberi budget 10 detik antar-IP (satu lookup yang sedang berjalan masih dapat menambah hingga sekitar 8 detik). Alert keamanan eksplisit tetap memakai jalur pengiriman yang ada. Lokasi log dapat sementara Unknown. |
| Retensi | DELETE semua event lama sekaligus dapat mengunci banyak row | Pertahankan kebijakan retensi, hapus paling banyak 1.000 row per job, lanjutkan satu menit kemudian sampai backlog habis. Tidak menambah indeks atau mengubah format tabel. |

## Pengukuran akhir

PHP CLI 8.4.25; source Option API/Hook API WordPress lokal mengidentifikasi versi 7.0. Sembilan proses PHP terpisah per skenario/versi; angka di bawah adalah median. Database dan object cache memakai adapter di memori, data sintetis. Tidak memuat wp-load.php, plugin situs, atau mengirim HTTP. Angka waktu PHP meliputi API WordPress dan adapter, bukan waktu end-to-end halaman.

| Metrik | Konfigurasi 2.7.24 → 2.7.25 | Asset 2.7.24 → 2.7.25 |
| --- | --- | --- |
| Skenario | 1.000 pembacaan, option 1,088,235 byte | 64 asset theme tanpa versi, masing-masing 66.000 byte; transient cache dingin |
| Waktu PHP | 0.203 → 0.195 ms | 19.087 → 0.843 ms |
| Pembacaan/deserialisasi tk_options | 1 → 1 | 0 → 1 |
| Operasi adapter query | 1 → 1 | 192 → 1 |
| Waktu SQL nyata | Tidak tersedia | Tidak tersedia |
| Tambahan peak memory | 1,538,848 → 1,538,848 byte | 224,928 → 20,360 byte |
| Memori dipertahankan setelah operasi | 1,518,600 → 1,518,600 byte | 213,544 → 16,192 byte |
| Penulisan transient | 0 → 0 | 64 → 0 |
| Panggilan HTTP | 0 → 0 | 0 → 0 |

Selisih kecil waktu konfigurasi bukan bukti peningkatan: algoritme dan jumlah deserialisasi tetap sama. Fixture asset menunjukkan hilangnya biaya hash/transient dingin; cache hangat, persistent object cache, jumlah asset, disk, dan beban database nyata akan menghasilkan angka berbeda. Tidak ada klaim persentase peningkatan situs produksi.

Data mentah seluruh run dan metode ada di `scripts/performance-benchmark-results.json`. Reproduksi satu run dari folder plugin:

```sh
TK_WP_ROOT=/path/to/wordpress php scripts/performance-benchmark.php /path/to/baseline/tool-kits config
TK_WP_ROOT=/path/to/wordpress php scripts/performance-benchmark.php /path/to/baseline/tool-kits assets
TK_WP_ROOT=/path/to/wordpress php scripts/performance-benchmark.php . config
TK_WP_ROOT=/path/to/wordpress php scripts/performance-benchmark.php . assets
```

## Pengujian dan batas lingkungan

- Suite default kandidat: 45/45 lulus pada PHP 8.4.25 dan PHP 7.4.33; suite mencakup regresi monitoring, updater, lisensi, SEO, lazy media, dan integrasi lama.
- request-options: 12/12 pemeriksaan lulus; frontend-performance: 9/9 lulus (termasuk suite default).
- performance-core: 47/47 pemeriksaan lulus pada PHP 8.4 dan 7.4, memakai Option/Hook API WordPress asli dan adapter SQL/cache. Meliputi add/update/delete, callback langsung, write gagal, filter write, multisite switch/restore, signature dan site mismatch, invalidasi konten/turunan, writer contention, purge saat render, partial flush, stale-window melalui proses PHP terpisah, asset, gambar, retensi, dan external purge.
- Image Optimizer: 52/52 pemeriksaan lulus dengan Imagick dan HTML parser WordPress asli. Meliputi kualitas/pixel, ICC, transparansi, source protection, WebP, srcset, frontend tanpa encoding, worker lock dan stop/resume. Loader fixture diperbarui agar dapat memuat dependensi UTF-8 parser WordPress lokal.
- Fallback tanpa Imagick: lulus pada PHP 8.2.0 dengan GD.
- Lint 79 file PHP: lulus pada PHP 8.4 dan 7.4; syntax runner JavaScript lulus.
- Browser monitoring-ui dicoba tetapi tidak dapat berjalan karena modul Playwright tidak tersedia. Tidak ada klaim pengujian browser visual/live.
- Percobaan audit database read-only, termasuk socket MAMP, gagal terhubung meski di luar sandbox. Ukuran autoload option/tabel produksi, query count/time SQL nyata, EXPLAIN, dan request frontend WordPress penuh belum terukur.
- Multisite diuji sebagai pergantian blog dengan API asli dan adapter terpisah, bukan instalasi multisite/database hidup. Tidak menguji semua theme/plugin pihak ketiga atau object cache Redis/Memcached sebenarnya.
- Lock memakai flock filesystem lokal. Cold miss yang tidak mendapat lock tetap merender respons dinamis tanpa menulis cache; jadi tidak menjanjikan deduplikasi seluruh render pada cold start. Grace stale membantu refresh cache panas. Storage terdistribusi perlu uji semantik lock sendiri.
- Optimizer HTML berbeda masih memiliki tahap masing-masing agar urutan buffering dengan CSP, image delivery dan minify tetap kompatibel. Penggabungan dilakukan pada dua tahap image hints yang redundan; tidak ada klaim hanya satu pass untuk seluruh plugin.

Perintah tambahan:

```sh
node scripts/regression-tests.cjs
TK_WP_ROOT=/path/to/wordpress node scripts/regression-tests.cjs request-options
TK_WP_ROOT=/path/to/wordpress node scripts/regression-tests.cjs performance-core
node scripts/regression-tests.cjs image-optimizer /path/to/wordpress
TK_PHP_BINARY=/path/to/gd-only-php node scripts/regression-tests.cjs image-optimizer-no-imagick
```

## Keputusan penyimpanan dan indeks

Tetap gunakan format tk_options yang ada. Fixture 1,09 MB menunjukkan bahwa laporan besar tetap menahan sekitar 1,52 MB memori request meski hanya dideserialisasi sekali. Ukuran laporan nyata/autoload belum terukur, sehingga belum ada dasar untuk migrasi ke option terpisah atau tabel baru. tk_options tetap disimpan dengan autoload=false pada jalur update plugin; perilaku WordPress untuk write tanpa perubahan tetap berlaku. Tidak menjalankan UPDATE SQL langsung untuk mengubah autoload situs.

Log 404 dibatasi 200 entri dan audit konfigurasi 200 entri; security events memiliki retensi terjadwal dan indeks time yang sudah ada. Tabel login log lama tetap memerlukan evaluasi retensi; tidak menghapus log di luar kebijakan yang sudah berlaku. Tidak menambah indeks tanpa EXPLAIN dan ukuran data nyata. Laporan attachment tetap dibatasi 500; migrasi/cursor besar tidak diperlukan untuk perubahan ini.

Untuk pengukuran lanjutan di staging: ukur OCTET_LENGTH tk_options, total option autoloaded (`yes`, `on`, `auto-on`, `auto` sesuai versi WP), ukuran setiap report/log, jumlah/usia row log, serta EXPLAIN query daftar login dan retensi. Ambil waktu PHP, peak memory, jumlah/waktu query dan HTTP pada URL serta data yang sama dengan cache dingin/hangat. Bila pemisahan report dibutuhkan, gunakan dual-read dengan fallback ke tk_options, batch resumable per blog, dan salinan legacy sampai rollback terverifikasi; belum ada migrasi tersebut pada rilis ini.

## API integrasi dan kompatibilitas

Helper publik `tk_request_options_cache`, `tk_get_options`, `tk_get_option`, `tk_update_option`, dan `tk_clear_request_options_cache` tetap tersedia. Pemanggil yang mengubah sumber konfigurasi di luar Option API harus memanggil tk_clear_request_options_cache setelah perubahan. Cache bersifat snapshot request, bukan cache lintas request.

Filter lama tetap: `tk_assets_version_theme_assets($enabled, $src)` dan `tk_page_cache_purge_for_meta_change($purge, $meta_key, $post_id)`.

Filter baru:

- `tk_page_cache_ignored_post_meta_keys($keys, $post_id)` untuk metadata hasil turunan; metadata konten tidak boleh dikecualikan sembarangan.
- `tk_page_cache_ignored_config_keys($keys)` untuk laporan/statistik non-render.
- `tk_page_cache_ignore_option_change($ignore, $option)` untuk option tambahan milik integrasi.
- `tk_assets_html_optimization_enabled`, `tk_lazy_html_optimization_enabled`, `tk_image_opt_html_rewrite_enabled`, `tk_minify_html_optimization_enabled`: boolean sebelum buffer terkait dimulai. Mengembalikan false untuk integrasi yang sudah menangani HTML tidak mematikan encoding upload atau hook native image.

Settings Asset Optimization menampilkan fallback versi theme dan prioritas gambar pertama tanpa atribut. Semua fitur lain tetap mengikuti konfigurasi masing-masing. Prioritas eksplisit yang saling bertentangan seperti loading=lazy dan fetchpriority=high dipertahankan keduanya; keputusan akhir tetap milik theme/browser. PHP minimum tetap 7.4. Tidak ada modifikasi khusus situs/theme.

## Upgrade dan rollback

1. Simpan backup folder plugin dan database sebelum pemasangan. Rilis baru: `dist/tool-kits-2.7.25.zip`. ZIP rollback dari baseline bersih: `dist/tool-kits-2.7.24-rollback.zip`.
2. Setelah pemasangan manual, purge cache halaman/CDN dan pastikan WP-Cron berjalan. Tidak ada deploy yang dijalankan dalam audit ini.
3. Multisite memakai direktori baru `wp-content/cache/tool-kits/page/blog-ID`. HTML legacy di direktori page bersama tidak digunakan oleh versi baru. Bersihkan file HTML legacy saat maintenance agar tidak tertinggal; jangan menyentuh upload atau direktori cache milik plugin lain.
4. Untuk rollback, nonaktifkan Tool Kits, hapus event `tk_security_events_cleanup_batch` bila masih terjadwal, ganti folder tool-kits dengan ZIP 2.7.24, lalu bersihkan direktori cache Tool Kits page (termasuk direktori blog dan HTML legacy), object cache/CDN, dan aktifkan kembali. Cache dibangun ulang dengan key versi lama. Pengaturan baru hanya berupa key tambahan yang diabaikan versi lama.
5. Tidak ada migrasi format/indeks sehingga tidak ada reverse migration. Retensi yang telah menghapus event kedaluwarsa mengikuti kebijakan lama; pemulihan row tersebut membutuhkan backup database jika memang diinginkan.

ZIP dibangun dengan `TK_SKIP_SYNC=1` agar tidak menyalin perubahan ke instalasi WordPress/repo rilis. Source kerja, data benchmark dan pengujian tetap tersedia di folder plugin; script pengembangan dikecualikan dari paket instalasi.
