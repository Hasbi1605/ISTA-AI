# Fix Document Export Spreadsheet to Google Drive

## Latar Belakang
Audit bug menemukan jalur `DocumentViewer::saveToGoogleDrive()` gagal untuk format `xlsx` dan `csv`. Service export dokumen membaca payload `extractTables()` sebagai list tabel langsung, padahal endpoint Python mengembalikan object dengan key `tables`.

## Tujuan
- Memastikan dokumen yang memiliki tabel bisa disimpan ke Google Drive sebagai `xlsx` atau `csv`.
- Menjaga error tetap jelas saat export spreadsheet diminta untuk konten tanpa tabel.
- Menutup gap test pada jalur Livewire document viewer + Google Drive spreadsheet export.

## Scope
- Laravel service export dokumen.
- Laravel controller export jawaban/dokumen.
- Test feature untuk Google Drive upload dan document export.

## Di Luar Scope
- Refactor besar export pipeline.
- Perubahan Python export engine.
- Perubahan integrasi OAuth Google Drive.

## Rencana Implementasi
1. Tambahkan test regresi untuk `DocumentViewer::saveToGoogleDrive('xlsx')` dengan `DocumentExportService` real dan HTTP fake ke Python export endpoints.
2. Perbaiki `DocumentExportService::exportDocument()` agar mengambil `tables` dari payload `extractTables()`.
3. Tambahkan guard backend pada `DocumentExportController::export()` agar `xlsx/csv` tanpa `<table>` mendapat pesan validasi yang jelas sebelum memanggil Python.
4. Tambahkan test controller untuk guard spreadsheet tanpa tabel.

## Rencana Verifikasi
- `cd laravel && php artisan test --filter='DocumentExportTest|DocumentViewerLivewireTest|GoogleDriveUploadTest'`
- `cd python-ai && source venv/bin/activate && pytest tests/test_document_export.py tests/test_table_extraction.py`

## Risiko
- Perubahan harus tetap menerima payload lama jika suatu test/service pernah mengembalikan list tabel langsung.
- Guard HTML tabel harus ringan dan tidak mengubah export PDF/DOCX.
