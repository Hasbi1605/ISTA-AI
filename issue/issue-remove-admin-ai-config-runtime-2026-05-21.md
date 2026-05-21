# Remove Admin AI Configuration Runtime

## Latar Belakang

Halaman Admin AI Configuration membingungkan karena tampil seperti katalog konfigurasi teknis, sementara keputusan produk terbaru adalah konfigurasi AI tetap dikelola manual melalui `python-ai/config/ai_config.yaml`.

## Tujuan

- Menghapus halaman admin AI Configuration dari navigasi dan route.
- Menghapus backend runtime konfigurasi AI berbasis database di Laravel.
- Memastikan Laravel tidak lagi mengirim override `runtime_config` dari database ke Python AI service.
- Membiarkan `python-ai/config/ai_config.yaml` sebagai sumber konfigurasi manual.

## Ruang Lingkup

- Route, sidebar, Livewire component, Blade view, CSS, model, service, migration, dan test yang khusus untuk AI Configuration DB/UI.
- Pemanggilan chat, document summary, dan memo generation agar tidak memakai `AIConfigurationResolver`.
- Test admin layout/access yang sebelumnya mengharapkan menu dan halaman AI Configuration.

## Di Luar Scope

- Mengubah isi `python-ai/config/ai_config.yaml`.
- Mengubah perilaku model/prompt/retrieval Python selain kembali ke konfigurasi YAML yang sudah ada.
- Menghapus event tracking AI usage yang masih dipakai monitoring admin.

## Area / File Terkait

- `laravel/routes/web.php`
- `laravel/resources/views/components/admin/sidebar.blade.php`
- `laravel/app/Services/AIService.php`
- `laravel/app/Services/Memo/MemoGenerationService.php`
- `laravel/app/Jobs/GenerateChatResponse.php`
- `laravel/app/Http/Controllers/Chat/ChatStreamController.php`
- File khusus AI config di `laravel/app/Livewire/Admin`, `laravel/app/Models`, `laravel/app/Services/AI`, `laravel/resources/views`, dan `laravel/tests/Feature/Admin`.
- Migration drop untuk membersihkan tabel AI config pada environment yang sudah pernah menjalankan migration lama.

## Risiko

- Reference tersisa ke class/model AI config dapat membuat autoload atau test gagal.
- Jika database production sudah pernah menjalankan migration AI config, migration drop akan menghapus tabel lama yang tidak lagi dipakai.
- Test admin perlu diperbarui agar route yang dihapus tidak dianggap regression.

## Langkah Implementasi

1. Hapus menu dan route `/admin/ai-config`.
2. Hapus Livewire component, Blade view, model, service, migration create lama, config env DB runtime, dan test khusus AI Configuration.
3. Tambahkan migration drop tabel AI config agar database existing ikut bersih.
4. Hilangkan integrasi resolver dari chat stream, job fallback, AIService, dan MemoGenerationService.
5. Bersihkan CSS khusus AI config dan env example.
6. Jalankan pencarian reference untuk memastikan tidak ada class/route/config AI config tersisa di kode aktif.
7. Jalankan verifikasi Laravel yang relevan.

## Rencana Test

- `cd laravel && php artisan test --filter=AdminAccessTest`
- `cd laravel && php artisan test --filter=AdminLayoutTest`
- `cd laravel && php artisan test --filter=ChatStreamTest`
- `cd laravel && php artisan test --filter=MemoGenerationServiceTest`
- `cd laravel && npm run build`
- `cd laravel && git diff --check`

## Kriteria Selesai

- Menu AI Configuration tidak tampil lagi.
- `/admin/ai-config` tidak memiliki route aktif.
- Tidak ada class/service/model runtime DB AI config yang dipakai kode aktif.
- Laravel tidak mengirim `runtime_config` DB override ke Python AI service.
- Test relevan dan build frontend lulus.

## Hasil Implementasi

- Route/menu AI Configuration dihapus; route list admin tidak lagi memuat `/admin/ai-config`.
- Livewire page, Blade, CSS, model, service, migration create lama, dan test khusus AI Configuration dihapus.
- Migration `2026_05_21_000001_drop_ai_configuration_tables.php` ditambahkan untuk membersihkan tabel lama.
- `AIService`, `ChatStreamController`, `GenerateChatResponse`, dan `MemoGenerationService` kembali memakai konfigurasi Python YAML tanpa override DB.
- Verifikasi lulus: `AdminAccessTest`, `AdminLayoutTest`, `AIServiceTest`, `ChatStreamTest`, `MemoGenerationServiceTest`, `npm run build`, `php artisan view:cache`, dan `git diff --check`.
