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
Controller di-pakai untuk SSE, file, callback, prompt reference image, dan admin auth. **Tidak ada `api.php`
maupun `channels.php`** (SSE = HTTP biasa, tanpa broadcast channel).

### 2.1 Controllers (`app/Http/Controllers`)
- **Auth/** `VerifyEmailController` (login/register/forgot/reset = Volt pages di `routes/auth.php`).
- **Admin/** `AdminLoginController` (login/logout `/admin/login`, lockout login progresif berbasis cache), `AdminPasswordChangeController` (forced password change), `TwoFactorSetupController` + `TwoFactorChallengeController` (enrol/verify 2FA TOTP wajib).
- **Chat/** `ChatStreamController` — **endpoint inti SSE** `GET /chat/stream/{conversationId}`: validasi ownership, rebuild history dari DB, resolve konteks dokumen, panggil `AIService::sendChat()`, stream event `chunk/model-name/sources/final-content/message-id/done`, persist pesan assistant idempoten (single-runner claim), log `AIUsageEvent`.
- **Documents/** `DocumentExportController` (extractContent/extractTables/export), `DocumentPreviewController` (status/stream SSE/html).
- **Memos/** `MemoFileController` (signed/download/exportPdf/forceSave docx).
- **Root** `OnlyOfficeCallbackController` (`POST /onlyoffice/callback/{memo}`), `Controller` (base).

### 2.2 Livewire — "controller" UI sesungguhnya (`app/Livewire`)
- **Chat/** `ChatIndex` (workspace chat+memo+Prompy bertab, route `chat/{id?}`; tab Prompy memakai key `prompy`, aktif default via `features.prompy`/`FEATURE_PROMPY`; `normalizeTab()` tetap menerima alias legacy `presentation`/`presentasi` lalu memetakannya ke `prompy`, dan nilai invalid/tab Prompy saat flag mati jatuh ke `chat`).
- **Memos/** `MemoIndex`, `MemoWorkspace` (generate/edit memo).
- **Prompts/** `PrompyWorkspace` (wrapper tab Prompy yang langsung merender `PrompyStudio`; generator PPTX internal, route unduh/editor, job render, dan tabel artefak presentasi sudah dihapus). `PrompyStudio` (#263: generator paket prompt untuk platform AI eksternal — sidebar kiri khusus riwayat prompt private dengan status selesai, form konfigurasi awal di panel tengah, lalu setelah output pertama panel tengah mengikuti pola Memo: ringkasan prompt aktif, tombol edit konfigurasi, dropdown versi, transcript bubble optimistis `Anda`/`ISTA AI`, loading bubble untuk generate, dan composer chat satu baris dengan tombol kirim bulat + lampiran gambar/file revisi opsional; gambar versi aktif ditampilkan sebagai konteks, kirim tanpa gambar baru mewarisi `reference_images` versi aktif, sedangkan lampiran gambar/file baru langsung membuat versi prompt baru dengan `reference_images` pengganti atau konteks dokumen terbaru; composer bersifat intent-aware: instruksi seperti ubah/tambahkan/ganti tetap merevisi paket prompt dan membuat `GeneratedPromptVersion`, sedangkan pertanyaan/konfirmasi biasa dibalas sebagai chat Prompy dan disimpan di `generated_prompts.chat_messages`; panel kanan berisi paket prompt/copy actions; input ide Bahasa Indonesia, pilih platform ChatGPT Images/GPT Image, Gemini/Nano Banana, Canva AI, atau Universal dan jenis keluaran wajib tanpa default terpilih saat compose baru, state lokal Alpine agar pilihan terasa instan, dokumen acuan opsional PDF/DOCX/XLSX/CSV maksimal 3 file untuk konteks isi/narasi, dan hingga 5 reference images privat JPG/PNG via card/dropzone dengan thumbnail `Gambar 1-5` yang dianalisis model vision terkonfigurasi agar gaya visual/warna/layout/komposisi benar-benar masuk ke prompt; output prompt utama Bahasa Inggris + catatan Bahasa Indonesia singkat berupa langkah "Cara pakai"; mode Presentasi diarahkan menjadi deck prompt kit long-form dengan deck brief, style bible, slide-by-slide prompt table, consistency rules, dan revision/repair prompts tanpa memotong detail slide penting; hasil awal/revisi/konfigurasi ulang disimpan sebagai versi prompt pada item riwayat yang sama; header/riwayat memakai judul ringkas deterministik dari ide; copy action main/variants/negative/settings; tidak memanggil platform eksternal).
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
- **Prompts/** `PromptStudioService` (#263: generate/revisi paket prompt via python `/api/prompts/generate` dan chat intent router via `/api/prompts/chat`, validasi platform/jenis termasuk ChatGPT Images/GPT Image, Gemini/Nano Banana, Canva AI, dan Universal; input legacy `google_flow` dinormalisasi ke Gemini agar riwayat lama tetap aman, simpan hingga 5 reference images privat JPG/PNG dengan validasi tipe/jumlah/ukuran, kirim gambar sebagai payload base64 internal berlabel `Gambar 1-5` ke Python untuk vision analysis, ekstrak hingga 3 dokumen acuan privat PDF/DOCX/XLSX/CSV lewat `/api/documents/extract-content` menjadi `context_notes` sementara tanpa menyimpan isi mentah, simpan versi prompt di `GeneratedPromptVersion`, mendukung override konfigurasi serta lampiran gambar/file dari composer revisi saat prompt aktif dibuat ulang sehingga riwayat tetap satu item dan versi bertambah, set `contains_internal_context` bila ada gambar/dokumen/konteks legacy, log usage `prompt_generation` dengan metadata aman; tidak memanggil platform AI eksternal).
- **OnlyOffice/** `DocumentConverter`, `DocxTextExtractor`, `DocxValidator`, `JwtSigner`, `MemoDocumentKey`, `MemoForceSaveService`, `ForceSaveException`.

### 2.4 Models / Jobs / Middleware
- **Models:** `User`, `Conversation`, `Message`, `Document`, `DocumentChunk`, `Memo`, `MemoVersion`, `GeneratedPrompt`, `GeneratedPromptVersion`, `KnowledgeDocument`, `KnowledgeChunk`, `KnowledgeSource`, `AIUsageEvent`, `AdminAccountAudit`.
- **Policies:** `DocumentPolicy`, `MemoPolicy` — auto-discovery Laravel.
- **Jobs:** `GenerateChatResponse` (jalur async chat, komplemen SSE), `ProcessDocument` (→ python `/api/documents/process`), `ProcessKnowledgeDocument` (→ `/api/knowledge/process`, memakai `processing_claim_token` agar retry lama tidak menimpa attempt baru), `RenderDocumentPreview`.
- **Middleware:** `EnsureUserIsActive` (`active`, memutus sesi akun nonaktif pada route user terautentikasi), `EnsureUserIsAdmin` (`admin`), `EnsureUserIsSuperAdmin` (`super_admin`), `EnsureAdminPasswordChanged` (`admin.password_changed`), `EnforceAdminSessionLifetime` (`admin.session`, batas absolut sesi admin), `EnsureTwoFactorVerified` (`admin.2fa`, 2FA TOTP wajib untuk admin), `UpdateUserPresence`, `AddSecurityHeaders` (CSP; `unsafe-eval` global opt-in via `SECURITY_CSP_ALLOW_UNSAFE_EVAL`, plus compatibility otomatis untuk response Livewire/Alpine via `SECURITY_CSP_ALLOW_LIVEWIRE_UNSAFE_EVAL`).
- **Events:** `BookingCreated/BookingStatusChanged/FeedbackSubmitted/ScheduleUpdated` — **sisa domain lama (booking)**, tidak terhubung ke flow chat/memo. Jangan diandalkan untuk fitur AI.

### 2.5 Routes (`routes/`) — hanya `web.php`, `auth.php`, `console.php`
- **web.php:** dashboard `/`; route user seperti `profile`, `chat/{id?}` (ChatIndex), `chat/stream/{conversationId}` (SSE), memo, dan dokumen memakai `auth+active` (serta `verified` sesuai kebutuhan) agar akun nonaktif tidak bisa memakai aplikasi lewat sesi lama; `onlyoffice/callback/{memo}` (JWT + trusted OnlyOffice URL scheme/host/port); grup `chat/memos/*` (download/export-pdf/force-save/signed); legacy `memos/*` redirect ke `/chat?tab=memo`; tidak ada lagi route `chat/presentations/*` atau callback OnlyOffice presentasi; `documents/*` (content-html, extract-tables, export, preview/*); admin auth (`/admin/login`, logout, password change); admin app (`auth+verified+admin+admin.password_changed`): `/admin`, `/users`, `/usage`, `/errors`, `/documents`, `/knowledge`; super-admin: `/admin/accounts`.
- **auth.php:** Volt pages login/forgot/reset/verify/confirm; register → redirect login register-view hanya jika `PUBLIC_REGISTRATION_ENABLED=true` (production private default tertutup).
- **Horizon:** `/horizon` memakai middleware `web+auth+verified+super_admin+admin.password_changed` dan gate `viewHorizon`; hanya super-admin aktif yang tidak sedang wajib ganti password boleh akses. `App\Console\Commands\CompatibleHorizonWorkCommand` menggantikan binding `horizon:work` agar Horizon 5.45 tetap kompatibel dengan opsi worker Laravel 13 `--stop-when-empty-for`, mencegah worker crash-loop di production.
- **console.php:** `documents:purge-deleted --days=7` tetap terjadwal sebagai no-op kompatibilitas karena dokumen kini hard-delete; `chat:resolve-stale-responses --minutes=10` berjalan tiap menit.

### 2.6 Frontend Laravel (`resources/`)
- **js/**: `app.js` (registrasi Alpine+Livewire), `bootstrap.js`, **`chat-page.js`** (client berat: Alpine `chatLayout` tab chat/memo/Prompy (`prompyEnabled` gating tab), `prompyWorkspace` untuk sidebar/mobile panel Prompy, drag-drop upload, `assistantTypewriter`, `chatMessages`). **SSE**: `openChatStream(...)` membangun `/chat/stream/{id}?...` lalu `new EventSource(url)` (dilacak di `activeEventSources`), render typewriter markdown (marked + DOMPurify); koordinasi placeholder via `$wire.on` (`assistant-output`, `model-name`, `assistant-sources`, dll) + marker localStorage.
- **css/**: `app.css`, `auth.css`.
- **views/**: `dashboard` (CTA Chat/Memo + Prompy saat `features.prompy` aktif), `profile`; `livewire/chat/*` (+partials), `livewire/memos/*`, `livewire/prompts/*` (Prompy Studio + riwayat prompt), `livewire/documents/*`, `livewire/admin/*` (dashboard/users/usage/errors/documents/knowledge/accounts), Volt auth pages, `components/admin/*` (design system: sidebar/table/tabs/kpi-card/badge/filter/empty-state), `layouts/` (app/admin/guest/auth-canvas), `emails/`.

### 2.7 Database (`laravel/database`)
Tabel utama: `users` (+verification_code, role/presence, kolom keamanan admin), `conversations`, `messages` (+document_ids), `documents` (metadata file, preview, index, unique original_name), `document_chunks`, `memos` (+chat_messages, configuration), `memo_versions`, `generated_prompts` (parent riwayat Prompy Studio #263: platform/prompt_type + label, title, idea, package/current_version_id kompatibilitas, `source_document_ids` JSON legacy/selalu kosong untuk prompt baru, `chat_messages` untuk percakapan Prompy biasa yang tidak membuat versi, contains_internal_context, metadata reference image pertama untuk kompatibilitas, model_label, owner-scoped + softDeletes), `generated_prompt_versions` (hasil v1/revisi/konfigurasi ulang Prompy: package JSON, revision_instruction untuk instruksi perubahan, reference_images privat berlabel, model_label), `ai_usage_events`, knowledge (`knowledge_sources`/`knowledge_documents`/`knowledge_chunks`), `admin_account_audits`, `activity_log` (spatie) + cache/jobs. Migrasi lama menghapus tabel `ai_configuration`, `cloud_storage_files`, `google_drive_oauth_connections`, serta migration `2026_06_21_000001_drop_presentation_generation_tables` menghapus tabel artefak presentasi lama (`presentations`, `presentation_versions`). Seeder: `DatabaseSeeder`; factory: `UserFactory`.

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
- `prompts.py` — `/api/prompts/generate` (POST, JSON), `/api/prompts/chat` (POST, JSON), + `/api/prompts/profiles` (GET): Prompy Studio (#263). `/generate` memanggil LLM untuk menyusun atau merevisi paket prompt (main_prompt EN, variants, negative_prompt, recommended_settings, notes_id ID) untuk platform AI eksternal; payload dapat membawa `context_notes` dari catatan/dokumen acuan, `reference_images[]` (label + mime + base64, maks 5; legacy `reference_image` tetap diterima) yang dianalisis dulu oleh model vision terkonfigurasi menjadi brief visual terstruktur per `Gambar 1-5` (orientasi/rasio, teks terlihat, layout, palet, elemen yang dipertahankan/diganti), lalu brief itu masuk ke generator prompt agar prompt akhir menjaga fidelity referensi; payload revisi dapat membawa `current_package` + `revision_instruction` agar output baru berbasis versi aktif. `/chat` memakai LLM sebagai router percakapan natural untuk memilih `answer`, `clarify`, atau `revise` dari pesan user + paket aktif + riwayat pendek; pertanyaan seperti "apa yang kurang?" tetap `answer`, sementara arahan perubahan jelas menghasilkan `revision_instruction` untuk revisi. **Tidak** memanggil platform target seperti GPT Image/Gemini/Canva. Profil platform/jenis, model vision, dan template dari `ai_config.yaml` (`prompt_studio`, `prompts.prompt_studio_reference_image`, `prompts.prompt_studio_chat`). `ValueError`→400.

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
- `lightweight_text_splitter.py` — splitter token-aware. `document_loaders.py` — load pdf/docx/xlsx/csv→Document. `document_content.py` — dokumen→HTML (preview/export). `table_extraction.py` — ekstrak tabel. `document_export.py` — `export_content` (HTML→format target). `memo_generation.py` — `generate_memo_docx`. `prompt_generation.py` — `generate_prompt_package` (Prompy Studio #263: rangkai/revisi paket prompt JSON via LLM, parse defensif; `context_notes` dapat berisi ringkasan dokumen acuan dari Laravel; `analyze_reference_images` membaca hingga 5 gambar referensi via model vision lalu memasukkan brief visual terstruktur berlabel `Gambar 1-5` ke prompt akhir, termasuk orientasi/rasio, teks terlihat, layout, dan instruksi preserve/replace; tipe Presentasi diarahkan menjadi deck prompt kit dengan style bible + slide-by-slide prompt table; `current_package` + `revision_instruction` membuat revisi berbasis versi aktif; fallback platform/jenis generic/image) dan `generate_prompt_chat_decision` (router AI untuk chat Prompy: `answer`/`clarify`/`revise`, JSON-only, parse defensif). `latency_logger.py` — timing privacy-safe.

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
- **Prompy Studio:** tab Prompy memakai key URL `prompy`, dengan alias lama `presentation`/`presentasi` tetap diarahkan ke Prompy. `PrompyWorkspace` merender `PrompyStudio` untuk menyusun paket prompt eksternal. Sidebar kiri berisi riwayat prompt private yang sudah berhasil dibuat. Saat compose baru, panel tengah berisi form ide/platform/jenis/gambar referensi/dokumen acuan; setelah paket prompt aktif tersedia, panel tengah mengikuti model Memo: ringkasan `Prompt aktif`, tombol `Edit konfigurasi`, dropdown `Versi prompt` bila versi >1, transcript bubble `Anda`/`ISTA AI` dari konfigurasi, versi prompt, dan `chat_messages`, loading bubble untuk generate, serta composer chat satu baris dengan tombol kirim bulat dan tombol lampirkan gambar/file. Submit composer Prompy memakai handler Alpine optimistis seperti Memo: teks user langsung muncul sebagai bubble `Anda`, input dikosongkan, lalu Livewire `sendPromptChat()` meminta `PromptStudioService::chat()` memanggil Python `/api/prompts/chat` bila tidak ada lampiran gambar/file baru. Model AI membaca pesan user, paket aktif, dan riwayat pendek untuk memilih `answer`, `clarify`, atau `revise`: pertanyaan/review/sapaan seperti "apa yg kurang?" atau "tesin" dibalas natural dan disimpan append-only ke `chat_messages`, sedangkan arahan perubahan jelas seperti "buat lebih minimalis" menghasilkan `revision_instruction` lalu diteruskan ke `PromptStudioService::revise()` sehingga versi baru dibuat dan panel kanan diperbarui. Jika composer revisi membawa gambar/file baru, Livewire memperlakukan pesan sebagai revisi eksplisit, melewati router chat natural, dan langsung membuat versi baru melalui `PromptStudioService::revise()` dengan `reference_images` pengganti atau `context_notes` dokumen terbaru; tanpa lampiran baru, revisi tetap mewarisi gambar versi aktif. Submit konfigurasi pada prompt aktif tidak membuat history baru; `PromptStudioService::revise()` menerima override ide/platform/jenis/reference image/dokumen acuan dan membuat versi baru pada item riwayat yang sama. Riwayat Prompy mengikuti pola Memo: urutan/grouping memakai `updated_at`, item aktif disinkronkan ke `activePromptId` Livewire setelah klik selesai, dan section yang berisi item aktif otomatis terbuka agar highlight tidak lompat. Pilihan platform/jenis wajib dipilih user dan tidak punya card default pada compose baru; platform aktif meliputi ChatGPT Images/GPT Image, Gemini/Nano Banana, Canva AI, dan Universal. State lokal Alpine yang ter-entangle deferred ke Livewire menjaga highlight berpindah instan tanpa round-trip server per klik. Dokumen acuan Prompy berbeda dari dokumen sumber RAG: hingga 3 PDF/DOCX/XLSX/CSV dapat diunggah sebagai bahan konteks prompt, diekstrak sementara lewat `/api/documents/extract-content`, lalu dikirim sebagai `context_notes` tanpa menyimpan isi mentah. Hingga 5 gambar referensi JPG/PNG dapat diunggah lewat card/dropzone; UI menampilkan thumbnail lokal berlabel otomatis `Gambar 1`, `Gambar 2`, dst. tanpa role/catatan per gambar. Laravel menyimpan gambar privat lalu Python menganalisisnya dengan model vision (`prompt_studio.vision_models`) menjadi brief gaya visual/warna/layout/komposisi, orientasi/rasio, teks terlihat, serta elemen preserve/replace sebelum generator prompt berjalan; prompt akhir wajib menjaga rasio referensi dan tidak fallback ke rasio umum seperti 16:9 saat referensi jelas vertikal/square. Untuk jenis Presentasi, prompt akhir berupa deck prompt kit long-form dengan deck brief, style bible, slide-by-slide prompt table, consistency rules, serta revision/repair prompts agar deck panjang dapat dibuat per slide atau batch kecil tetapi tetap konsisten; backend tidak lagi memotong `main_prompt` presentasi di batas prompt gambar biasa, dan panel hasil tetap scrollable sehingga output panjang dibiarkan utuh. Panel hasil tidak lagi memakai badge tambahan untuk reference image; usage metadata tetap menyimpan flag/count aman `has_reference_image`/`reference_image_analyzed`/`has_reference_document` tanpa isi prompt, brief visual, atau isi dokumen mentah. `Prompt Baru` menampilkan panel hasil kosong/compose baru walau riwayat sudah ada, sekaligus mereset ide, platform, jenis keluaran, gambar referensi, dan dokumen acuan; klik riwayat membuka paket prompt yang dipilih dengan loading kecil pada item riwayat seperti Memo. Judul prompt dipadatkan deterministik dari ide agar header tidak menyalin permintaan user penuh. Generator PPTX internal sudah dihapus; arah produk untuk deck/visual adalah memperkuat kualitas prompt yang disalin ke platform eksternal.
- **Export:** `POST /documents/export` & memo `export-pdf`/`download` → python `/api/documents/export`.

### 5.2 Flow Admin (`/admin`, guard `admin`/`super_admin` + `admin.session` + `admin.password_changed` + `admin.2fa`)
- **Usage:** analitik AI dari `ai_usage_events` (chat/web_search/document_rag, model, latensi).
- **Documents:** kelola dokumen + status pipeline ingest.
- **Users:** manajemen user/presence. **Accounts (super-admin):** kelola akun admin + audit.
- **Errors:** monitor event AI gagal.
- **Knowledge:** CRUD KB internal; ingest via job `ProcessKnowledgeDocument` + `KnowledgeLifecycleService` → python `/api/knowledge` (scope global_internal). Archive/activate melakukan sync `knowledge_status` di Chroma sebelum status DB berubah; stale job knowledge ditahan oleh `processing_claim_token`. Flow ini menggerakkan jawaban knowledge internal di general chat.
- **Dashboard:** KPI via `AdminMetricsService`. Auth admin: `/admin/login` terpisah + forced password change.
- **Keamanan login admin (paritas dengan Istura):**
  - **Lockout login progresif** — `AdminLoginController` memakai cache: setelah 3 percobaan gagal (per email+IP, counter 30 menit) berlaku delay eksponensial `2^(n-3)` detik (cap 300 dtk) di atas throttle route `throttle:10,1`. Pesan gagal tetap generik (anti-enumerasi) dan kegagalan tercatat di `admin_account_audits`.
  - **2FA TOTP wajib** — semua route `/admin/*` (kecuali login, logout, ganti password, dan halaman 2FA itu sendiri) berada di belakang `admin.2fa` (`EnsureTwoFactorVerified`). Admin tanpa 2FA dipaksa enrol di `/admin/2fa/setup` (QR + recovery codes via `App\Services\Auth\TwoFactorService`, `pragmarx/google2fa` + `bacon/bacon-qr-code`); admin yang sudah enrol harus lolos `/admin/2fa/challenge` (TOTP atau recovery code) untuk memverifikasi sesi. Opsi "percayai perangkat 30 hari" disimpan di tabel `trusted_devices` (cookie `ista_trusted_device`, hash sha256). Aksi 2FA dicatat ke audit (`ACTION_TWO_FACTOR_ENABLED/VERIFIED/FAILED`).
  - **Absolute session lifetime admin** — `EnforceAdminSessionLifetime` (`admin.session`) menstempel `admin_session_started_at` saat login dan memaksa logout + redirect `/admin/login` setelah `session.admin_absolute_lifetime` menit (env `ADMIN_SESSION_ABSOLUTE_LIFETIME`, default 720) terlampaui, terlepas dari aktivitas.
  - Urutan middleware grup admin: `auth → verified → admin/super_admin → admin.session → admin.password_changed → admin.2fa`, sehingga akun nonaktif/role salah ditolak lebih dulu, ganti password dipaksa sebelum 2FA, dan halaman setup/challenge 2FA tidak memicu loop.

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
- Token, secret OnlyOffice, kredensial DB, kredensial deploy/email, API key provider → hanya di `.env` lokal/deploy, jangan commit. Jangan commit dokumen produksi, dump DB, service-account JSON, atau data Chroma.
- Akses dokumen: signed URL, private disk, otorisasi server-side. Baca `docs/data-flow-privacy.md` sebelum pakai data nyata.
