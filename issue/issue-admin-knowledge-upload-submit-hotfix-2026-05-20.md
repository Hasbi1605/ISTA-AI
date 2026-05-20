# Admin Knowledge Upload Submit Hotfix - 2026-05-20

## Gejala
- File sudah tampil di modal upload Knowledge, tetapi klik `Upload Knowledge` tidak membuat dokumen baru.
- Modal masih bisa ditutup dengan `X` atau `Batal` setelah tombol upload ditekan.
- Dropzone masih bisa menerima file baru setelah tombol upload ditekan.
- Setelah patch pertama, file name dan loading sudah muncul, tetapi tombol submit bisa tetap tidak bisa dipencet.

## Bukti
- Production menerima request `/livewire/upload-file`, jadi temporary upload file sudah masuk.
- Nama file di modal dirender dari `$file->getClientOriginalName()`, sehingga file sudah sampai ke state Livewire, bukan hanya tampil dari browser lokal.
- Tidak ada row baru di `knowledge_documents`.
- Tidak ada exception Knowledge upload di log Laravel pada waktu reproduksi.

## Dugaan Akar Masalah
- Submit final masih bergantung pada submit form Livewire dan loading state `wire:loading`; saat action final tidak benar-benar masuk/tertahan di browser, UI tidak masuk mode locked.
- Modal belum punya state eksplisit di komponen untuk membedakan proses temporary upload file dan proses submit knowledge.
- Gate client `data-upload-can-submit`/`canSubmit()` bisa stale atau belum sinkron setelah temporary upload dan field source update, sehingga tombol masih disabled walaupun UI sudah menampilkan nama file dan source.

## Scope Perbaikan
- Tambahkan state backend `isUploading` yang dikunci sejak action `upload()` mulai sampai selesai/gagal.
- Jadikan submit form memanggil action eksplisit `submitKnowledgeUpload` melalui `$wire.$call(...)`, bukan hanya bergantung pada submit form Livewire.
- Hapus gate disabled berbasis `data-upload-can-submit`; validasi wajib dikembalikan ke backend agar tombol tetap bisa dipencet dan error muncul bila state belum lengkap.
- Kunci tombol `X`, `Batal`, input, select, textarea, dan dropzone saat submit sedang berjalan.
- Tampilkan status processing berbasis state backend, sehingga user langsung melihat proses sedang berjalan.
- Tambahkan test untuk memastikan markup submit final punya action eksplisit dan kontrol modal terkunci saat state uploading.

## Verifikasi
- `php artisan test --filter=AdminKnowledgeManagementTest`
- `php -d memory_limit=-1 vendor/bin/phpunit`
- `./vendor/bin/pint --test app/Livewire/Admin/AdminKnowledge.php tests/Feature/Admin/AdminKnowledgeManagementTest.php`
- `npm run build`
- Deploy production dan smoke check `/up`.
