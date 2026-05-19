# Redesign Admin Account Management UI

## Latar Belakang
Halaman Account Management masih memakai layout lama, sementara tab admin lain sudah memakai pola compact: hero, KPI dot-card, filter panel, table panel, dan modal/drawer yang rapi.

## Tujuan
- Menyamakan tampilan Account Management dengan Overview, Users, Usage, Errors, dan Documents.
- Menjaga semua aksi sensitif tetap memakai service dan guard yang ada.
- Menampilkan ringkasan akun admin secara dinamis.

## Ruang Lingkup
- Redesign Blade Account Management.
- Tambah summary data di Livewire render tanpa mengubah aturan bisnis.
- Tambah CSS khusus `admin-accounts-*`.
- Update test feature yang relevan bila assertion UI berubah.
- Refinement tabel admin mengikuti referensi: kolom akun/role/status/login/aksi, tanpa 2FA, tanpa kolom password, dan tanpa kartu prioritas akun.

## Di Luar Scope
- Tidak mengubah permission super admin.
- Tidak mengubah logic create/edit/deactivate/reset password.
- Tidak mengubah audit log atau skema database.

## Area / File Terkait
- `laravel/app/Livewire/Admin/AdminAccounts.php`
- `laravel/resources/views/livewire/admin/admin-accounts.blade.php`
- `laravel/resources/css/app.css`
- `laravel/tests/Feature/Admin/AdminAccountManagementTest.php`

## Risiko
- Aksi akun sensitif tidak boleh kehilangan confirm/validation.
- Modal create/edit/deactivate harus tetap memetakan error Livewire dengan benar.
- Header dan table harus tetap memuat teks yang dipakai test.

## Langkah Implementasi
1. Tambah summary akun di Livewire.
2. Redesign halaman dengan pola admin modern.
3. Buat filter panel dan table compact.
4. Samakan modal create/edit/deactivate dengan gaya drawer/modal admin terbaru.
5. Jalankan test dan build.
6. Refinement tabel admin: hapus kolom password, aksi menjadi Edit + toggle status + Reset Password, dan ubah panel kanan menjadi Ringkasan Keamanan tanpa data 2FA.
7. Padatkan ukuran KPI/card dan hilangkan kebutuhan scrollbar horizontal pada tabel admin.
8. Samakan avatar, role badge, dan status badge tabel Account Management dengan pola tabel Users.
9. Pastikan pagination Account Management tetap 15 akun per halaman dan header aksi tidak menempel ke sisi kanan tabel.
10. Perbaiki Ringkasan Keamanan agar item memakai ikon berwarna seperti referensi dan tetap tanpa 2FA.
11. Hapus panel Ringkasan Keamanan dan lebarkan tabel Account Management menjadi full-width.
12. Ubah 4 KPI header Account Management mengikuti referensi tanpa ikon: total akun, akun aktif, perlu reset password, dan super admin aktif.

## Rencana Test
- `php artisan test tests/Feature/Admin/AdminAccountManagementTest.php`
- `php artisan test tests/Feature/Admin/AdminMonitoringDashboardTest.php`
- `npm run build`
- `git diff --check`

## Kriteria Selesai
- UI Account Management konsisten dengan tab admin lain.
- Semua aksi akun tetap tersedia.
- Test dan build lulus.
