# Admin Bootstrap-Style Pagination Hotfix

Tanggal: 2026-05-21

## Gejala

- Tombol pagination pada tab Usage masih kadang terlihat hilang sebagian setelah navigasi antar halaman.
- Masalah terlihat acak karena jumlah tombol yang dirender berubah per halaman dan layout custom sebelumnya bergantung pada flex row yang mudah kehabisan ruang.

## Acuan

- Gunakan struktur pagination Bootstrap: `nav > ul.pagination > li.page-item > .page-link`.
- Pertahankan interaksi Livewire `wire:click` agar tidak terjadi full page reload.
- Warna disesuaikan ke brand ISTA.

## Scope

- Ganti markup shared `admin.pagination` ke pola Bootstrap-style.
- Ganti CSS pagination agar list memakai lebar baris penuh, fixed-size controls, dan wrapping yang stabil.
- Update test markup pagination agar memverifikasi struktur `pagination/page-item/page-link`.
- Jalankan test Laravel relevan dan build asset sebelum deploy.

## Risiko

- View pagination dipakai beberapa halaman admin, jadi perubahan harus tetap umum.
- Perubahan tidak boleh mengubah query/listing, hanya rendering kontrol pagination.

## Verifikasi

- `cd laravel && php artisan test tests/Feature/Admin/AdminPaginationViewTest.php tests/Feature/Admin/AdminMonitoringDashboardTest.php`
- `cd laravel && npm run build`
