# Issue: Admin Documents & Knowledge production hotfix

## Tujuan
- Rapikan tabel `/admin/documents` agar mengikuti contoh: kolom file, user, tipe, size, status, chunks, waktu, aksi.
- Hilangkan kolom pipeline dari tabel dokumen dan pindahkan konteks pipeline ke detail modal yang lebih ringkas.
- Ubah detail dokumen dari drawer ramai menjadi modal yang serasi dengan modal detail error.
- Selaraskan tampilan `/admin/knowledge` dengan tab admin lain.

## Scope
- Blade admin documents dan knowledge.
- CSS admin documents/knowledge.
- Test feature admin yang memverifikasi copy, kolom, modal, dan konsistensi class.

## Di luar scope
- Mengubah pipeline ingest dokumen atau knowledge.
- Mengubah isi data production.
- Menambahkan fitur retrieval knowledge baru.

## Risiko
- Table overflow pada viewport sempit.
- Modal bisa terlalu padat jika metadata panjang.
- Knowledge action buttons tetap harus aman dan tidak berubah perilaku.

## Verifikasi
- Targeted Laravel admin tests.
- `npm run build`.
- `git diff --check`.
- Setelah merge, deploy production dan smoke check `/up`, `/admin/login`, `/admin/documents`, `/admin/knowledge`.

## Catatan implementasi
- Tabel documents dibuat full-width agar kolom file, user, tipe, size, status, chunks, waktu, dan aksi tidak sesak.
- Kolom pipeline dihapus dari tabel; status pipeline tetap tersedia di konteks panel samping dan detail modal ringkas.
- Detail dokumen diubah menjadi modal centered yang mengikuti pola modal detail error.
- Knowledge dipoles menjadi layout admin konsisten: hero, KPI cards, filter, tabel, upload, dan status panel.
