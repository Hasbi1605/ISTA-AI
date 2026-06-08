# Security Hardening Audit Fixes — 2026-06-08

## Tujuan

Menutup temuan keamanan yang masih aktif dari audit ISTA AI dengan perubahan kecil, teruji, dan siap deploy langsung ke `main`.

## Scope Fix

- Update dependency Laravel/Symfony yang terkena advisory `composer audit`.
- Mitigasi CVE Laravel email-rule yang belum punya patched Laravel 11 release dengan rule anti header-injection pada semua email input user-controlled.
- Tutup public self-registration secara default untuk production/private deployment, dengan flag eksplisit untuk mengaktifkan kembali jika diperlukan.
- Kurangi risiko CSP dengan menghapus `unsafe-eval` secara default dan hanya mengizinkannya melalui konfigurasi eksplisit.
- Kunci Horizon UI ke user terautentikasi, terverifikasi, aktif, `super_admin`, dan tidak sedang wajib ganti password.
- Catat dan test mitigasi ChromaDB: tidak diexpose publik dalam compose production; dependency tetap dipantau karena advisory belum punya fixed version.

## Batasan

- Tidak membuat branch baru atau PR baru.
- Tidak refactor besar pada auth/chat/knowledge.
- Tidak mengubah flow login, email verification existing user, chat, memo, atau knowledge admin selain akses pendaftaran publik dan Horizon.
- Tidak mengganti `chromadb==1.5.5` tanpa fixed version yang tersedia.

## Rencana Implementasi

1. Tambah konfigurasi `auth.registration.enabled` berbasis `PUBLIC_REGISTRATION_ENABLED`.
2. Blokir route/query/register action saat registrasi dinonaktifkan dan sembunyikan form register dari UI.
3. Tambah konfigurasi CSP untuk `unsafe-inline`/`unsafe-eval`, dengan `unsafe-eval=false` default.
4. Perketat `config/horizon.php` middleware dan `HorizonServiceProvider` gate.
5. Update dependency Laravel/Symfony via Composer dan verifikasi `composer audit`.
6. Tambah `NoEmailHeaderInjection` untuk email input auth/profile/admin dan Composer audit ignore spesifik dengan alasan mitigasi.
7. Update dokumen production/config/context dan changelog.
8. Tambah/update test feature untuk registrasi tertutup, CRLF email payload, CSP, Horizon access, dan compose Chroma internal-only.

## Verifikasi

- `cd laravel && php artisan test --filter=RegistrationTest`
- `cd laravel && php artisan test --filter=SecurityHeadersTest`
- `cd laravel && php artisan test --filter=HorizonAccessTest`
- `cd laravel && composer audit`
- `cd python-ai && source venv/bin/activate && pip-audit -r requirements.txt --format json`
- `cd laravel && php artisan test`
- `cd python-ai && source venv/bin/activate && pytest`

## Risiko

- Registrasi user baru tidak bisa dilakukan di production kecuali `PUBLIC_REGISTRATION_ENABLED=true` diset eksplisit.
- `unsafe-eval` bisa diperlukan hanya jika ada library legacy runtime; jika UI rusak, aktifkan sementara via env sambil migrasi script.
- Horizon menjadi tidak bisa diakses regular admin; hanya `super_admin` yang boleh.
- `composer audit` mengabaikan satu advisory Laravel 11 yang dimitigasi lokal; follow-up ideal adalah upgrade mayor ke Laravel 12 saat scope memadai.
- `pip-audit` tetap melaporkan ChromaDB karena belum ada fixed version; mitigasi production adalah internal-only/no published port.
