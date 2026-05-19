# Issue 240 - Admin Usage Redesign

## Tujuan
Merapikan `/admin/usage` agar konsisten dengan halaman admin Overview dan Users, serta mengganti ikon Usage di sidebar yang saat ini menyerupai ikon musik.

## Scope
- Ganti ikon sidebar Usage menjadi ikon grafik/aktivitas.
- Ubah heading/topbar Usage agar konsisten.
- Tambahkan KPI compact untuk total event, sukses, pending, dan gagal/blocked berbasis query backend.
- Rapikan filter, tabel event, dan panel distribusi fitur dengan ukuran dan ritme visual seperti tab Users.

## Risiko
- Query KPI harus tetap read-only dan tidak mengambil isi percakapan/dokumen/memo.
- CSS baru harus terisolasi di class `admin-usage-*`.

## Verifikasi
- Jalankan build Vite.
- Jalankan test admin monitoring dan unit service metrics.
