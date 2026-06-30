# Contributing to ISTA AI

Terima kasih sudah membantu memperbaiki ISTA AI. Project ini dikelola dengan
perubahan kecil, scoped, dan diverifikasi sesuai area yang disentuh.

## Prinsip Kerja

- Jaga perubahan tetap fokus dan mudah direview.
- Jangan commit secret, `.env` asli, dokumen produksi, dump database, private key, atau data Chroma.
- Ikuti pola yang sudah ada di Laravel dan Python.
- Untuk behavior change, tambahkan atau jalankan test yang relevan.
- Update dokumentasi bila setup, deployment, security, flow user/admin, atau kontrak lintas-service berubah.
- Untuk perubahan kompleks, tulis plan singkat di percakapan/PR description. Repo ini tidak memakai folder `issue/`.

## Area Project

- `laravel/` - web app, Livewire UI, auth, admin, queue, upload, memo, OnlyOffice callback.
- `python-ai/` - FastAPI service, chat, RAG, embeddings, export, memo, Prompy Studio.
- `docs/` - handover, deployment, operasi, security/privacy.
- `deploy/` dan Docker Compose - template deployment.
- `benchmarks/` - benchmark manual provider/RAG.

## Verifikasi

Jalankan check dekat dengan area yang diubah.

Laravel:

```bash
cd laravel && php artisan test
```

Python:

```bash
cd python-ai && source venv/bin/activate && pytest
```

Frontend build:

```bash
cd laravel && npm run build
```

General:

```bash
git diff --check
```

Jika perubahan hanya dokumentasi, jelaskan check dokumentasi yang dijalankan.

## Checklist Sebelum Commit/PR

- Scope jelas dan terbatas.
- Tidak ada secret/data privat.
- Test atau check relevan sudah dijalankan.
- Dokumentasi terkait diperbarui.
- Perubahan security-sensitive menjelaskan risiko dan verifikasi.

## Security

Jangan membuka vulnerability atau data privat di public issue. Ikuti
[SECURITY.md](SECURITY.md).
