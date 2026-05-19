# Issue 242 - Admin Usage Model and Error Detail

## Tujuan
Membedakan fokus `/admin/usage` dan `/admin/errors` agar Usage menjadi observability request normal, sedangkan Errors menjadi incident/debugging view.

## Scope
- Tambahkan metadata model AI (`model_label`, `model_provider`, `model_name`) pada event chat ketika Python stream mengirim marker model.
- Tampilkan kolom Model di tabel Usage.
- Tambahkan severity otomatis pada Errors berdasarkan `error_code`, status, feature, dan metadata.
- Tambahkan tombol aksi Detail pada Errors yang membuka modal berisi informasi lengkap, metadata aman, kemungkinan penyebab, dan langkah penanganan.
- Pertahankan privasi: tidak menampilkan prompt, jawaban, isi dokumen, atau memo.

## Risiko
- Event lama belum memiliki metadata model; UI harus menampilkan fallback yang rapi.
- Severity dihitung otomatis tanpa migration, jadi harus deterministik dan mudah dipahami.
- Modal detail harus mengambil data dari event yang sudah terfilter sebagai error/blocked.

## Verifikasi
- Jalankan build Vite.
- Jalankan test admin monitoring, tracking event AI, dan unit service metrics.
- Jalankan test Python terkait metadata stream bila relevan.
