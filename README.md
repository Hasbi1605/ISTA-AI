# ISTA AI - Architecture Overview

Ini adalah repositori inti untuk subsistem kognitif **ISTA AI**, yang berfungsi ganda sebagai *mesin obrolan pintar (Chat)* dan *mesin pencari dokumen (RAG - Retrieval Augmented Generation)*.

Arsitektur saat ini menggunakan **Laravel-only runtime** dengan AI SDK untuk semua capability AI (chat, RAG, document processing, summarization).

## 🚀 Laravel-Only Runtime (April 2026)

**Status:** ✅ Implemented

Sistem telah bermigrasi sepenuhnya dari arsitektur hybrid (Laravel + Python AI) ke Laravel-only:

- **Chat:** Menggunakan Laravel AI SDK dengan OpenAI GPT models
- **Document Processing:** Provider-managed file search dan vector storage
- **Summarization:** Laravel AI SDK agents
- **Web Search:** DuckDuckGo integration via Laravel AI SDK

### Quick Start

```bash
# Start services (MySQL, Redis, Laravel)
docker-compose up -d

# Run migrations
docker-compose exec laravel php artisan migrate

# Access Laravel
open http://localhost:8000
```

### Re-ingest Dokumen Lama

Untuk dokumen yang di-upload sebelum migrasi, gunakan command:

```bash
cd laravel
php artisan documents:reindex              # Re-index semua dokumen
php artisan documents:reindex --dry-run    # Preview tanpa memproses
php artisan documents:reindex --user=1     # Hanya untuk user tertentu
php artisan documents:reindex --limit=10   # Batasi jumlah dokumen
```

### Konfigurasi Runtime

Di `.env`:

```env
# AI Runtime Configuration (default: laravel)
AI_RUNTIME_CHAT=laravel
AI_RUNTIME_DOCUMENT_RETRIEVAL=laravel
AI_RUNTIME_DOCUMENT_PROCESS=laravel
AI_RUNTIME_DOCUMENT_SUMMARIZE=laravel
AI_RUNTIME_DOCUMENT_DELETE=laravel

# Laravel AI SDK Configuration
OPENAI_API_KEY=sk-...
AI_MODEL=gpt-4o-mini
AI_WEB_SEARCH_ENABLED=true
AI_DOCUMENT_PROCESS_ENABLED=true
AI_DOCUMENT_SUMMARIZE_ENABLED=true
AI_DOCUMENT_RETRIEVAL_ENABLED=true
```

### Rollback ke Python (Emergency)

Jika diperlukan rollback emergency (tidak direkomendasikan untuk produksi):

```env
AI_RUNTIME_CHAT=python
AI_RUNTIME_DOCUMENT_RETRIEVAL=python
```

## 🌟 High-Level Flow Architecture

### 1. Chat Generation

 Menggunakan Laravel AI SDK agents dengan OpenAI GPT models. Web search menggunakan DuckDuckGo tool.

### 2. Document Processing & RAG

Provider-managed file search menggunakan OpenAI file_search tool. Dokumen di-upload ke OpenAI dan di-index secara otomatis.

### 3. Web Search

DuckDuckGo integration via Laravel AI SDK untuk pencarian real-time.

## Development

```bash
# Run tests
cd laravel && php artisan test

# Run Python tests (legacy reference)
cd python-ai && source venv/bin/activate && pytest
```

## Struktur Folder

```
laravel/          # Main application (Laravel + AI SDK)
python-ai/        # Legacy Python service (deprecated, untuk referensi saja)
docker-compose.yml
```