# Wave 2 Medium-Risk Security Hardening

GitHub issue: https://github.com/Hasbi1605/ISTA-AI/issues/232

## Latar Belakang
Validasi temuan security menunjukkan tiga hardening penting yang masih layak dikerjakan setelah Wave 1: key separation untuk signed URL OnlyOffice, sanitasi HTML export berbasis parser allowlist, dan pembatasan trusted proxy Laravel. Ketiganya bernilai security lebih besar daripada quick wins, tetapi perlu test regresi karena menyentuh boundary request, export dokumen, dan URL signed file.

## Tujuan
- Memisahkan key HMAC signed URL OnlyOffice dari `APP_KEY`.
- Mengganti sanitasi HTML export yang regex-based menjadi sanitizer berbasis parser/allowlist.
- Membatasi trusted proxy Laravel agar tidak lagi default percaya semua forwarded header.

## Ruang Lingkup
- Tambah konfigurasi secret/derived key untuk signed URL OnlyOffice.
- Pastikan validasi signed URL tetap kompatibel dengan key baru dan test menutup perubahan.
- Sanitasi `content_html` export dokumen dengan parser allowlist sebelum dikirim ke Python.
- Letakkan sanitizer di boundary `DocumentExportService` agar semua jalur export, termasuk upload Google Drive dan export dokumen tersimpan, konsisten.
- Pertahankan tag aman yang diperlukan untuk export jawaban AI seperti heading, paragraph, list, code/pre, blockquote, link aman, dan table.
- Tambah konfigurasi `TRUSTED_PROXIES` dengan default private subnet internal yang aman untuk Docker/Caddy.
- Dokumentasikan `TRUSTED_PROXIES` dan `ONLYOFFICE_SIGNED_URL_SECRET` di env example/deployment docs.
- Test regresi untuk proxy header spoofing, signed URL key separation, dan sanitasi HTML export.

## Di Luar Scope
- CSP dan blok markdown image eksternal.
- Migrasi `.env.droplet` ke secret manager.
- Perubahan produksi/deploy langsung.
- Refactor besar arsitektur export dokumen atau OnlyOffice.
- Perubahan UI.

## Area / File Terkait
- `laravel/bootstrap/app.php`
- `laravel/config/services.php`
- `laravel/app/Services/OnlyOffice/MemoDocumentKey.php`
- `laravel/app/Http/Controllers/Documents/DocumentExportController.php`
- `laravel/app/Services/Documents/DocumentExportHtmlSanitizer.php`
- `laravel/app/Services/DocumentExportService.php`
- `laravel/tests/Feature/Memos/MemoPolicyTest.php`
- `laravel/tests/Feature/Documents/DocumentExportTest.php`
- Test baru untuk trusted proxies bila diperlukan.

## Risiko
- Jika trusted proxy terlalu sempit, Laravel bisa salah membaca HTTPS/IP di balik Caddy.
- Sanitizer allowlist dapat menghapus atribut/tag yang sebelumnya ikut masuk export.
- Key separation dapat memengaruhi signed URL OnlyOffice jika deployment tidak membawa secret/derived key yang konsisten.

## Langkah Implementasi
1. Tambah helper konfigurasi trusted proxies dengan env `TRUSTED_PROXIES`, default private subnet/localhost.
2. Tambah config OnlyOffice `signed_url_secret`, lalu gunakan derived HMAC key khusus signed URL.
3. Update signed URL test agar membuktikan key baru bukan `APP_KEY` langsung.
4. Tambah sanitizer HTML export berbasis DOM parser allowlist.
5. Tindak lanjuti review: pastikan unwrap tetap men-sanitasi child, parsing UTF-8 aman, link `http/https` sah tetap dipertahankan, dan sanitizer berada di service boundary.
6. Tambah test payload HTML berisiko untuk export endpoint dan service boundary.
7. Jalankan formatter dan test Laravel penuh.

## Rencana Test
- Test trusted proxy memastikan spoofed `X-Forwarded-For` dari remote tak dipercaya ketika proxy list terbatas.
- Test signed URL tetap valid dengan `ONLYOFFICE_SIGNED_URL_SECRET` dan gagal bila diverifikasi dengan `APP_KEY` langsung.
- Test export endpoint menghapus script/iframe/event handler/style/resource eksternal tetapi menjaga table/list/text aman.
- Test service export memastikan HTML dibersihkan sebelum dikirim ke Python meski tidak lewat HTTP controller.
- Jalankan `vendor/bin/pint --test --dirty`.
- Jalankan `php artisan test`.
- Jalankan audit dependency Laravel bila tersedia.

## Kriteria Selesai
- GitHub issue dibuat dan linked di plan.
- Branch Wave 2 berisi hanya scope hardening ini.
- Test baru dan full Laravel test lulus.
- Branch dipush dan PR dibuat ke `main`.
