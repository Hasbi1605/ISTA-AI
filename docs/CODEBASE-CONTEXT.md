# ISTA AI (Magang-Istana) — Codebase Context & Mapping

> Dokumen acuan utama struktur kode untuk agent/developer sebelum mengubah apa pun.
> Wajib dibaca bersama `README.md`, `AGENTS.md` (aturan kerja), dan `docs/` lain
> (deploy, privacy, testing, maintenance).
>
> Jika struktur/flow berubah, **perbarui dokumen ini** pada bagian yang relevan.

---

## 1. Ringkasan Sistem

ISTA AI = asisten AI atas dokumen privat untuk pegawai Istana Kepresidenan Yogyakarta.
**Monorepo hybrid 2 layanan**:

- `laravel/` — web UI, auth/otorisasi, upload, metadata dokumen, admin UI, queue, callback OnlyOffice.
- `python-ai/` — layanan AI FastAPI: chat streaming, ingest dokumen, RAG, model routing, embeddings, summarization, export, memo.

Keduanya berkomunikasi via HTTP dengan bearer token bersama. Vector store = ChromaDB.

| Komponen | Teknologi |
|----------|-----------|
| Web app | Laravel + **Livewire/Volt + Blade + Alpine.js** + Tailwind (Vite) |
| AI service | FastAPI + Pydantic, **litellm** (multi-provider routing), ChromaDB, rank-bm25, tiktoken |
| Streaming chat | **SSE** (`EventSource`) — bukan Echo/WebSocket |
| Data store | MySQL (app), Redis (queue/cache), ChromaDB (vektor) |
| Doc editing | OnlyOffice Document Server (DOCX, signed URL + JWT) |
| Provider AI | GitHub Models (GPT-4.1/4o/mini/nano), Groq Llama 3.3, Mistral, Bedrock; LangSearch (web search + rerank) |

> Bahasa app & persona AI: Indonesia. Konfigurasi AI tunggal: `python-ai/config/ai_config.yaml`.

---

## 2. Backend Context & Mapping — Laravel (`laravel/`)

Catatan penting: **UI interaktif user/admin ada di Livewire components**, bukan controller.
Controller di-pakai untuk SSE, file, callback, OAuth, dan admin auth. **Tidak ada `api.php`
maupun `channels.php`** (SSE = HTTP biasa, tanpa broadcast channel).

### 2.1 Controllers (`app/Http/Controllers`)
- **Auth/** `VerifyEmailController` (login/register/forgot/reset = Volt pages di `routes/auth.php`).
- **Admin/** `AdminLoginController` (login/logout `/admin/login`), `AdminPasswordChangeController` (forced password change).
- **Chat/** `ChatStreamController` — **endpoint inti SSE** `GET /chat/stream/{conversationId}`: validasi ownership, rebuild history dari DB, resolve konteks dokumen, panggil `AIService::sendChat()`, stream event `chunk/model-name/sources/final-content/message-id/done`, persist pesan assistant idempoten (single-runner claim), log `AIUsageEvent`.
- **Documents/** `DocumentExportController` (extractContent/extractTables/export), `DocumentPreviewController` (status/stream SSE/html).
- **Memos/** `MemoFileController` (signed/download/exportPdf/forceSave docx).
- **Root** `OnlyOfficeCallbackController` (`POST /onlyoffice/callback/{memo}`), `Controller` (base).

### 2.2 Livewire — "controller" UI sesungguhnya (`app/Livewire`)
- **Chat/** `ChatIndex` (workspace chat+memo+Prompy bertab, route `chat/{id?}`; tab Prompy memakai key `prompy`, aktif default via `features.prompy`/`FEATURE_PROMPY`; `normalizeTab()` tetap menerima alias legacy `presentation`/`presentasi` lalu memetakannya ke `prompy`, dan nilai invalid/tab Prompy saat flag mati jatuh ke `chat`).
- **Memos/** `MemoIndex`, `MemoWorkspace` (generate/edit memo).
- **Prompts/** `PrompyWorkspace` (wrapper tab Prompy yang langsung merender `PrompyStudio`; generator PPTX internal, route unduh/editor, job render, dan tabel artefak presentasi sudah dihapus). `PrompyStudio` (#263: generator paket prompt untuk platform AI eksternal — sidebar kiri khusus riwayat prompt private dengan status selesai, form di panel tengah, dan hasil paket prompt/copy actions di panel kanan; input ide Bahasa Indonesia, pilih platform/jenis wajib tanpa default terpilih saat compose baru, state lokal Alpine agar pilihan terasa instan, catatan konteks opsional, dan reference image privat JPG/PNG via card/dropzone yang dianalisis model vision terkonfigurasi agar gaya visual/warna/layout/komposisi benar-benar masuk ke prompt; output prompt utama Bahasa Inggris + catatan Bahasa Indonesia; header/riwayat memakai judul ringkas deterministik dari ide; copy action main/variants/negative/settings; tidak memanggil platform eksternal).
- **Documents/** `DocumentViewer`.
- **Admin/** `AdminDashboard`, `AdminUsers`, `AdminUsage`, `AdminErrors`, `AdminDocuments`, `AdminKnowledge`, `AdminAccounts` (super-admin).
- **Forms/** `LoginForm`; **Actions/** `Logout`.

### 2.3 Services (`app/Services`)
Root:
- `AIService` — **jembatan HTTP ke python-ai**: `sendChat()` stream `POST /api/chat`; `summarizeDocument()` `POST /api/documents/summarize`. Dual base URL/token (chat vs document service), retry, timeout, redaksi rahasia, `ERROR_SENTINEL = [ISTA_AI_ERROR]`.
- `ChatOrchestrationService` — build history, resolve konteks dokumen aktif, source policy, izin web-search, parse metadata stream (model/sources), sanitasi, single-runner claim + persist idempoten.
- `DocumentExportService`, `DocumentLifecycleService` (upload/proses/hapus dokumen).

Subfolder:
- **Admin/** `AdminMetricsService`, `AIUsageEventService`, `AdminUserManagementService`, `AdminAccountManagementService`, `AdminAccountAuditService`.
- **Auth/** `PasswordResetLinkService`, `PendingRegistrationService`, `PendingRegistrationWorkflowService`.
- **Chat/** `ChatDocumentStateService`.
- **Documents/** `DocumentPreviewRenderer`, `DocumentExportHtmlSanitizer`.
- **Knowledge/** `KnowledgeLifecycleService` (ingest KB → python `/api/knowledge`, archive/activate sync metadata Chroma via status endpoint).
- **Memo/** `MemoGenerationService` (→ python `/api/memos/generate-body`), `MemoLifecycleService`, `MemoDocumentStructureExtractor`.
- **Prompts/** `PromptStudioService` (#263: generate paket prompt via python `/api/prompts/generate`, validasi platform/jenis, simpan reference image privat JPG/PNG dengan validasi tipe/ukuran, kirim gambar sebagai payload base64 internal ke Python untuk vision analysis, set `contains_internal_context` bila ada catatan/gambar, log usage `prompt_generation`; tidak memanggil platform AI eksternal).
- **OnlyOffice/** `DocumentConverter`, `DocxTextExtractor`, `DocxValidator`, `JwtSigner`, `MemoDocumentKey`, `MemoForceSaveService`, `ForceSaveException`.

### 2.4 Models / Jobs / Middleware
- **Models:** `User`, `Conversation`, `Message`, `Document`, `DocumentChunk`, `Memo`, `MemoVersion`, `GeneratedPrompt`, `KnowledgeDocument`, `KnowledgeChunk`, `KnowledgeSource`, `AIUsageEvent`, `AdminAccountAudit`.
- **Policies:** `DocumentPolicy`, `MemoPolicy` — auto-discovery Laravel.
- **Jobs:** `GenerateChatResponse` (jalur async chat, komplemen SSE), `ProcessDocument` (→ python `/api/documents/process`), `ProcessKnowledgeDocument` (→ `/api/knowledge/process`, memakai `processing_claim_token` agar retry lama tidak menimpa attempt baru), `RenderDocumentPreview`.
- **Middleware:** `EnsureUserIsActive` (`active`, memutus sesi akun nonaktif pada route user terautentikasi), `EnsureUserIsAdmin` (`admin`), `EnsureUserIsSuperAdmin` (`super_admin`), `EnsureAdminPasswordChanged` (`admin.password_changed`), `UpdateUserPresence`, `AddSecurityHeaders` (CSP; `unsafe-eval` global opt-in via `SECURITY_CSP_ALLOW_UNSAFE_EVAL`, plus compatibility otomatis untuk response Livewire/Alpine via `SECURITY_CSP_ALLOW_LIVEWIRE_UNSAFE_EVAL`).
- **Events:** `BookingCreated/BookingStatusChanged/FeedbackSubmitted/ScheduleUpdated` — **sisa domain lama (booking)**, tidak terhubung ke flow chat/memo. Jangan diandalkan untuk fitur AI.

### 2.5 Routes (`routes/`) — hanya `web.php`, `auth.php`, `console.php`
- **web.php:** dashboard `/`; route user seperti `profile`, `chat/{id?}` (ChatIndex), `chat/stream/{conversationId}` (SSE), memo, dan dokumen memakai `auth+active` (serta `verified` sesuai kebutuhan) agar akun nonaktif tidak bisa memakai aplikasi lewat sesi lama; `onlyoffice/callback/{memo}` (JWT + trusted OnlyOffice URL scheme/host/port); grup `chat/memos/*` (download/export-pdf/force-save/signed); legacy `memos/*` redirect ke `/chat?tab=memo`; tidak ada lagi route `chat/presentations/*` atau callback OnlyOffice presentasi; `documents/*` (content-html, extract-tables, export, preview/*); admin auth (`/admin/login`, logout, password change); admin app (`auth+verified+admin+admin.password_changed`): `/admin`, `/users`, `/usage`, `/errors`, `/documents`, `/knowledge`; super-admin: `/admin/accounts`.
- **auth.php:** Volt pages login/forgot/reset/verify/confirm; register → redirect login register-view hanya jika `PUBLIC_REGISTRATION_ENABLED=true` (production private default tertutup).
- **Horizon:** `/horizon` memakai middleware `web+auth+verified+super_admin+admin.password_changed` dan gate `viewHorizon`; hanya super-admin aktif yang tidak sedang wajib ganti password boleh akses. `App\Console\Commands\CompatibleHorizonWorkCommand` menggantikan binding `horizon:work` agar Horizon 5.45 tetap kompatibel dengan opsi worker Laravel 13 `--stop-when-empty-for`, mencegah worker crash-loop di production.
- **console.php:** `documents:purge-deleted --days=7` (03:00), `chat:resolve-stale-responses --minutes=10` (tiap menit).

### 2.6 Frontend Laravel (`resources/`)
- **js/**: `app.js` (registrasi Alpine+Livewire), `bootstrap.js`, **`chat-page.js`** (client berat: Alpine `chatLayout` tab chat/memo/Prompy (`prompyEnabled` gating tab), `prompyWorkspace` untuk sidebar/mobile panel Prompy, drag-drop upload, `assistantTypewriter`, `chatMessages`). **SSE**: `openChatStream(...)` membangun `/chat/stream/{id}?...` lalu `new EventSource(url)` (dilacak di `activeEventSources`), render typewriter markdown (marked + DOMPurify); koordinasi placeholder via `$wire.on` (`assistant-output`, `model-name`, `assistant-sources`, dll) + marker localStorage.
- **css/**: `app.css`, `auth.css`.
- **views/**: `dashboard` (CTA Chat/Memo + Prompy saat `features.prompy` aktif), `profile`; `livewire/chat/*` (+partials), `livewire/memos/*`, `livewire/prompts/*` (Prompy Studio + riwayat prompt), `livewire/documents/*`, `livewire/admin/*` (dashboard/users/usage/errors/documents/knowledge/accounts), Volt auth pages, `components/admin/*` (design system: sidebar/table/tabs/kpi-card/badge/filter/empty-state), `layouts/` (app/admin/guest/auth-canvas), `emails/`.

### 2.7 Database (`laravel/database`)
Tabel utama: `users` (+verification_code, role/presence, kolom keamanan admin), `conversations`, `messages` (+document_ids), `documents` (metadata file, preview, index, unique original_name), `document_chunks`, `memos` (+chat_messages, configuration), `memo_versions`, `generated_prompts` (riwayat Prompy Studio #263: platform/prompt_type + label, title, idea, package JSON, `source_document_ids` JSON legacy/selalu kosong untuk prompt baru, contains_internal_context, reference_image_path/mime/size privat, model_label, owner-scoped + softDeletes), `ai_usage_events`, knowledge (`knowledge_sources`/`knowledge_documents`/`knowledge_chunks`), `admin_account_audits`, `activity_log` (spatie) + cache/jobs. Migrasi lama menghapus tabel `ai_configuration`, `cloud_storage_files`, `google_drive_oauth_connections`, serta migration `2026_06_21_000001_drop_presentation_generation_tables` menghapus tabel artefak presentasi lama (`presentations`, `presentation_versions`). Seeder: `DatabaseSeeder`; factory: `UserFactory`.

---

## 3. AI Service Context & Mapping — Python (`python-ai/`)

**Dua FastAPI app** (microservice split):
1. **Chat service** — `app/chat_api.py` (`app/main.py` jalankan uvicorn :8001). Endpoint: `GET /api/health`, `GET /api/ready`, `POST /api/chat` (token, SSE).
2. **Document service** — `app/documents_api.py` (mount router documents/knowledge/memos). Health/ready + router.

`AIService` Laravel memakai base URL/token terpisah untuk chat vs document service.

### 3.1 Modul inti (`app/`)
- `main.py` — entry uvicorn (re-export chat app).
- `chat_api.py` — orkestrasi `/api/chat`: flag policy (`document_context` vs `hybrid_realtime_auto`), keputusan web-search, retrieval RAG, retrieval knowledge internal, fallback, wrapping TTFT/latency.
- `documents_api.py` — app dokumen + mount router.
- `api_shared.py` — `verify_token` (bearer constant-time), payload health/ready (cek token + path Chroma).
- `config_loader.py` — load `config/ai_config.yaml` (model chat, prompt, top_k, dll).
- `runtime_config.py` — override per-request (`runtime_int`, `runtime_prompt`, `render_prompt_template`).
- `env_utils.py` — helper env.
- `llm_manager.py` — **routing/cascade model**: `get_llm_stream` (general/web), `get_llm_stream_with_sources` (RAG/knowledge), komposisi system prompt, injeksi web context, fallback antar-model (rate-limit / 413).
- `document_runner.py` / `document_tasks.py` — ingest dokumen di subprocess (`run_document_process`).
- `retrieval_runner.py` / `retrieval_tasks.py` — retrieval search di subprocess (`run_retrieval_search`).

### 3.2 Routers (`app/routers/`)
- `documents.py` — `/api/documents`: `process` (upload+ingest pdf/docx/xlsx/csv, cap 50MB, safe-filename), `DELETE /{filename}`, `extract-tables`, `extract-content`, `export`, `summarize` (single vs hierarchical).
- `knowledge.py` — `/api/knowledge`: ingest KB internal (`scope=global_internal`, `audience=all_users`, `user_id=__knowledge__`), `process`, `DELETE /{filename}`, dan `PATCH /{filename}/status` untuk sync `knowledge_status` vector child+parent tanpa reingest. Reuse validator + pipeline `documents.py`.
- `memos.py` — `/api/memos/generate-body`: generate memorandum `.docx` (binary + header searchable-text/page-size/model-label).
- `prompts.py` — `/api/prompts/generate` (POST, JSON) + `/api/prompts/profiles` (GET): Prompy Studio (#263). Memanggil LLM untuk menyusun paket prompt (main_prompt EN, variants, negative_prompt, recommended_settings, notes_id ID) untuk platform AI eksternal; payload dapat membawa `reference_image` (mime + base64) yang dianalisis dulu oleh model vision terkonfigurasi menjadi brief visual terstruktur (orientasi/rasio, teks terlihat, layout, palet, elemen yang dipertahankan/diganti), lalu brief itu masuk ke generator prompt agar prompt akhir menjaga fidelity referensi; **tidak** memanggil platform target seperti GPT Image/Gemini/Canva. Profil platform/jenis, model vision, dan template dari `ai_config.yaml` (`prompt_studio`, `prompts.prompt_studio_reference_image`). `ValueError`→400.

### 3.3 Services RAG (`app/services/`)
- `rag_service.py` — facade re-export komponen RAG.
- `rag_config.py` — config chunk/embed + `CHROMA_PATH`, `EMBEDDING_MODELS`.
- `rag_ingest.py` — `process_document` (chunk→embed→store, PDR parent/child), `delete_document_vectors`.
- `rag_embeddings.py` — provider embedding (GitHub OpenAI large/small, Bedrock Titan V2), fallback + TTL cache, token count.
- `rag_retrieval.py` — `search_relevant_chunks` (vector + BM25 child corpus), filter scope user/document_ids.
- `rag_hybrid.py` — hybrid search: HyDE expansion (smart), BM25, RRF merge, resolve PDR parent.
- `rag_prompt.py` / `build_rag_prompt` — rakit system prompt RAG (context + web section).
- `rag_policy.py` — `should_use_web_search`, `detect_explicit_web_request`, `get_context_for_query`.
- `rag_summarization.py` — batching chunk untuk summarization (token-budgeted).
- `knowledge_retrieval.py` — `knowledge_internal_enabled`, `should_use_internal_knowledge`, `search_internal_knowledge`, `build_knowledge_prompt`.
- `langsearch_service.py` — client LangSearch (web search + semantic rerank, cached).
- `llm_streaming.py` — cascade streaming bersama (`stream_with_cascade`), builder pesan/system prompt, deteksi rate-limit/context-size, ekstraksi web-source.
- `lightweight_text_splitter.py` — splitter token-aware. `document_loaders.py` — load pdf/docx/xlsx/csv→Document. `document_content.py` — dokumen→HTML (preview/export). `table_extraction.py` — ekstrak tabel. `document_export.py` — `export_content` (HTML→format target). `memo_generation.py` — `generate_memo_docx`. `prompt_generation.py` — `generate_prompt_package` (Prompy Studio #263: rangkai paket prompt JSON via LLM, parse defensif; `analyze_reference_image` membaca gambar referensi via model vision lalu memasukkan brief visual terstruktur ke prompt akhir, termasuk orientasi/rasio, teks terlihat, layout, dan instruksi preserve/replace; fallback platform/jenis generic/image). `latency_logger.py` — timing privacy-safe.

### 3.4 Config / scripts / tests
- `config/ai_config.yaml` — **single source of truth**: global timeout/retry; lanes (cascade chat models, reasoning null, embedding); retrieval (LangSearch search+rerank top_k=5, hybrid BM25 0.3, HyDE smart); chunking (1500/150, PDR child256/parent1500); integrations (SMTP); **prompts** (security guardrail anti-injection, system persona ISTA AI, rag, web_search, summarization, memo_generation, knowledge_internal, hyde, prompt_studio, prompt_studio_reference_image, fallback), termasuk `prompt_studio.vision_models` untuk analisis gambar referensi.
- `scripts/benchmark_chat.py`; benchmark manual lebih luas di `/benchmarks`.
- `tests/` (~27 file pytest): routing, chat concurrency, document export/runner, knowledge retrieval/router, langsearch cache, splitter, llm streaming, memo, prompt contracts, rag embeddings/eval/tuning, rerank skip, retrieval runner, table extraction, web search tuning.
- `requirements.txt`: fastapi, uvicorn, pydantic 2, litellm, openai, chromadb + langchain-chroma/core, rank-bm25, tiktoken, pdfplumber/python-docx/openpyxl/pandas, weasyprint, beautifulsoup4, PyYAML.

---

## 4. Fitur Utama
Chat streaming (RAG/web/knowledge/general), upload + RAG dokumen, generate memo (DOCX) + edit OnlyOffice, export dokumen (HTML→PDF/docx), knowledge base internal (admin). Admin: dashboard KPI, usage analytics, manajemen dokumen/user/error/knowledge, akun admin (super-admin) + audit.

---

## 5. Flow Aplikasi (ringkas)

### 5.1 Flow User
- **Chat (streaming):** `ChatIndex` persist pesan user → `chat-page.js` buka `EventSource /chat/stream/{id}` → `ChatStreamController` rebuild history + resolve konteks/policy → `AIService::sendChat()` → python `/api/chat`. Python memutuskan: RAG-with-sources (dokumen aktif) / fallback web-search / knowledge internal / general chat, stream token balik. Controller sanitasi + persist idempoten, emit `model-name`/`sources`/`final-content`/`message-id`/`done`; client render typewriter + sumber. Usage → `ai_usage_events`.
- **Upload dokumen + RAG:** upload via sidebar chat → row `Document` + job `ProcessDocument` → python `/api/documents/process` (subprocess ingest → chunk/embed/PDR → Chroma). Dokumen aktif menyetel chat ke mode RAG. Preview via `documents/{document}/preview/*`. Summarize via `/api/documents/summarize`.
- **Memo:** `MemoWorkspace` → `MemoGenerationService` → python `/api/memos/generate-body` (DOCX + `MemoVersion`). Edit via OnlyOffice (`OnlyOfficeCallbackController` + JWT + URL OnlyOffice trusted scheme/host/port) dengan force-save.
- **Prompy Studio:** tab Prompy memakai key URL `prompy`, dengan alias lama `presentation`/`presentasi` tetap diarahkan ke Prompy. `PrompyWorkspace` merender `PrompyStudio` untuk menyusun paket prompt eksternal. Sidebar kiri berisi riwayat prompt private yang sudah berhasil dibuat, panel tengah berisi form ide/platform/jenis/catatan/gambar referensi, dan panel kanan hanya menampilkan paket prompt aktif beserta tombol salin. Pilihan platform/jenis wajib dipilih user dan tidak punya card default pada compose baru; state lokal Alpine yang ter-entangle deferred ke Livewire menjaga highlight berpindah instan tanpa round-trip server per klik. Dokumen sumber sengaja tidak lagi dipakai di Prompy agar flow visual tetap ringan dan tidak bercampur dengan RAG. Jika gambar referensi JPG/PNG diunggah lewat card/dropzone, Laravel menyimpannya privat lalu Python menganalisisnya dengan model vision (`prompt_studio.vision_models`) menjadi brief gaya visual/warna/layout/komposisi, orientasi/rasio, teks terlihat, serta elemen preserve/replace sebelum generator prompt berjalan; prompt akhir wajib menjaga rasio referensi dan tidak fallback ke rasio umum seperti 16:9 saat referensi jelas vertikal/square. Panel hasil tidak lagi memakai badge tambahan untuk reference image; usage metadata tetap menyimpan flag aman `has_reference_image`/`reference_image_analyzed` tanpa isi prompt atau brief visual mentah. `Prompt Baru` menampilkan panel hasil kosong/compose baru walau riwayat sudah ada, sekaligus mereset ide, platform, jenis keluaran, catatan, dan gambar referensi; klik riwayat membuka paket prompt yang dipilih dengan loading kecil pada item riwayat seperti Memo. Judul prompt dipadatkan deterministik dari ide agar header tidak menyalin permintaan user penuh. Generator PPTX internal sudah dihapus; arah produk untuk deck/visual adalah memperkuat kualitas prompt yang disalin ke platform eksternal.
- **Export:** `POST /documents/export` & memo `export-pdf`/`download` → python `/api/documents/export`.

### 5.2 Flow Admin (`/admin`, guard `admin`/`super_admin` + `admin.password_changed`)
- **Usage:** analitik AI dari `ai_usage_events` (chat/web_search/document_rag, model, latensi).
- **Documents:** kelola dokumen + status pipeline ingest.
- **Users:** manajemen user/presence. **Accounts (super-admin):** kelola akun admin + audit.
- **Errors:** monitor event AI gagal.
- **Knowledge:** CRUD KB internal; ingest via job `ProcessKnowledgeDocument` + `KnowledgeLifecycleService` → python `/api/knowledge` (scope global_internal). Archive/activate melakukan sync `knowledge_status` di Chroma sebelum status DB berubah; stale job knowledge ditahan oleh `processing_claim_token`. Flow ini menggerakkan jawaban knowledge internal di general chat.
- **Dashboard:** KPI via `AdminMetricsService`. Auth admin: `/admin/login` terpisah + forced password change.

### 5.3 Flow Registrasi
- Deployment private/production default menutup registrasi mandiri dengan `PUBLIC_REGISTRATION_ENABLED=false`.
- Saat flag mati, `/register` kembali ke `/login`, tombol/form register disembunyikan, dan action Livewire `register()`/OTP register menolak pembuatan akun.
- Saat flag aktif, flow lama tetap: user isi form login register-view → OTP email via `PendingRegistrationWorkflowService` → akun dibuat verified setelah OTP valid.

---

## 6. Verifikasi & Perintah
- **Laravel:** `cd laravel && php artisan test`.
- **Python:** `cd python-ai && source venv/bin/activate && pytest`.
- Jalankan test pada area terdampak; tambahkan test bila perilaku penting berubah tanpa coverage. Detail di `AGENTS.md` (Verifikasi wajib & Verifikasi Akhir Penuh).
- **CI/CD:** `.github/workflows/ci-cd.yml` menjalankan Composer/npm/PHPUnit untuk `laravel/`, pytest untuk `python-ai/`, lalu pada `push main` deploy production via SSH ke git checkout server menggunakan `git pull --ff-only`, Docker Compose production, migrasi, restart service runtime, verifikasi `laravel`/`python-ai`/`python-ai-docs`/`horizon`/`scheduler` tetap running, `horizon:status`, dan smoke check internal.
- **Production healthcheck Python:** `docker-compose.production.yml` memakai `/api/ready` untuk `python-ai` dan `python-ai-docs` agar deploy gagal cepat bila token internal atau path Chroma belum siap; `/api/health` hanya liveness ringan.

---

## 7. Catatan Keamanan & Privasi (ringkas)
- python-ai dilindungi bearer token (`verify_token`); jangan log isi prompt/dokumen.
- **Anti prompt injection (chat):** guardrail keamanan berprioritas tertinggi (`prompts.security.guardrails` di `ai_config.yaml`, fallback `config_loader.DEFAULT_PROMPTS["security"]["guardrails"]`, getter `get_security_preamble()`) disuntikkan otomatis ke paling depan setiap system prompt chat oleh `llm_manager._apply_security_guardrail()` di chokepoint `_stream_with_cascade` — sehingga mencakup semua lane (general/web/RAG/knowledge, termasuk override `runtime_config`). Guardrail menolak kebocoran/printout system prompt, upaya override instruksi ("abaikan instruksi sebelumnya", "STOP", "kamu sekarang admin"), role-play (mis. "nenek"), penyusunan ulang/penggabungan kata, dan instruksi yang disandikan (Base64/ROT13/leetspeak); instruksi di dalam dokumen/web/teks tempelan diperlakukan sebagai DATA, bukan perintah. Ubah teks guardrail hanya di `ai_config.yaml` (single source of truth), jangan hardcode.
- ChromaDB dipakai embedded oleh service Python melalui volume `chroma_data`; compose production tidak mempublish port Chroma ke publik.
- Public self-registration ditutup default di production agar knowledge/dokumen privat hanya diakses akun yang disetujui.
- Callback OnlyOffice memo memakai JWT ber-`exp`, key segar, anti-replay, validasi DOCX, dan URL download yang harus cocok scheme/host/port trusted.
- Email input user-controlled memakai `App\Rules\NoEmailHeaderInjection` di auth/profile/admin untuk menolak CR/LF/control chars sebelum email dipakai di reset/register/admin account flows.
- Token, secret OnlyOffice, kredensial DB, OAuth, API key provider → hanya di `.env` lokal/deploy, jangan commit. Jangan commit dokumen produksi, dump DB, service-account JSON, atau data Chroma.
- Akses dokumen: signed URL, private disk, otorisasi server-side. Baca `docs/data-flow-privacy.md` sebelum pakai data nyata.
