# Redesign Admin Documents Menjadi AI Pipeline View

## Latar Belakang
Tab Admin Documents saat ini sudah konsisten secara visual dengan tab monitoring lain, tetapi masih terasa seperti file list biasa. Untuk platform AI document, admin perlu melihat dokumen sebagai pipeline: uploaded, parsed, indexed, lalu ready.

## Tujuan
- Membuat Documents terlihat modern sebagai AI document pipeline.
- Menampilkan file icon sesuai tipe dokumen.
- Menampilkan status chip, progress pipeline, owner, size, waktu upload.
- Menambah filter by type, status, user, dan date.
- Menambah detail drawer metadata file dan status pipeline tanpa membuka isi dokumen.

## Ruang Lingkup
- Update Livewire admin documents untuk filter tambahan, pagination, selected document detail.
- Update service admin metrics untuk filter dokumen, owner options, chunk count, dan metadata aman.
- Update Blade/CSS Documents agar konsisten dengan Overview, Users, Usage, Errors.
- Tambah relasi/model minimal untuk menghitung `document_chunks`.
- Update test feature/unit yang relevan.

## Di Luar Scope
- Tidak menambah kolom database baru.
- Tidak membaca atau menampilkan isi dokumen.
- Tidak mengubah job parsing/embedding Python.
- Tidak mengubah lifecycle upload/process dokumen.

## Area / File Terkait
- `laravel/app/Livewire/Admin/AdminDocuments.php`
- `laravel/app/Services/Admin/AdminMetricsService.php`
- `laravel/app/Models/Document.php`
- `laravel/app/Models/DocumentChunk.php`
- `laravel/resources/views/livewire/admin/admin-documents.blade.php`
- `laravel/resources/css/app.css`
- `laravel/tests/Feature/Admin/AdminMonitoringDashboardTest.php`
- `laravel/tests/Unit/Services/Admin/AdminMetricsServiceTest.php`

## Risiko
- Pipeline status perlu diturunkan dari field yang ada (`status`, `preview_status`, chunk count), sehingga label harus jelas sebagai metadata operasional, bukan klaim isi dokumen.
- Filter user/date/type harus tetap aman terhadap input query string malformed.
- Detail drawer tidak boleh membocorkan konten dokumen atau chunk text.

## Langkah Implementasi
1. Tambahkan model/relasi `DocumentChunk` hanya untuk menghitung chunk count.
2. Perluas `AdminMetricsService::documentListing()` dengan filter type, user, date, chunk count, dan helper owner options.
3. Perluas `AdminDocuments` dengan state filter dan detail drawer.
4. Redesign Blade Documents: icon tipe, status chip, progress pipeline, filter lengkap, dan drawer detail metadata.
5. Tambahkan CSS khusus Documents pipeline.
6. Update test untuk filter, pagination, chunk count/detail drawer.

## Rencana Test
- Jalankan feature test admin monitoring.
- Jalankan unit test admin metrics.
- Jalankan `npm run build`.
- Jalankan `git diff --check`.

## Kriteria Selesai
- Documents tampil sebagai AI pipeline view.
- Filter type/status/user/date bekerja.
- Detail drawer menampilkan metadata file, parsing status, chunk count, embedding status.
- Tidak ada isi dokumen yang bocor.
- Test dan build relevan lulus.
