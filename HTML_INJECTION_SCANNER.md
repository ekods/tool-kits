# HTML Injection Scanner — Tool Kits 2.7.30

Buka **Tool Kits → Security → HTML Injection Scanner**, atau tombol **Open HTML
Injection Scanner** pada halaman Hardening. Pilih batas scan dan tekan **Start
New Scan**. Hasil tersimpan per situs; hanya administrator yang memiliki akses
Tool Kits dan lisensi aktif yang dapat menjalankan atau membaca scanner.

## Yang diperiksa

- `post_title`, `post_content`, dan `post_excerpt` pada posts/pages/custom post
  types. Revision, attachment, trash dan auto-draft dikecualikan.
- Post meta milik konten tersebut, termasuk HTML builder dalam JSON atau array
  serialized yang didukung. Field kredensial, nonce/token, metadata attachment,
  serta data internal/report Tool Kits tidak dipindai.
- Options dengan prefix `widget_`, `theme_mods_`, `theme_`, `themes_`, `options_`.
  Ini mencakup banyak pengaturan tema, widget HTML, dan ACF options; tabel custom
  serta option di luar prefix tersebut tidak ikut dipindai.
- HTML respons homepage dan permalink publik terbaru, sesuai batas URL. Konten
  draft/private hanya diperiksa sebagai data database; crawler tidak login dan
  tidak meminta halaman yang membutuhkan password.

Pola meliputi inline event handler (`onerror`, `onload`, dan lainnya), URL
`javascript:`/`vbscript:`, data URL berisi dokumen/script aktif, iframe tersembunyi,
script/iframe/embed eksternal, form yang mengirim ke domain lain, external base
URL, meta refresh, link eksternal tersembunyi, script tersamarkan, serta kombinasi
pembacaan data sensitif dengan pengiriman keluar, termasuk ke provider yang dikenal. Isi `srcdoc`
iframe diperiksa sampai kedalaman terbatas. CSP nonce tidak membuat script lolos
dari pemeriksaan.

Script executable yang tersimpan dalam database mendapat temuan **REVIEW** agar
operator memverifikasi apakah script tersebut memang sengaja ditambahkan.
JSON-LD dan script JSON biasa tidak dianggap executable. Contoh kode HTML yang
sudah di-escape serta komentar HTML tidak diperlakukan sebagai elemen aktif.

## Membaca hasil

Severity memakai **CRITICAL, HIGH, MEDIUM, LOW, INFO**. Severity terpisah dari
tindakan: **REPORT** untuk bukti risiko yang kuat, **REVIEW** untuk indikasi yang
belum cukup pasti, dan **SKIP** untuk pola yang seluruh konteksnya memenuhi aturan
aman. `eval()` atau `createElement('script')` saja tidak cukup untuk severity HIGH.
Confidence adalah keyakinan pada klasifikasi heuristik, bukan probabilitas malware.

Laporan dibagi menjadi **Security Findings**, **Needs Review**, dan **Suppressed
Findings**. Bagian terakhir tertutup secara default dan tetap menyimpan rule ID,
alasan, sumber, bukti, timestamp, confidence, serta halaman terdampak. **Restore to
Needs Review** memindahkan satu grup untuk ditinjau dan menyimpan override yang
berlaku untuk fingerprint yang sama pada scan berikutnya. Aksi ini ditolak selama
scan masih aktif dan hanya mengubah metadata laporan.

Laporan menyertakan sumber, ID row/post, field, path data nested, nomor baris,
alasan, host terkait, dan cuplikan bukti. Nomor baris mengacu pada field HTML yang
dianalisis, bukan file PHP pembuat halaman. Tombol **Edit source** tersedia untuk
post/postmeta; options tetap ditinjau melalui pengaturan tema/widget terkait.
Cuplikan selalu ditampilkan sebagai teks dan tidak dijalankan dalam halaman
admin. Nonce, nilai form, dan query URL disamarkan pada bukti yang disimpan.

**Trusted external hosts** menerima hostname persis seperti `cdn.example.com`,
tanpa wildcard, URL, atau pencocokan suffix. Setting wildcard dari versi lama
tetap tersimpan, tetapi tidak memberikan kepercayaan pada engine baru; ganti
dengan hostname persis sebelum memulai scan baru. Kepercayaan custom memvalidasi
tujuan CSS dan embed yang memang disetujui; tidak otomatis menyetujui JavaScript
arbitrer atau pengiriman password/data sensitif.

Registry provider terpisah mengenali HTTPS, host persis, path dan parameter yang
disetujui: Google Analytics/Tag Manager, Google Fonts, serta path CSS Font Awesome
dan AOS yang memiliki versi numerik pada cdnjs. Host tiruan, port tidak sesuai,
userinfo pada URL, path berbeda, parameter tak dikenal, atau payload tambahan
tidak ikut disembunyikan. Registry dapat disesuaikan oleh developer melalui filter
`tk_html_scan_recognized_providers`; lihat [aturan dan contoh](SCANNER_CONTEXT_FILTERING.md).

Handler async CSS diverifikasi menggunakan DOM dan lexer/grammar JavaScript yang
dibatasi. `this.media='all'` hanya disetujui pada stylesheet `media=print`, dan
`this.rel='stylesheet'` pada preload `as=style`, dengan tujuan yang tervalidasi.
Pembersihan `this.onload=null` juga didukung. Kode tambahan atau atribut event lain
membatalkan verifikasi; atribut berbahaya lainnya tetap dianalisis terpisah.

Loader Google hanya di-SKIP jika seluruh token stream cocok dengan grammar yang
disetujui. Template yang didukung: loader literal sederhana dengan `appendChild`,
snippet GTM standar, dan konfigurasi gtag standar yang tersimpan. Perubahan yang
belum dikenali tetap masuk REVIEW. Tidak ada JavaScript yang dieksekusi untuk
membuat keputusan ini.

Temuan publik identik pada lokasi DOM yang sama digabung lintas halaman, dengan
jumlah kemunculan dan daftar URL. Record database, atribut, lokasi DOM, file,
resource atau bukti yang berbeda tetap dipisahkan. Fingerprint tidak bergantung
pada nomor baris maupun atribut nonce request yang berubah. Filter laporan
mencakup severity, tipe, sumber, classification, action, minimum confidence,
dan halaman terdampak.

## Batas dan perilaku

- Default: maksimal 20 URL dan 500 row per sumber database. Batas dapat dinaikkan
  menjadi 50 URL dan 5.000 row per sumber. Scan memakai cursor ID, lima row per
  batch atau satu HTTP request, tanpa memuat seluruh database ke memori.
- Maksimal 512 KiB per field/respons, 40 temuan per potongan HTML, 300 temuan per
  laporan, serta batas struktur/kedalaman. Data yang melampaui batas atau format
  serialized yang tidak didukung ditandai sebagai cakupan belum lengkap.
- Pengamatan aman memiliki budget terpisah: 80 cuplikan per sumber dan 300 grup
  suppressed per laporan. Setelah batas detail, jumlah/ringkasan rule audit tetap
  dicatat dan cakupan ditandai terbatas. Pemeriksaan ancaman terus berjalan sampai
  batas struktur; bukti dengan risiko lebih tinggi diprioritaskan bila pengamatan
  lemah mengisi budget temuan. Restore tersedia untuk grup dengan bukti detail.
- JSON dan array/scalar serialized dibaca sebagai data tanpa `unserialize`,
  eval, instansiasi class, atau pemanggilan constructor/wakeup. Object serialized
  tidak dibuka. Kredensial dan session bukan bagian target scan.
- Request publik hanya HTTP(S) ke origin situs sendiri dengan TLS verification,
  tanpa cookie/login, timeout delapan detik, dan batas ukuran respons. Redirect
  tidak diikuti. HTTP gagal, redirect, respons non-HTML atau URL yang tidak bisa
  diperiksa akan dicatat sebagai cakupan tidak lengkap. Safe HTTP WordPress dapat
  menolak hostname lokal/private; kegagalannya ditampilkan sebagai belum dicek.
- Stylesheet lokal yang ditemukan diperiksa dua file per batch, maksimal 100 file
  berbeda. Root yang diizinkan adalah `WP_CONTENT_DIR` dan `wp-includes`, dengan
  validasi realpath dan ekstensi `.css` pada target akhir. Symlink keluar root,
  traversal terenkode, file PHP yang menyamar sebagai CSS, serta URL eksternal
  tidak dibaca. Import CSS lokal ikut diantrikan; file yang sama diproses sekali.
- Isi CSS diperiksa untuk expression legacy, URL executable, import/resource
  eksternal tak dikenal, kombinasi selector sensitif + request lintas origin,
  dan kombinasi overlay tersembunyi yang didukung bukti tambahan. `url()`, data
  image/font biasa, serta properti layout tunggal bukan otomatis kerentanan.
- Hash CSS pertama yang dibaca menjadi referensi metadata; perubahan berikutnya
  masuk LOW/REVIEW. Update tema/build dapat menjadi penyebab sah. Referensi ini
  bukan attestasi bahwa file awal bersih, dan tidak menonaktifkan analisis isi.
- Scanner memeriksa HTML yang benar-benar dikembalikan server, termasuk jika
  dilayani cache. Ia tidak membuka browser, menjalankan JavaScript, mengunduh
  script/CSS eksternal, mengikuti redirect resource, atau memindai file PHP/JS. Gunakan Malware
  Scanner yang sudah ada untuk pemeriksaan file.
- Memerlukan extension PHP DOM. Parser DOM/libxml bukan parser browser HTML5;
  payload yang sangat tersamarkan atau bergantung pada perilaku browser bisa
  luput. Laporan tanpa temuan tidak menjamin situs bebas injection.
- Page scanner perlu tetap terbuka untuk melanjutkan batch. Jika tab ditutup,
  administrator yang sama dapat membuka kembali dan memilih **Resume Scan**
  selama sesi belum kedaluwarsa (30 menit sejak batch terakhir).
- **Stop Scan** menunggu batch yang sedang berjalan lalu menyimpan laporan yang
  sudah ada. **Clear Report** hanya menghapus laporan dan ditolak saat scan aktif.
  Data situs tidak dihapus/diperbaiki otomatis. Status `partial`, `cancelled`,
  atau `interrupted` tidak boleh dibaca sebagai hasil bersih seluruh situs.
- Data scan memakai option/transient khusus yang tidak autoload. Perubahan
  progress/laporan tidak memicu purge page cache. Modul tidak memasang scan pada
  request frontend biasa, cron, email, atau layanan pihak ketiga.

## Validasi lokal

```sh
php scripts/html-injection-engine-regression.php
php scripts/html-injection-context-regression.php
php scripts/html-injection-scanner-regression.php
node scripts/html-injection-ui-regression.cjs
python3 scripts/html-injection-browser-regression.py
```

Tes memakai HTML/JSON/serialized fixtures, DOM parser asli, database/HTTP adapters
in-memory, dan UI fixture. Tidak membaca database produksi atau menjalankan
payload bukti. Cakupannya termasuk deteksi positif/negatif, host allowlist,
serialized object safety, izin dan nonce, cursor/batas, read-only content, sesi
antar-user, penguncian batch, stop/resume, escaping laporan, dan cache exclusions.
Tes browser menggunakan Chrome headless dan HTML fixture lokal, tanpa database
produksi atau request ke situs eksternal. Semua kasus PHP/Node di atas juga
terdaftar pada `node scripts/regression-tests.cjs`. Untuk tes browser, sesuaikan
`TK_CHROME_BINARY` jika lokasi Chrome berbeda.

Referensi implementasi:
[WordPress safe HTTP](https://developer.wordpress.org/reference/functions/wp_safe_remote_get/),
[PHP DOM loadHTML](https://www.php.net/manual/en/domdocument.loadhtml.php),
[PHP unserialize](https://www.php.net/manual/en/function.unserialize.php).
