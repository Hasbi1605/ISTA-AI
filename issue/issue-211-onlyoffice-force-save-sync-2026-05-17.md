# Sinkronisasi Force-Save OnlyOffice untuk Memo

## Latar Belakang
Edit manual di OnlyOffice dapat terlihat di editor, tetapi belum tentu sudah tersimpan ke file DOCX Laravel. Tombol DOCX/PDF, revisi via prompt, dan generate ulang konfigurasi saat ini dapat berjalan dari file atau `searchable_text` yang masih lama. Setelah refresh, OnlyOffice juga dapat menampilkan peringatan versi berubah karena lifecycle `document.key` belum dikelola jelas setelah save final.

## Tujuan
- Menjadikan file DOCX hasil callback OnlyOffice sebagai sumber kebenaran sebelum download/export/revisi.
- Memicu force-save sebelum DOCX/PDF, revisi via prompt, dan revisi konfigurasi.
- Memastikan sesi berikutnya memakai document key baru setelah save final.
- Memberi status UI saat dokumen sedang disimpan.

## Ruang Lingkup
- Tambah service Laravel untuk command service `forcesave` OnlyOffice.
- Tambah endpoint auth untuk force-save memo aktif/version aktif.
- Tambah marker callback `status=6` berbasis `userdata` agar endpoint dapat menunggu save selesai.
- Update frontend download memo agar menunggu force-save.
- Update form revisi prompt dan konfigurasi agar memicu sinkronisasi sebelum Livewire action.
- Update konfigurasi editor untuk menampilkan fitur Save OnlyOffice.
- Update callback untuk invalidasi key pada save final.
- Tambah test feature/unit relevan.

## Di Luar Scope
- Mengubah format dokumen memo dari sisi Python generator.
- Menambah sistem histori visual OnlyOffice.
- Merge/deploy production.

## Area / File Terkait
- `laravel/app/Http/Controllers/OnlyOfficeCallbackController.php`
- `laravel/app/Http/Controllers/Memos/MemoFileController.php`
- `laravel/app/Livewire/Memos/MemoWorkspace.php`
- `laravel/app/Services/OnlyOffice/*`
- `laravel/resources/js/chat-page.js`
- `laravel/resources/views/livewire/memos/partials/*`
- `laravel/routes/web.php`
- `laravel/tests/Feature/Memos/*`

## Risiko
- Force-save command bisa lambat atau gagal bila session editor belum aktif.
- Callback status `6` bisa datang setelah timeout endpoint.
- Invalidasi key terlalu agresif dapat membuat session aktif stale.
- Regenerate dari konfigurasi dapat tetap menimpa edit manual bila baseline tidak disinkronkan.

## Langkah Implementasi
1. Tambah `MemoForceSaveService` untuk command service `/command?shardkey=...` dengan JWT dan wait marker.
2. Tambah endpoint `POST /chat/memos/{memo}/force-save`.
3. Update callback status `6` untuk menandai `userdata` selesai, dan status `2` untuk invalidasi key session berikutnya.
4. Update frontend download agar `await forceSave` sebelum fetch DOCX/PDF.
5. Update submit revisi prompt dan submit konfigurasi agar sinkronisasi berjalan sebelum Livewire action.
6. Update mode konfigurasi agar revisi konfigurasi membawa body memo terbaru setelah sync.
7. Tambah test untuk force-save endpoint, callback marker, download/revisi sync contract, dan key invalidation.

## Rencana Test
- `php artisan test tests/Feature/Memos/OnlyOfficeCallbackTest.php tests/Feature/Memos/MemoPolicyTest.php tests/Feature/Memos/MemoWorkspaceTest.php`
- Jalankan subset tambahan sesuai file test baru/terdampak.
- Bila JS build tersedia, jalankan `npm run build`.

## Kriteria Selesai
- Download DOCX/PDF tidak berjalan dari file lama saat editor aktif dan ada perubahan.
- Revisi prompt/config menunggu sinkronisasi editor terlebih dahulu.
- Callback status `6` dapat ditunggu oleh endpoint force-save.
- Callback status `2` menyiapkan key baru untuk sesi berikutnya.
- Test relevan lulus dan risiko tersisa diringkas.
