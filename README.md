# ISTA AI

ISTA AI adalah sistem asisten AI internal untuk pegawai Istana Kepresidenan
Yogyakarta. Sistem ini membantu pengguna bertanya atas dokumen privat,
mengunggah dokumen untuk RAG, membuat draf memorandum/dokumen dinas, mengedit
DOCX melalui OnlyOffice, membuat paket prompt melalui Prompy Studio, dan
memantau operasional lewat panel admin.

Repo ini berisi source code, template konfigurasi, dan dokumentasi handover.
Jangan commit dokumen produksi, dump database, file `.env` asli, API key,
service-account JSON, private key, atau data Chroma.

## Status Saat Ini

- Production-deployed dengan akses terbatas.
- Stack utama: Laravel 13 + Livewire/Volt + Blade + Alpine.js, FastAPI, MySQL,
  Redis, ChromaDB, OnlyOffice, Docker Compose.
- Chat streaming memakai Server-Sent Events (SSE), bukan WebSocket/Echo.
- Google Drive import/export dan generator PPTX internal sudah dihapus.
- Prompy Studio aktif sebagai generator paket prompt untuk platform eksternal
  seperti ChatGPT Images/GPT Image, Gemini, Canva AI, dan Universal. ISTA AI
  tidak memanggil platform target tersebut dan tidak menghasilkan gambar/video.

## Fitur Utama

- Chat AI streaming dengan riwayat percakapan.
- Upload dokumen PDF/DOCX/XLSX/CSV, ekstraksi teks, chunking, embedding, dan
  pencarian RAG berbasis ChromaDB.
- Web search augmentation melalui LangSearch untuk pertanyaan yang perlu
  konteks eksternal terbaru.
- Knowledge base internal yang dikelola admin dan dapat dipakai semua user.
- Generate memo DOCX, versioning, edit via OnlyOffice, download, dan export PDF.
- Prompy Studio untuk menyusun, merevisi, dan menyimpan paket prompt.
- Admin dashboard untuk usage, error, dokumen, user, knowledge, dan admin account.
- Keamanan admin: login terpisah, lockout progresif, forced password change,
  2FA TOTP wajib, trusted device, absolute session lifetime, dan audit log.

## Arsitektur Ringkas

```text
Browser
  |
  | Livewire + Alpine + SSE EventSource
  v
laravel/
  - UI, auth, authorization, upload, metadata, admin, queue, OnlyOffice callback
  - AIService menghubungkan Laravel ke python-ai dengan bearer token
  |
  | HTTP internal + bearer token
  v
python-ai/
  - FastAPI chat service :8001
  - FastAPI document service :8002
  - RAG, embeddings, summarization, memo, export, prompt generation
  |
  v
MySQL + Redis + ChromaDB + OnlyOffice + provider AI/search eksternal
```

Konfigurasi AI non-secret ada di `python-ai/config/ai_config.yaml`. Secret dan
nilai runtime berada di `.env` lokal/server, bukan di repo.

## Dokumentasi Penting

Mulai dari [docs/README.md](docs/README.md). Untuk handover mentor, urutan yang
paling enak dibaca:

- [docs/00-HANDOVER.md](docs/00-HANDOVER.md) - ringkasan handover untuk mentor.
- [docs/01-PRODUCT-OVERVIEW.md](docs/01-PRODUCT-OVERVIEW.md) - fitur dan batas sistem.
- [docs/02-STAFF-USER-GUIDE.md](docs/02-STAFF-USER-GUIDE.md) - panduan pengguna biasa.
- [docs/03-ADMIN-USER-GUIDE.md](docs/03-ADMIN-USER-GUIDE.md) - panduan admin/operator.
- [docs/04-ARCHITECTURE.md](docs/04-ARCHITECTURE.md) - arsitektur teknis ringkas.
- [docs/05-ENVIRONMENT.md](docs/05-ENVIRONMENT.md) - environment variable penting.
- [docs/06-DEPLOYMENT.md](docs/06-DEPLOYMENT.md) - setup server dan CI/CD.
- [docs/07-SERVER-DOMAIN-MIGRATION.md](docs/07-SERVER-DOMAIN-MIGRATION.md) - checklist pindah server/domain.
- [docs/08-OPERATIONS-RUNBOOK.md](docs/08-OPERATIONS-RUNBOOK.md) - runbook operasional.
- [docs/09-SECURITY-PRIVACY.md](docs/09-SECURITY-PRIVACY.md) - keamanan dan alur data.
- [docs/10-DATABASE-SUMMARY.md](docs/10-DATABASE-SUMMARY.md) - ringkasan tabel penting.

Dokumen teknis yang lebih detail tetap tersedia di
[docs/CODEBASE-CONTEXT.md](docs/CODEBASE-CONTEXT.md),
[PRD-ISTA-AI.md](PRD-ISTA-AI.md), dan panduan production lama di folder `docs/`.

## Local Development

Kebutuhan utama:

- PHP 8.3+ (Docker/CI memakai PHP 8.4)
- Composer 2
- Node.js 22 + npm
- Python 3.11
- MySQL, Redis, ChromaDB, dan OnlyOffice, atau Docker Compose

Laravel:

```bash
cd laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

Python AI:

```bash
cd python-ai
source venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 8001
```

Document service:

```bash
cd python-ai
source venv/bin/activate
uvicorn app.documents_api:app --host 127.0.0.1 --port 8002
```

## Testing

Laravel:

```bash
cd laravel && php artisan test
```

Python:

```bash
cd python-ai && source venv/bin/activate && pytest
```

Build frontend:

```bash
cd laravel && npm run build
```

Untuk perubahan dokumentasi saja, minimal jalankan:

```bash
git diff --check
```

## Deployment

Production saat ini memakai Docker Compose dengan service Laravel, Python chat,
Python document service, MySQL, Redis, ChromaDB, OnlyOffice, Horizon, scheduler,
dan Caddy. Push ke `main` menjalankan GitHub Actions CI/CD dan deploy production
jika secret deploy tersedia.

Panduan utama:

- [docs/06-DEPLOYMENT.md](docs/06-DEPLOYMENT.md)
- [docs/github-actions-cicd.md](docs/github-actions-cicd.md)
- [docs/deploy-digitalocean.md](docs/deploy-digitalocean.md)
- [docs/production-config-guide.md](docs/production-config-guide.md)

## Keamanan dan Privasi

Sebelum memakai data nyata, baca:

- [SECURITY.md](SECURITY.md)
- [docs/09-SECURITY-PRIVACY.md](docs/09-SECURITY-PRIVACY.md)
- [docs/data-flow-privacy.md](docs/data-flow-privacy.md)

Poin penting:

- `AI_SERVICE_TOKEN` wajib sama antara Laravel dan Python, dan tidak boleh memakai placeholder.
- Provider AI/search eksternal dapat menerima prompt, chat history, chunk dokumen,
  embedding input, reference image Prompy, atau query web sesuai fitur yang dipakai.
- OnlyOffice berjalan self-hosted, tetapi tetap memakai signed URL + JWT.
- Dokumen privat, database, Chroma data, dan env production tidak boleh masuk Git.

## Contributing

Lihat [CONTRIBUTING.md](CONTRIBUTING.md). Perubahan harus scoped, tidak membawa
secret/data privat, dan menyertakan verifikasi yang relevan.

## License

ISTA AI dirilis dengan lisensi MIT. Lihat [LICENSE](LICENSE).
