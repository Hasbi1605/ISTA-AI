# Issue: Refresh Prompt dan Persona ISTA AI

## Latar Belakang
- Prompt ISTA AI saat ini sudah dipusatkan di `python-ai/config/ai_config.yaml`, tetapi perilaku runtime masih belum sepenuhnya konsisten.
- Masih ada duplikasi persona di Laravel, fallback prompt di Python, dan copy UI chat yang campur Bahasa Indonesia dan Inggris.
- Akibatnya, karakter ISTA AI belum terasa utuh sebagai asisten internal untuk pegawai Istana Kepresidenan Yogyakarta: ramah, serius, fokus, dan tetap nyaman dipakai.

## Tujuan
- Menjadikan `python-ai/config/ai_config.yaml -> prompts.*` sebagai source of truth prompt yang aktif.
- Menormalkan satu effective system prompt per mode percakapan.
- Menyegarkan persona ISTA AI ke gaya “kerja ringkas”: hangat seperlunya, profesional, jujur, dan tidak bertele-tele.
- Menyamakan copy UI chat dengan persona backend.
- Menambahkan regresi test dasar untuk menjaga tone, boundary, dan fallback prompt.

## Scope
### Dikerjakan
- Refresh prompt utama, RAG, web search, summarization, dan fallback dokumen.
- Refactor loader/fallback prompt di Python.
- Hapus hardcoded system prompt Laravel dari alur runtime.
- Rapikan copy UI chat yang masih berbahasa Inggris.
- Tambah test regresi prompt yang relevan.

### Tidak Dikerjakan
- Refactor besar arsitektur chat di luar area prompt.
- Pembuatan dashboard UI untuk mengelola prompt.
- Evals model online atau benchmark otomatis lintas provider.

## Area / File Terkait
- `python-ai/config/ai_config.yaml`
- `python-ai/app/config_loader.py`
- `python-ai/app/llm_manager.py`
- `python-ai/app/main.py`
- `python-ai/app/routers/documents.py`
- `python-ai/app/services/rag_retrieval.py`
- `laravel/app/Services/ChatOrchestrationService.php`
- `laravel/config/ai.php`
- `laravel/resources/views/livewire/chat/partials/chat-composer.blade.php`
- `laravel/resources/views/livewire/chat/partials/chat-messages.blade.php`

## Langkah Implementasi
1. Tambahkan issue markdown ini sebagai acuan perubahan.
2. Ubah prompt aktif di `ai_config.yaml` agar sesuai persona baru dan tambah prompt fallback dokumen.
3. Selaraskan fallback di `config_loader.py` dengan struktur prompt baru.
4. Hapus injeksi system prompt hardcoded dari Laravel agar Python menjadi perakit prompt utama.
5. Gunakan prompt fallback terkonfigurasi untuk kondisi dokumen tidak ditemukan/gagal dibaca.
6. Perbarui fallback summarization agar tetap konsisten dengan gaya baru.
7. Rapikan placeholder, empty state, label, dan disclaimer chat ke Bahasa Indonesia.
8. Tambahkan test regresi untuk kontrak prompt dan orkestrasi chat.

## Tindak Lanjut Prioritas
Perubahan inti prompt sudah masuk, tetapi masih ada pekerjaan hardening yang layak dilanjutkan agar arsitektur prompt benar-benar stabil:

1. Kurangi fallback duplication di `python-ai/app/routers/documents.py`
   - Hilangkan fallback prompt panjang yang masih ditulis inline.
   - Arahkan seluruh prompt summarization ke config loader agar YAML benar-benar menjadi sumber utama.

2. Tambahkan prompt khusus untuk skenario jawaban tidak ditemukan pada mode dokumen
   - Pertimbangkan key seperti `prompts.rag.no_answer` agar perilaku “jawaban tidak ada di dokumen aktif” tidak bercampur dengan prompt RAG utama.
   - Pastikan tone fallback tetap ramah, jujur, dan fokus.

3. Tambahkan eval/regression set kecil yang lebih representatif
   - Mulai dari 12–15 skenario lintas mode: chat umum, RAG ada jawaban, RAG tidak ada jawaban, realtime web, ringkasan, dan pertanyaan ambigu.
   - Gunakan kriteria: akurasi, kenyamanan dibaca, konsistensi tone, dan kejujuran saat konteks kurang.

4. Tambahkan test prompt injection dasar
   - Uji skenario dokumen yang menyisipkan instruksi seperti “abaikan semua aturan sebelumnya”.
   - Pastikan model tetap mengikuti aturan grounding dan tidak mengeksekusi instruksi yang datang dari isi dokumen.

5. Putuskan kebijakan formatting sumber di layer backend/UI
   - Evaluasi apakah blok `Sumber Referensi` akan tetap selalu ditambahkan oleh backend/UI atau dibuat lebih adaptif.
   - Tujuannya agar prompt fokus pada akurasi dan tone, bukan pada formatting sumber yang berlebihan.

## Risiko
- Perubahan prompt bisa mengubah gaya output lintas mode secara luas.
- Penghapusan system prompt Laravel harus hati-hati agar chat biasa tidak kehilangan persona.
- Prompt baru yang lebih tegas bisa memengaruhi panjang jawaban dan perilaku fallback pada semua model.

## Rencana Verifikasi
- Jalankan test Python yang menyentuh prompt/config.
- Jalankan test Laravel yang relevan untuk service chat.
- Validasi bahwa tidak ada duplikasi system prompt pada mode chat dokumen.
- Untuk tindak lanjut hardening, tambahkan verifikasi khusus pada skenario no-answer, injection sederhana, dan konsistensi format sumber.

## Kriteria Selesai
- `prompts.*` menjadi sumber prompt aktif yang konsisten.
- Chat biasa dan RAG tidak lagi mengirim dua system prompt yang bersaing.
- Persona ISTA AI terasa konsisten di backend dan UI chat.
- Fallback dokumen tidak lagi hardcoded.
- Test yang relevan berjalan dengan hasil jelas.
- Eval/regression tambahan untuk mode dokumen, no-answer, dan prompt injection dasar tersedia dan hasilnya jelas.
