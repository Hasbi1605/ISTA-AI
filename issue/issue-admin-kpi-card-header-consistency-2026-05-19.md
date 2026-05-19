# Samakan Header KPI Card Admin

## Latar Belakang
Empat card ringkasan di Overview sudah memakai gaya yang lebih disukai: icon kecil dan judul merah. KPI card di tab lain masih memakai dot marker sehingga terasa kurang konsisten.

## Tujuan
- Menyamakan header KPI card bagian atas di semua tab admin.
- Memakai pola icon kecil + judul merah seperti Overview.
- Menjaga isi card tetap ringkas dan tidak mengubah data atau aksi.

## Ruang Lingkup
- Users
- Usage
- Errors
- Documents
- Account Management
- AI Configuration

## Di Luar Scope
- Panel kerja seperti Filter, tabel, chart, drawer, dan modal.
- Perubahan query/backend.
- Perubahan wording besar selain penambahan icon.

## Area / File Terkait
- `laravel/resources/views/livewire/admin/admin-users.blade.php`
- `laravel/resources/views/livewire/admin/admin-usage.blade.php`
- `laravel/resources/views/livewire/admin/admin-errors.blade.php`
- `laravel/resources/views/livewire/admin/admin-documents.blade.php`
- `laravel/resources/views/livewire/admin/admin-accounts.blade.php`
- `laravel/resources/views/admin/ai-config.blade.php`
- `laravel/resources/css/app.css`
- Test admin feature terkait

## Langkah Implementasi
1. Tambahkan path icon pada array KPI tiap tab.
2. Ganti marker dot menjadi SVG icon pada markup KPI.
3. Tambah CSS umum untuk header KPI icon + label merah.
4. Update assertion test ringan agar ikon KPI ikut terjaga.
5. Jalankan test admin dan build frontend.

## Rencana Test
- `cd laravel && php artisan test tests/Feature/Admin/AdminMonitoringDashboardTest.php tests/Feature/Admin/AdminAccountManagementTest.php tests/Feature/Admin/AdminLayoutTest.php`
- `cd laravel && npm run build`
- `git diff --check`
