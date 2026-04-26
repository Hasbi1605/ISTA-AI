# Issue #93: AI Parity - Summarization Dokumen Besar dan Source/Model Metadata Parity

## Parent Blueprint
Issue #84: Blueprint Parity Python AI ke Laravel-only

## Tujuan
Mempertahankan summarization dokumen besar dan metadata UI yang sebelumnya dibantu Python.

## Scope
- Summarization berbasis chunk/batch untuk dokumen besar
- Fallback model saat ringkasan terlalu panjang atau provider gagal
- Pertahankan source metadata dan model metadata
- Kontrak rendering `[SOURCES:...]` dan `[MODEL:...]` tetap stabil
- Pastikan hasil ringkasan grounded pada dokumen

## Acceptance Criteria
- Dokumen besar bisa diringkas tanpa Python service
- Ringkasan tetap grounded dan tidak kehilangan source utama
- Source/model metadata tersedia untuk UI/log
- Ada test dokumen besar, provider fallback, dan source rendering

## Implementasi

### 1. Chunk-Based Summarization (LaravelDocumentService)
- Implementasi batch processing untuk dokumen besar (>8000 tokens)
- Hierarchical summarization: ringkasan per batch lalu gabung
- Token-aware chunking dengan konfigurasi max_tokens

### 2. Provider Fallback untuk Summarization
- Cascade provider untuk summarization (mirip chat cascade)
- Fallback ke model lain bila provider gagal
- Logging metadata provider yang digunakan

### 3. Source/Model Metadata
- Sertakan source document dalam response
- Sertakan model metadata dalam response
- Rendering `[SOURCES:...]` dan `[MODEL:...]`

### 4. Test
- Test dokumen besar dengan batch processing
- Test provider fallback
- Test source rendering

## File yang Diubah
- `laravel/app/Services/Document/LaravelDocumentService.php`
- `laravel/tests/Unit/Services/Document/LaravelDocumentServiceTest.php`