# Security Hardening Audit Fixes

## Latar Belakang

Audit keamanan mendalam menemukan beberapa area hardening yang valid dan layak diperbaiki tanpa refactor besar: security headers production belum lengkap, session cookie/encryption production belum eksplisit, awal registrasi belum memiliki rate limit, reset password URL membawa email di query string, port Laravel dev terbuka ke semua interface, dan audit dependency Python belum tersedia sebagai tooling.

## Tujuan

- Menutup risiko hardening production dengan perubahan kecil dan terukur.
- Menambahkan rate limit untuk start registrasi sebelum email OTP dikirim.
- Mengurangi kebocoran metadata dari reset password URL/referrer.
- Membuat konfigurasi dev lebih aman secara default.
- Menambahkan cara standar untuk menjalankan audit dependency Python.

## Ruang Lingkup

- Tambah header HTTP aman di Caddy dengan konfigurasi konservatif yang tidak mengganggu OnlyOffice.
- Set env production template agar session cookie secure dan session storage terenkripsi.
- Tambah rate limit awal registrasi berbasis IP dan email.
- Tambah test Laravel untuk rate limit registrasi.
- Hilangkan email dari link reset password jika kompatibel dengan flow saat ini, atau minimal mitigasi leakage dengan header.
- Bind port Laravel docker-compose ke localhost.
- Tambah pip-audit sebagai dependency dev/test dan dokumentasikan command verifikasi.
- Update dependency Python yang terbukti rentan berdasarkan `pip-audit`.

## Di Luar Scope

- Refactor besar flow auth, OnlyOffice, Google Drive, atau Python AI runtime.
- Rotasi secret production secara otomatis.
- Deploy production.
- Full penetration test aktif ke environment production.

## Area / File Terkait

- `deploy/Caddyfile`
- `.env.droplet.example`
- `laravel/.env.example`
- `laravel/.env.production`
- `laravel/resources/views/livewire/pages/auth/login.blade.php`
- `laravel/app/Services/Auth/PendingRegistrationWorkflowService.php`
- `laravel/app/Notifications/CustomResetPassword.php`
- `docker-compose.yml`
- `python-ai/requirements-dev.txt` atau dokumentasi dependency audit Python
- `python-ai/requirements.txt`
- Test Laravel auth/registration/password reset

## Risiko

- Header frame/CSP yang terlalu ketat dapat mengganggu OnlyOffice, jadi perubahan header harus konservatif.
- Rate limit registrasi bisa terlalu agresif dan mengganggu user legit jika threshold terlalu rendah.
- Mengubah reset password URL perlu memastikan test password reset tetap hijau.
- Menambah pip-audit dapat memperkenalkan dependency tooling baru, bukan dependency runtime.

## Langkah Implementasi

1. Tambahkan header Caddy yang aman dan konservatif: HSTS, nosniff, referrer policy, permissions policy, dan X-Frame-Options SAMEORIGIN.
2. Perbarui env example/production template untuk `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, dan session flags eksplisit.
3. Tambahkan guard rate limit di start registrasi sebelum pending registration/email dibuat.
4. Tambahkan atau perbarui test untuk membuktikan email OTP tidak dikirim saat limit start registrasi terlampaui.
5. Perbaiki reset password URL agar tidak membawa email di query string jika test flow mendukung.
6. Ubah binding port Laravel di `docker-compose.yml` ke localhost.
7. Tambahkan tooling pip-audit untuk dependency audit Python.

## Rencana Test

- `cd laravel && php artisan test tests/Feature/Auth/RegistrationTest.php tests/Feature/Auth/PasswordResetTest.php tests/Feature/Memos/MemoPolicyTest.php tests/Feature/Memos/OnlyOfficeCallbackTest.php tests/Feature/Documents/DocumentPreviewControllerTest.php`
- `cd laravel && composer audit`
- `cd laravel && npm audit --audit-level=low`
- `cd python-ai && source venv/bin/activate && pytest tests/test_document_export.py tests/test_rag_ingest_delete.py tests/test_rag_eval_set.py`
- `cd python-ai && source venv/bin/activate && pip-audit -r requirements.txt`

## Kriteria Selesai

- Semua patch hardening valid terimplementasi dengan scope kecil.
- Test auth/password reset/OnlyOffice/document preview tetap lulus.
- Test baru menutup rate limit awal registrasi.
- Audit dependency Laravel, npm, dan Python dijalankan atau alasan blocker dicatat.
- Perubahan sudah commit, push, dan dibuatkan draft PR.
