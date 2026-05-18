# Hotfix OnlyOffice Signed File Access and Status 4 Key Stability

## Latar Belakang
Audit bug Batch 1 menemukan dua risiko pada flow memo OnlyOffice:

- Route `chat/memos/{memo}/signed-file` memang memerlukan signed URL dan `oo_token`, tetapi signature lama bersifat relatif sehingga URL yang bocor dapat diganti host-nya ke host publik selama token masih valid.
- Callback OnlyOffice status `4` saat ini menghapus editor key dari cache, padahal tab/editor lain masih bisa memakai key lama untuk force-save atau callback lanjutan.

## Tujuan
- Memastikan URL file memo untuk OnlyOffice memakai signature absolut berbasis internal Laravel URL.
- Menolak akses signed-file dari host publik anonim walaupun URL bertanda tangan bocor.
- Tetap mengizinkan OnlyOffice mengambil file melalui host internal yang dipercaya.
- Menjaga editor key tetap stabil setelah status `4` agar sesi lain tidak putus.

## Ruang Lingkup
- Service key/token/URL OnlyOffice untuk memo.
- Controller signed-file memo.
- Generator editor config dan converter URL memo.
- Test feature memo policy dan callback OnlyOffice.

## Di Luar Scope
- Perubahan batch chat context, streaming race, Google Drive shortcut, atau upload streaming.
- Refactor besar integrasi OnlyOffice.
- Deploy production.

## Area / File Terkait
- `laravel/app/Services/OnlyOffice/MemoDocumentKey.php`
- `laravel/app/Http/Controllers/Memos/MemoFileController.php`
- `laravel/app/Http/Controllers/OnlyOfficeCallbackController.php`
- `laravel/app/Livewire/Memos/MemoWorkspace.php`
- `laravel/app/Services/OnlyOffice/DocumentConverter.php`
- `laravel/tests/Feature/Memos/MemoPolicyTest.php`
- `laravel/tests/Feature/Memos/OnlyOfficeCallbackTest.php`

## Risiko
- Signed-file route tidak boleh dipasang `auth` murni karena OnlyOffice mengambil file tanpa session browser user.
- Perubahan signature harus kompatibel dengan host internal `ONLYOFFICE_LARAVEL_INTERNAL_URL`.
- Menghapus invalidasi status `4` harus tetap menjaga status `2/6` dan replay guard callback berjalan.

## Langkah Implementasi
1. Tambahkan helper URL signed absolut internal untuk file memo OnlyOffice.
2. Tambahkan metadata token `user_id` dan `purpose`, dengan validasi backward-compatible untuk token lama yang masih hidup.
3. Ubah `MemoWorkspace` dan `DocumentConverter` agar memakai URL signed absolut internal.
4. Ubah `MemoFileController::signed()` agar signature absolut divalidasi, dengan fallback legacy relative signature hanya untuk host internal terpercaya.
5. Tambahkan gate akses: boleh jika user login pemilik memo atau request datang dari host internal OnlyOffice yang dipercaya.
6. Hapus invalidasi editor key pada callback status `4`.
7. Update dan tambah test regresi untuk host publik anonim, host internal OnlyOffice, URL absolut, dan status `4` + force-save lama.

## Rencana Test
- `cd laravel && ./vendor/bin/pint --test ...`
- `cd laravel && php artisan test --filter='MemoPolicyTest|OnlyOfficeCallbackTest'`
- Jika perlu, jalankan subset tambahan untuk memo workspace/converter.

## Kriteria Selesai
- Signed-file public/anonymous dengan host publik ditolak.
- Signed-file internal OnlyOffice dengan token valid tetap bisa fetch.
- URL baru yang dihasilkan memakai signature absolut internal.
- Status `4` tidak mengganti/menghapus editor key.
- Callback status `6` setelah status `4` dengan key lama tetap diterima.
- Test relevan lulus.
