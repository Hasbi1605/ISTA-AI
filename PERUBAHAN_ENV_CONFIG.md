# Perubahan Konfigurasi .env

## ✅ Yang Sudah Benar

1. **API Keys** - Semua sudah terkonfigurasi:
   - ✅ GEMINI_API_KEY
   - ✅ GOOGLE_API_KEY
   - ✅ GITHUB_TOKEN (Primary)
   - ✅ GITHUB_TOKEN_2 (Backup)
   - ✅ GROQ_API_KEY
   - ✅ LANGSEARCH_API_KEY

2. **Chunking Settings** - Sudah optimal untuk dokumen besar:
   - ✅ TOKEN_CHUNK_SIZE=1000 (bagus untuk dokumen >500KB)
   - ✅ AGGRESSIVE_BATCH_SIZE=50 (aman untuk menghindari error 413)
   - ✅ BATCH_DELAY_SECONDS=1.0 (cukup untuk stabilitas)

## 🔧 Yang Diperbaiki/Ditambahkan

### 1. TOKEN_CHUNK_OVERLAP
**Sebelum**: Tidak ada
**Sekarang**: `TOKEN_CHUNK_OVERLAP=100`

**Alasan**: Overlap membantu menjaga konteks antar chunks. Proporsi yang baik adalah ~10% dari chunk size.

### 2. EMBEDDING_TIMEOUT
**Sebelum**: `EMBEDDING_TIMEOUT=10`
**Sekarang**: `EMBEDDING_TIMEOUT=30`

**Alasan**: Dengan batch size 50 chunks, proses embedding bisa memakan waktu lebih lama. Timeout 30 detik lebih aman.

### 3. LangSearch Configuration
**Ditambahkan**:
```env
LANGSEARCH_TIMEOUT=10
LANGSEARCH_CACHE_TTL=300
LANGSEARCH_RERANK_ENABLED=true
LANGSEARCH_RERANK_MODEL=langsearch-reranker-v1
LANGSEARCH_RERANK_TIMEOUT=8
LANGSEARCH_RERANK_MAX_DOCS=50
LANGSEARCH_RERANK_CACHE_TTL=300
LANGSEARCH_RERANK_DOC_CANDIDATES=20
LANGSEARCH_RERANK_DOC_TOP_N=5
LANGSEARCH_RERANK_WEB_CANDIDATES=10
LANGSEARCH_RERANK_WEB_TOP_N=5
LANGSEARCH_RERANK_MIN_SCORE=0.15
```

**Alasan**: Konfigurasi lengkap untuk web search dan reranking yang sudah ada di code.

### 4. DEFAULT_SYSTEM_PROMPT
**Ditambahkan**:
```env
DEFAULT_SYSTEM_PROMPT=Anda adalah ISTA AI, asisten virtual istana pintar. Jawablah dengan sopan dan membantu.
```

**Alasan**: Memberikan identitas yang jelas untuk AI.

## 📊 Perbandingan Konfigurasi

### Konfigurasi Lama (Error 413):
```env
TOKEN_CHUNK_SIZE=1500
AGGRESSIVE_BATCH_SIZE=200
BATCH_DELAY_SECONDS=0.5
EMBEDDING_TIMEOUT=10
```
**Hasil**: 39 chunks × ~1,420 tokens = ~55,399 tokens per batch → **ERROR 413**

### Konfigurasi Baru (Optimal):
```env
TOKEN_CHUNK_SIZE=1000
TOKEN_CHUNK_OVERLAP=100
AGGRESSIVE_BATCH_SIZE=50
BATCH_DELAY_SECONDS=1.0
EMBEDDING_TIMEOUT=30
```
**Hasil**: Max 50 chunks × ~1,000 tokens = ~50,000 tokens per batch → **✅ AMAN** (di bawah 60K limit)

## 🎯 Rekomendasi Berdasarkan Ukuran Dokumen

### Dokumen Kecil (<100 KB):
```env
TOKEN_CHUNK_SIZE=1500
TOKEN_CHUNK_OVERLAP=150
AGGRESSIVE_BATCH_SIZE=200
BATCH_DELAY_SECONDS=0.5
```

### Dokumen Sedang (100-500 KB):
```env
TOKEN_CHUNK_SIZE=1200
TOKEN_CHUNK_OVERLAP=120
AGGRESSIVE_BATCH_SIZE=100
BATCH_DELAY_SECONDS=0.8
```

### Dokumen Besar (>500 KB) - **CURRENT SETTING**:
```env
TOKEN_CHUNK_SIZE=1000
TOKEN_CHUNK_OVERLAP=100
AGGRESSIVE_BATCH_SIZE=50
BATCH_DELAY_SECONDS=1.0
```

### Dokumen Sangat Besar (>2 MB):
```env
TOKEN_CHUNK_SIZE=800
TOKEN_CHUNK_OVERLAP=80
AGGRESSIVE_BATCH_SIZE=30
BATCH_DELAY_SECONDS=1.5
```

## ⚠️ Catatan Penting

1. **Smart Batching Otomatis**: 
   - Sistem sekarang otomatis membagi batch jika melebihi 60K tokens
   - Tidak perlu khawatir tentang error 413 lagi

2. **Cascading Fallback**:
   - Jika GITHUB_TOKEN rate limit → otomatis pakai GITHUB_TOKEN_2
   - Jika semua GitHub token habis → cascade ke model lain
   - Total kapasitas: 2M TPM (4 models × 500K TPM)

3. **Restart Required**:
   - Setelah mengubah .env, **HARUS restart service**:
     ```bash
     cd python-ai
     # Ctrl+C untuk stop
     uvicorn app.main:app --reload --port 8001
     ```

4. **Re-upload Dokumen**:
   - Dokumen yang sebelumnya gagal (0% success) tidak bisa diperbaiki
   - **HARUS di-upload ulang** setelah restart service

## ✅ Checklist Setelah Update

- [x] File .env sudah diupdate
- [ ] Service sudah di-restart
- [ ] Dokumen lama sudah dihapus
- [ ] Dokumen sudah di-upload ulang
- [ ] Log menunjukkan "Success: X/X chunks (100.0%)"
- [ ] Test query berhasil menemukan konten dokumen

## 🧪 Testing

Jalankan script testing:
```bash
cd python-ai
./test_embedding_fix.sh
```

Atau manual check:
```bash
# Check if service running
curl http://localhost:8001/health

# Check ChromaDB
ls -la chroma_data/

# Monitor logs saat upload
tail -f logs/app.log  # atau lihat di terminal
```
