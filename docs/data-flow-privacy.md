# ISTA AI Data Flow and Privacy Notes

Dokumen ini menjelaskan alur data yang terlihat dari codebase saat ini. Ini bukan keputusan klasifikasi final untuk dokumen Istana; kebijakan retensi, klasifikasi dokumen rahasia, dan penggunaan provider eksternal tetap perlu persetujuan mentor atau pemilik data.

## Ringkasan Alur

ISTA AI berjalan sebagai stack hybrid:

- Laravel melayani UI, autentikasi, upload, memo canvas, dan akses file.
- Python AI menerima request internal untuk chat, RAG, embedding, export, summarization, dan memo generation.
- MySQL menyimpan user, conversation, message, document metadata, dan memo metadata.
- Redis menjalankan queue/cache.
- Chroma menyimpan indeks vektor dokumen di volume `chroma_data`.
- OnlyOffice Document Server berjalan self-hosted sebagai editor DOCX.

## Data yang Disimpan Lokal

### Chat

- Pesan user dan assistant disimpan di tabel `conversations` dan `messages`.
- Riwayat chat dikirim ke Python AI saat user mengirim pesan baru.
- Bila dokumen aktif dipilih, Laravel mengirim daftar filename dokumen dan `user_id` ke Python AI.

### Dokumen Upload

- File upload disimpan di Laravel storage private melalui disk `local`.
- Metadata file disimpan di tabel `documents`.
- File dikirim ke Python documents service untuk ekstraksi teks dan embedding.
- Chunk teks dan metadata `filename` + `user_id` disimpan di Chroma.
- Delete dokumen sekarang mengirim `filename` dan `user_id` agar cleanup vector tidak lintas user.

### Memo

- Instruksi memo dikirim dari Laravel ke Python documents service.
- Python membuat DOCX awal dan mengembalikannya ke Laravel.
- File DOCX memo disimpan di Laravel storage private.
- OnlyOffice menerima signed URL sementara untuk membaca file dan callback JWT untuk menyimpan perubahan.

## Data yang Dikirim ke Provider Eksternal

Provider eksternal dikendalikan oleh `python-ai/config/ai_config.yaml` dan env secret di `.env.droplet`.

### GitHub Models

Dipakai untuk model chat dan embedding sesuai konfigurasi `lanes.chat.models` dan `lanes.embedding.models`.

Data yang dapat terkirim:

- prompt chat dan history percakapan aktif
- prompt RAG yang berisi chunk dokumen relevan
- input embedding berupa teks chunk dokumen
- prompt summarization atau memo generation bila model aktif memakai GitHub Models

Endpoint aktif:

- `https://models.github.ai/inference`

### Groq

Dipakai sebagai fallback chat sesuai urutan model di `ai_config.yaml`.

Data yang dapat terkirim:

- prompt chat dan history percakapan aktif
- prompt RAG atau summarization bila fallback Groq terpakai

### Gemini

Dipakai sebagai fallback terakhir sesuai urutan model di `ai_config.yaml`.

Data yang dapat terkirim:

- prompt chat dan history percakapan aktif
- prompt RAG atau summarization bila fallback Gemini terpakai

### LangSearch

Dipakai untuk web search dan rerank.

Data yang dapat terkirim:

- query user untuk web search
- kandidat dokumen/chunk untuk rerank bila semantic rerank aktif

### Licensed Web Asset Enrichment (presentasi, #227, opsional)

Generator presentasi default berjalan `local_assets_only` (no-internet): semua
aset visual digambar lokal. Mode opsional `licensed_web_assets` dapat mengambil
aset berlisensi dari provider whitelist (Openverse, Wikimedia Commons) **hanya**
bila dipilih eksplisit dan fetcher jaringan disuntikkan ke
`LicensedWebAssetService` (`python-ai/app/services/presentation_web_assets.py`).

Saat aktif, data yang dapat terkirim ke provider terbatas pada **request HTTP
untuk URL aset** (mis. gambar berlisensi CC). Tidak ada isi dokumen, prompt,
judul presentasi, atau identitas user yang dikirim ke provider aset.

Jaminan:

- Hanya provider whitelist dan lisensi reuse (`CC0`/`Public Domain`/`CC-BY`/
  `CC-BY-SA`) yang lolos; metadata wajib (source/license/attribution) divalidasi.
- Aset di-cache ke storage privat lokal (`storage/presentation_assets/`,
  di-`.gitignore`) sebelum dipakai, dengan metadata atribusi yang dapat diaudit.
- Kegagalan provider/jaringan/lisensi fallback ke aset lokal (generate tidak
  pernah putus / tidak pernah bergantung pada internet).
- Jejak audit hanya memuat metadata aset publik berlisensi (provider, URL,
  lisensi, atribusi, status, cache path, accessed_at) — bukan konten/secret.

## Data yang Tidak Dikirim Langsung oleh OnlyOffice

OnlyOffice Document Server pada konfigurasi ini berjalan self-hosted di container internal. User aplikasi tidak login ke akun OnlyOffice terpisah. Laravel mengirim identitas display editor (`user.id`, `user.name`) dalam config editor untuk kebutuhan editor/collaboration.

OnlyOffice menerima:

- signed URL file DOCX dari Laravel
- callback URL Laravel
- JWT shared secret untuk validasi request

## Proteksi yang Sudah Ada

- Route utama dokumen dan memo dilindungi `auth` dan `verified`.
- Policy memastikan user hanya melihat dokumen/memo miliknya.
- File upload disimpan di disk private, bukan public disk.
- Preview HTML dokumen dikirim dengan CSP `sandbox`.
- OnlyOffice editor config dan callback memakai JWT.
- Download file memo untuk editor memakai signed URL sementara.
- Callback OnlyOffice hanya menerima download URL dari host internal OnlyOffice yang trusted.

## Risiko yang Perlu Disadari

- Isi dokumen dapat masuk ke provider AI eksternal saat embedding, RAG, summarization, atau memo generation.
- Riwayat chat ikut dikirim sebagai konteks saat chat berlanjut.
- Web search dapat mengirim query user ke LangSearch.
- Fallback model berarti data bisa berpindah provider jika provider utama gagal.
- Chroma menyimpan chunk teks dokumen dan perlu ikut dipertimbangkan saat backup, retensi, dan penghapusan.

## Rekomendasi Kebijakan Lanjutan

- Tetapkan klasifikasi dokumen yang boleh/tidak boleh diproses provider eksternal.
- Tambahkan opsi per dokumen atau per chat: `Jangan kirim ke provider eksternal`.
- Tetapkan retensi chat, dokumen, memo, dan vector.
- Audit provider aktif sebelum demo/production formal.
- Review privacy terms provider secara berkala sebelum memproses dokumen sensitif.

## Referensi Provider

- OpenAI Business Data Privacy: https://openai.com/business-data/
- OpenAI API Data Controls: https://platform.openai.com/docs/models/how-we-use-your-data
- Microsoft Foundry Data Privacy: https://learn.microsoft.com/en-us/azure/foundry/responsible-ai/openai/data-privacy
- GitHub Models REST API: https://docs.github.com/en/rest/models/inference
- GitHub Models endpoint deprecation notice: https://github.blog/changelog/2025-07-17-deprecation-of-azure-endpoint-for-github-models/
