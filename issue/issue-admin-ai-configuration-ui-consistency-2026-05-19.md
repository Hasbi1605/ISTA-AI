# Redesign Admin AI Configuration UI

## Latar Belakang
Halaman AI Configuration masih berupa placeholder, sementara tab admin lain sudah memakai pola compact: hero, KPI dot-card, panel konfigurasi, tabel/status list, dan detail operasional yang rapi.

## Tujuan
- Menyamakan tampilan AI Configuration dengan Overview, Users, Usage, Errors, Documents, dan Account Management.
- Menampilkan konfigurasi AI yang sudah tersedia dari Laravel config tanpa membuka secret.
- Membuat halaman terasa seperti pusat kendali AI, walau backend editor prompt/model penuh belum tersedia.

## Ruang Lingkup
- Redesign Blade `admin.ai-config`.
- Tambah CSS khusus `admin-ai-config-*`.
- Pakai nilai dari `services.ai_service` dan `services.ai_document_service` bila tersedia.
- Update test akses/layout untuk assertion UI baru.

## Di Luar Scope
- Tidak membuat tabel konfigurasi AI baru.
- Tidak menambah aksi simpan/activate/rollback.
- Tidak menampilkan token/API key mentah.
- Tidak mengubah runtime model resolver atau service AI.

## Area / File Terkait
- `laravel/resources/views/admin/ai-config.blade.php`
- `laravel/resources/css/app.css`
- `laravel/tests/Feature/Admin/AdminAccessTest.php`
- `laravel/tests/Feature/Admin/AdminLayoutTest.php`

## Risiko
- UI tidak boleh memberi kesan konfigurasi tersimpan bila backend belum ada.
- Nilai endpoint tidak boleh menampilkan token.
- Copy perlu jelas sebagai status operasional, bukan fitur edit penuh.

## Langkah Implementasi
1. Bangun hero dan KPI dot-card konsisten dengan tab admin lain.
2. Tampilkan routing model/pipeline per fitur sebagai tabel ringkas.
3. Tampilkan parameter service dan prompt profile sebagai panel compact.
4. Tampilkan guardrail dan rollout status tanpa aksi destruktif.
5. Tambah CSS responsive dan dark mode.
6. Update test dan jalankan verifikasi.

## Rencana Test
- `cd laravel && php artisan test tests/Feature/Admin/AdminAccessTest.php tests/Feature/Admin/AdminLayoutTest.php`
- `cd laravel && npm run build`
- `git diff --check`

## Kriteria Selesai
- AI Configuration tidak lagi placeholder.
- Tampilan konsisten dengan tab admin lain.
- Secret tetap tidak muncul.
- Test dan build lulus.
