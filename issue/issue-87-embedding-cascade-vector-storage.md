# Issue Plan: Embedding Cascade GitHub Models dan Storage Vector Laravel

## Deskripsi
Issue ini bertujuan untuk memindahkan tanggung jawab embedding dan pencarian vektor dari Python ke Laravel sepenuhnya (Laravel-only). Ini melibatkan implementasi cascade embedding menggunakan GitHub Models sebagai fallback jika satu token/model gagal, serta mekanisme penyimpanan vektor yang kompatibel dengan Laravel untuk menggantikan fungsionalitas Chroma.

Parent Issue: #84

## Tujuan
1.  **Embedding Cascade**: Implementasi 4 level fallback untuk embedding menggunakan GitHub Models:
    -   `text-embedding-3-large` + `GITHUB_TOKEN`
    -   `text-embedding-3-large` + `GITHUB_TOKEN_2`
    -   `text-embedding-3-small` + `GITHUB_TOKEN`
    -   `text-embedding-3-small` + `GITHUB_TOKEN_2`
2.  **Storage Vector**: Menyimpan embedding dalam database Laravel agar tidak perlu dihitung ulang setiap kali pencarian dilakukan.
3.  **Dimensi Eksplisit**: Menangani perbedaan dimensi (3072 untuk large, 1536 untuk small) secara aman.
4.  **Usage Logging**: Mencatat penggunaan token dan model untuk observabilitas.

## Ruang Lingkup
1.  **Konfigurasi**: Menambahkan konfigurasi cascade embedding di `config/ai.php`.
2.  **Service Baru**: Membuat `App\Services\AI\EmbeddingCascadeService` untuk menangani logika fallback.
3.  **Database**: 
    - Membuat model `App\Models\DocumentChunk`.
    - Menambah kolom `embedding`, `embedding_model`, and `embedding_dimensions` pada tabel `document_chunks`.
4.  **Refactor Retrieval**: Mengubah `LaravelDocumentRetrievalService` agar menggunakan cache embedding di database dan memanggil service cascade jika belum ada.

## Risiko
- **Kualitas Pencarian**: Perubahan dimensi embedding (large ke small) dalam satu dokumen dapat merusak hasil pencarian jika tidak ditangani dengan benar (misal: menghapus embedding lama jika model berubah).
- **Performa Database**: Mencari kemiripan kosinus (cosine similarity) di PHP/MySQL pada ribuan chunk mungkin lambat jika tidak dioptimalkan.
- **Rate Limit**: Cascade membantu, tetapi tetap ada risiko rate limit jika traffic tinggi.

## Langkah Implementasi

### 1. Fondasi dan Model
- [ ] Buat migration untuk tabel `document_chunks` (jika belum lengkap) atau update untuk menambah kolom embedding.
- [ ] Buat model `DocumentChunk` dan relasi di model `Document`.
- [ ] Tambahkan konfigurasi `ai.embedding_cascade` di `config/ai.php`.

### 2. Embedding Cascade Service
- [ ] Implementasikan `EmbeddingCascadeService` dengan metode `embed(array $inputs)`.
- [ ] Pastikan error handling yang tepat untuk memicu fallback ke node berikutnya dalam cascade.
- [ ] Tambahkan logging untuk setiap percobaan dalam cascade.

### 3. Vector Storage & Retrieval Refactor
- [ ] Update `LaravelDocumentRetrievalService` untuk melakukan "lazy ingestion":
    - Cek apakah chunk sudah ada di DB dan punya embedding dengan model/dimensi yang sesuai.
    - Jika belum, lakukan chunking (jika belum) dan hitung embedding via `EmbeddingCascadeService`.
    - Simpan ke DB.
- [ ] Implementasikan pencarian vektor di database menggunakan `cosineSimilarity` (tetap di PHP untuk awal, sesuai arsitektur Laravel-only tanpa plugin DB khusus).

### 4. Verifikasi
- [ ] Test unit untuk `EmbeddingCascadeService`.
- [ ] Test integrasi untuk `LaravelDocumentRetrievalService` dengan database.
- [ ] Pastikan tidak ada regresi pada flow chat yang sudah ada.

## Rencana Test
1.  **Test Fallback**: Mock error pada `GITHUB_TOKEN` dan pastikan sistem beralih ke `GITHUB_TOKEN_2`.
2.  **Test Dimensi**: Pastikan jika model berubah dari large ke small, sistem tidak mencoba membandingkan vektor dengan panjang berbeda.
3.  **Test Persistence**: Upload dokumen, tanya chat, hapus cache (jika ada), tanya lagi, pastikan embedding diambil dari DB (bukan hit ulang API jika data masih valid).

## Kriteria Selesai
- [ ] Embedding cascade berjalan sesuai spesifikasi (4 level).
- [ ] Embedding disimpan di database dan digunakan kembali (tidak re-compute on-the-fly setiap request).
- [ ] Pencarian tetap akurat dan menangani dimensi 3072/1536.
- [ ] Lolos test `php artisan test`.
