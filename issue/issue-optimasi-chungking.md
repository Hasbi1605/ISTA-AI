## Plan: Stabilitas Ingest Dokumen Panjang (Optimasi Token-Aware & Batching Async)

Tujuan utama plan ini adalah mengatasi masalah _crash_ dan lambatnya pemrosesan saat mengupload dokumen yang sangat besar, dengan mengganti pendekatan chunking ke metode yang lebih deterministik, cepat, dan handal (Token-Aware Recursive Chunking + Batching API), serta mendelegasikan beban ke _background task_.

## Latar Belakang

Masalah utama saat ini adalah stabilitas pipeline ingest untuk dokumen dengan konten sangat panjang (puluhan sampai ratusan ribu kata) yang selalu lambat dan _crash_. Upaya menggunakan _Semantic Chunking_ pada tahap ingest justru berisiko memperparah kelambatan komputasi dan memori (OOM/Timeout). Karena kita sudah mengadopsi **LangSearch API** (Rerank) untuk _semantic matching_ di fase _query-time_, _ingest-time_ cukup difokuskan pada kecepatan pemotongan dan stabilitas pengiriman ke OpenAI Embeddings.

## Prinsip Strategi

1. **Gugurkan Semantic Chunking di fase Ingest**: Menghindari _overhead_ komputasi NLP/Embedding yang berulang saat proses _ingest_.
2. **Prioritaskan Kecepatan dan Memori**: Menggunakan Token-Aware chunking yang cepat dan efisien.
3. **Optimasi Pengiriman API**: Menggunakan _Batching_ untuk endpoint OpenAI agar waktu proses dipangkas drastis.
4. **Resiliensi via Asynchronous & Fallback**: Memisahkan proses dari siklus HTTP request dan menerapkan _circuit breaker_ dengan model backup.

## Target Outcome

- Upload dan pemrosesan dokumen sangat panjang bebas _crash_ dan selesai jauh lebih cepat.
- Tidak ada lagi _timeout_ (_OOM / HTTP Timeout_).
- Beban proses backend tetap stabil walau dokumen memuat ratusan ribu kata.
- Kualitas _retrieval_ tetap tinggi karena mengandalkan _LangSearch Reranker_ di sisi _query-time_.

## High-Level Steps

1. **Fase 1: Transisi ke Token-Aware Recursive Chunking**
   - Ganti _approach_ eksisting/Semantic Chunking ke `RecursiveCharacterTextSplitter` dari LangChain.
   - Gunakan tokenizer `tiktoken` (`cl100k_base`) yang sesuai dengan OpenAI Gen 3.
   - Set maksimal token per chunk (mis. 1000 hingga 2000 token dengan overlap 100-200), menyesuaikan kemampuan `text-embedding-3-large` (mendukung hingga 8191 token).

2. **Fase 2: Asynchronous Ingestion & Batching Agresif**
   - Pindahkan logika pemotongan (chunking) dan _embedding_ ke _Background Job / Message Queue_ (mis. Celery / FastAPI `BackgroundTasks` / Laravel Horizon).
   - Terapkan **Aggressive Batching API Calls**: Kumpulkan list chunk dan kirim sekaligus dalam _array_ besar. Dibekali 2 model utama (`large`) dan 2 model fallback (`small`), total kapasitas efektif mencapai **2.000.000 TPM** (4 x 500.000 TPM). Batch request dapat digenjot tajam hingga ~250.000 - 500.000 token per _request_ (misal: 100-250 chunk berukuran 2000 token sekaligus) untuk utilitas _latency_ terbaik tanpa takut _crash_.

3. **Fase 3: Circuit Breaker & Cascading Fallback Berjenjang**
   - Tangkap error (terutama HTTP 429 `RateLimitError`) saat _batching_ menguras limit model primer `text-embedding-3-large` (akun 1).
   - **Cascading Failover**: Jika limit primer 500.000 TPM habis, sisa antrian _batch_ dalam detik itu langsung dialihkan ke `text-embedding-3-large` Backup (akun 2), menyediakan ruang ekstra 500.000 TPM dengan kualitas _embedding_ yang sama persis.
   - Jika kedua model _large_ habis, oper kembali secara berjenjang ke Fallback 1: `text-embedding-3-small` (akun 1), dan terakhir ke Fallback 2: `text-embedding-3-small` (akun 2). Kumpulan kekuatan 4 infrastruktur (2 Juta TPM) ini memungkinkan dokumen maha-tebal (ribuan halaman) dikunyah instan.

4. **Fase 4: Evaluasi & Testing**
   - Lakukan tes unggah dokumen super besar (50k - 250k kata / ~150 halaman).
   - Validasi bahwa memori RAM tidak melonjak (spike) tajam secara tidak terkontrol.
   - Uji RAG _search_ untuk memastikan LangSearch berhasil merangking _chunk_ berbasis token dengan hasil yang relevan.

## Risks & Mitigation

- Risiko: Limit individu setiap model tercapai dengan ekstrim karena _Aggressive Batching_.
  Mitigasi: Karena total kapasitas menembus **2 Juta TPM** lewat 2 model _backup_ identik dan 2 model _fallback_, _cascading fallback_ menangani sisa _batch_ secara nirkedip. Bila keempatnya mencapai limit bersamaan (biasanya terjadi jika total upload menembus belasan ribu halaman / > 2.000.000 token per menit), _background task_ otomatis menerapkan mekanisme **Meredam (Truncated Exponential Backoff)** untuk menunggu pulihan TPM tanpa membatalkan antrian _job_.
- Risiko: Chunk terpotong di tengah kalimat/konteks krusial.
  Mitigasi: _Overlap_ yang memadai antara token (100-200 token), ditambah peranan kuat _LangSearch API_ saat _retrieval_.
- Risiko: Struktur dokumen hilang.
  Mitigasi: (Opsional) Menggunakan `MarkdownHeaderTextSplitter` lebih dulu jika dokumen memiliki struktur hirarki yang jelas sebelum diukur berdasarkan token.

## Operasional: Kapan Pakai Apa

- **Ingest-Time**: Gunakan _Token-Aware Recursive Chunking_ murni + _Batch Embedding_ (Fokus kecepatan).
- **Query-Time**: Gunakan pencarian vektor biasa + _LangSearch Reranker_ (Fokus presisi semantik).

## Kesimpulan

Penerapan _Semantic Chunking_ secara resmi **tidak dilanjutkan (di-drop)** dari lingkup optimasi ini. Fokus sepenuhnya pada arsitektur _batching asynchronous_ dan pemotongan berbasis token.
