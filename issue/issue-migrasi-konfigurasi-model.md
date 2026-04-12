# Issue Planning: Pemisahan Konfigurasi AI & Integrasi ke File Terpisah (Tahap 1)

## 1) Latar Belakang
Konfigurasi model, embedding, SMTP Gmail, search, dan semantic rerank masih tersebar di beberapa titik kode, sehingga pergantian nilai operasional tidak praktis. Metode fallback/backup yang berjalan saat ini sudah dianggap baik, jadi fokus tahap ini adalah memindahkan konfigurasi ke file terpisah tanpa mengubah perilaku fallback yang sudah ada.

## 2) Tujuan High-Level
- Memusatkan konfigurasi model, embedding, SMTP Gmail, search, dan semantic rerank ke file konfigurasi terpisah.
- Mempertahankan strategi fallback/backup yang sudah berjalan saat ini (tanpa perubahan logika).
- Menyiapkan struktur dan logika lane reasoning dari sekarang, dengan default model masih kosong.
- Memudahkan user mengganti nilai konfigurasi sendiri tanpa perlu ubah logika inti aplikasi.
- Menurunkan risiko perubahan saat update model atau parameter operasional.

## 3) Scope High-Level
### Dikerjakan
- Inventaris seluruh nilai hardcoded terkait model, embedding, SMTP Gmail, search, dan semantic rerank.
- Penambahan file konfigurasi terpisah sebagai sumber utama.
- Refactor komponen agar membaca konfigurasi terpusat.
- Menyiapkan pembacaan konfigurasi untuk lane reasoning meskipun model belum diisi.
- Dokumentasi ringkas cara mengganti konfigurasi untuk user/junior programmer.

### Tidak Dikerjakan
- Perubahan strategi fallback/backup yang sudah berjalan baik.
- Perubahan urutan prioritas fallback yang ada saat ini.
- Aktivasi penuh lane reasoning di production sebelum model reasoning diisi oleh user.
- Fine-tuning model atau optimasi prompt mendalam.
- Integrasi provider baru di luar provider/model yang sudah ada.
- Pembuatan secret manager baru dari nol.

## 4) Rencana Implementasi Bertahap (Fase)
### Fase 1 - Discovery & Mapping
- Petakan semua entry point dan nilai hardcoded untuk area chat, reasoning, embedding, SMTP, search, dan rerank.
- Tandai mana yang wajib dipindahkan lebih dulu (jalur kritikal).

### Fase 2 - Ekstraksi Konfigurasi
- Buat file konfigurasi terpisah sebagai single source of truth.
- Pindahkan nilai konfigurasi secara 1:1 dari kondisi saat ini (tanpa ubah strategi fallback).
- Tambahkan key lane reasoning dengan nilai default kosong agar siap diisi kapan pun.

### Fase 3 - Refactor Konsumen
- Ubah komponen agar membaca konfigurasi terpusat, bukan nilai hardcoded.
- Pastikan perilaku layanan tetap setara dengan kondisi sebelum migrasi.
- Pastikan jika model reasoning kosong, sistem tetap berjalan normal (skip/no-op secara graceful).

### Fase 4 - Validasi Kesetaraan
- Uji skenario normal dan fallback untuk memastikan hasil tetap konsisten.
- Verifikasi alur email SMTP dan retrieval (search + rerank) tetap berfungsi.

### Fase 5 - Dokumentasi & Handover
- Finalisasi panduan ringkas edit konfigurasi untuk user/junior programmer.
- Serahkan checklist operasional perubahan yang aman.

## 5) Kriteria Keberhasilan (Acceptance Criteria)
- Tidak ada lagi nilai hardcoded kritikal untuk model, embedding, SMTP Gmail, search, dan semantic rerank di jalur utama.
- Strategi fallback/backup tetap sama seperti sebelum migrasi.
- User dapat mengganti model, embedding, SMTP, search, dan rerank melalui file konfigurasi tanpa edit logika inti.
- Key konfigurasi lane reasoning sudah tersedia, dengan default model kosong.
- Saat model reasoning masih kosong, aplikasi tidak crash dan alur utama tetap berjalan normal.
- Konfigurasi sandi SMTP Gmail tidak disimpan hardcoded di source code (menggunakan referensi env/secret).
- Dokumentasi konfigurasi dapat dipahami dan dieksekusi oleh junior programmer.

## 6) Risiko & Mitigasi (High-Level)
- Risiko: Perilaku berubah karena nilai konfigurasi tidak terpindah lengkap.
  Mitigasi: Lakukan verifikasi mapping before/after pada jalur utama.
- Risiko: Key reasoning kosong menimbulkan error runtime.
  Mitigasi: Terapkan guard clause agar lane reasoning otomatis nonaktif saat model belum diisi.
- Risiko: Salah edit konfigurasi oleh operator.
  Mitigasi: Sediakan template aman + validasi format dasar saat startup.
- Risiko: Kredensial SMTP Gmail terekspos.
  Mitigasi: Simpan password di env/secret, file konfigurasi hanya menyimpan referensi key.
- Risiko: Retrieval berubah karena parameter search/rerank ikut bergeser.
  Mitigasi: Kunci nilai default awal agar sama dengan konfigurasi lama saat cutover pertama.

## 7) Deliverables
- File konfigurasi terpisah untuk model, embedding, SMTP Gmail, search, dan semantic rerank.
- Refactor komponen agar seluruh area terkait membaca konfigurasi terpusat.
- Dokumen panduan singkat penggantian konfigurasi untuk user/junior programmer.
- Catatan validasi kesetaraan perilaku sebelum vs sesudah migrasi.

## 8) Saran Struktur File Konfigurasi (High-Level)
Contoh struktur logis (bukan detail implementasi):

- `global`: timeout, retry, dan policy umum resolver.
- `lanes.chat`: konfigurasi model chat (mengikuti urutan existing).
- `lanes.reasoning`: key sudah ada, default `primary = ""` dan `fallback = ""` sampai user mengisi.
- `lanes.embedding`: model embedding untuk indexing dan query-time.
- `retrieval.search`: strategi search, prioritas, dan batas hasil.
- `retrieval.semantic_rerank`: pengaturan aktif/nonaktif dan model rerank.
- `integrations.smtp_gmail`: host/port/username/sender + referensi env key untuk password.
- `metadata`: versi konfigurasi dan catatan perubahan.

Penempatan disarankan di folder konfigurasi aplikasi yang konsisten dengan pola repo (mis. `config/ai.*` atau setara).

## 9) Checklist Eksekusi Ringkas
- [ ] Petakan semua lokasi hardcoded untuk model, embedding, SMTP Gmail, search, dan semantic rerank.
- [ ] Buat file konfigurasi terpisah sebagai sumber utama.
- [ ] Pindahkan nilai konfigurasi secara 1:1 dari kondisi saat ini.
- [ ] Tambahkan key konfigurasi lane reasoning dengan default kosong.
- [ ] Refactor komponen agar seluruhnya membaca konfigurasi.
- [ ] Pastikan lane reasoning aman saat kosong (skip/no-op) sampai model diisi.
- [ ] Uji kesetaraan perilaku (termasuk fallback/backup existing).
- [ ] Tulis dokumentasi singkat untuk user/junior programmer.
- [ ] Review akhir: pastikan tidak ada hardcoded kritikal di jalur utama.
