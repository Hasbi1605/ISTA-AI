# ISTA AI - Architecture Overview

Ini adalah repositori inti untuk subsistem kognitif **ISTA AI**, yang berfungsi ganda sebagai *mesin obrolan pintar (Chat)* dan *mesin pencari dokumen (RAG - Retrieval Augmented Generation)*.

Arsitektur saat ini telah berevolusi menjadi arsitektur tingkat *Enterprise* dengan skema **Dual-Node Load Balancing** dan **Search-Aware Filtering** untuk memaksimalkan ketersediaan, kecepatan, dan efisiensi kuota.

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
1. **Mesin Pembangun:** `text-embedding-3-large` (OpenAI via GitHub Models).
2. **Mesin Penyimpan:** **ChromaDB** (Database Vektor Lokal `chroma_data/`).
3. **Peringatan Penting:** Embeddings hanya mengandalkan API GitHub Models *tanpa* *fallback* eksternal (seperti Gemini atau open-source lainnya). Ini secara sengaja diinjeksi untuk menghindari **Dimension Mismatch** (Perbedaan dimensi vektor) jika sewaktu-waktu embedding melompat ke penyedia lain yang memiliki struktur data yang berbeda. Jika GitHub down, proses unggah sementara ikut tertunda demi menyelamatkan integritas database vektor.

### 3. Smart Search & Fallback (LangSearch)
**File Utama:** `app/services/langsearch_service.py`
Sistem RAG menggunakan pendekatan campuran (*Hybrid/Search-First Strategy*):
1. **Anti-Greeting Filter:** Sebelum melempar pencarian ke *Web Search*, `rag_service` mem-filter pertanyaan. Jika teks hanyalah sapaan pendek (`hai`, `halo`, `siapa kamu`, `terima kasih`), sistem *Skip Web Search*, tidak membuang kuota, dan langsung diarahkan ke GPT-5 untuk mode interaksi *"Chitchat"* manusiawi.
2. **Web Search Augmentation (Tavily):** Apabila pertanyaan membutuhkan pencarian atau kompleks, sistem memanggil *LangSearch* untuk menarik 5 artikel terbaru dari internet (Live Data), kemudian memprioritaskan gabungan dari Live Data + Dokumen Internal untuk di-injeksikan kepada GPT-5. Pengetahuan tidak pernah kuno.

---

Dengan ketiga ekosistem di atas, ISTA AI memiliki sifat yang sangat Tangguh (anti-Down), Ekosistem RAG yang presisi (anti-Mismatch), dan berbudaya sapaan cepat tanpa membuang uang/berceramah (Web Search Filter).
