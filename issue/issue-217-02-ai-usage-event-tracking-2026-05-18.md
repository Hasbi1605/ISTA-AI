# Child 2: AI Usage Event Tracking

Parent: https://github.com/Hasbi1605/ISTA-AI/issues/209
GitHub Issue: https://github.com/Hasbi1605/ISTA-AI/issues/213

## Latar Belakang
Dashboard admin membutuhkan data penggunaan AI yang konsisten. `activity_log` sudah ada, tetapi belum ideal untuk metrik AI karena dashboard perlu agregasi fitur, status, latency, request id, dan metadata tanpa menyimpan prompt mentah.

## Tujuan
- Membuat tabel `ai_usage_events`.
- Membuat service pencatat event AI.
- Mencatat aktivitas chat, web search, document RAG, upload dokumen, memo, dan Google Drive.
- Menjaga privacy dengan tidak menyimpan isi prompt, jawaban, atau dokumen.

## Ruang Lingkup
- Migration dan model `AIUsageEvent`.
- `AIUsageEventService`.
- Integrasi event pada jalur utama.
- Sanitasi metadata.
- Index untuk query dashboard.
- Test event sukses/gagal.

## Di Luar Scope
- UI dashboard monitoring.
- AI Configuration.
- Knowledge internal retrieval.
- Penyimpanan isi percakapan di dashboard.

## Area / File Terkait
- `laravel/app/Livewire/Chat/ChatIndex.php`
- `laravel/app/Http/Controllers/Chat/ChatStreamController.php`
- `laravel/app/Jobs/GenerateChatResponse.php`
- `laravel/app/Jobs/ProcessDocument.php`
- `laravel/app/Services/DocumentLifecycleService.php`
- `laravel/app/Services/Memo/MemoGenerationService.php`
- `laravel/app/Models/AIUsageEvent.php`
- `laravel/app/Services/Admin/AIUsageEventService.php`
- `laravel/database/migrations/*`
- `laravel/tests/Feature/*`
- `laravel/tests/Unit/*`

## Risiko
- Double count karena chat memakai streaming dan background job fallback.
- Logging error tidak boleh membuat request chat gagal.
- Metadata tidak boleh mengandung prompt/jawaban/dokumen.

## Langkah Implementasi
1. Buat migration `ai_usage_events` dengan index `user_id`, `feature`, `status`, `created_at`, `request_id`.
2. Buat model `AIUsageEvent`.
3. Buat `AIUsageEventService` dengan method `started`, `completed`, `failed`, dan sanitizer metadata.
4. Tambah helper feature detection untuk chat biasa/web search/document RAG.
5. Integrasikan event pada `ChatIndex::sendMessage`.
6. Integrasikan completion/failure pada stream dan job fallback.
7. Integrasikan upload dokumen, memo, dan Google Drive export.
8. Pastikan event tracking best-effort dan tidak memblokir fitur utama.
9. Tambah test privacy dan event lifecycle.

## Rencana Test
```bash
cd laravel && php artisan test --filter='AIUsage|Chat|DocumentUpload|Memo|GoogleDrive'
```

Minimal test:
- Chat biasa mencatat feature `chat`.
- Chat dokumen mencatat feature `document_rag`.
- Web search mencatat feature `web_search`.
- Upload dokumen mencatat feature `document_upload`.
- Metadata tidak menyimpan prompt mentah.
- Logging failure tidak menggagalkan request utama.

## Kriteria Selesai
- Event utama tercatat.
- Data event aman untuk dashboard.
- Query dashboard punya index cukup.
- Test relevan lulus.
