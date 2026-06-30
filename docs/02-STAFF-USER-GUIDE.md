# Staff User Guide

Panduan ini menjelaskan alur penggunaan ISTA AI untuk user biasa/staf.

## Login

1. Buka aplikasi.
2. Klik fitur Chat, Memo, atau Prompy.
3. Login dengan akun yang sudah dibuat/disetujui.
4. Jika email belum verified, selesaikan verifikasi email terlebih dahulu.

Jika registrasi mandiri production ditutup, akun dibuat atau diaktifkan oleh admin.

## Chat

1. Buka `/chat`.
2. Ketik pertanyaan.
3. Jawaban akan muncul streaming.
4. Jika jawaban memakai dokumen, sumber akan ditampilkan terpisah.

Gunakan pertanyaan yang spesifik. Untuk hal berbasis waktu atau berita terbaru,
sebutkan bahwa jawaban perlu konteks terbaru agar web search dapat dipertimbangkan.

## Upload Dokumen untuk RAG

1. Upload dokumen dari sidebar chat.
2. Format yang didukung: PDF, DOCX, XLSX, CSV.
3. Ukuran maksimal upload mengikuti validasi aplikasi, saat ini 50 MB.
4. Tunggu status dokumen siap/ready.
5. Pilih dokumen sebagai konteks aktif.
6. Ajukan pertanyaan tentang isi dokumen.

Jika jawaban tidak ada di dokumen, desain ISTA AI adalah menyatakan bahwa
informasi tidak ditemukan dan menawarkan jalur lain, bukan mengarang.

## Memo

1. Buka tab Memo di workspace chat.
2. Isi kebutuhan memo.
3. Generate memo.
4. Buka hasil DOCX di OnlyOffice bila perlu diedit.
5. Simpan/force-save, lalu download atau export PDF.

Memo tetap perlu dicek manusia sebelum dipakai sebagai dokumen resmi.

## Prompy Studio

Prompy Studio membantu membuat prompt siap pakai untuk platform eksternal seperti
ChatGPT Images/GPT Image, Gemini, Canva AI, atau Universal.

Alur umum:

1. Buka tab Prompy.
2. Tulis ide.
3. Pilih platform tujuan.
4. Pilih jenis keluaran.
5. Tambahkan reference image JPG/PNG bila ingin gaya visual diikuti.
6. Tambahkan dokumen acuan PDF/DOCX/XLSX/CSV bila perlu konteks isi.
7. Generate prompt.
8. Revisi lewat composer bila ingin mengubah gaya, format, atau detail.
9. Salin prompt ke platform eksternal secara manual.

Catatan penting: ISTA AI tidak mengirim output ke platform target dan tidak
menghasilkan gambar/video. Output Prompy adalah teks prompt.

## Praktik Aman

Gunakan dokumen yang memang sudah disetujui untuk diproses AI. Prompt Prompy
yang berisi data sensitif juga perlu ditinjau sebelum disalin ke platform
eksternal. Untuk memo dan jawaban AI, pemeriksaan manusia tetap penting sebelum
dipakai sebagai rujukan resmi. Pada perangkat bersama, logout setelah selesai
memakai aplikasi.
