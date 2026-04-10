# ISTA AI - Architecture Overview

Ini adalah repositori inti untuk subsistem kognitif **ISTA AI**, yang berfungsi ganda sebagai *mesin obrolan pintar (Chat)* dan *mesin pencari dokumen (RAG - Retrieval Augmented Generation)*.

Arsitektur saat ini telah berevolusi menjadi arsitektur tingkat *Enterprise* dengan skema **Dual-Node Load Balancing** dan **Search-Aware Filtering** untuk memaksimalkan ketersediaan, kecepatan, dan efisiensi kuota.

## 🚀 Update Tahap 5: Stabilitas Ingest Dokumen Panjang

**Status:** ✅ Implemented (April 2026)

Sistem RAG telah diupgrade dengan teknologi **Token-Aware Chunking** dan **Aggressive Batching** untuk mengatasi masalah crash dan lambatnya pemrosesan dokumen besar:

- **Token-Aware Recursive Chunking:** Menggunakan tiktoken (cl100k_base) untuk chunking berbasis token, bukan karakter
- **Aggressive Batching:** 200 chunks per batch (20x lebih cepat dari sebelumnya)
- **4-Tier Cascading Fallback:** Total kapasitas 2 Million TPM (4 × 500K TPM)
- **Circuit Breaker:** Automatic failover saat rate limit dengan exponential backoff
- **Performa:** Dokumen 150 halaman dari ~15 menit → ~1.5 menit (10x faster)

📖 **Detail lengkap:** Lihat [CHANGELOG_TAHAP5.md](python-ai/CHANGELOG_TAHAP5.md)

## 🌟 High-Level Flow Architecture (Update: 2026)

### 1. Chat Generation (LLM Manager)
**File Utama:** `app/llm_manager.py`
Sistem akan memproses percakapan dengan metode **Failover Load Balancer** berjenjang:
1. **[Primary Node] GPT-5 Chat via GitHub Models (`GITHUB_TOKEN`)**
   Otomatis memproses seluruh obrolan menggunakan kecerdasan GPT-5 terbaru. Cepat dan ideal untuk penalaran RAG.
2. **[Backup Node] GPT-5 Chat via GitHub Models (`GITHUB_TOKEN_2`)**
   *Auto-Failover* peluru perak. Jika Token Utama terkena *Rate Limit* atau *Server Down*, sistem secara cerdas menangkap *RateLimitError* dari `litellm` tanpa jeda (*zero retry timeout*), dan mengoper beban ke Token Cadangan secara instan. User tidak menyadari adanya perpindahan.
3. **[Tertiary Node] Gemini 3 Flash / Llama 3.3**
   Digunakan jika kedua GitHub token lumpuh total (Misal: GitHub Models sedang down secara global).

### 2. Embeddings & Document Vectoring (RAG Service)
**File Utama:** `app/services/rag_service.py`

Saat user mengunggah PDF, ISTA AI mengubah dokumen fisik menjadi data vektor numerik tingkat tinggi (3072 dimensi).

**Update Tahap 5 - Token-Aware Chunking:**
1. **Mesin Pemotong:** RecursiveCharacterTextSplitter dengan tiktoken (cl100k_base)
   - Chunk size: 1500 tokens (optimal untuk text-embedding-3-large)
   - Overlap: 150 tokens (mempertahankan konteks)
   - Prioritas semantic boundaries: paragraf → kalimat → kata
   
2. **Mesin Embedding:** 4-Tier Cascading System
   - **Tier 1:** text-embedding-3-large (Primary) - 500K TPM, 3072 dim
   - **Tier 2:** text-embedding-3-large (Backup) - 500K TPM, 3072 dim
   - **Tier 3:** text-embedding-3-small (Fallback 1) - 500K TPM, 1536 dim
   - **Tier 4:** text-embedding-3-small (Fallback 2) - 500K TPM, 1536 dim
   - **Total Capacity:** 2 Million TPM
   
3. **Mesin Penyimpan:** **ChromaDB** (Database Vektor Lokal `chroma_data/`)

4. **Aggressive Batching:** 200 chunks per batch dengan 0.5s delay
   - Dapat memproses ~300,000 tokens per batch
   - Circuit breaker untuk automatic failover saat rate limit
   - Exponential backoff retry logic

**Performa:**
- Dokumen 50 halaman: ~30 detik (sebelumnya ~5 menit)
- Dokumen 150 halaman: ~1.5 menit (sebelumnya ~15 menit)
- Dokumen 500 halaman: ~5 menit (sebelumnya crash/timeout)

**Peringatan Penting:** Embeddings hanya mengandalkan API GitHub Models *tanpa* *fallback* eksternal (seperti Gemini atau open-source lainnya). Ini secara sengaja diinjeksi untuk menghindari **Dimension Mismatch** (Perbedaan dimensi vektor) jika sewaktu-waktu embedding melompat ke penyedia lain yang memiliki struktur data yang berbeda. Jika GitHub down, proses unggah sementara ikut tertunda demi menyelamatkan integritas database vektor.

### 3. Smart Search & Fallback (LangSearch)
**File Utama:** `app/services/langsearch_service.py`
Sistem RAG menggunakan pendekatan campuran (*Hybrid/Search-First Strategy*):
1. **Anti-Greeting Filter:** Sebelum melempar pencarian ke *Web Search*, `rag_service` mem-filter pertanyaan. Jika teks hanyalah sapaan pendek (`hai`, `halo`, `siapa kamu`, `terima kasih`), sistem *Skip Web Search*, tidak membuang kuota, dan langsung diarahkan ke GPT-5 untuk mode interaksi *"Chitchat"* manusiawi.
2. **Web Search Augmentation (Tavily):** Apabila pertanyaan membutuhkan pencarian atau kompleks, sistem memanggil *LangSearch* untuk menarik 5 artikel terbaru dari internet (Live Data), kemudian memprioritaskan gabungan dari Live Data + Dokumen Internal untuk di-injeksikan kepada GPT-5. Pengetahuan tidak pernah kuno.

---

Dengan ketiga ekosistem di atas, ISTA AI memiliki sifat yang sangat Tangguh (anti-Down), Ekosistem RAG yang presisi (anti-Mismatch), dan berbudaya sapaan cepat tanpa membuang uang/berceramah (Web Search Filter).
