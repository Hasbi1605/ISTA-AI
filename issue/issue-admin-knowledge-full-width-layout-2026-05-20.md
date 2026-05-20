# Admin Knowledge Full Width Layout

## Latar Belakang
Halaman Admin Knowledge masih memakai panel kanan berisi `Status pipeline` dan `Pipeline`. Setelah KPI cards menampilkan Total, Ready, Processing, dan Failed, panel status menjadi informasi duplikat. Panel pipeline juga lebih berupa penjelasan statis dan mengambil ruang horizontal yang seharusnya dipakai tabel dokumen.

## Tujuan
- Menghapus panel kanan `Status pipeline` dan `Pipeline`.
- Membuat area filter dan tabel `Dokumen Knowledge` full width.
- Memindahkan tombol `Upload Knowledge` ke header card `Dokumen Knowledge`.
- Menjaga banner processing dinamis tetap muncul hanya saat ada dokumen pending.

## Ruang Lingkup
- Blade halaman Admin Knowledge.
- CSS layout Admin Knowledge.
- Test fitur Admin Knowledge untuk memastikan layout baru tidak regress.

## Di Luar Scope
- Perubahan upload modal.
- Perubahan pipeline worker/job.
- Redesign KPI cards.
- Perubahan status, filter, atau pagination.

## Area / File Terkait
- `laravel/resources/views/livewire/admin/admin-knowledge.blade.php`
- `laravel/resources/css/app.css`
- `laravel/tests/Feature/Admin/AdminKnowledgeManagementTest.php`

## Risiko
- Tabel bisa terlalu lebar di desktop jika spacing tidak diatur; mitigasi dengan stack full-width yang tetap constrained oleh layout admin.
- Tombol upload bisa kurang terlihat setelah dipindah; mitigasi dengan posisi di kanan header tabel, dekat konteks dokumen.
- Test lama yang mencari teks `Pipeline` perlu tetap membedakan kolom tabel dari panel penjelasan.

## Langkah Implementasi
1. Hapus tombol upload dari hero.
2. Tambahkan tombol upload di header `Dokumen Knowledge`.
3. Hapus `aside` berisi `Status pipeline` dan `Pipeline`.
4. Ubah wrapper konten menjadi single full-width stack.
5. Bersihkan CSS layout yang tidak lagi dipakai.
6. Tambahkan/ubah test layout.

## Rencana Test
- `php artisan test --filter=AdminKnowledgeManagementTest`
- `./vendor/bin/pint --test tests/Feature/Admin/AdminKnowledgeManagementTest.php`
- `npm run build`
- `php -d memory_limit=-1 vendor/bin/phpunit` bila targeted verification sudah stabil.

## Kriteria Selesai
- Tidak ada panel `Status pipeline`.
- Tidak ada panel `Pipeline` urutan proses.
- Filter dan tabel dokumen memakai lebar penuh area admin.
- Tombol `Upload Knowledge` berada di header card dokumen.
- Test dan build relevan lulus.
