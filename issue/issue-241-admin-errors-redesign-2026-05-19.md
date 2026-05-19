# Issue 241 - Admin Errors Redesign

## Tujuan
Merapikan `/admin/errors` agar konsisten dengan halaman admin Overview, Users, dan Usage.

## Scope
- Ubah heading, badge, filter, tabel error, dan panel ringkasan ke pola visual compact admin terbaru.
- Tambahkan KPI error yang dihitung dari filter aktif.
- Batasi tabel error menjadi 5 baris per halaman dengan pagination.
- Tetap tampilkan `request_id` untuk debugging tanpa membuat tabel penuh.

## Risiko
- Halaman harus tetap read-only dan tidak menampilkan isi percakapan, dokumen, atau memo.
- Summary error harus dihitung dari keseluruhan filter aktif, bukan hanya row halaman saat ini.
- CSS baru harus terisolasi di class `admin-errors-*`.

## Verifikasi
- Jalankan build Vite.
- Jalankan test admin monitoring dan unit service metrics.
