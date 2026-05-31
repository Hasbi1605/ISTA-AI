# ISTA AI

ISTA AI is an open-source reference implementation for a private-document AI assistant. It combines a Laravel web application with Python AI services for chat, document ingestion, retrieval-augmented generation (RAG), document export, and operational admin workflows.

The project is designed for teams that need a self-hosted assistant over private documents while keeping storage, access control, and document processing explicit. This public repository contains source code, configuration templates, and documentation only. Do not commit production documents, database dumps, API keys, service account files, or Chroma vector data.

## What It Does

- Chat assistant with streaming responses and conversation history.
- Document upload, extraction, chunking, embedding, and retrieval.
- Token-aware chunking for long PDFs and office documents.
- ChromaDB-backed vector search with per-user document metadata.
- Web search augmentation for questions that need current external context.
- Memo/document generation and export workflows.
- Google Drive import/export integration for controlled office document flows.
- OnlyOffice integration for editing generated DOCX files through signed URLs.
- Admin dashboards for usage, documents, users, errors, and knowledge management.

## Deployment Status

ISTA AI is actively deployed in a production environment. Access is restricted because the system is designed for private-document workflows; production data, user accounts, and operational credentials are not part of this repository.

## Architecture

ISTA AI runs as a hybrid stack:

- `laravel/`: web UI, authentication, authorization, uploads, document metadata, admin UI, queues, and OnlyOffice callbacks.
- `python-ai/`: chat service, document processing service, RAG pipeline, model routing, embeddings, summarization, and export helpers.
- `docs/`: production, deployment, testing, privacy, and maintenance notes.
- `deploy/`: deployment templates such as Caddy and environment examples.
- `benchmarks/`: manual benchmark scripts for provider and RAG checks.
- `issue/`: implementation plans and review notes used by the maintainer workflow.

Core data stores and runtime services:

- MySQL for application data.
- Redis for queue/cache operations.
- ChromaDB for local vector indexes.
- OnlyOffice Document Server for DOCX editing.
- External AI/search providers configured through environment variables and `python-ai/config/ai_config.yaml`.

## Repository Layout

```text
.
├── laravel/                  # Laravel app, routes, controllers, jobs, tests
├── python-ai/                # FastAPI AI services, RAG, prompts, tests
├── docs/                     # Deployment, privacy, testing, maintenance docs
├── deploy/                   # Production deployment templates
├── benchmarks/               # Manual provider/RAG benchmark utilities
├── issue/                    # Planning and implementation notes
├── docker-compose.yml        # Local multi-service stack
├── docker-compose.production.yml
└── .env.droplet.example      # Redacted production env key template
```

## Security and Privacy

Before running ISTA AI with real data, read [docs/data-flow-privacy.md](docs/data-flow-privacy.md). The system can send selected prompts, chat history, document chunks, and search queries to configured external providers. Production usage needs a clear data classification policy.

Important rules:

- Keep real `.env` files local only.
- Keep `laravel/database/*.sqlite`, database dumps, Chroma data, service account JSON files, and private keys out of git.
- Rotate secrets immediately if they were ever shared outside a trusted local machine.
- Use strong unique values for `AI_SERVICE_TOKEN`, OnlyOffice secrets, database passwords, OAuth secrets, and provider API keys.
- Use signed URLs, private disks, and server-side authorization for document access.

Security reporting instructions are in [SECURITY.md](SECURITY.md).

## Environment Files

Tracked examples intentionally contain placeholders only:

- `.env.droplet.example`
- `deploy/digitalocean.env.example`
- `laravel/.env.example`
- `python-ai/.env.example`

Create local env files from those templates and replace placeholders with real values on your own machine or deployment platform. Real env files are ignored by git.

## Local Development

Requirements depend on the area you want to run:

- PHP 8.2+
- Composer
- Node.js and npm
- Python 3.13-compatible environment for the current service stack
- MySQL, Redis, and ChromaDB, or Docker Compose

Typical Laravel setup:

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

Typical Python setup:

```bash
cd python-ai
source venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 8001
```

For production and Docker-oriented setup, use the guides in `docs/` and `deploy/` instead of copying local development defaults.

## Testing

Laravel:

```bash
cd laravel && php artisan test
```

Python:

```bash
cd python-ai && source venv/bin/activate && pytest
```

See [docs/testing-guide.md](docs/testing-guide.md) for the maintainer verification workflow.

## Contributing

Contributions are welcome when they keep the system safer, easier to run, and easier to review. Start with [CONTRIBUTING.md](CONTRIBUTING.md), keep changes scoped, and include relevant verification results.

Good first contribution areas:

- Documentation improvements.
- Test coverage for RAG, document export, auth, and admin flows.
- Safer defaults for deployment templates.
- Observability, logging, and maintenance improvements.
- Accessibility and responsive UI fixes.

## License

ISTA AI is released under the MIT License. See [LICENSE](LICENSE).
