# Perbaikan Error 413 - Token Limit Exceeded

## Masalah yang Terjadi

### Error Log:
```
ERROR: Error code: 413 - {'error': {'code': 'tokens_limit_reached', 
'message': 'Request body too large for text-embedding-3-large model. Max size: 64000 tokens.'}}
```

### Analisis:
1. **Dokumen berhasil di-upload** tapi gagal di-embed
2. **Total dokumen**: ~50,622 tokens dibagi menjadi 39 chunks
3. **Batch size**: 200 chunks (terlalu besar)
4. **Total tokens dalam 1 batch**: ~55,399 tokens
5. **Limit API OpenAI**: **64,000 tokens per request** (bukan per menit!)
6. **Hasil**: 0/39 chunks berhasil → AI tidak bisa menemukan isi dokumen

## Penyebab Root Cause

Sistem menggunakan `AGGRESSIVE_BATCH_SIZE=200` yang artinya mencoba mengirim **200 chunks sekaligus** dalam 1 request. Untuk dokumen besar, ini menyebabkan total tokens dalam 1 batch melebihi limit API (64K tokens).

**Kesalahan konsep**: 
- TPM (Tokens Per Minute) = 500K ✅ (rate limit per menit)
- Tokens per request = 64K ❌ (limit per single API call) - **INI YANG DILANGGAR**

## Solusi yang Diterapkan

### 1. Smart Batching dengan Token Validation
Sebelumnya:
```python
# Batch berdasarkan jumlah chunks saja
for batch_num in range(0, len(chunks), AGGRESSIVE_BATCH_SIZE):
    batch = chunks[batch_num:batch_num + AGGRESSIVE_BATCH_SIZE]
```

Sekarang:
```python
# Smart batching: validasi token count per batch
MAX_TOKENS_PER_BATCH = 60000  # Safe limit (below 64K)

for chunk in chunks:
    chunk_tokens = count_tokens(chunk.page_content)
    
    # Check if adding this chunk would exceed limits
    would_exceed_tokens = (current_batch_tokens + chunk_tokens) > MAX_TOKENS_PER_BATCH
    would_exceed_count = len(current_batch) >= AGGRESSIVE_BATCH_SIZE
    
    if (would_exceed_tokens or would_exceed_count) and current_batch:
        # Save current batch and start new one
        smart_batches.append((current_batch, current_batch_tokens))
        current_batch = [chunk]
        current_batch_tokens = chunk_tokens
```

### 2. Enhanced Error Handling
Menambahkan deteksi khusus untuk error 413 (token limit):
```python
is_token_limit = any(indicator in error_msg.lower() for indicator in 
    ["413", "tokens_limit_reached", "too large", "body too large"])

if is_token_limit:
    logger.error(f"❌ Batch exceeds token limit ({batch_tokens:,} tokens)")
    logger.error(f"💡 Suggestion: Reduce TOKEN_CHUNK_SIZE or AGGRESSIVE_BATCH_SIZE")
```

### 3. Improved Logging
Sekarang menampilkan informasi lebih detail:
```
Step 4: Smart Batching & Embedding Generation...
Max batch size: 200 chunks OR 60,000 tokens (whichever is smaller)
Created 3 smart batches (token-aware)
Processing batch 1/3: 20 chunks, 28,450 tokens...
✅ Batch 1/3 success | Progress: 20/39 chunks
```

## Cara Menggunakan

### 1. Restart Python AI Service
```bash
cd python-ai
# Stop service jika sedang berjalan (Ctrl+C)
# Restart dengan:
uvicorn app.main:app --reload --port 8001
```

### 2. Re-upload Dokumen yang Gagal
Dokumen yang sebelumnya gagal (0/39 chunks) perlu di-upload ulang:
1. Hapus dokumen dari database Laravel (soft delete)
2. Upload ulang dokumen melalui UI
3. Monitor log untuk memastikan semua chunks berhasil

### 3. Monitoring
Perhatikan log berikut:
```
✅ Document '1uu000.pdf' processing completed
Success: 39/39 chunks (100.0%)  ← HARUS 100%!
Failed: 0 chunks
```

## Konfigurasi Optimal (Optional)

Jika masih ada masalah, sesuaikan di `.env`:

```env
# Ukuran chunk (dalam tokens)
TOKEN_CHUNK_SIZE=1200          # Turunkan dari 1500 jika perlu
TOKEN_CHUNK_OVERLAP=120        # Turunkan dari 150 jika perlu

# Batch size (jumlah chunks per batch)
AGGRESSIVE_BATCH_SIZE=50       # Turunkan dari 200 untuk dokumen besar

# Delay antar batch (detik)
BATCH_DELAY_SECONDS=1.0        # Naikkan dari 0.5 jika rate limit
```

### Rekomendasi berdasarkan ukuran dokumen:

| Ukuran Dokumen | TOKEN_CHUNK_SIZE | AGGRESSIVE_BATCH_SIZE |
|----------------|------------------|-----------------------|
| < 100 KB       | 1500             | 200                   |
| 100-500 KB     | 1200             | 100                   |
| 500 KB - 2 MB  | 1000             | 50                    |
| > 2 MB         | 800              | 30                    |

## Testing

### Test dengan dokumen yang sama:
1. Upload dokumen `1uu000.pdf` (233 KB)
2. Cek log - seharusnya muncul:
   ```
   Created 2-3 smart batches (token-aware)
   Processing batch 1/3: 15 chunks, 21,300 tokens...
   ✅ Batch 1/3 success
   ...
   Success: 39/39 chunks (100.0%)
   ```

### Test query:
```
User: "apa isi dokumen tersebut"
AI: [Seharusnya bisa menjawab dengan konten dari dokumen]

User: "jelaskan isi dari pasal 18"
AI: [Seharusnya bisa menemukan dan menjelaskan pasal 18]
```

## Troubleshooting

### Jika masih error 413:
1. Turunkan `TOKEN_CHUNK_SIZE` ke 1000
2. Turunkan `AGGRESSIVE_BATCH_SIZE` ke 30
3. Restart service dan re-upload

### Jika AI masih tidak menemukan dokumen:
1. Cek log: pastikan "Success: X/X chunks (100.0%)"
2. Cek ChromaDB: `ls -la python-ai/chroma_data/`
3. Cek metadata: pastikan `user_id` dan `filename` tersimpan

### Jika rate limit (429):
1. Naikkan `BATCH_DELAY_SECONDS` ke 2.0
2. Sistem akan otomatis cascade ke model backup

## Perubahan Teknis

### File yang diubah:
- `python-ai/app/services/rag_service.py`

### Fungsi yang dimodifikasi:
- `process_document()` - Smart batching logic
- Error handling untuk token limit (413)
- Retry logic untuk token limit vs rate limit

### Backward Compatibility:
✅ Tidak ada breaking changes
✅ Konfigurasi lama tetap berfungsi
✅ Hanya menambahkan validasi token per batch
