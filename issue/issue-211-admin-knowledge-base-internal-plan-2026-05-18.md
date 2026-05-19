# Plan: Admin Knowledge Base Internal (Issue #211)

Parent: https://github.com/Hasbi1605/ISTA-AI/issues/209
Issue ini: https://github.com/Hasbi1605/ISTA-AI/issues/211
Base branch: `codex/issue-210-admin-monitoring-dashboard` (chain dengan PR admin foundation/monitoring)
Working branch: `codex/issue-211-admin-knowledge-base-internal`

## Tujuan
Bangun modul knowledge base internal milik admin yang terpisah dari dokumen pribadi user. Admin bisa upload dokumen referensi (PDF/DOCX/XLSX/CSV), mengelola siklus hidup dokumen (draft → processing → active → error → archived), dan vector hasil ingest punya metadata `scope=global_internal` agar mudah dibedakan dari dokumen user di luar issue retrieval.

## Scope teknis
1. **Database**
   - Migration baru: `knowledge_sources`, `knowledge_documents`, `knowledge_chunks`.
   - `knowledge_sources` = grouping/source label (mis. "SOP HR", "Aturan ISTANA").
   - `knowledge_documents` = file fisik + state machine (status, mime, size, checksum, scope, audience, vector_namespace).
   - `knowledge_chunks` = ringkasan jumlah chunk per dokumen + flag aktif (vector data tetap di Chroma; tabel ini hanya catatan).
2. **Models**
   - `KnowledgeSource`, `KnowledgeDocument`, `KnowledgeChunk`.
   - State helper `KnowledgeDocument::STATUS_*`, `SCOPE_*`, `AUDIENCE_*`.
3. **Service & Job**
   - `App\Services\Knowledge\KnowledgeLifecycleService` (upload, archive, reprocess, delete).
   - `App\Jobs\ProcessKnowledgeDocument` (memanggil Python `/api/knowledge/process`).
4. **Python AI**
   - Tambahkan router `python-ai/app/routers/knowledge.py` dengan `POST /api/knowledge/process` dan `DELETE /api/knowledge/{filename}`.
   - Re-use `process_document` & `delete_document_vectors` lewat wrapper `process_knowledge_document` di `rag_ingest.py` yang menyuntik metadata `scope=global_internal`, `audience=all_users`, `status=active`, dan `knowledge_id`.
   - Endpoint baru terdaftar di `app/main.py`.
5. **Admin UI**
   - Livewire component `App\Livewire\Admin\AdminKnowledge` (default Halaman `/admin/knowledge`).
   - Livewire component `App\Livewire\Admin\AdminKnowledgeUpload` (form upload modal/standalone) — atau cukup form HTML yang submit ke controller untuk MVP.
   - Tambah link "Knowledge" di sidebar admin.
   - Tampilkan list dokumen knowledge (nama, status, source, ukuran, tanggal upload, tombol activate/archive/reprocess/delete). Tidak menampilkan isi dokumen.
6. **Routes**
   - `GET /admin/knowledge` (list)
   - `POST /admin/knowledge` (upload)
   - `POST /admin/knowledge/{document}/activate`
   - `POST /admin/knowledge/{document}/archive`
   - `POST /admin/knowledge/{document}/reprocess`
   - `DELETE /admin/knowledge/{document}` (hard delete + vector cleanup)
7. **Event tracking**
   - Tambahkan konstanta feature `AIUsageEvent::FEATURE_KNOWLEDGE_ADMIN`.
   - Catat `started/completed/failed` saat upload, processing, archive, reprocess, dan delete via `AIUsageEventService`.
8. **Storage**
   - File knowledge disimpan di disk `local` di folder `knowledge/<source_id>/<filename>` agar terpisah dari `documents/<user_id>/...`.
9. **Permissions**
   - Hanya admin (atau super admin) yang bisa akses semua route. Gunakan middleware `admin` (sudah ada).

## Test
### Laravel
- `tests/Feature/Admin/AdminKnowledgeManagementTest.php`
  - admin bisa lihat halaman `/admin/knowledge`
  - regular user mendapat 403
  - guest redirect ke login
  - upload PDF berhasil, status awal `draft` lalu di-dispatch job
  - validasi tipe file: tolak `.txt`/`.exe` dengan validation error
  - validasi ukuran maksimum 50MB
  - state transitions: archive, activate, reprocess (dispatch job ulang)
  - delete memanggil cleanup vectors (mock Http)
  - event tracking dipanggil (assert AIUsageEvent dibuat dengan feature `knowledge_admin`)
- `tests/Feature/Jobs/ProcessKnowledgeDocumentTest.php`
  - sukses → status `active`
  - http error → status `error`
  - missing file → status `error`
  - kirim metadata scope/audience ke Python

### Python
- `python-ai/tests/test_knowledge_router.py`
  - process endpoint memanggil `process_knowledge_document` dengan metadata global_internal
  - delete endpoint scoped knowledge_id
- `python-ai/tests/test_rag_ingest_knowledge.py` (jika perlu)
  - `process_knowledge_document` menyetel metadata `scope=global_internal`, `audience=all_users`

## Risiko & mitigasi
- **Mixing vector**: gunakan `scope=global_internal` di metadata dan `user_id="__knowledge__"` agar tidak match dengan retrieval dokumen pribadi user. Karena issue Child 6 yang akan handle retrieval, untuk Child 5 cukup memastikan metadata tertulis dengan benar dan vector tidak tercampur dengan filter user.
- **Hard delete tidak bersih**: KnowledgeLifecycleService::delete memanggil Python delete dengan `cleanup_legacy=true` dulu sebelum DB delete.
- **File besar / OCR**: pakai validasi 50MB (sama dengan dokumen user).

## Rencana eksekusi
1. Buat issue plan markdown (file ini).
2. Buat migration knowledge.
3. Buat models + factory.
4. Buat service & job.
5. Buat controller + livewire + route + view.
6. Tambah link sidebar.
7. Tambah konstanta event + integrasi event tracking.
8. Tambah Python router knowledge + wrapper rag_ingest.
9. Tambah test Laravel + Python.
10. Jalankan verifikasi (`php artisan test --filter='Knowledge|Admin'` & pytest).
11. Commit, push, buat PR.

## Done when
- File issue plan tersedia.
- Schema knowledge ter-migrate, models tersedia.
- Admin bisa upload, archive, activate, reprocess, delete via UI (test Livewire/HTTP).
- Vector hasil ingest punya metadata global_internal (test Python).
- Event tracking knowledge_admin tercatat (test).
- Test Laravel + Python yang ditargetkan lulus.
- PR dibuat ke `codex/issue-210-admin-monitoring-dashboard` (chain) atau `main` (jika instruksi reviewer terbaru).
