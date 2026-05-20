# Admin Pagination Window

Tanggal: 2026-05-20

## Tujuan

Membuat pagination tabel admin tetap ringkas saat jumlah halaman bertambah, tanpa menghilangkan konteks posisi halaman.

## Scope

- Terapkan pola pagination shared untuk semua tabel admin yang memakai `admin.pagination`.
- Jika total halaman kecil, tampilkan semua nomor halaman.
- Jika total halaman besar, tampilkan halaman pertama, halaman terakhir, halaman aktif, satu tetangga kiri/kanan, dan ellipsis.
- Pertahankan kompatibilitas Livewire `previousPage`, `nextPage`, dan `gotoPage`.

## File Terkait

- `laravel/resources/views/admin/pagination.blade.php`
- `laravel/tests/Feature/Admin/AdminMonitoringDashboardTest.php`

## Rencana Implementasi

1. Hitung daftar item pagination di view shared berdasarkan total halaman dan halaman aktif.
2. Render tombol angka dan ellipsis dari daftar tersebut.
3. Tambahkan test regresi untuk total halaman besar agar jumlah angka tidak melebar terus.
4. Jalankan test Laravel relevan dan build frontend bila diperlukan.

## Risiko

- Pagination shared dipakai banyak halaman admin, jadi perubahan harus tetap aman untuk semua paginator Livewire.
- Test existing di file admin sedang memiliki perubahan lokal; patch harus seminimal mungkin dan tidak merusak perubahan yang sudah ada.
