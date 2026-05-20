# Admin Knowledge Pipeline Refresh dan Action Button Polish

## Latar Belakang
Halaman Admin Knowledge menampilkan dokumen yang baru di-upload sebagai `Processing`, tetapi status tidak berubah ke `Ready` setelah job selesai kecuali admin melakukan refresh manual. Kolom `Aksi` juga masih memakai tombol teks yang terlalu besar, mudah menempel, dan menampilkan aksi yang kurang relevan untuk dokumen yang masih diproses.

## Tujuan
- Membuat status pipeline knowledge tersinkron otomatis saat ada dokumen `draft` atau `processing`.
- Memberi indikator ringkas bahwa pipeline sedang dipantau/diproses.
- Mengubah tombol aksi tabel menjadi icon-only, minimalis, dan state-aware.
- Mencegah aksi yang tidak tepat untuk status dokumen tertentu, terutama `Aktifkan` pada dokumen yang belum selesai diproses.

## Ruang Lingkup
- Komponen Livewire Admin Knowledge.
- Blade view halaman Admin Knowledge.
- CSS Admin Knowledge.
- Test fitur Admin Knowledge yang mengunci polling dan tombol aksi.

## Di Luar Scope
- Perubahan worker/job processing knowledge.
- Perubahan API Python ingest atau queue infrastructure.
- Redesign penuh halaman Admin Knowledge.
- Perubahan permission/admin role.

## Area / File Terkait
- `laravel/app/Livewire/Admin/AdminKnowledge.php`
- `laravel/app/Models/KnowledgeDocument.php`
- `laravel/resources/views/livewire/admin/admin-knowledge.blade.php`
- `laravel/resources/css/app.css`
- `laravel/tests/Feature/Admin/AdminKnowledgeManagementTest.php`

## Risiko
- Polling terlalu agresif dapat menambah request Livewire; mitigasi dengan polling hanya saat ada dokumen pending dan modal upload tidak aktif.
- Tombol icon-only bisa kurang jelas bila tidak ada label aksesibilitas; mitigasi dengan `title` dan `aria-label`.
- Guard status bisa mengubah aksi yang dulu terlihat; mitigasi dengan tetap mempertahankan reprocess/delete untuk pemulihan dokumen.

## Langkah Implementasi
1. Tambahkan method refresh ringan pada Livewire agar polling memicu re-render.
2. Hitung jumlah dokumen pending dari status `draft` dan `processing`.
3. Render polling bersyarat saat pending ada dan modal upload tidak sedang aktif.
4. Tambahkan indikator pipeline aktif yang ringkas.
5. Ubah action button menjadi icon-only dengan guard status.
6. Tambahkan server-side guard pada action Livewire agar aksi tidak valid tidak berjalan dari request manual.
7. Tambahkan test untuk polling, idle state, dan tombol aksi state-aware.

## Rencana Test
- Jalankan `php artisan test --filter=AdminKnowledgeManagementTest`.
- Jalankan Pint pada file PHP yang berubah.
- Jalankan `npm run build` untuk memastikan CSS/Blade aman diproses.
- Jalankan full PHPUnit bila perubahan relevan sudah stabil.

## Kriteria Selesai
- Dokumen `draft` atau `processing` memunculkan polling otomatis dan indikator pipeline.
- Saat semua dokumen sudah `active/error/archived`, polling aktif hilang.
- Tombol aksi knowledge compact icon-only, punya label aksesibilitas, dan tidak menampilkan `Aktifkan` untuk `draft/processing`.
- Test relevan dan build lulus.
