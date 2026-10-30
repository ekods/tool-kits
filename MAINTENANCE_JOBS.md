# Cleanup, alert queue, dan heartbeat delivery

## DB Cleanup

Tool Kits → Database → Cleanup memproses paling banyak 50 kandidat per request,
dengan budget sekitar tiga detik di antara item. Satu pemanggilan hook WordPress
atau OPTIMIZE TABLE masih dapat berjalan lebih lama. Tidak ada DELETE massal dari
handler cleanup. Post/comment dihapus melalui API WordPress agar dependensi dan
object cache ikut ditangani.

Pilih tindakan dan tekan Run Database Pruning setelah membuat backup. Progress
menampilkan tahap serta jumlah data terhapus. Pause menunggu batch yang sedang
berjalan selesai. Continue / Resume melanjutkan cursor tersimpan. Cancel hanya
membatalkan pekerjaan tersisa; penghapusan yang sudah terjadi tidak dikembalikan.
Menutup halaman menghentikan permintaan batch berikutnya. Membuka halaman lagi
tidak otomatis menjalankan penghapusan; tekan Continue / Resume. Hanya pengelola
yang memulai job dapat mengendalikannya. Semua mutasi memakai POST, nonce, akses
Tool Kits dan pemeriksaan settings lock. DB tools tetap mengikuti pengecualian
lisensi yang sudah ada.

Primary-key cursor dan batas ID awal mengecualikan row yang baru dibuat. Setiap
batch memeriksa kondisi kandidat kembali. Kegagalan read/delete menjeda pekerjaan
dan mempertahankan cursor untuk dilanjutkan. State disimpan sebagai option
non-autoload `tk_jobs_cleanup`; tidak memakai cron dan tidak menyebabkan purge
cache halaman hanya karena progress berubah.

Clear Expired Transients mempertahankan cache yang masih berlaku dan yang tidak
memiliki waktu kedaluwarsa. Include Unexpired Transients adalah pilihan eksplisit
untuk menghapus cache valid juga. Transient Tool Kits dikecualikan agar nonce,
lock, dan proses lain tidak terganggu. Transient jaringan multisite tidak dihapus
oleh cleanup per-situs. Dengan persistent object cache, transient berada pada
backend cache, sehingga pembersihan row options tidak membersihkan seluruh cache
eksternal dan tidak menjalankan flush global.

ACF cleanup tetap opsional. Referensi field aktif dipertahankan. Bila ACF tidak
aktif, setiap batch memerlukan source usage scan lengkap tanpa bukti penggunaan;
ketidakpastian menjeda cleanup. Optimize Tables mati secara default dan memproses
satu tabel per request. Pada multisite hanya tabel blog yang terdaftar yang dipilih.

## Security alerts

Pengiriman melalui `tk_security_alert_send()` sekarang hanya mengantrekan pesan;
tidak mengirim email/webhook atau melakukan geolocation/reverse DNS saat login.
Lokasi dalam pesan memakai cache yang sudah ada atau Unknown.

Worker `tk_security_alert_dispatch` mengirim maksimal lima job per eksekusi, dengan
budget 15 detik di antara job dan timeout webhook lima detik. `tk_security_alert_sweep`
berjalan per jam sebagai pemulihan bila jadwal dispatch hilang. WP-Cron perlu
berjalan; gunakan scheduler server pada situs yang tidak sering dikunjungi.

Email dan webhook memiliki checkpoint sendiri. Kanal yang sudah berhasil tidak
diulang ketika kanal lain gagal. Retry dilakukan setelah kira-kira 1 menit,
5 menit, 15 menit, 1 jam, dan 4 jam, dengan jitter maksimal 30 detik. Maksimal
enam percobaan per kanal. HTTP 408/429/5xx dan kegagalan transport boleh diulang;
penolakan HTTP permanen menghentikan kanal tersebut. Retry-After pada 429/503
didukung dalam detik atau HTTP date, maksimum 24 jam.

Pesan identik dengan penerima yang sama digabung selama 10 menit, mengabaikan
baris Time saat membuat identitas. Pengelompokan mempertahankan pesan pertama;
duplikat setelah pengiriman dalam jendela yang sama tidak memicu pengiriman baru.
Antrean dibatasi sekitar 100 record per blog; job berbeda yang melewati kapasitas
dicatat sebagai `security_alert_queue_full` tanpa menyimpan isi pesan. Race pada
kapasitas bisa menghasilkan sedikit lebih dari 100 record. Status akhir tercatat
di audit log. Pesan/penerima dibuang dari record setelah delivery berakhir; record
deduplikasi selesai dibersihkan setelah 10 menit. Pending job kedaluwarsa setelah
24 jam dan dicatat; alert yang dinonaktifkan dibatalkan saat worker berjalan.

Webhook memakai WordPress safe HTTP, tanpa redirect dan tanpa menyimpan response
body. `X-Tool-Kits-Event-ID` tetap sama selama retry agar penerima bisa melakukan
deduplikasi. Pengiriman bersifat at-least-once: proses yang terputus setelah email
diterima transport tetapi sebelum checkpoint masih dapat menghasilkan duplikat.
Sukses wp_mail berarti diterima transport, bukan jaminan masuk inbox.

## Heartbeat

System Monitoring → Collector heartbeat delivery menyediakan interval 15 menit,
1 jam, 6 jam atau 24 jam. Default tetap 24 jam. Jadwal awal diberi jitter hingga
dua menit; interval berulang mengikuti pilihan yang disimpan. State retry
non-autoload `tk_jobs_heartbeat` hanya menyimpan percobaan/status/waktu/HTTP code,
tanpa payload, key lisensi, token atau respons server.

Kegagalan transport, HTTP 408/429/5xx dan acknowledgement tidak valid mendapat
hingga lima retry setelah percobaan awal, dengan delay/jitter seperti alert.
Retry-After 429/503 dihormati. HTTP autentikasi/validasi dan peringatan lisensi
tidak memicu retry otomatis. Tombol manual menghormati waktu retry yang masih
berlaku. Setiap percobaan membuat payload/timestamp/signature baru dari konfigurasi
saat itu. Sukses menghapus retry; heartbeat otomatis yang dimatikan membersihkan
jadwal dan state retry. Deaktivasi plugin membersihkan seluruh hook pengiriman.

Lease database per-blog mencegah worker bersamaan; pelepasan lease membandingkan
token sehingga worker lama tidak menghapus lock penggantinya. Perubahan state
internal tidak memicu purge page cache. Uninstall dengan opt-in delete data
menghapus state `tk_jobs_*`.

## Collector NexaMonitor

Heartbeat diverifikasi dan lisensinya dinilai sebelum penyimpanan. Payload yang
disimpan memakai allowlist telemetri, termasuk field nested cron/maintenance;
`license_key`, token, password, authorization, dan field di luar kontrak tidak
disimpan. Fingerprint lisensi tetap tersedia untuk diagnosis. Laporan error
heartbeat plugin tidak menyalin response body atau error transport mentah.

Setelah kode Laravel dipasang, bersihkan payload lama dari root NexaMonitor:

```sh
php artisan toolkits:scrub-heartbeat-payloads --dry-run
php artisan toolkits:scrub-heartbeat-payloads
```

Perintah memindai 100 record per batch dan memakai row lock singkat saat menulis,
agar heartbeat yang baru datang tidak tertimpa. Idempotent; tidak mengubah tabel
lisensi atau fingerprint. Tidak mencetak kredensial. Backup/log lama yang sudah
berisi kredensial tidak diubah oleh perintah ini.

## Verifikasi

```sh
php scripts/maintenance-jobs-regression.php
php scripts/maintenance-jobs-regression.php --inactive-acf
node scripts/regression-tests.cjs cleanup-ui
node scripts/regression-tests.cjs cleanup-browser
node scripts/regression-tests.cjs heartbeat-recheck
```

Fixture SQL menggunakan SQLite terisolasi dengan API WordPress palsu; tidak memuat
wp-config, tidak menghapus data situs, tidak mengirim email atau request eksternal.
Tes browser memakai halaman fixture dan fetch palsu. Integrasi plugin pihak ketiga,
SMTP asli, object cache eksternal, dan database MySQL produksi perlu diperiksa di
staging sebelum rollout.
