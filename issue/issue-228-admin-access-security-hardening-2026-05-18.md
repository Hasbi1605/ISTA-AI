# Admin Access Security Hardening sebelum Merge Knowledge Base

Parent: https://github.com/Hasbi1605/ISTA-AI/issues/209
GitHub Issue: https://github.com/Hasbi1605/ISTA-AI/issues/245
Blocks: https://github.com/Hasbi1605/ISTA-AI/issues/211

## Latar Belakang
Fitur admin dashboard foundation, usage tracking, design shell, dan monitoring MVP sudah berjalan sampai tahap PR/merge/deploy awal. Child #211 Admin Knowledge Base Internal sudah dikerjakan dalam PR tetapi belum merge. Sebelum #211 masuk ke production, akses admin perlu di-hardening karena Knowledge Base Internal memungkinkan admin mengunggah, mengaktifkan, mengarsipkan, dan memproses dokumen internal yang nantinya bisa digunakan seluruh user.

Hardening ini dibuat sebagai issue baru agar tidak mengubah scope issue yang sudah selesai/terlanjur dikerjakan. Issue ini menjadi blocker sebelum merge #211 dan sebelum melanjutkan fitur yang lebih sensitif seperti Knowledge Internal Retrieval, AI Configuration, dan ISTURA.

## Tujuan
- Menyediakan jalur login admin khusus yang lebih aman.
- Memastikan `/admin` hanya dapat diakses admin/super_admin aktif.
- Menyediakan satu akun `admin` dan satu akun `super_admin` awal melalui command/seeder berbasis environment.
- Membuat `super_admin` dapat membuat, mengedit, mengaktifkan/menonaktifkan, dan reset akun admin.
- Mencegah admin biasa mengatur akun admin, termasuk akunnya sendiri.
- Menambahkan audit log untuk login admin dan perubahan akun admin.
- Menjaga desain halaman admin tetap konsisten dengan ISTA AI.

## Ruang Lingkup
- Route `/admin/login`, `POST /admin/login`, dan `POST /admin/logout`.
- Login admin hardened tanpa pendaftaran, lupa password, guest chat, social login, atau CTA publik.
- Migration additive untuk status keamanan akun admin.
- Seeder/command bootstrap akun admin dan super_admin dari `.env`.
- Account management untuk `super_admin`.
- Audit log perubahan akun admin.
- Middleware active-admin check.
- Test akses, login, account management, audit, dan desain dasar.

## Di Luar Scope
- Mengubah ulang fitur monitoring dashboard yang sudah merge/deploy.
- Mengubah logic Knowledge Base Internal #211 selain menambahkan guard akses bila perlu.
- SSO/2FA.
- Public self-service reset password untuk admin.
- AI Configuration.
- Retrieval knowledge internal.

## Design Consistency Guardrails
- Gunakan Laravel + Livewire + Tailwind sesuai stack existing.
- `/admin/login` harus memakai identitas visual ISTA AI: logo, warna, typography, dark mode, radius, dan button style yang konsisten.
- Halaman admin login harus minimal dan formal: email, password, tombol masuk, pesan error generik.
- Jangan tampilkan link register, lupa password, guest chat, dashboard publik, atau CTA fitur.
- Account management harus memakai admin shell/design system yang sudah dibuat di #216.
- Gunakan table, filter, status badge, action button, modal/confirmation pattern yang konsisten dengan admin dashboard.
- Tidak ada nested cards.
- Responsive mobile dan dark mode wajib dicek.
- Copywriting harus ringkas, formal, dan sesuai konteks instansi.

## Rekomendasi Keamanan
- `/admin` unauthenticated redirect ke `/admin/login`, bukan login publik jika memungkinkan.
- Login admin hanya berhasil untuk role `admin` atau `super_admin` dengan `is_active=true`.
- User biasa yang mencoba login lewat `/admin/login` mendapat error generik.
- Error login tidak membocorkan apakah email ada, role salah, password salah, atau akun nonaktif.
- Rate limit admin login lebih ketat daripada login publik.
- Regenerate session setelah login berhasil.
- Akun awal dibuat dari `.env`, bukan hardcoded.
- Temporary password awal harus memaksa `force_password_change=true`.
- Admin nonaktif tidak dapat mengakses route admin meskipun masih punya session.
- Audit log tidak menyimpan password atau secret.

## Data Model

Kolom additive pada `users`:

```text
is_active boolean default true index
disabled_at timestamp nullable
disabled_by nullable foreign key users
disabled_reason string nullable
force_password_change boolean default false
last_admin_login_at timestamp nullable
last_admin_login_ip string nullable
```

Jika kolom `role`, `last_seen_at`, atau `last_active_feature` sudah dibuat pada issue sebelumnya, jangan dibuat ulang.

Tabel audit:

```text
admin_account_audits
id
actor_id nullable
target_user_id nullable
action string index
ip_address nullable
user_agent nullable
before_snapshot json nullable
after_snapshot json nullable
metadata json nullable
created_at
updated_at
```

Action audit:

```text
admin_login_success
admin_login_failed
admin_created
admin_updated
admin_activated
admin_deactivated
admin_password_reset
admin_role_changed
```

Env bootstrap:

```text
INITIAL_ADMIN_EMAIL=admin@example.go.id
INITIAL_ADMIN_PASSWORD=temporary-secret
INITIAL_SUPER_ADMIN_EMAIL=superadmin@example.go.id
INITIAL_SUPER_ADMIN_PASSWORD=temporary-secret
```

Jika env credential tidak tersedia, command/seeder harus gagal dengan pesan jelas dan tidak membuat password default.

## Admin Account Management

Route yang disarankan:

```text
GET /admin/accounts
GET /admin/accounts/create
POST /admin/accounts
GET /admin/accounts/{user}/edit
PUT /admin/accounts/{user}
POST /admin/accounts/{user}/activate
POST /admin/accounts/{user}/deactivate
POST /admin/accounts/{user}/reset-password
```

Aturan:
- Hanya `super_admin` aktif yang dapat mengakses account management.
- Super admin dapat membuat akun `admin` dan `super_admin`.
- Super admin dapat mengedit nama, email, role, status aktif, dan `force_password_change`.
- Super admin dapat reset password admin menjadi temporary password.
- Admin biasa tidak bisa membuka account management.
- Admin biasa tidak bisa mengubah akunnya sendiri dari dashboard admin.
- Cegah deactivate super_admin terakhir.
- Cegah self-deactivate jika membuat tidak ada super_admin aktif.
- Hard delete tidak direkomendasikan untuk MVP; gunakan deactivate agar audit tetap utuh.

## Area / File Terkait
- `laravel/routes/web.php`
- `laravel/app/Models/User.php`
- `laravel/app/Models/AdminAccountAudit.php`
- `laravel/app/Http/Middleware/*`
- `laravel/app/Http/Controllers/Auth/*`
- `laravel/app/Http/Controllers/Admin/*`
- `laravel/app/Livewire/Admin/*`
- `laravel/resources/views/admin/*`
- `laravel/resources/views/livewire/admin/*`
- `laravel/resources/css/app.css`
- `laravel/database/migrations/*`
- `laravel/database/seeders/*`
- `laravel/app/Console/Commands/*`
- `laravel/tests/Feature/*`

## Risiko
- Admin login yang masih memakai halaman publik bisa membawa link self-service yang tidak diinginkan.
- Account management yang terlalu bebas bisa menonaktifkan semua super_admin.
- Pesan error login terlalu spesifik bisa membantu enumerasi akun.
- UI baru bisa terasa berbeda dari admin dashboard jika tidak memakai shell/komponen #216.
- Hardening yang terlalu besar bisa mengganggu #211; perubahan harus difokuskan pada akses dan akun.

## Langkah Implementasi
1. Audit kondisi admin auth yang sudah ada dari #214/#210.
2. Tambah migration kolom keamanan akun admin jika belum ada.
3. Buat `AdminAccountAudit`.
4. Buat command/seeder bootstrap akun `admin` dan `super_admin` dari env.
5. Buat `/admin/login` khusus admin dengan desain konsisten.
6. Tambah login handler admin dengan rate limit, generic error, session regenerate, role check, active check, dan audit.
7. Tambah middleware active-admin check untuk route `/admin/*`.
8. Buat halaman account management untuk `super_admin` memakai admin shell.
9. Tambah guard agar super_admin terakhir tidak bisa dinonaktifkan.
10. Tambah audit pada create/update/activate/deactivate/reset password.
11. Pastikan `/admin/knowledge` pada PR #211 memakai guard admin aktif.
12. Tambah test akses, login, account management, audit, dan smoke render desain.

## Rencana Test
```bash
cd laravel && php artisan test --filter='Admin|AdminAccess|AdminAccount|Knowledge'
```

Minimal test:
- Guest membuka `/admin` diarahkan ke `/admin/login`.
- `/admin/login` tidak menampilkan register, forgot password, guest chat, atau CTA publik.
- User biasa tidak bisa login lewat `/admin/login`.
- Admin aktif bisa login dan membuka `/admin`.
- Admin nonaktif tidak bisa login.
- Super admin aktif bisa membuka `/admin/accounts`.
- Admin biasa tidak bisa membuka `/admin/accounts`.
- Super admin bisa create/edit/activate/deactivate/reset password admin.
- Admin biasa tidak bisa mengatur akunnya sendiri.
- Sistem menolak deactivate super_admin terakhir.
- Audit tercatat untuk login berhasil/gagal dan perubahan akun admin.
- `/admin/knowledge` tetap terlindungi.

Verifikasi manual:
- Tampilan `/admin/login` konsisten dengan ISTA AI.
- Account management memakai admin shell yang sama.
- Dark mode dan mobile tidak overlap.

## Kriteria Selesai
- GitHub issue ini merge sebelum #211.
- `/admin/login` hardened tersedia.
- Akun awal admin dan super_admin dapat dibuat aman dari env.
- `super_admin` dapat mengelola akun admin.
- Admin biasa tidak bisa mengatur akun admin atau akunnya sendiri.
- Admin nonaktif tidak dapat mengakses admin area.
- Audit admin account tersedia.
- Desain admin login dan account management konsisten dengan sistem existing.
- Test relevan lulus.
