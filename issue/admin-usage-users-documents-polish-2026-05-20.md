# Admin Usage, Users, and Documents polish

Tanggal: 2026-05-20

## Konteks

Production sudah menjalankan perubahan Knowledge dan AI Configuration. Setelah deploy, tampilan sempat terlihat tidak konsisten karena cache compiled view/proses Laravel lama; production sudah di-clear dan service direcreate. Perubahan berikutnya fokus pada tiga keluhan UX/admin monitoring terbaru:

- Usage terlalu penuh karena event lifecycle seperti `started` memenuhi tabel.
- Aksi delete pada tab Users masih berbentuk teks, lebih baik icon trash agar konsisten dan hemat ruang.
- Documents perlu insight pipeline yang lebih cepat terbaca: distribusi tipe dan status pipeline sebaiknya berada di atas tabel, lebih ringkas, serta kolom chunks perlu disambungkan ke data backend yang benar.

## Scope

1. Usage
   - Tabel default menampilkan event final/bermakna, bukan lifecycle `started`/pending.
   - KPI tetap boleh menghitung total event seperti saat ini agar metrik tidak berubah diam-diam.
   - Copy dan state filter dibuat jelas bila event pending/lifecycle disembunyikan.

2. Users
   - Ganti tombol teks `Delete` menjadi tombol icon trash.
   - Pertahankan `wire:confirm`, guard super admin, dan aksesibilitas (`aria-label`, title).

3. Documents
   - Pindahkan `Distribusi Tipe` dan `Status Pipeline` ke area atas sebelum tabel.
   - Ubah distribusi tipe menjadi card donut compact.
   - Ubah pipeline menjadi ringkasan compact yang mudah dipindai.
   - Perbaiki sumber data chunks agar tidak selalu `0` bila pipeline indexing sudah menghasilkan chunk.

## Rencana Teknis

- Baca `AdminUsage`, `AdminUsers`, `AdminDocuments`, `AdminMetricsService`, `ProcessDocument`, dan service Python document ingest.
- Untuk Usage, tambahkan filter query agar listing default mengecualikan event `started`/pending; jika perlu sediakan toggle kecil untuk menampilkan event lifecycle.
- Untuk Users, patch Blade dan CSS tombol delete.
- Untuk Documents, ubah layout Blade dan CSS, lalu sambungkan chunk count melalui metadata dokumen yang di-update setelah proses indexing.
- Tambah/update test Laravel untuk memastikan:
  - Usage table default tidak ramai oleh event `started`.
  - Delete users tetap memanggil action dan tampil sebagai aksi icon.
  - Documents memakai chunk count backend baru/fallback lama.

## Risiko

- Perubahan chunk count menyentuh pipeline dokumen; harus tetap backward-compatible untuk dokumen lama.
- Jika Python response belum menyediakan jumlah chunk, perlu menambah field response tanpa mengubah kontrak lama.
- Production deploy perlu migration bila menambah kolom metadata chunk.

## Verifikasi

- `cd laravel && php artisan test --filter=Admin`
- `cd laravel && npm run build`
- Jika menyentuh Python document service: `cd python-ai && source venv/bin/activate && pytest`
- Setelah deploy: cek `/admin/usage`, `/admin/users`, `/admin/documents`, dan health production.
