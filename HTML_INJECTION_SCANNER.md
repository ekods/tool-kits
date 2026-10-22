# HTML Injection Scanner — Tool Kits 2.7.28

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
pembacaan cookie dengan pengiriman data ke host yang belum ditinjau. Isi `srcdoc`
iframe diperiksa sampai kedalaman terbatas. CSP nonce tidak membuat script lolos
dari pemeriksaan.

Script executable yang tersimpan dalam database mendapat temuan **REVIEW** agar
operator memverifikasi apakah script tersebut memang sengaja ditambahkan.
JSON-LD dan script JSON biasa tidak dianggap executable. Contoh kode HTML yang
sudah di-escape serta komentar HTML tidak diperlakukan sebagai elemen aktif.

## Membaca hasil

**HIGH** menunjukkan pola yang perlu diprioritaskan, **MEDIUM** perlu ditinjau
sesuai konteks, dan **REVIEW** adalah inventaris resource/script yang belum
dikonfirmasi. Semuanya merupakan indikator heuristik, bukan vonis malware.

Laporan menyertakan sumber, ID row/post, field, path data nested, nomor baris,
alasan, host terkait, dan cuplikan bukti. Nomor baris mengacu pada field HTML yang
dianalisis, bukan file PHP pembuat halaman. Tombol **Edit source** tersedia untuk
post/postmeta; options tetap ditinjau melalui pengaturan tema/widget terkait.
Cuplikan selalu ditampilkan sebagai teks dan tidak dijalankan dalam halaman
admin. Nonce, nilai form, dan query URL disamarkan pada bukti yang disimpan.

Tambahkan domain integrasi yang sudah diverifikasi ke **Trusted external hosts**,
misalnya `www.googletagmanager.com` atau `www.youtube.com`. `*.example.com`
mencakup subdomain, bukan domain induknya. Daftar ini hanya mengurangi temuan
berdasarkan tujuan eksternal; inline event handler, script tersamarkan dan iframe
tersembunyi tetap diperiksa.

## Batas dan perilaku

- Default: maksimal 20 URL dan 500 row per sumber database. Batas dapat dinaikkan
  menjadi 50 URL dan 5.000 row per sumber. Scan memakai cursor ID, lima row per
  batch atau satu HTTP request, tanpa memuat seluruh database ke memori.
- Maksimal 512 KiB per field/respons, 40 temuan per potongan HTML, 300 temuan per
  laporan, serta batas struktur/kedalaman. Data yang melampaui batas atau format
  serialized yang tidak didukung ditandai sebagai cakupan belum lengkap.
- JSON dan array/scalar serialized dibaca sebagai data tanpa `unserialize`,
  eval, instansiasi class, atau pemanggilan constructor/wakeup. Object serialized
  tidak dibuka. Kredensial dan session bukan bagian target scan.
- Request publik hanya HTTP(S) ke origin situs sendiri dengan TLS verification,
  tanpa cookie/login, timeout delapan detik, dan batas ukuran respons. Redirect
  tidak diikuti. HTTP gagal, redirect, respons non-HTML atau URL yang tidak bisa
  diperiksa akan dicatat sebagai cakupan tidak lengkap. Safe HTTP WordPress dapat
  menolak hostname lokal/private; kegagalannya ditampilkan sebagai belum dicek.
- Scanner memeriksa HTML yang benar-benar dikembalikan server, termasuk jika
  dilayani cache. Ia tidak membuka browser, menjalankan JavaScript, mengunduh
  script eksternal, mengikuti link, atau memindai file PHP/JS. Gunakan Malware
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
php scripts/html-injection-scanner-regression.php
node scripts/html-injection-ui-regression.cjs
```

Tes memakai HTML/JSON/serialized fixtures, DOM parser asli, database/HTTP adapters
in-memory, dan UI fixture. Tidak membaca database produksi atau menjalankan
payload bukti. Cakupannya termasuk deteksi positif/negatif, host allowlist,
serialized object safety, izin dan nonce, cursor/batas, read-only content, sesi
antar-user, penguncian batch, stop/resume, escaping laporan, dan cache exclusions.

Referensi implementasi:
[WordPress safe HTTP](https://developer.wordpress.org/reference/functions/wp_safe_remote_get/),
[PHP DOM loadHTML](https://www.php.net/manual/en/domdocument.loadhtml.php),
[PHP unserialize](https://www.php.net/manual/en/function.unserialize.php).
