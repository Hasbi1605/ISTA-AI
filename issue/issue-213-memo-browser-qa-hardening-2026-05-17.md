# Issue 213: Memo Browser QA Hardening

## Tujuan

Menjalankan QA browser production untuk fitur memo dengan skenario yang berisiko memunculkan bug persistensi, salah target memo, download stale, dan konflik versi OnlyOffice. Jika bug ditemukan, lakukan perbaikan minimal, verifikasi, deploy, lalu ulang QA sampai hasil memo aman.

## Scope QA

- Generate memo baru dari konfigurasi.
- Edit manual di OnlyOffice dan download DOCX/PDF.
- Download segera setelah edit manual untuk mengecek race force-save.
- Refresh halaman, buka riwayat memo, dan pastikan edit manual tetap ada.
- Revisi via prompt metadata-only dan pastikan isi manual dipertahankan.
- Edit konfigurasi setelah manual edit dan pastikan baseline yang dipakai benar.
- Pindah antar memo/history dan pastikan prompt atau download tidak salah target.
- Cek version history agar versi aktif dan dokumen yang tampil konsisten.

## Kriteria Aman

- DOCX/PDF hasil download selalu sesuai dengan tampilan OnlyOffice setelah status tersimpan.
- Prompt metadata-only tidak menghapus isi manual.
- Frasa instruksi kontrol tidak ikut masuk ke field memo.
- Setelah refresh, memo tetap editable dan tidak kehilangan perubahan manual.
- Saat pindah history, submit revisi dan download harus mengarah ke memo aktif yang benar.
- Tidak ada file DOCX korup yang diterima sebagai versi valid.

## Rencana Implementasi Jika Bug Ditemukan

1. Reproduksi bug dengan marker unik dan simpan bukti screenshot/download.
2. Identifikasi area Laravel/Livewire/OnlyOffice callback yang terkait.
3. Tambahkan test terdekat yang gagal sebelum patch bila memungkinkan.
4. Patch minimal sesuai pola existing.
5. Jalankan Pint/test Laravel relevan dan full test jika scope menyentuh jalur inti.
6. Deploy production dan ulang QA browser pada skenario yang gagal.

## Risiko

- QA production dapat membuat memo/user sementara; data harus dibersihkan setelah selesai.
- Skenario race condition bisa flakey; hasil harus dibedakan antara bug produk dan timing automasi.
- Patch pada sinkronisasi OnlyOffice berisiko menambah latency download/revisi, sehingga perlu diuji lewat browser.
