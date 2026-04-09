## Plan: Optimasi Chunking Dokumen dengan Semantic Chunking (Option B)

Tujuan utama plan ini adalah meningkatkan kualitas retrieval dan menurunkan beban proses dokumen panjang dengan mengganti pola chunking fixed-size ke semantic chunking.

## Target Outcome

- Dokumen panjang diproses lebih stabil dan lebih cepat dibanding pendekatan chunking saat ini.
- Jumlah chunk berkurang secara signifikan tanpa menurunkan relevansi hasil pencarian.
- Konteks antar paragraf/ide lebih terjaga sehingga jawaban RAG lebih akurat.

## Scope (Fase Implementasi)

1. Ubah strategi pemecahan dokumen dari fixed-size menjadi semantic chunking pada alur ingest dokumen.
2. Pertahankan arsitektur RAG yang sudah ada (vector store, metadata, pipeline proses, endpoint API).
3. Tambahkan evaluasi kualitas hasil retrieval sebelum dan sesudah perubahan.
4. Siapkan fallback ke strategi lama jika semantic chunking tidak tersedia atau gagal.

## High-Level Steps

1. Tetapkan semantic chunking sebagai strategi utama di pipeline pemrosesan dokumen.
2. Definisikan konfigurasi chunking yang adaptif (bukan angka statis tunggal) agar cocok untuk berbagai tipe dokumen.
3. Jalankan migrasi bertahap: mulai dari dokumen baru dulu, lalu evaluasi sebelum diterapkan penuh.
4. Validasi dampak di tiga aspek: kualitas jawaban, waktu proses, dan stabilitas job queue.
5. Aktifkan monitoring sederhana untuk jumlah chunk, durasi proses, dan error rate per dokumen.
6. Dokumentasikan aturan operasional: kapan pakai semantic chunking, kapan fallback, dan kapan rollback.

## Acceptance Criteria

- Pipeline dapat memproses dokumen panjang tanpa peningkatan error rate.
- Rata-rata jumlah chunk per dokumen turun dibanding baseline lama.
- Kualitas retrieval minimal sama atau lebih baik pada uji query internal.
- Tidak ada perubahan negatif pada alur pengguna (upload, status processing, selectable document).

## Risks & Mitigation

- Risiko: kualitas chunk tidak konsisten pada format dokumen tertentu.
  Mitigasi: gunakan fallback ke strategi sebelumnya untuk kasus edge.

- Risiko: waktu proses awal naik karena perhitungan semantik.
  Mitigasi: terapkan bertahap, monitor durasi, lalu tuning konfigurasi.

- Risiko: regresi pada retrieval untuk dokumen lama.
  Mitigasi: bandingkan hasil retrieval lama vs baru sebelum rollout penuh.

## Non-Goals

- Tidak mengganti vector database atau arsitektur utama RAG.
- Tidak melakukan redesign UI upload/chat.
- Tidak langsung menerapkan teknik lanjutan lain (late chunking, hierarchical chunking) di fase ini.

## Catatan Eksekusi untuk Implementer

Plan ini sengaja high-level agar mudah dieksekusi oleh junior programmer atau AI agent biaya rendah. Fokuskan implementasi pada perubahan strategi chunking, evaluasi dampak, dan rollout bertahap yang aman.
