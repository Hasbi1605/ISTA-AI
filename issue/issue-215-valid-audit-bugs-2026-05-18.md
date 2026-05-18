# Fix Valid Audit Bugs: Export, OnlyOffice, Document Processing, History, and Memo Edge Cases

GitHub Issue: https://github.com/Hasbi1605/ISTA-AI/issues/207

## Latar Belakang
Audit bug terbaru menemukan beberapa defect valid di area chat export, OnlyOffice callback, job pemrosesan dokumen, upload dokumen, stream context, history loading, memo editor, dan endpoint Python document service. Sebagian temuan lain hanya hardening atau bukan bug, sehingga tidak masuk scope implementasi ini.

## Tujuan
- Mencegah export spreadsheet dari jawaban chat yang tidak punya tabel.
- Menjaga OnlyOffice editor key tetap stabil selama sesi aktif.
- Mencegah job dokumen lama menimpa status dokumen yang sudah final.
- Membuat upload dokumen lebih toleran terhadap MIME browser yang berbeda.
- Mengurangi beban load history chat/memo dengan batas query dan index.
- Mengikat context dokumen chat stream ke user message yang tersimpan.
- Menyamakan validasi ekstensi endpoint extract Python dengan endpoint process.
- Mengurangi churn token OnlyOffice pada re-render Livewire.
- Menambah retry cleanup stale vector/parent chunks.
- Memperbaiki parsing revisi memo yang terlalu luas.

## Ruang Lingkup
- Bug valid: #2, #3/#5, #6, #7, #8, #9, #10, #13, #17, #18, #20.
- Perubahan kecil di Laravel Livewire/controller/service/model/migration.
- Perubahan kecil di Python document export/router dan RAG cleanup.
- Test regression yang dekat dengan perilaku yang berubah.

## Di Luar Scope
- Hardening Google Drive OAuth local/testing.
- Redesign penuh pagination UI chat/memo.
- Refactor besar sistem chat, RAG, atau OnlyOffice.
- Perubahan security policy legacy email verification.

## Area / File Terkait
- `laravel/app/Livewire/Chat/ChatIndex.php`
- `laravel/app/Http/Controllers/Chat/ChatStreamController.php`
- `laravel/app/Services/ChatOrchestrationService.php`
- `laravel/app/Models/Message.php`
- `laravel/app/Jobs/ProcessDocument.php`
- `laravel/app/Http/Controllers/OnlyOfficeCallbackController.php`
- `laravel/app/Livewire/Memos/MemoWorkspace.php`
- `laravel/app/Livewire/Memos/MemoIndex.php`
- `laravel/database/migrations/*`
- `python-ai/app/routers/documents.py`
- `python-ai/app/services/document_export.py`
- `python-ai/app/services/rag_ingest.py`

## Risiko
- Migrasi `messages.document_ids` harus backward-compatible untuk pesan lama.
- Perubahan OnlyOffice key tidak boleh mengganggu status force-save.
- Pembatasan history harus tidak merusak urutan sidebar.
- Python export harus tetap mengekspor XLSX/CSV saat HTML memang berisi tabel.

## Langkah Implementasi
1. Tambah metadata `document_ids` pada user message dan gunakan metadata itu di stream.
2. Blok export/upload XLSX/CSV ketika HTML tidak mengandung tabel.
3. Hentikan invalidasi editor key pada OnlyOffice status 2.
4. Guard `ProcessDocument::failed()` agar hanya mengubah pending/processing menjadi error.
5. Longgarkan validasi upload dengan menghapus strict `mimetypes` pada Livewire upload.
6. Batasi query history chat/memo dan tambahkan index user/update/id.
7. Cache config editor memo per memo/version/file path di component state terkunci.
8. Tambah validasi ekstensi untuk endpoint Python extract.
9. Tambah retry helper untuk cleanup stale Chroma IDs.
10. Perketat cleanup value revisi memo untuk trailing instruksi generik.

## Rencana Test
- Laravel: targeted feature/unit tests untuk Google Drive upload, OnlyOffice callback, ProcessDocument, Chat UI/stream context, MemoWorkspace.
- Laravel formatting: Pint untuk file PHP yang berubah.
- Python: pytest untuk document export/router dan RAG cleanup.
- Compile Python file yang berubah.

## Kriteria Selesai
- GitHub issue dibuat.
- Branch baru dibuat dari `main`.
- Perubahan bug valid diimplementasikan dengan test regression.
- Test relevan Laravel dan Python lulus.
- Commit dibuat, branch di-push, dan draft PR dibuka.
