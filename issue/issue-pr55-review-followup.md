## Plan: Tindak Lanjut Review PR #55 - Embedding Dimension Fix

## Konteks Komentar Review (#4276456451)

**Blocker 1**: `python-ai/app/services/rag_service.py` di head PR masih memakai `current_embedding_dim` dari model aktif. Karena Chroma mengunci dimensi collection pada insert pertama, fallback 3072 ↔ 1536 masih bisa memutus ingest.

**Blocker 2**: Branch PR belum membawa regression test yang membuktikan transisi dimensi embedding / fallback tier.

## Analisis Masalah

1. Kode saat ini mendefinisikan `EMBEDDING_MODELS` dengan dimensi berbeda (3072 untuk large, 1536 untuk small)
2. Saat rate limit dan cascade dari large (3072) ke small (1536), vectorstore baru dibuat dengan embedding model 1536
3. Chroma collection sudah dibuat dengan dimensi 3072 - akan reject embeddings 1536

## Solusi yang Dipilih

**Fixed-dimension strategy**: Selalu gunakan MAX_EMBEDDING_DIM=3072 untuk semua embedding model, termasuk saat fallback ke small model. Ini memastikan Chroma collection tidak pernah menerima dimensi berbeda.

## Langkah Implementasi

1. Modifikasi `get_embeddings_with_fallback()` untuk selalu gunakan MAX_EMBEDDING_DIM
2. Modifikasi cascade handling di `process_document()` agar tetap gunakan MAX_EMBEDDING_DIM saat retry
3. Tambahkan regression test untuk transisi dimensi embedding
4. Jalankan pytest untuk verifikasi
5. Commit dan push

## Risiko

- Perubahan pada cascade logic harus diuji dengan rate limit simulation
- Test coverage tambahan diperlukan untuk membuktikan fixed-dimension strategy bekerja
