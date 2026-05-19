# Redesign Admin Overview sesuai referensi

## Latar Belakang
Halaman `/admin` saat ini sudah menampilkan metrik operasional, tetapi susunan visualnya masih berupa blok KPI generik dan belum mengikuti referensi yang diberikan: sidebar lebih ringan, topbar dengan breadcrumb dan search, hero ringkasan operasional, kartu metrik ringkas, grafik garis, donut distribusi, ringkasan insiden, tabel aktivitas, dan daftar error.

## Tujuan
Menyusun ulang halaman admin overview agar tampak sangat dekat dengan referensi gambar, tanpa mengubah kontrak data monitoring, kontrol akses, atau isi sensitif yang sengaja tidak ditampilkan.

## Ruang Lingkup
- Update layout admin bersama untuk sidebar dan topbar yang terlihat pada halaman overview.
- Update view Livewire `AdminDashboard` untuk hero, kartu ringkasan, grafik, donut, ringkasan insiden, tabel aktivitas, dan daftar error.
- Update CSS admin design system yang dibutuhkan oleh tampilan baru.
- Sambungkan teks tren hero dan ringkasan insiden ke perbandingan nyata dari `AdminMetricsService`.
- Pertahankan tautan ke halaman admin yang sudah ada.

## Di Luar Scope
- Tidak menambah chart library JavaScript baru.
- Tidak mengubah model monitoring atau skema database.
- Tidak mengubah akses admin, auth, logout, atau page admin lain di luar dampak visual shell bersama.
- Tidak melakukan deploy production.

## Area / File Terkait
- `laravel/resources/views/layouts/admin.blade.php`
- `laravel/resources/views/components/layouts/admin.blade.php`
- `laravel/resources/views/components/admin/sidebar.blade.php`
- `laravel/resources/views/livewire/admin/admin-dashboard.blade.php`
- `laravel/resources/css/app.css`
- `laravel/app/Services/Admin/AdminMetricsService.php`
- Test admin existing di `laravel/tests/Feature/Admin`
- Unit test service di `laravel/tests/Unit/Services/Admin/AdminMetricsServiceTest.php`

## Risiko
- Shell admin dipakai halaman admin lain, sehingga perubahan topbar/sidebar harus tetap responsif dan tidak merusak navigasi.
- Grafik SVG/donut CSS harus tetap aman ketika data kosong.
- Perhitungan tren harus menampilkan state "belum ada pembanding" saat periode pembanding kosong agar tidak menampilkan angka palsu.
- Test snapshot berbasis teks/class dapat gagal jika class penting hilang.

## Langkah Implementasi
1. Petakan markup dashboard, layout, sidebar, dan CSS admin yang sudah ada.
2. Refactor sidebar menjadi dua grup menu seperti referensi dan tetap pertahankan link super admin.
3. Refactor topbar menjadi breadcrumb, search, tombol tema, kembali ke chat, logout, dan profil.
4. Ubah overview menjadi hero ringkasan, empat kartu metrik, grid grafik/donut/insiden, tabel aktivitas, dan error list.
5. Tambahkan perhitungan tren hari ini vs kemarin dan 7 hari terakhir vs 7 hari sebelumnya.
6. Tambahkan kelas CSS khusus admin overview untuk visual yang lebih dekat dengan referensi.
7. Jalankan build asset dan test Laravel yang relevan.
8. Simplifikasi overview agar tidak terlalu ramai: hero hanya 3 KPI utama, card ringkas memakai satu angka utama, distribusi fitur dipindahkan ke tab Usage, panel error digabung menjadi insiden terbaru, dan tabel aktivitas dibatasi 3 baris.
9. Kembalikan header empat card ringkasan ke gaya sebelumnya: icon kecil dan judul merah, tetapi isi card tetap ringkas.

## Rencana Test
- `cd laravel && npm run build`
- `cd laravel && php artisan test tests/Unit/Services/Admin/AdminMetricsServiceTest.php`
- `cd laravel && php artisan test tests/Feature/Admin/AdminAccessTest.php tests/Feature/Admin/AdminLayoutTest.php tests/Feature/Admin/AdminMonitoringDashboardTest.php`
- Jika ada kegagalan akibat perubahan markup yang disengaja, update test secara minimal selama coverage perilaku tetap sama.

## Kriteria Selesai
- Halaman `/admin` memiliki struktur visual sesuai referensi gambar.
- Navigasi admin, tombol kembali ke chat, logout, dark mode, dan profil tetap tersedia.
- Data sensitif prompt/jawaban tetap tidak tampil.
- Build frontend dan test admin relevan lulus atau kegagalannya terdokumentasi jelas.
