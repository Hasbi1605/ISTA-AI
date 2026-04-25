# Issue Plan: Parity Penuh Capability Python AI ke Laravel-only Multi-provider Sebelum Decommission

## Latar Belakang
Migrasi menuju arsitektur `Laravel-only` sudah berjalan bertahap melalui boundary runtime, Laravel AI SDK, document lifecycle, chat/web search, dan RAG dokumen. Namun hasil analisis terbaru menunjukkan bahwa `python-ai` masih memiliki beberapa capability penting yang belum boleh hilang begitu saja saat full cutover.

User sudah menegaskan bahwa kemampuan yang sebelumnya ada di Python terasa penting dan perlu dipertahankan. User juga tidak memiliki `OPENAI_API_KEY`, sehingga target parity tidak boleh bergantung pada OpenAI API sebagai syarat utama. Karena itu issue ini menjadi gate wajib sebelum issue cutover, re-ingest, dan decommission `python-ai` dijalankan.

Targetnya bukan sekadar mematikan Python, tetapi memastikan seluruh capability bernilai tinggi dari Python punya pengganti setara di Laravel-only dengan token/API yang sudah dipakai sebelumnya: `GITHUB_TOKEN`, `GITHUB_TOKEN_2`, `GROQ_API_KEY`, `GEMINI_API_KEY` jika tersedia/free-tier memadai, dan `LANGSEARCH_API_KEY`. Provider-managed path boleh dipakai hanya jika kompatibel dengan token yang tersedia dan kualitasnya setara atau hampir setara.

Issue ini melengkapi issue cutover yang sudah ada:

- `issue/issue-cutover-reingest-decommission-python-ai.md`

Cutover final harus menunggu issue parity ini selesai agar tidak ada regresi kualitas besar pada chat, RAG, retrieval, OCR/parsing, source rendering, model fallback, dan lifecycle dokumen.

## Tujuan
- Menginventaris seluruh capability penting yang saat ini hanya atau terutama tersedia di `python-ai`.
- Menentukan target pengganti setiap capability di ekosistem Laravel-only multi-provider, bukan OpenAI-only.
- Membuat parity gate yang wajib lolos sebelum `python-ai` boleh dinonaktifkan.
- Memastikan migrasi tidak menghilangkan kualitas yang sudah terasa penting bagi user.
- Menentukan capability mana yang harus setara 1:1, mana yang boleh berubah implementasi tetapi wajib setara secara user-facing, dan mana yang butuh fallback tambahan.
- Memastikan sistem tetap bisa berjalan tanpa `OPENAI_API_KEY`.

## Ruang Lingkup
- Inventaris capability Python yang wajib dipertahankan:
  - multi-model cascade dan fallback
  - handling context-too-large/rate-limit antar model
  - model marker `[MODEL:...]`
  - web search dan realtime policy
  - LangSearch web search dan rerank
  - document RAG
  - Chroma vector store atau pengganti Laravel-only yang setara/hampir setara
  - embedding fallback primary/backup
  - `text-embedding-3-large` dan `text-embedding-3-small`
  - hybrid retrieval vector + BM25
  - Reciprocal Rank Fusion/RRF
  - HyDE
  - Parent Document Retrieval/PDR
  - token-aware chunking
  - batch delay/rate limit guard saat ingest
  - PDF, DOCX, XLSX, CSV parsing
  - scanned PDF/OCR fallback
  - summarization dokumen besar berbasis chunk/batch
  - document-first vs web/realtime policy
  - source rendering dan source metadata
  - delete cleanup artifact dengan isolasi user
- Pemetaan setiap capability ke target:
  - Laravel AI SDK jika mendukung provider/token yang tersedia
  - Laravel custom provider adapter untuk GitHub Models, Groq, Gemini/free-tier, dan LangSearch
  - Laravel-managed RAG/hybrid retrieval sebagai pengganti utama Chroma/Python bila provider-managed tidak cocok
  - provider-managed file search hanya jika kompatibel dengan token yang tersedia dan lolos parity
  - Laravel service custom
  - package PHP tambahan
  - fallback minimal yang tetap Laravel-only
- Penyusunan test matrix parity sebelum cutover.
- Penyusunan keputusan token/API/model mana yang tetap dipakai, diganti, atau dipensiunkan setelah parity selesai.

## Di Luar Scope
- Menghapus `python-ai` langsung.
- Melakukan cutover production.
- Re-ingest dokumen lama.
- Menghapus token provider lama sebelum pengganti parity dinyatakan aman.
- Refactor UI/UX chat yang tidak terkait parity capability.
- Mengganti MySQL sebagai database utama.

## Capability Inventory dan Target Parity

| Capability Python Saat Ini | Fungsi | Target Laravel-only Multi-provider | Parity Wajib |
| --- | --- | --- | --- |
| Multi-model cascade GPT-4.1, GPT-4o, Groq, Gemini | Menjaga jawaban tetap tersedia saat model gagal, rate limit, atau context terlalu besar | Runtime fallback/cascade di Laravel dengan urutan sama seperti Python | Wajib |
| Handling 413/context-too-large | Pindah ke model context lebih besar | Laravel fallback policy per error provider | Wajib |
| Handling 429/rate-limit | Pindah ke model/provider cadangan | Laravel fallback policy dan observability | Wajib |
| `[MODEL:...]` marker | Menampilkan model yang dipakai ke UI/log | Standard stream metadata Laravel | Wajib |
| LangSearch web search | Pencarian realtime/web | LangSearch dipanggil langsung dari Laravel | Wajib setara user-facing |
| LangSearch rerank | Ranking hasil web/dokumen | LangSearch rerank dipanggil langsung dari Laravel | Wajib |
| Chroma vector store | Simpan dan query embeddings lokal | Laravel-managed index/vector store atau alternatif setara/hampir setara yang lolos test | Wajib ada pengganti |
| Embedding fallback GitHub primary/backup | Ketahanan ingest/retrieval saat kuota habis | Embedding provider fallback di Laravel | Wajib |
| `text-embedding-3-large` 3072 dim | Kualitas retrieval utama | Pertahankan model atau buktikan model baru setara | Wajib dibuktikan |
| `text-embedding-3-small` fallback | Fallback embedding lebih murah/ringan | Tetap sebagai fallback Laravel | Wajib |
| Hybrid vector + BM25 | Menangkap query keyword/nama teknis | Implementasi Laravel custom atau provider search yang setara | Wajib untuk dokumen akademik/kantor |
| RRF | Menggabungkan ranking BM25 dan vector | Custom rank fusion Laravel atau provider rerank | Wajib jika hybrid dipertahankan |
| HyDE | Meningkatkan retrieval konseptual | Laravel pre-query expansion atau provider query rewrite | Wajib diuji |
| PDR | Presisi child chunk + konteks parent chunk | Provider file search atau Laravel parent/child index | Wajib untuk dokumen panjang |
| Token-aware chunking | Mengontrol ukuran chunk dan biaya | Laravel chunker token-aware | Wajib |
| Batch ingest throttling | Menghindari rate limit saat dokumen besar | Laravel queue batching/rate limiter | Wajib |
| OCR/scanned PDF | Membaca PDF scan | Samakan dengan Python jika Gemini/free-tier memadai; jika tidak, pakai alternatif Laravel-compatible gratis seperti Tesseract/system OCR atau provider gratis yang lolos fixture | Wajib jika user memakai PDF scan |
| Summarization chunk/batch | Ringkas dokumen besar | Laravel summarization pipeline berbasis chunk/provider file | Wajib |
| Source metadata | Rujukan dokumen/web di UI | Kontrak `[SOURCES:...]` tetap dipertahankan | Wajib |
| Document-vs-web policy | Dokumen-first, explicit web, realtime auto | `DocumentPolicyService` plus test parity | Wajib |
| Delete cleanup per user | Cegah artifact user lain terhapus | Laravel delete path dengan `user_id` sebagai source of truth | Wajib |

## Model dan API Token yang Harus Diputuskan

| Env/Model | Status Saat Ini | Keputusan yang Dibutuhkan |
| --- | --- | --- |
| `OPENAI_API_KEY` | Tidak dimiliki user dan tidak boleh menjadi syarat runtime utama | Jadikan opsional saja; sistem harus lolos tanpa token ini |
| `AI_MODEL` | Model utama Laravel saat ini masih satu model | Ganti/augment dengan konfigurasi cascade multi-provider agar sama seperti Python |
| `GITHUB_TOKEN` | Python GitHub Models primary untuk chat/embedding | Tetap dipakai sebagai primary di Laravel |
| `GITHUB_TOKEN_2` | Python GitHub Models backup | Tetap dipakai sebagai backup di Laravel |
| `GROQ_API_KEY` | Python fallback Llama context besar | Tetap dipakai sebagai fallback Laravel untuk context besar/limit tertentu |
| `GEMINI_API_KEY`/`GOOGLE_API_KEY` | Gemini fallback dan potensi OCR/vision path | Pakai hanya jika free-tier/token tersedia; jika tidak memadai, siapkan alternatif OCR gratis Laravel-compatible |
| `LANGSEARCH_API_KEY` | Web search dan rerank | Tetap dipakai dari Laravel untuk web search dan rerank |
| `LANGSEARCH_API_KEY_BACKUP` | Backup LangSearch | Tetap dipakai dari Laravel jika tersedia |
| `AI_SERVICE_URL` | Boundary Laravel ke Python | Hapus hanya setelah cutover final |
| `AI_SERVICE_TOKEN` | Auth internal Laravel ke Python | Hapus hanya setelah cutover final |

Urutan model chat yang ditargetkan tetap mengikuti Python:

1. `openai/gpt-4.1` via GitHub Models dengan `GITHUB_TOKEN`
2. `openai/gpt-4.1` via GitHub Models dengan `GITHUB_TOKEN_2`
3. `openai/gpt-4o` via GitHub Models dengan `GITHUB_TOKEN`
4. `openai/gpt-4o` via GitHub Models dengan `GITHUB_TOKEN_2`
5. `groq/llama-3.3-70b-versatile` dengan `GROQ_API_KEY`
6. Gemini Flash/free-tier dengan `GEMINI_API_KEY` hanya jika tersedia dan lolos test; jika tidak, harus ada fallback Laravel-only lain yang disetujui.

Urutan embedding yang ditargetkan tetap mengikuti Python:

1. `text-embedding-3-large` via GitHub Models dengan `GITHUB_TOKEN`
2. `text-embedding-3-large` via GitHub Models dengan `GITHUB_TOKEN_2`
3. `text-embedding-3-small` via GitHub Models dengan `GITHUB_TOKEN`
4. `text-embedding-3-small` via GitHub Models dengan `GITHUB_TOKEN_2`

## Area / File Terkait
- `laravel/config/ai.php`
- `laravel/config/ai_runtime.php`
- `laravel/app/Services/AIRuntimeResolver.php`
- `laravel/app/Services/Runtime/LaravelAIGateway.php`
- `laravel/app/Services/Runtime/PythonLegacyAdapter.php`
- `laravel/app/Services/Chat/LaravelChatService.php`
- `laravel/app/Services/Document/LaravelDocumentService.php`
- `laravel/app/Services/Document/LaravelDocumentRetrievalService.php`
- `laravel/app/Services/Document/DocumentPolicyService.php`
- `laravel/app/Services/DocumentLifecycleService.php`
- `laravel/app/Jobs/ProcessDocument.php`
- `python-ai/config/ai_config.yaml`
- `python-ai/app/llm_manager.py`
- `python-ai/app/main.py`
- `python-ai/app/routers/documents.py`
- `python-ai/app/services/rag_config.py`
- `python-ai/app/services/rag_ingest.py`
- `python-ai/app/services/rag_retrieval.py`
- `python-ai/app/services/rag_policy.py`
- `python-ai/app/services/rag_summarization.py`
- `python-ai/app/services/langsearch_service.py`
- `python-ai/requirements.txt`

## Risiko
- Migrasi terlihat berhasil secara deployment tetapi kualitas RAG turun karena PDR, HyDE, BM25, atau rerank hilang.
- Satu model Laravel default tidak cukup menggantikan cascade Python saat provider rate limit atau context terlalu besar.
- Jika Laravel hanya diarahkan ke OpenAI provider-managed file search, sistem tidak sesuai kebutuhan user karena `OPENAI_API_KEY` tidak tersedia.
- Pengganti Chroma/PDR/BM25/HyDE di Laravel bisa lebih kompleks daripada provider-managed, tetapi dibutuhkan jika ingin kualitas mendekati Python tanpa OpenAI.
- OCR/scanned PDF bisa menjadi regresi besar jika Gemini free-tier tidak cukup dan tidak ada alternatif OCR gratis.
- Token lama dipensiunkan terlalu cepat padahal masih dibutuhkan untuk fallback kualitas.
- Delete cleanup bisa menghapus artifact lintas user jika user isolation tidak menjadi kontrak end-to-end.
- Biaya provider bisa naik jika semua capability dipindahkan ke provider-managed tanpa quota/rate limit.

## Langkah Implementasi
1. Buat matrix baseline capability Python dari `python-ai/config/ai_config.yaml` dan service RAG terkait.
2. Tandai setiap capability sebagai:
   - exact parity
   - behavior parity
   - provider parity
   - perlu fallback tambahan
3. Tambahkan test/fixture untuk membuktikan capability yang sekarang belum terlindungi:
   - multi-model fallback
   - context-too-large fallback
   - rate-limit fallback
   - document RAG grounded answer
   - source metadata
   - hybrid/BM25 query
   - HyDE query konseptual
   - dokumen panjang dengan PDR
   - PDF scan/OCR
   - summarization dokumen besar
   - delete duplicate filename lintas user
4. Implementasikan runtime fallback/cascade Laravel dengan urutan model yang sama seperti Python.
5. Implementasikan adapter Laravel untuk GitHub Models, Groq, Gemini/free-tier jika tersedia, dan LangSearch.
6. Implementasikan LangSearch web search dan LangSearch rerank langsung dari Laravel.
7. Implementasikan atau pilih pengganti retrieval Python dengan target behavior sama:
   - Laravel-managed hybrid index berbasis `document_chunks`, embedding, BM25/fulltext, RRF, HyDE, dan PDR; atau
   - provider/layanan lain yang kompatibel dengan token tersedia dan terbukti setara/hampir setara; atau
   - fallback Laravel-only lain yang lolos test parity.
8. Implementasikan pipeline ingest/chunking Laravel yang token-aware dan aman terhadap rate limit.
9. Implementasikan strategy OCR/scanned PDF:
   - gunakan Gemini/free-tier jika tersedia dan kualitasnya memadai; atau
   - gunakan alternatif gratis Laravel-compatible seperti Tesseract/system OCR; atau
   - nyatakan tidak setara hanya dengan approval eksplisit user.
10. Jalankan dual-run/shadow comparison Python vs Laravel untuk fixture parity.
11. Putuskan token/model mana yang tetap dipakai, diganti, atau dihapus.
12. Baru setelah semua gate lolos, lanjutkan issue cutover/decommission.

## Rencana Test
- Full Laravel test:
  - `cd laravel && php artisan test`
- Full Python baseline test selama Python masih menjadi referensi:
  - `cd python-ai && source venv/bin/activate && pytest`
- Test parity baru di Laravel untuk:
  - runtime berjalan tanpa `OPENAI_API_KEY`
  - model cascade dan fallback
  - stream metadata `[MODEL:...]` dan `[SOURCES:...]`
  - chat biasa tanpa dokumen
  - chat dokumen dengan jawaban grounded
  - dokumen tidak memiliki jawaban
  - explicit web saat dokumen aktif
  - realtime auto web tanpa dokumen
  - upload/process PDF, DOCX, XLSX, CSV
  - PDF scan/OCR
  - dokumen panjang dengan chunk/batch
  - summarization dokumen besar
  - delete duplicate filename lintas user
- Manual smoke test setelah parity:
  - pertanyaan umum
  - pertanyaan realtime
  - pertanyaan dokumen akademik/kantor
  - pertanyaan keyword spesifik
  - pertanyaan konseptual
  - upload, summarize, delete dokumen

## Kriteria Selesai
- Semua capability penting Python sudah dipetakan ke target Laravel-only multi-provider atau alternatif setara/hampir setara.
- Tidak ada token/model Python yang dipensiunkan sebelum pengganti setara tersedia.
- Sistem berjalan tanpa `OPENAI_API_KEY`.
- Multi-model fallback/cascade punya padanan Laravel dengan urutan yang sama seperti Python atau keputusan eksplisit yang diuji.
- GitHub Models, Groq, Gemini/free-tier jika tersedia, dan LangSearch dipanggil dari Laravel tanpa menjalankan service Python.
- Retrieval dokumen Laravel lolos parity untuk vector, keyword, dokumen panjang, dan source rendering.
- OCR/scanned PDF punya keputusan final: didukung setara via Gemini/free-tier, didukung via OCR gratis Laravel-compatible, atau dinyatakan tidak dipakai dengan approval eksplisit.
- Delete cleanup memakai isolasi user end-to-end dan dilindungi test caller-level.
- Acceptance matrix parity hijau untuk skenario utama.
- Issue cutover/decommission boleh dilanjutkan hanya setelah issue ini selesai.

## Catatan Keputusan
- Default keputusan saat issue dibuat: jangan menghapus `python-ai`, token provider Python, atau Chroma artifacts sampai parity issue ini selesai.
- `OPENAI_API_KEY` tidak tersedia dan tidak boleh menjadi syarat runtime utama.
- Target RAG adalah parity behavior dengan Python. Implementasi boleh berbeda, tetapi kualitas harus sama atau hampir sama berdasarkan fixture.
- Jika provider-managed tidak mampu atau tidak kompatibel dengan token yang tersedia, fallback terkecil tetap harus menjaga arah Laravel-only tanpa mengurangi kualitas user-facing.
- LangSearch tetap dipakai untuk web search dan rerank.

## Acceptance Matrix (Skenario Test Parity)
Setiap capability telah dipetakan menjadi test case minimal di `laravel/tests/Feature/Parity/AIParityMatrixTest.php`. File tersebut berfungsi sebagai executable acceptance matrix. Saat ini, sebagian besar test di-mark sebagai `incomplete` karena merupakan gap yang harus diselesaikan.

## Daftar Gap Parity (Input Child Issue)
Berdasarkan inventaris di atas, berikut adalah gap utama yang belum ada di ekosistem Laravel-only dan harus dipecah menjadi child issue:
1. **Runtime Fallback & Error Handling**: Laravel perlu mendukung cascade multi-model (GPT-4.1 -> 4o -> Groq -> Gemini) dan mendeteksi error 413 (context too large) / 429 (rate limit) untuk otomatis berpindah model.
2. **LangSearch Integration**: Laravel perlu memiliki client adapter untuk memanggil LangSearch Web Search dan LangSearch Reranker secara langsung.
3. **Advanced RAG Engine (Vector Store, Hybrid, RRF, HyDE, PDR)**: Pengganti Chroma yang mendukung pencarian hybrid (Vector + BM25), Reciprocal Rank Fusion, query expansion (HyDE), dan Parent Document Retrieval.
4. **Embedding Fallback**: Laravel harus mendukung fallback text-embedding-3 (primary -> backup -> small).
5. **Ingest Pipeline & Chunking**: Laravel perlu mengimplementasikan pemotongan dokumen yang sadar-token (token-aware chunking) dan batch ingest throttling.
6. **OCR / Scanned PDF**: Laravel perlu strategi OCR menggunakan Gemini Vision (free-tier) atau alternatif lain seperti Tesseract untuk file scan.
7. **Summarization Pipeline**: Mekanisme map-reduce/batch untuk meringkas dokumen yang sangat panjang.
8. **Stream Marker & Metadata**: Render metadata `[MODEL:...]` dan `[SOURCES:...]` ke UI Chat dari Laravel stream.
