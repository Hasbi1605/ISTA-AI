
# [Issue Planning] Refactor Konfigurasi dan Optimasi Chunking + Summarization

## Tujuan
Merapikan arsitektur konfigurasi agar seluruh pengaturan **chunking** dan **summarization** berada di satu tempat khusus, mudah diubah oleh user/operator, dan konsisten di seluruh pipeline.

Sekaligus menjalankan optimasi yang sudah disepakati:
- tetap memakai metode **token-aware recursive chunking**,
- menambahkan kebijakan **adaptive parameter** (berdasarkan ukuran dokumen dan model aktif),
- memperjelas fallback chain untuk embedding dan summarization.

---

## Keputusan Arsitektur (Final)
1. **Tidak ganti total metode chunking.**
   - Tetap pakai token-aware recursive chunking sebagai metode utama.
   - Opsi tambahan: header-aware pre-split untuk dokumen terstruktur (laporan/SOP/kebijakan).

2. **Pisahkan konfigurasi chunking + summarization ke file khusus.**
   - File target: `python-ai/config/chunking_summarization.yaml`.
   - `ai_config.yaml` tetap dipakai untuk konfigurasi AI umum (prompt global, retrieval global, dsb).

3. **Tetapkan chain model sebagai kebijakan resmi.**
   - Embedding: `text-embedding-3-large` -> `text-embedding-3-large (backup)` -> `text-embedding-3-small` -> `text-embedding-3-small (backup)`.
   - Summarization: `gpt-4.1` -> `gpt-4.1 (backup)` -> `gpt-4o` -> `gpt-4o (backup)`.
   - Semantic rerank: `langsearch-reranker-v1` primary + backup.

---

## Latar Belakang Masalah
Konfigurasi saat ini masih tersebar di beberapa lokasi (env, hardcode, YAML), sehingga:
- sulit tahu nilai aktif saat runtime,
- tuning lambat dan rawan inkonsistensi,
- onboarding implementor baru lebih berat,
- fallback behavior tidak selalu mudah diaudit.

Di sisi kualitas, optimasi parameter saat ini lebih penting dibanding mengganti metode chunking secara drastis.

---

## Ruang Lingkup
### In-Scope
- Konsolidasi seluruh konfigurasi chunking + summarization ke file khusus.
- Penetapan kebijakan prioritas konfigurasi (source of truth dan override).
- Penetapan profil parameter chunking/summarization yang model-aware.
- Penegasan fallback policy embedding dan summarization.
- Dokumentasi operasional agar mudah di-maintain user/operator.

### Out-of-Scope
- Perombakan total arsitektur RAG/retrieval.
- Penggantian provider/model di luar chain yang sudah disepakati.
- Implementasi dashboard observability skala besar.
- Optimasi low-level per fungsi/class pada fase awal.

---

## Prinsip Desain Konfigurasi
1. **Single Source of Truth untuk domain ini**
   - Semua pengaturan chunking/summarization dibaca dari file khusus.

2. **Precedence yang jelas**
   - Urutan yang disarankan: `ENV override terbatas` -> `chunking_summarization.yaml` -> `default internal`.
   - Hanya field tertentu yang boleh dioverride dari env.

3. **Pisahkan non-secret vs secret**
   - Non-secret (chunk size, overlap, threshold, model order) di YAML.
   - Secret/API key tetap di env/secret manager.

4. **Mudah dipahami implementor junior**
   - Struktur field konsisten, nama deskriptif, catatan penggunaan singkat.

---

## Baseline Optimasi yang Harus Diadopsi
> Catatan: ini baseline awal, bisa dituning setelah pengukuran produksi.

1. **Chunking strategy**
   - Default tetap recursive token-aware.
   - Gunakan profil adaptif berdasarkan ukuran dokumen (pendek/menengah/panjang).

2. **Embedding profile (high-level target)**
   - Profil `large` memakai chunk lebih besar.
   - Profil `small` memakai chunk lebih kecil + overlap relatif lebih tinggi.
   - Batch token budget diturunkan ke zona aman (jangan mepet limit provider).

3. **Summarization strategy**
   - Threshold single vs hierarchical harus model-aware:
     - `gpt-4.1` lebih longgar (single untuk dokumen menengah),
     - `gpt-4o` lebih konservatif.
   - Batasi output partial/final agar biaya dan latency lebih stabil.

---

## Rencana Implementasi Bertahap (High-Level)
### Fase 1 - Fondasi Konfigurasi
- Buat file `chunking_summarization.yaml`.
- Definisikan struktur utama: chunking profiles, embedding batching, summarize thresholds, fallback policy.
- Tetapkan default yang aman untuk produksi.

### Fase 2 - Wiring ke Runtime
- Arahkan pembacaan konfigurasi di service terkait ke file baru.
- Kurangi hardcode dan pembacaan env langsung di jalur utama.
- Jaga backward compatibility selama masa transisi.

### Fase 3 - Aktivasi Optimasi Model-Aware
- Aktifkan profil chunking adaptif.
- Terapkan threshold summarize single/hierarchical per model tier.
- Terapkan guardrail fallback supaya perilaku konsisten.

### Fase 4 - Validasi dan Rollout
- Uji dokumen pendek, menengah, panjang.
- Validasi metrik utama: kualitas retrieval, kualitas ringkasan, latency, dan kestabilan fallback.
- Rollout bertahap, lalu finalisasi nilai default.

### Fase 5 - Cleanup
- Deprecated/hapus konfigurasi lama yang tidak dipakai.
- Finalisasi dokumentasi operasional user/operator.

---

## Fallback Policy (Level Tinggi)
### Embedding
Urutan wajib:
1. `text-embedding-3-large`
2. `text-embedding-3-large (backup)`
3. `text-embedding-3-small`
4. `text-embedding-3-small (backup)`

Aturan umum:
- Utamakan perpindahan ke backup dalam tier yang sama sebelum turun tier.
- Jika turun dari large ke small, jaga kompatibilitas index/vector policy agar tidak merusak kualitas retrieval.
- Setiap fallback harus tercatat jelas di log.

### Summarization
Urutan wajib:
1. `gpt-4.1`
2. `gpt-4.1 (backup)`
3. `gpt-4o`
4. `gpt-4o (backup)`

Aturan umum:
- Jika konteks terlalu besar, ubah mode ke hierarchical dulu pada tier yang sama.
- Turun tier model dipakai saat masalah availability/limit/timeout persisten.

---

## Risiko Utama dan Mitigasi
- **Risiko:** konfigurasi baru tidak terbaca konsisten di semua alur.
  - **Mitigasi:** validasi startup + smoke test per endpoint utama.

- **Risiko:** tuning awal menurunkan kualitas jawaban.
  - **Mitigasi:** rollout bertahap, bandingkan hasil dengan baseline lama.

- **Risiko:** fallback terlalu sering meningkatkan biaya/latency.
  - **Mitigasi:** log fallback reason dan review berkala untuk retuning.

- **Risiko:** konflik dengan konfigurasi legacy.
  - **Mitigasi:** tetapkan precedence tegas dan cleanup setelah transisi.

---

## Definition of Done
- File konfigurasi khusus chunking/summarization tersedia dan dipakai jalur utama.
- Chain embedding, summarization, dan rerank sesuai kebijakan issue ini.
- Hardcode kritikal untuk parameter domain ini sudah dieliminasi dari flow utama.
- Dokumentasi perubahan konfigurasi tersedia dan mudah dipahami operator non-senior.
- Pengujian dasar lulus pada skenario normal + fallback.

---

## Catatan Handoff untuk Implementor Junior / Model AI Ringan
- Prioritaskan konsolidasi konfigurasi dulu, jangan lompat ke optimasi kompleks.
- Ikuti urutan fase agar risiko regresi rendah.
- Jika ada konflik antar nilai, ikuti precedence yang sudah ditetapkan issue ini.
- Jangan menyimpan secret di file konfigurasi baru.
- Buat PR kecil per fase agar review cepat dan aman.

Dokumen ini adalah panduan implementasi level issue. Detail teknis file-per-file diputuskan saat eksekusi dengan tetap mengikuti keputusan arsitektur di atas.
