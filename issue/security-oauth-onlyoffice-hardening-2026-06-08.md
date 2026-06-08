# Security OAuth + OnlyOffice Hardening — 2026-06-08

## Tujuan

Menutup temuan keamanan aktif yang tersisa setelah audit hardening sebelumnya tanpa membuat branch atau PR baru.

## Temuan Valid

1. Google Drive central OAuth hanya mengecek setup key + email allowlist. Jika email user biasa tercantum di `GOOGLE_DRIVE_OAUTH_ADMIN_EMAILS`, user tersebut bisa menghubungkan akun Drive pusat yang berlaku untuk semua user tanpa role admin aktif.
2. Validasi URL download callback OnlyOffice mencocokkan host dan optional port, tetapi belum mencocokkan scheme. Ini terlalu longgar untuk endpoint yang mengunduh file eksternal sebelum menyimpan DOCX memo.

## Rencana Fix

- Wajibkan user Google Drive OAuth setup memiliki role admin/super-admin aktif selain lolos allowlist email/config environment.
- Ketatkan trusted URL OnlyOffice supaya scheme, host, dan port cocok dengan `ONLYOFFICE_INTERNAL_URL` atau `ONLYOFFICE_PUBLIC_URL`.
- Tambahkan regression test untuk user non-admin allowlisted, inactive admin, admin allowlisted, fallback local/testing, dan scheme mismatch OnlyOffice.
- Update dokumentasi konteks dan changelog.

## Verifikasi

- `cd laravel && php artisan test tests/Feature/CloudStorage/GoogleDriveCentralOAuthTest.php tests/Feature/Memos/OnlyOfficeCallbackTest.php`
- `cd laravel && php artisan test`
- `cd python-ai && source venv/bin/activate && pytest`

## Risiko

- Akun yang sebelumnya hanya diizinkan lewat email allowlist tidak bisa setup Google Drive pusat sampai role Laravel-nya diubah menjadi admin/super-admin aktif.
- OnlyOffice callback akan menolak URL download jika scheme production env tidak sesuai dengan URL yang benar-benar dikirim Document Server.
