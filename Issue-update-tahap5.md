# Issue Update Tahap 5: Optimasi RAG, Fallback Model & Perbaikan UI Chat

## Latar Belakang

Ditemukan beberapa isu terkait limitasi kuota (rate limit) dari API GitHub Models serta beberapa _bug_ visual pada antarmuka chat aplikasi web.

1. Model unggulan `gpt-5-chat` memiliki limitasi penggunaan harian yang sangat rendah, sehingga menyebabkan interaksi terputus jika sering digunakan.
2. Proses pembuatan _embedding_ fitur RAG cukup rapuh jika terjadi ledakan jumlah token, karena tidak ada _fallback_ cadangan yang mumpuni antar penyedia/token.
3. Fitur animasi proses pengetikan (_typewriter_) balasan asisten virtual gagal di-render utuh untuk teks panjang dan langsung lompat ke akhir paragraf akibat tumpang tindih siklus _resource polling_ di server Livewire.

## Implementation Plan

### 1. Peningkatan Fallback LLM Manager

_Lokasi: `python-ai/app/llm_manager.py`_

- [ ] Cek status dan limitasi batas atas (_Rate Limit Requests / Tokens_) untuk alternatif model yang tersedia.
- [ ] Ubah prioritas model primer dan cadangan pada `MODEL_LIST` untuk menghindari _downtime_.
- [ ] Rombak struktur _fallback_ secara berurutan:
  1. **GPT-4o (Primary)** menggunakan var env `GITHUB_TOKEN`.
  2. **GPT-4o (Backup Node)** menggunakan var env `GITHUB_TOKEN_2`.
  3. **Gemini 3 Flash**.
  4. **Llama 3.3 70B (Groq)**.

### 2. Hierarki Fallback Mode pada Layanan RAG / Embeddings

_Lokasi: `python-ai/app/services/rag_service.py`_

- [ ] Perbarui inisiasi provider _vector embeddings_ pada daftar variabel konstan `EMBEDDING_MODELS`.
- [ ] Pastikan saat server API melontarkan status kode 429 (Rate Limit), sistem tidak langsung _"crash"_, melainkan otomatis bergeser ke layer pengaman berikut secara mandiri:
  - **Tahap 1:** _text-embedding-3-large_ (via GITHUB_TOKEN utama)
  - **Tahap 2:** _text-embedding-3-large_ (via GITHUB_TOKEN_2)
  - **Tahap 3:** _text-embedding-3-small_ (via GITHUB_TOKEN utama)
  - **Tahap 4:** _text-embedding-3-small_ (via GITHUB_TOKEN_2)

### 3. Perbaikan Sinkronisasi Animasi Typewriter (Livewire ↔ Alpine.js)

_Lokasi: `laravel/app/Livewire/Chat/ChatIndex.php` & `laravel/resources/views/livewire/chat/chat-index.blade.php`_

- [ ] **State Component (PHP):** Tambahkan sebuah properti akses publik `$newMessageId`. Variabel ini bertugas menampung referensi _Primary Key_ khusus untuk entri pesan asisten yang _baru saja_ dibuat pada _database_.
- [ ] **Update Metode (PHP):** Pada metode penangkap stream chat (`sendMessage`), simpan nilai ID pesan balasan bot ke variabel `$this->newMessageId` tepat sebelum menyegarkan daftar percakapan (_refresh state_).
- [ ] **Logika Blade (HTML):** Hapus skenario pembandingan sisa selang waktu 5 detik (`diffInSeconds`) karena mudah usang (kedaluwarsa akibat durasi baca panjang asisten).
- [ ] **Data Binding (HTML):** Ubah render pengkondisian menjadi deteksi persis `@if($message['id'] == $newMessageId)` untuk pemicu blok _typewriter_.
- [ ] **Isolasi Node (HTML):** Sematkan dua parameter wajib dari Livewire: `wire:ignore` dan `wire:key="msg-typing-{{$message['id']}}"` tepat di elemen penampung skrip perulangan _x-data_ / DOM. Aksi ini ditujukan guna mencegah DOM diganggu/di-render ulang secara kasar oleh skenario siklus `wire:poll` yang berjalan _asynchronous_.
