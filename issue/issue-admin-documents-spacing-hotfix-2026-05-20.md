# Issue: Admin Documents insight-to-table spacing hotfix

## Tujuan
- Menambahkan jarak visual antara panel Distribusi Tipe / Status Pipeline dan tabel Dokumen Terbaru.
- Menjaga perubahan tetap kecil agar aman untuk merge dan deploy cepat.

## Scope
- CSS admin documents.

## Di luar scope
- Mengubah struktur data dokumen, pipeline ingest, filter, pagination, atau modal detail.
- Redesign ulang halaman admin documents.

## Risiko
- Jarak berlebihan pada viewport mobile jika spacing tidak mengikuti ritme layout yang sudah ada.

## Implementasi
1. Tambahkan margin top khusus untuk `admin-documents-table-panel`.
2. Jalankan build frontend dan test Laravel admin documents yang relevan.
3. Commit, push, merge ke `main`, deploy production, lalu smoke check.

## Verifikasi
- `npm run build`
- Targeted Laravel admin monitoring/document test
- `git diff --check`
- Production smoke check `/up`

## Hasil Verifikasi Lokal
- `npm run build` lulus.
- `php artisan test --filter=AdminMonitoringDashboardTest` lulus.
- `php -d memory_limit=-1 vendor/bin/phpunit` lulus: 555 tests, 2416 assertions.
- `cd python-ai && source venv/bin/activate && pytest` lulus: 367 tests.
- `npm audit --audit-level=high` lulus: 0 vulnerabilities.
- `git diff --check` lulus.
