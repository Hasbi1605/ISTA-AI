# Workflow Review

Dokumen ini menjelaskan alur kerja review dan implementasi di repo ini, termasuk kapan menggunakan model yang lebih kuat dan kapan cukup menggunakan model yang lebih hemat.

## Tujuan

Workflow ini dirancang untuk:
- menjaga kualitas hasil kerja
- memisahkan tahap perencanaan, implementasi, dan review
- menghemat biaya dengan memakai model sesuai kebutuhan
- memastikan setiap perubahan tetap diverifikasi dengan baik

## Prinsip Umum

- Gunakan model yang lebih kuat untuk tugas yang membutuhkan penalaran, perencanaan, peninjauan, atau keputusan penting.
- Gunakan model yang lebih hemat untuk tugas implementasi yang sudah jelas arah dan ruang lingkupnya.
- Jangan menganggap implementasi selesai sebelum verifikasi dilakukan.
- Jika hasil implementasi masih diragukan, eskalasi kembali ke review dengan model yang lebih kuat.

## Pembagian Peran Model

### Model mahal
Gunakan model mahal untuk tugas berikut:
- memahami codebase sebelum tugas besar
- membuat plan atau breakdown pekerjaan
- menyusun issue atau acceptance criteria
- melakukan review PR
- mengevaluasi komentar review
- melakukan review ulang setelah revisi
- memutuskan apakah perubahan sudah siap di-merge
- menilai risiko arsitektur, maintainability, dan kemungkinan regresi
- menilai apakah test coverage sudah memadai untuk perubahan penting

### Model murah
Gunakan model murah untuk tugas berikut:
- mengimplementasikan plan yang sudah jelas
- menulis atau memperbaiki kode berdasarkan issue/plan yang sudah ada
- menindaklanjuti komentar review yang sudah spesifik
- melakukan perubahan kecil, terarah, dan berisiko rendah
- menambahkan atau memperbaiki test yang sudah jelas kebutuhannya
- menjalankan verifikasi rutin dan merangkum hasilnya

## Alur Kerja Standar

### 1. Planning
Gunakan model mahal untuk:
- memahami konteks tugas
- membaca codebase bila perlu
- membuat plan yang jelas
- menulis draft issue kerja ke file markdown di folder `issue/`

Output minimum:
- tujuan tugas
- ruang lingkup
- file atau area yang kemungkinan terlibat
- risiko utama
- urutan langkah implementasi
- kriteria selesai

## Aturan Artefak Planning

Untuk tugas yang kompleks, hasil planning tidak cukup hanya ada di chat.

Planning harus ditulis ke file `.md` di folder `issue/` agar:
- bisa direview sebelum implementasi
- menjadi acuan implementasi
- mudah diposting ke GitHub Issue
- bisa dibandingkan dengan hasil akhir

Implementasi sebaiknya mengacu pada file issue markdown yang sudah ada, bukan hanya instruksi singkat dari percakapan.

### 2. Implementasi
Gunakan model murah untuk:
- mengerjakan plan yang sudah ada
- membuat perubahan kecil dan terarah
- mengikuti batasan repo dan aturan pada `AGENTS.md`
- menambahkan test bila dibutuhkan

Pada tahap ini:
- hindari perubahan besar di luar scope
- fokus pada tujuan issue atau plan
- rangkum file yang diubah dan alasan perubahan

### 3. Verifikasi
Setelah implementasi:
- jalankan test yang relevan
- jika test yang dibutuhkan belum ada, tambahkan test terlebih dahulu
- jalankan ulang verifikasi
- ringkas hasil test, kegagalan, dan risiko

### 4. Review
Gunakan model mahal untuk:
- mereview hasil implementasi
- mengecek apakah perubahan sesuai plan
- mengecek kualitas kode, risiko regresi, dan kecukupan test
- memberi komentar review bila perlu

### 5. Tindak Lanjut Review
Jika ada komentar review:
- gunakan model murah untuk mengerjakan komentar yang sudah jelas
- gunakan model mahal lagi bila komentar menyentuh desain, arsitektur, perubahan lintas modul, atau ada ketidakpastian besar

### 6. Review Ulang
Gunakan model mahal untuk review ulang sampai perubahan dianggap siap.

## Kapan Harus Eskalasi ke Review Mahal

Eskalasi ke model mahal jika terjadi salah satu dari kondisi berikut:

### A. Scope berubah
- implementasi ternyata membutuhkan perubahan lebih luas dari plan awal
- ada perubahan lintas modul atau lintas layanan
- ada indikasi perubahan memengaruhi arsitektur atau kontrak antarsistem

### B. Akar masalah belum jelas
- bug belum benar-benar dipahami
- ada beberapa kemungkinan akar masalah
- hasil perbaikan tampak bekerja tetapi penyebab utamanya belum meyakinkan

### C. Risiko tinggi
- menyentuh autentikasi, otorisasi, pembayaran, file penting, migrasi data, atau integrasi penting
- menyentuh logika bisnis utama
- menyentuh area yang sering regresi

### D. Review menghasilkan pertanyaan substantif
- ada komentar review yang mempertanyakan pendekatan
- ada komentar tentang desain, maintainability, atau keamanan
- ada komentar bahwa test belum cukup untuk perilaku penting

### E. Verifikasi tidak meyakinkan
- test gagal dan penyebabnya tidak langsung jelas
- perubahan lolos test, tetapi masih ada kekhawatiran pada edge case penting
- test tambahan yang dibutuhkan belum jelas bentuknya

### F. Dokumentasi atau ringkasan tidak lagi akurat
- implementasi bergeser cukup jauh dari plan atau issue awal
- perlu evaluasi ulang tentang apa yang sebenarnya berubah

## Kapan Model Murah Sudah Cukup

Model murah biasanya cukup jika:
- plan sudah jelas
- perubahan terlokalisasi
- komentar review sangat spesifik
- perbaikan hanya menyentuh beberapa file
- kebutuhan test sudah jelas
- tidak ada keputusan desain besar yang harus diambil

## Aturan Praktis

- Jika tugasnya membutuhkan keputusan, gunakan model mahal.
- Jika tugasnya membutuhkan eksekusi dari keputusan yang sudah dibuat, gunakan model murah.
- Jika ragu apakah perubahan ini kecil atau tidak, anggap perlu review mahal.
- Jika review menemukan isu baru yang mengubah pemahaman masalah, kembali ke model mahal.

## Ringkasan Workflow

1. Model mahal: pahami konteks, analisis codebase, buat plan, buat issue.
2. Model murah: implementasi sesuai plan.
3. Model murah: jalankan verifikasi dan tambah test bila perlu.
4. Model mahal: review PR dan beri komentar.
5. Model murah: kerjakan komentar review.
6. Model murah: verifikasi ulang.
7. Model mahal: review ulang sampai siap approve.

## Hasil Akhir yang Diharapkan

Sebuah perubahan dianggap siap jika:
- tujuan issue atau plan tercapai
- implementasi tetap dalam scope yang benar
- test yang relevan sudah dijalankan
- test penting yang sebelumnya belum ada sudah ditambahkan
- review mahal terakhir tidak menemukan blocker besar
- risiko dan tindak lanjut sudah diringkas dengan jelas
