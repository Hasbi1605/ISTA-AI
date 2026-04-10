# Cara Perbaikan Cepat - Error Upload Dokumen

## Masalah
- Dokumen berhasil di-upload tapi AI tidak bisa menemukan isinya
- Error: "Request body too large for text-embedding-3-large model"
- Log menunjukkan: "Success: 0/39 chunks (0.0%)"

## Penyebab
Sistem mencoba mengirim terlalu banyak data sekaligus ke API embedding (melebihi limit 64,000 tokens per request).

## Solusi (3 Langkah)

### 1. Update Code
Code sudah diperbaiki dengan "Smart Batching" yang otomatis membagi data menjadi batch yang lebih kecil.

### 2. Restart Service
```bash
cd python-ai

# Stop service (tekan Ctrl+C jika sedang berjalan)

# Start ulang
uvicorn app.main:app --reload --port 8001
```

### 3. Re-upload Dokumen
1. Hapus dokumen yang gagal dari UI
2. Upload ulang dokumen yang sama
3. Tunggu sampai selesai
4. Cek log - harus muncul: **"Success: 39/39 chunks (100.0%)"**

## Testing
Setelah re-upload, test dengan query:
- "apa isi dokumen tersebut"
- "jelaskan isi dari pasal 18"

AI seharusnya bisa menjawab dengan konten dari dokumen.

## Jika Masih Error

Edit file `python-ai/.env`, ubah nilai berikut:

```env
# Untuk dokumen besar (>500KB), gunakan:
TOKEN_CHUNK_SIZE=1000
AGGRESSIVE_BATCH_SIZE=50
BATCH_DELAY_SECONDS=1.0
```

Lalu restart service dan re-upload dokumen.

## Monitoring

Saat upload dokumen, perhatikan log:
```
✅ Created 3 smart batches (token-aware)
✅ Processing batch 1/3: 15 chunks, 21,300 tokens...
✅ Batch 1/3 success
...
✅ Success: 39/39 chunks (100.0%)  ← HARUS 100%!
```

Jika ada yang failed, turunkan nilai `TOKEN_CHUNK_SIZE` dan `AGGRESSIVE_BATCH_SIZE`.

## Script Testing

Jalankan script untuk cek konfigurasi:
```bash
cd python-ai
./test_embedding_fix.sh
```

---

**Catatan**: Dokumen yang sebelumnya gagal (0% success) tidak akan bisa digunakan. Harus di-upload ulang setelah perbaikan ini.
