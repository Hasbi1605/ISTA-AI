# AGENTS.md

## Tujuan
Repo ini menggunakan workflow berbasis plan, implementasi bertahap, verifikasi wajib, dan review berulang sampai siap di-merge.

## Cara bekerja
- Untuk tugas yang kompleks, mulai dengan plan yang jelas sebelum menulis kode.
- Gunakan perubahan sekecil mungkin yang tetap menyelesaikan masalah.
- Ikuti issue, plan, atau tujuan tugas yang sudah ada.
- Jangan melakukan refactor besar kecuali memang diminta atau benar-benar diperlukan.
- Jika ada ketidakpastian, nyatakan asumsi secara eksplisit.

## Workflow yang diharapkan
1. Pahami codebase dan konteks tugas.
2. Untuk tugas yang kompleks, buat plan tertulis dalam file `.md` di folder `issue/`.
3. Gunakan file issue markdown tersebut sebagai acuan utama implementasi.
4. Implementasikan perubahan secara bertahap.
5. Jalankan verifikasi yang relevan.
6. Jika test belum ada atau kurang memadai, tambahkan test terlebih dahulu.
7. Ringkas hasil perubahan, hasil verifikasi, dan risiko.
8. Setelah review, tindak lanjuti komentar lalu verifikasi ulang.
9. Jika konteksnya adalah PR aktif dan verifikasi ulang memadai, commit lalu push ke branch PR yang sama.
10. Jika review akhir menyatakan PR lolos, jalankan full test akhir.
11. Jika full test akhir memadai, merge PR, tutup PR, dan hapus branch kerja.
12. Setelah merge, pastikan branch lokal bersih, kembali ke `main`, dan sinkron dengan remote.

## Aturan Review PR
- Review PR dipost sebagai komentar biasa melalui GitHub CLI.
- Jangan gunakan request changes.
- Jangan gunakan approval formal GitHub.
- Jika masih ada perbaikan yang dibutuhkan, tulis komentar review biasa yang menjelaskan blocker dan tindak lanjut.
- Jika PR sudah sesuai scope, tidak ada blocker, dan verifikasi memadai, tulis komentar approval-style sebagai komentar biasa yang berisi tanda approval dan ringkasan hasil review.

## Aturan Tindak Lanjut Review PR
- Jika tugasnya adalah menindaklanjuti komentar review pada PR yang sudah ada, kerjakan perubahan pada branch PR yang aktif.
- Setelah perbaikan selesai, jalankan verifikasi yang relevan.
- Jika verifikasi memadai, commit perubahan dan push ke branch PR yang sama.
- Jangan berhenti pada perubahan lokal saja jika konteks tugasnya adalah follow-up review PR.
- Setelah push, tulis ringkasan singkat tentang komentar review yang ditindaklanjuti, file yang berubah, dan test yang dijalankan.

## Aturan Pasca-Approve PR
- Jika PR sudah dinyatakan lolos oleh review terakhir dan tidak ada blocker, lakukan verifikasi akhir penuh sebelum merge.
- Verifikasi akhir penuh harus mencakup seluruh test yang relevan untuk Laravel dan Python, bukan hanya subset yang sebelumnya dijalankan.
- Untuk Python, gunakan environment lokal:
  `cd python-ai && source venv/bin/activate && pytest`
- Setelah full test akhir memadai, lanjutkan merge PR.
- Setelah merge selesai, tutup PR jika masih terbuka dan hapus branch kerja yang sudah tidak dibutuhkan.
- Setelah itu, pastikan branch lokal kembali bersih dan menyisakan `main` sebagai branch aktif.
- Pastikan `main` lokal sudah sinkron dengan `main` di remote.
- Jangan melakukan merge jika full test akhir masih gagal atau ada error yang belum jelas.

## Aturan Merge PR
- Merge hanya boleh dilakukan setelah review terakhir menyatakan PR lolos dan tidak ada blocker.
- Sebelum merge, lakukan full test akhir untuk memastikan perubahan tidak menimbulkan error atau bug tambahan.
- Jangan merge jika full test akhir gagal atau hasil verifikasi belum jelas.
- Setelah merge, hapus branch kerja yang sudah selesai jika aman untuk dihapus.
- Setelah branch dihapus, pastikan branch aktif lokal kembali ke `main`.
- Pastikan `main` lokal sudah update dan sinkron dengan remote.

## Aturan Planning
- Untuk tugas yang kompleks, planning harus ditulis sebagai file markdown di folder `issue/` sebelum implementasi dimulai.
- File issue markdown menjadi acuan utama untuk scope, tujuan, risiko, dan langkah implementasi.
- Jika implementasi menyimpang signifikan dari plan awal, perbarui file issue markdown atau catat perbedaannya dengan jelas.
- Jangan memulai implementasi besar tanpa issue markdown yang cukup jelas.

## Verifikasi wajib
Setiap perubahan kode wajib diverifikasi pada area yang terdampak.

### Laravel
- Jika perubahan menyentuh folder `laravel`, jalankan test Laravel yang relevan.
- Jika perilaku penting berubah tetapi test belum ada, tambahkan test yang relevan terlebih dahulu.

### Python
- Jika perubahan menyentuh folder `python-ai`, jalankan test Python yang relevan.
- Test Python harus dijalankan dari environment `python-ai` dengan virtual environment lokal yang diaktifkan terlebih dahulu.
- Gunakan pola:
  `cd python-ai && source venv/bin/activate && pytest`
- Jika perilaku penting berubah tetapi test belum ada, tambahkan test yang relevan terlebih dahulu.

## Verifikasi Akhir Penuh
- Untuk Laravel, jalankan seluruh test Laravel yang tersedia.
- Untuk Python, jalankan seluruh test Python dari environment `python-ai` setelah aktivasi virtual environment.
- Full test akhir dilakukan setelah PR dinyatakan lolos review terakhir dan sebelum merge.

### Laravel full test
- `cd laravel && php artisan test`

### Python full test
- `cd python-ai && source venv/bin/activate && pytest`

## Aturan test
- Jangan menganggap tugas selesai bila test yang relevan belum dijalankan.
- Jika ada gap test yang jelas pada perilaku yang diubah, buat test terlebih dahulu.
- Prioritaskan test yang langsung memverifikasi perilaku yang diubah atau bug yang diperbaiki.
- Setelah menambah test, jalankan ulang verifikasi.

## Ekspektasi output
Saat menyelesaikan tugas, ringkas:
- apa yang diubah
- file utama yang disentuh
- test yang dijalankan
- test yang ditambahkan
- risiko atau pekerjaan lanjutan

## Done when
Sebuah tugas dianggap selesai hanya jika:
- tujuan tugas tercapai
- perubahan utama sudah diimplementasikan
- test yang relevan sudah dijalankan
- test yang diperlukan sudah ditambahkan bila sebelumnya belum ada atau belum memadai
- hasil verifikasi jelas
- risiko dan tindak lanjut sudah diringkas
- jika tugasnya adalah follow-up review PR, perubahan sudah di-push ke branch PR yang benar setelah verifikasi memadai
- jika tugasnya melalui PR, review akhir sudah menyatakan tidak ada blocker
- full test akhir sudah dijalankan dan hasilnya memadai
- PR sudah di-merge
- branch kerja sudah dihapus bila tidak lagi dibutuhkan
- branch lokal sudah kembali ke `main`
- kondisi lokal dan remote sudah sinkron
