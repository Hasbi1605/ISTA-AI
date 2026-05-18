# Child 1: Admin Foundation, Role, Route Protection, dan Presence

Parent: https://github.com/Hasbi1605/ISTA-AI/issues/209
GitHub Issue: https://github.com/Hasbi1605/ISTA-AI/issues/214

## Latar Belakang
Dashboard admin membutuhkan fondasi akses yang aman sebelum modul monitoring, knowledge base, dan AI Configuration dibuat. Saat ini `users` belum memiliki role admin/super_admin dan belum ada `last_seen_at` untuk status aktif user.

## Tujuan
- Menambahkan role `user`, `admin`, dan `super_admin`.
- Melindungi route admin dengan middleware.
- Menambahkan presence tracking melalui `last_seen_at`.
- Menyiapkan fondasi tanpa mengubah perilaku chat, memo, dokumen, atau Google Drive.

## Ruang Lingkup
- Migration additive untuk `users`.
- Middleware admin/super_admin.
- Middleware presence dengan cache throttle.
- Route group `/admin`.
- Command/seeder untuk promote admin dan super_admin.
- Test akses dan presence.

## Di Luar Scope
- Dashboard KPI lengkap.
- Event tracking detail.
- Knowledge base internal.
- AI Configuration.
- ISTURA.

## Area / File Terkait
- `laravel/app/Models/User.php`
- `laravel/bootstrap/app.php`
- `laravel/routes/web.php`
- `laravel/database/migrations/*`
- `laravel/app/Http/Middleware/*`
- `laravel/app/Console/Commands/*`
- `laravel/tests/Feature/*`

## Risiko
- Salah konfigurasi middleware bisa membuka akses admin ke user biasa.
- Presence update terlalu sering bisa menambah beban database.
- Role `super_admin` harus tetap bisa mengakses route admin umum.

## Langkah Implementasi
1. Tambah migration `role`, `last_seen_at`, `last_active_feature` pada `users`.
2. Tambah cast/fillable yang diperlukan pada `User`.
3. Buat middleware `EnsureUserIsAdmin`.
4. Buat middleware `EnsureUserIsSuperAdmin`.
5. Buat middleware `UpdateUserPresence` dengan cache throttle 60 detik.
6. Daftarkan middleware di Laravel 11 bootstrap.
7. Buat route placeholder `/admin` yang hanya bisa diakses admin/super_admin.
8. Buat command promote admin dan promote super_admin.
9. Tambah test akses dan presence.

## Rencana Test
```bash
cd laravel && php artisan test --filter='Admin|Presence|User'
```

Minimal test:
- Guest diarahkan ke login.
- User biasa 403 untuk `/admin`.
- Admin bisa membuka `/admin`.
- Super admin bisa membuka `/admin` dan `/admin/ai-config`.
- Admin biasa 403 untuk `/admin/ai-config`.
- `last_seen_at` terupdate dan tidak update terlalu sering.

## Kriteria Selesai
- Role dan middleware tersedia.
- Route admin terlindungi.
- Presence user tercatat.
- Tidak ada perubahan perilaku chat/memo/dokumen existing.
- Test relevan lulus.
