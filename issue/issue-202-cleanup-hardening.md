# Issue 202 Cleanup Hardening

## Latar Belakang
GitHub issue #202 memisahkan technical debt dan hardening dari bug runtime issue #201. Scope ini tidak ditujukan untuk mengubah produk besar, tetapi memperjelas flow legacy, menghapus kode tidak reachable, dan menguatkan edge case kecil.

## Tujuan
- Memperjelas dua flow OTP yang masih sah: pending registration OTP dan account email verification OTP.
- Menghapus `MemoCanvas` legacy jika `MemoWorkspace` adalah entry point final.
- Menghapus fallback path `ProcessDocument` yang salah untuk disk `local` saat ini.
- Menguatkan parser stream metadata `[SOURCES:...]` tanpa mematahkan buffering partial chunk.
- Membuat handling `version_id` memo file endpoint eksplisit dan token-bound.

## Ruang Lingkup
- Laravel auth copy/comment kecil tanpa migrasi data.
- Removal komponen/view/test `MemoCanvas` yang tidak lagi reachable dari route user.
- Laravel document processing path check.
- Laravel chat orchestration metadata parsing.
- Memo file controller dan OnlyOffice file token validation.
- Targeted tests untuk auth, memo, document, stream parser, dan memo version token.

## Di Luar Scope
- Perubahan Python AI selain memahami kontrak output saat ini.
- Redesign UI memo/chat.
- Mengubah flow registration OTP cache-based menjadi table-based, atau sebaliknya.
- Menggabungkan perubahan issue #201.

## Area / File Terkait
- `laravel/app/Services/Auth/PendingRegistrationWorkflowService.php`
- `laravel/app/Models/User.php`
- `laravel/resources/views/livewire/pages/auth/verify-email.blade.php`
- `laravel/app/Livewire/Memos/MemoCanvas.php`
- `laravel/resources/views/livewire/memos/memo-canvas.blade.php`
- `laravel/tests/Feature/Memos/MemoCanvasTest.php`
- `laravel/app/Jobs/ProcessDocument.php`
- `laravel/app/Services/ChatOrchestrationService.php`
- `laravel/app/Http/Controllers/Memos/MemoFileController.php`
- `laravel/app/Services/OnlyOffice/MemoDocumentKey.php`

## Risiko
- Menghapus `MemoCanvas` harus tidak menghapus route redirect legacy yang masih diuji.
- Parser metadata harus tetap support chunk split dan malformed JSON tanpa membuang konten jawaban.
- Token version binding harus tidak mematahkan OnlyOffice retry window dan conversion URL.

## Langkah Implementasi
1. Tambahkan komentar domain dan perbaiki copy misleading pada account email verification OTP.
2. Hapus `MemoCanvas`, view, dan test yang hanya menargetkan component legacy; pertahankan route redirect legacy.
3. Hapus fallback `private/` di `ProcessDocument` dan sesuaikan log.
4. Ganti regex `[SOURCES]` dengan parser balanced JSON array marker.
5. Buat `resolveVersion()` eksplisit per method request dan bind `oo_token` ke `version_id`.
6. Tambahkan/update tests relevan.

## Rencana Test
- Auth registration dan email verification targeted tests.
- Memo route redirect/workspace/policy targeted tests.
- Process document targeted tests.
- Chat orchestration unit tests untuk parser metadata.
- Full Laravel test suite sebelum PR.

## Kriteria Selesai
- Flow OTP lebih jelas dan copy tidak misleading untuk account verification.
- `MemoCanvas` legacy tidak tersisa sebagai entry point tidak reachable.
- Log missing file document tidak menyebut fallback path salah.
- Parser `[SOURCES]` punya coverage edge case.
- Signed memo file token tidak bisa dipakai silang versi.
- Tests relevan dan full Laravel suite lulus.
