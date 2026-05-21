# Admin Usage Pagination Overflow Hotfix

Tanggal: 2026-05-21

## Gejala

- Pagination pada tab Usage terlihat tidak stabil saat berada di halaman tengah/akhir.
- Tombol halaman kanan dapat terpotong di panel tabel Usage.
- Total KPI dan total listing dapat terasa tidak satu konteks karena tabel menyembunyikan event `started`, sementara ringkasan masih menghitung semua event.

## Scope

- Stabilkan layout pagination agar tidak terpotong di panel Usage.
- Samakan KPI Usage dengan filter listing ketika event lifecycle disembunyikan.
- Tambahkan test regresi untuk window pagination 46 event / 5 per halaman.
- Tambahkan test agar total Usage mengikuti event yang sedang ditampilkan saat `started` disembunyikan.

## Risiko

- Pagination dipakai bersama beberapa halaman admin, jadi perubahan CSS harus tetap aman untuk tabel admin lain.
- Perubahan total harus tetap mempertahankan perilaku filter status; jika status dipilih, event lifecycle tetap boleh masuk sesuai filter.

## Rencana Implementasi

1. Update CSS pagination shared agar nav tidak terpotong dan bisa wrap dengan stabil.
2. Update grid/panel Usage dengan `min-width` yang aman untuk area tabel.
3. Update `AdminUsage` agar summary KPI memakai filter listing yang sama.
4. Tambah/update test Laravel terkait pagination dan summary.
5. Jalankan test Laravel relevan dan build asset.

## Verifikasi

- `cd laravel && php artisan test tests/Feature/Admin/AdminPaginationViewTest.php tests/Feature/Admin/AdminMonitoringDashboardTest.php`
- `cd laravel && npm run build`
