# Issue 237 Production E2E QA

Tanggal: 2026-05-18

## Tujuan

Melakukan QA browser end-to-end pada production setelah hotfix UI/CSP `557d5d9`, dengan fokus mencari regresi dari perubahan security wave dan hotfix UI.

## Scope QA

- Dashboard publik, header, footer, dark mode, dan navigasi ke chat/memo.
- Registrasi akun test, verifikasi OTP bila tersedia dari database production, login, logout, dan reset password.
- Chat biasa, chat dengan dokumen, chat dengan web search.
- Upload dokumen kecil, sedang, dan besar dengan konten teks banyak.
- Preview dokumen dan export jawaban chat.
- Memo: buat memo, ubah konfigurasi, generate via konfigurasi, edit/revisi via prompt, export/download memo.
- Pemeriksaan error browser console, request gagal, overlay upload stuck, ikon dark mode dobel, layout header/footer, dan CSP block.

## Batasan dan Asumsi

- QA dilakukan pada production `https://ista-ai.app` menggunakan akun test baru.
- Jika email reset/OTP tidak bisa diakses via inbox, token/kode test boleh diverifikasi lewat database production menggunakan SSH, tanpa menampilkan secret.
- Jika ditemukan bug valid akibat security fix, buat branch hotfix, implementasikan perbaikan kecil, jalankan test relevan, PR/merge/deploy, lalu ulangi QA area terdampak.
- Hindari mengubah atau menghapus data pengguna non-test.

## Rencana Eksekusi

1. Siapkan browser automation dan file fixture dokumen kecil/sedang/besar.
2. Jalankan QA auth: register, OTP, logout/login, forgot/reset password, login password baru.
3. Jalankan QA dashboard dan preferensi UI: header/footer, dark mode, tab chat/memo.
4. Jalankan QA chat: chat biasa, web search, upload dokumen, chat dengan dokumen, preview, export.
5. Jalankan QA memo: buat memo, konfigurasi, generate, revisi prompt, manual/editor surface, export/download.
6. Catat bug dengan bukti screenshot/log/console.
7. Jika perlu perbaikan, implementasikan di branch hotfix dan ulangi verifikasi lokal + deploy production.

## Kriteria Selesai

- Alur utama di atas berhasil atau limitation-nya jelas.
- Tidak ada console error/CSP block yang terkait security fix pada alur utama.
- Tidak ada overlay/upload/dark-mode/header/footer regresi yang terlihat.
- Jika ada bug valid, bug sudah diperbaiki dan QA area tersebut diulang.

## Temuan Selama QA

### Bug: Export/download memo diblokir force-save kosong

Status: valid, perlu hotfix.

Bukti:
- Browser QA untuk memo `753` timeout saat klik `Unduh DOCX`.
- Direct authenticated GET `/chat/memos/753/download?version_id=968` berhasil `200` dan mengembalikan DOCX `38718` bytes.
- POST `/chat/memos/753/force-save` mengembalikan `409` dengan pesan `OnlyOffice belum siap menyimpan dokumen. Kode error: 1`.

Analisis:
- UI selalu memanggil force-save sebelum download/export/config/revisi, walaupun editor tidak punya perubahan manual yang belum tersimpan.
- Untuk kondisi tanpa perubahan aktif, file memo versi saat ini sudah aman diunduh langsung. Memaksa force-save justru membuat workflow gagal jika OnlyOffice tidak punya sesi dokumen aktif.

Rencana fix:
- Tambahkan guard frontend agar force-save hanya dijalankan ketika `window.memoOnlyOfficeState` menandai dokumen sebagai `dirty`.
- Terapkan guard yang sama pada download/export, submit konfigurasi, revisi prompt, before-unload, dan perpindahan memo via JS.
- Ulangi QA download DOCX/PDF dan alur memo setelah deploy.
