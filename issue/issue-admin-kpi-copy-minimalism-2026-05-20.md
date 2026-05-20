# Admin KPI copy minimalism

Tanggal: 2026-05-20

## Konteks

Card KPI admin di Overview, Users, Usage, Errors, Documents, dan Account Management masih memuat subtext yang terlalu ramai. Beberapa card menampilkan dua metadata sekaligus atau menonjolkan angka nol yang tidak perlu, sehingga dashboard terasa lebih seperti laporan padat daripada tool admin yang cepat dipindai.

## Tujuan

- Setiap card KPI hanya membawa satu subtext utama.
- Subtext memakai insight pendek, bukan kalimat penjelasan panjang.
- Angka nol yang tidak penting ditulis sebagai kondisi ringkas seperti `Tidak ada gagal`, bukan detail persentase panjang.
- Detail sekunder seperti latensi, memo mingguan, atau status pipeline tetap tersedia di area tabel/detail, bukan di card utama.
- Warna semantic tetap dipakai seperlunya: brand/aktif, sukses, warning, dan error.

## Scope

1. Overview
   - Hapus subtext kedua pada card ringkasan.
   - Ringkas metadata `Users`, `AI Usage`, `Documents`, dan `Percakapan & Memo`.

2. Monitoring pages
   - Ringkas description KPI pada `Users`, `Usage`, `Errors`, dan `Documents`.
   - Hindari copy panjang seperti `dari total user`, `pending / processing`, dan `error code tersanitasi`.

3. Account Management
   - Ringkas description KPI admin agar lebih scan-friendly.

4. Style
   - Sesuaikan warna metadata overview agar card ready/sukses tampil hijau, warning hanya untuk kondisi yang benar-benar warning.

## Rencana Implementasi

- Patch Blade KPI arrays di `laravel/resources/views/livewire/admin/*.blade.php`.
- Tambahkan helper kecil di Blade untuk memilih copy pendek berdasarkan angka.
- Patch CSS overview agar satu metadata tetap rapi dan semantic color lebih sesuai.
- Update test admin bila ada assertion copy lama yang perlu disesuaikan.

## Verifikasi

- `cd laravel && php artisan test tests/Feature/Admin/AdminMonitoringDashboardTest.php`
- `cd laravel && npm run build`
- Smoke check production setelah deploy untuk halaman admin utama.

## Risiko

- Risiko rendah karena perubahan bersifat presentational/copy.
- Test snapshot berbasis teks bisa perlu penyesuaian bila mengunci copy lama.
