# WordPress Health Center

Widget **WordPress Health Center** tersedia di Dashboard WordPress. Halaman lengkapnya ada di **Tool Kits → System → Health Center**. Fitur mengikuti hak akses pengelola Tool Kits dan lisensi aktif.

Tekan **Run Manual Scan** pada widget atau halaman tersebut. Tombol **View Details** membuka laporan lengkap. Seluruh teks widget, metrik, status, dan pesan error menggunakan bahasa Inggris. Scan berjalan dalam empat tahap AJAX: snapshot server/cron/kompatibilitas, pemeriksaan homepage publik, request profil homepage, dan penyimpanan laporan. Membuka dashboard atau halaman Health Center hanya membaca hasil terakhir, tanpa menjalankan HTTP probe, audit, atau cron scanner. Sebelum scan pertama, widget menampilkan keadaan kosong tanpa angka contoh.

## Sumber angka

| Tampilan | Sumber |
|---|---|
| TTFB homepage | `CURLINFO_STARTTRANSFER_TIME` dari satu request publik ke `home_url('/')`, termasuk koneksi/TLS. Bukan total waktu unduh body. Header cache pada respons ditampilkan agar konteks HIT/MISS terlihat. |
| Database queries | `$wpdb->num_queries` pada shutdown request profil homepage yang terverifikasi, termasuk overhead kecil pengukuran. Bukan jumlah query request AJAX/admin. Tidak mengaktifkan SAVEQUERIES atau menyimpan SQL. |
| Slow HTTP requests | Jumlah request WP HTTP API selesai yang memerlukan ≥1 detik dalam request profil, sejak Tool Kits dimuat. Tidak mencakup request sebelum Tool Kits dimuat, API browser, atau pemanggilan cURL langsung oleh plugin lain. |
| Recorded failed jobs | Job berstatus `failed` yang masih tersimpan di Action Scheduler bila API telah diinisialisasi. Maksimal 1.000, dengan penanda batas bawah jika lebih banyak. Bukan riwayat seluruh WP-Cron atau kegagalan dalam rentang waktu tertentu. |
| Overdue cron (>15m) | Event WP-Cron yang jadwalnya tertinggal lebih dari 15 menit. Maksimal 5.000 event diperiksa, dengan penanda bila dibatasi. Tidak menjalankan atau memperbaiki cron. Terlambat tidak membuktikan eksekusi gagal. |
| Server | PHP dan WordPress aktif, `memory_limit` efektif, versi database, dan status persistent object cache saat scan. |

Query/HTTP memakai request profil terpisah yang melewati page cache melalui cookie `tk_health_center_session`. Profiler memerlukan token acak aktif selama 120 detik, metode GET, host/path homepage yang tepat, dan lisensi aktif. Token tidak diteruskan ke tujuan redirect. Setelah validasi, request memakai `DONOTCACHEPAGE`, header no-cache, dan tanda verifikasi respons. Pengunjung biasa tidak mendapat hook profiler atau lookup transient probe.

Jika loopback, cURL, TLS, proteksi hosting, autentikasi, proxy, atau cache menghalangi verifikasi, angka terkait ditampilkan **Not measured/Unavailable**, bukan nol. TTFB publik dan query tanpa cache merupakan dua sampel dengan tujuan berbeda. Hasil ini tidak sama dengan pengukuran seluruh situs atau pengalaman semua pengunjung. Situs yang homepage-nya redirect perlu memakai URL homepage final agar pemeriksaan HTML berhasil; scanner tidak mengikuti redirect.

## Pemeriksaan dan skor

* **Security:** HTTPS terkonfigurasi, tampilan debug, editor file dashboard, serta keberadaan header nosniff/CSP/referrer/frame protection pada respons homepage. Keberadaan CSP tidak membuktikan seluruh kebijakannya aman.
* **Performance:** TTFB, masa cache browser HTML, query database, HTTP lambat, persistent object cache, batas memori, cron terlambat, dan kegagalan Action Scheduler yang tercatat.
* **SEO:** satu title yang terisi, meta description, canonical absolut, dan directive indeks pada HTML homepage. Sampel tidak menilai seluruh artikel atau robots.txt.
* **Compatibility:** respons homepage serta syarat PHP/WordPress yang dideklarasikan plugin aktif dan tema. Kompatibilitas runtime keseluruhan dan metadata yang tidak dideklarasikan tidak dapat disimpulkan.

Setiap pemeriksaan terukur bernilai **passed=100, warning=50, critical=0**. Rekomendasi opsional (`info`) dikecualikan dari skor dan dihitung terpisah. Skor kategori adalah rata-ratanya; skor overall adalah rata-rata kategori yang memiliki data. Unknown dikecualikan, dihitung terpisah dan diberi catatan hasil belum lengkap. Kategori tanpa data menampilkan N/A. Batas awal TTFB: warning ≥0,8 detik, critical ≥1,8 detik. Query: warning ≥150, critical ≥300. Batas TTFB, query, dan memori dapat disesuaikan lewat **Warning thresholds**. Ini indikator diagnosis awal, bukan benchmark universal atau jaminan keamanan.

Laporan disimpan pada option non-autoload `tk_health_center_report`. Job memakai transient 10 menit dan lock satu scan/step, terikat admin yang memulainya. Hasil sebelumnya tetap tersedia selama scan baru berlangsung atau gagal. Tab perlu tetap terbuka hingga selesai. Jika terputus, sesi akan kedaluwarsa dalam 10 menit sebelum scan baru dapat dimulai. Option/transient internal scan tidak menyebabkan purge page cache.

## Bukti, Recheck, dan batas warning

Setiap hasil baru menyimpan **Current**, **Expected**, **Source**, dan waktu pengukuran.
Header/HTML publik ditampilkan sebagai teks yang di-escape dan dibatasi panjangnya;
tidak menyimpan body HTML lengkap atau SQL. Laporan lama tetap dapat dibuka, dengan
petunjuk melakukan Recheck untuk menangkap bukti yang belum tersedia.

**Recheck** memperbarui satu baris melalui dua tahap AJAX yang memakai nonce,
hak akses, lisensi, lock, dan sesi milik admin yang sama dengan scan lengkap.
Pemeriksaan lokal hanya mengumpulkan nilai untuk baris tersebut. Pemeriksaan header/SEO
memakai satu respons homepage publik untuk mengukur baris terpilih; pemeriksaan query/HTTP
memakai satu profil homepage terverifikasi. Target hanya ID pemeriksaan yang dikenal
dan sudah ada dalam laporan, tanpa menerima URL atau callback dari browser.

Hasil dan metrik terkait diperbarui hanya setelah pengukuran selesai. Nilai yang gagal
diverifikasi menjadi **Not measured**, bukan Passed atau nol. Baris lain, snapshot server,
dan waktu **Last full scan** tetap; waktu Recheck dicatat terpisah. Karena hasil dapat
berasal dari waktu berbeda, jalankan scan lengkap untuk snapshot menyeluruh yang baru.
Laporan penuh yang stale tetap meminta scan lengkap setelah Recheck individual.

**Warning thresholds** pada halaman lengkap mengatur:

- TTFB: warning >= 0,8 detik, critical >= 1,8 detik secara default.
- Query: warning >= 150, critical >= 300 secara default.
- Memori PHP: warning di bawah 256 MB, critical di bawah 128 MB secara default.
- Persistent object cache: opsional secara default. Inaktif ditampilkan sebagai
  **Recommended** dan tidak mengurangi skor. Tandai wajib untuk menghasilkan warning
  jika situs memang membutuhkannya.

Kebijakan disimpan non-autoload di `tk_health_center_settings`. Input divalidasi beserta
urutan batas warning/critical, dan perubahan ditolak saat scan sedang berjalan.
Menyimpan tidak mengubah PHP, cache, keamanan, atau klasifikasi hasil tersimpan;
perlu scan/Recheck untuk pengukuran baru. Setiap job memakai snapshot kebijakan sehingga
batasnya konsisten sampai selesai. Perubahan kebijakan menandai laporan lengkap lama stale.
Bukti tiap baris mempertahankan batas yang berlaku saat baris itu diukur.

Tabel Check Details dapat di-scroll horizontal pada layar kecil serta difokuskan lewat keyboard.
Scan dan Recheck tetap hanya berjalan atas tindakan pengguna; tidak menambahkan cron,
request pada tampilan dashboard, atau pengukuran pada kunjungan frontend biasa.

## Riwayat scan lengkap

**Scan history** di halaman lengkap menyimpan maksimal **10 snapshot scan lengkap**
dalam option non-autoload `tk_health_center_history`. Setiap snapshot berisi waktu,
ID scan terpisah dari token sesi, status pemeriksaan (maksimal 100), kebijakan threshold,
fingerprint konfigurasi, serta angka TTFB/query/HTTP lambat/cron/job gagal dan status cache.
HTML, SQL, header respons lengkap, detail mentah, dan token probe tidak diarsipkan.
Membuka dashboard tidak memuat riwayat; membuka halaman lengkap hanya membaca data tersimpan.

Snapshot dibuat setelah scan lengkap selesai. Recheck individual dan perubahan kebijakan
tidak mengubah snapshot lama. Saat riwayat pertama dibuat, laporan lama yang masih utuh
bisa menjadi baseline; laporan yang sudah bercampur hasil Recheck tidak dimasukkan
sebagai snapshot scan lengkap. Scan gagal tidak mengganti laporan atau menambah riwayat.

Buka **Scan history**, pilih **Earlier scan** dan **Later scan**, lalu tekan **Compare scans**.
Perbandingan hanya membaca snapshot yang dipilih, tanpa scan, HTTP probe, perubahan opsi,
atau modifikasi konfigurasi website. Request memakai nonce, POST, lisensi aktif, dan hak
akses pengelola. Pilihan yang sama/terbalik ditolak; snapshot yang sudah keluar dari retensi
memerlukan refresh pilihan.

- **Newly detected**: temuan warning/critical yang belum tercatat sebagai masalah sebelumnya.
- **Resolved**: temuan warning/critical yang sekarang terukur Passed dengan kebijakan sama.
- **Worsened / Improved, still open**: perubahan severity antara warning dan critical.
- **Needs verification**: hasil menjadi Not measured atau pemeriksaannya tidak lagi disertakan.
- **Changed**: perubahan lain, termasuk rekomendasi atau perubahan cakupan.

Jika threshold berubah atau kebijakan lama tidak tercatat, perubahan status ditampilkan
netral dan tidak dihitung sebagai perbaikan/penurunan terverifikasi. Perubahan fingerprint
konfigurasi juga diberi catatan. Perbedaan angka adalah dua sampel, bukan kesimpulan kausal:
TTFB menyertakan status cache, nilai yang tidak terukur tidak dianggap nol, dan jumlah
job/cron yang dibatasi sampel tidak menghasilkan selisih numerik pasti.

## PHP2Shell, prioritas, dan batas beban

Pemeriksaan **PHP2Shell runtime patch** membandingkan versi PHP yang menjalankan scan web dengan rilis perbaikan pertama: 8.2.35, 8.3.36, 8.4.27, dan 8.5.12. Sumber: [advisory resmi PHP 21 Oktober 2026](https://www.php.net/archive/2026.php). Build vendor/prerelease memerlukan verifikasi vendor; cabang baru yang belum terdaftar dan runtime CLI tidak dianggap lolos. Pemeriksaan versi tidak mendeteksi kompromi atau menjamin semua worker/host memakai build yang sama. Tidak ada payload eksploitasi, shell command, perubahan php.ini, lookup API CVE, atau scan file.

Widget menampilkan maksimal tiga tindakan prioritas dari laporan tersimpan, dengan Critical lebih dahulu. Tautan membuka baris pemeriksaan di halaman lengkap. Indikator AJAX menampilkan empat tahap scan dan membedakan sukses/gagal; klien dibatasi satu start dan maksimal empat step, tanpa polling/retry otomatis.

Laporan meminta scan ulang setelah 24 jam, bila cap waktu tidak valid, atau bila fingerprint konfigurasi berubah. Fingerprint hanya memakai nilai lokal kecil: versi runtime/WordPress/Tool Kits, batas memori dan konstanta relevan, nama tema, daftar plugin aktif, URL homepage, dan pilihan keamanan/SEO Tool Kits tertentu. Upgrade/aktivasi/deaktivasi plugin dan pergantian tema menulis penanda invalidasi sekali per kejadian. Tidak ada pemeriksaan isi file atau pendeteksian setiap perubahan konfigurasi eksternal; perubahan web server, proxy, atau file konfigurasi di luar nilai ini tetap memerlukan scan manual.

Heartbeat yang sudah ada membawa ringkasan hasil PHP tersimpan beserta sumber, waktu, dan penanda stale; tidak menjalankan scan atau menambah request. NexaMonitor menerima field opsional ini tanpa migrasi database dan hanya memberi badge hijau untuk laporan web yang masih berlaku. Klien lama tetap didukung. Seluruh tambahan tidak membuat cron baru, crawl situs, pemeriksaan berkala, enumerasi file, atau query database tabel besar. Dua request homepage pada scan Health Center manual yang sudah ada tetap berlaku.

## Verifikasi tambahan

```
php scripts/health-center-regression.php
php scripts/health-center-history-regression.php
php scripts/php-runtime-security-regression.php
node scripts/health-center-ui-regression.cjs
python3 scripts/health-center-http-regression.py
```

Tes HTTP memakai server lokal sementara, tanpa memuat wp-config atau database situs. Menjalankan tes tersebut membutuhkan izin membuka port loopback. Versi PHP dapat dipilih lewat `TK_PHP_BINARY` untuk tes HTTP.

Referensi API: [PHP cURL timing](https://www.php.net/manual/en/function.curl-getinfo.php), [pre_http_request](https://developer.wordpress.org/reference/hooks/pre_http_request/), [http_api_debug](https://developer.wordpress.org/reference/hooks/http_api_debug/), dan [Action Scheduler](https://actionscheduler.org/api/).
