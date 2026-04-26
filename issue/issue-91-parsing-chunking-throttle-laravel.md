# Issue #91 - Parsing, Token-aware Chunking, dan Ingest Throttling Laravel

## Tujuan
Memindahkan ingest dokumen Python ke Laravel-only tanpa kehilangan kontrol chunk, format dokumen, dan rate-limit guard.

## Scope
1. Parsing PDF, DOCX, XLSX, CSV di Laravel (tanpa Python service)
2. Token-aware chunking dengan overlap setara Python  
3. Batch ingest dan throttling untuk mencegah rate limit embedding/provider
4. Persist chunk metadata cukup untuk PDR, source rendering, delete cleanup
5. Job queue Laravel sebagai runtime ingest utama

## Acceptance Criteria
- Format PDF/DOCX/XLSX/CSV bisa diproses tanpa Python service
- Chunk size/overlap terukur dan tidak merusak konteks
- Ingest dokumen besar tidak menabrak rate-limit tanpa retry/backoff
- Metadata chunk cukup untuk retrieval dan source

## Referensi Python
- Parsing: PyPDFLoader → UnstructuredFileLoader fallback
- Chunking: RecursiveCharacterTextSplitter dengan tiktoken count
- PDR: parent 1500 token, child 256 token
- Batching: max 100 chunks OR 40,000 tokens per batch
- Batch delay: 0.8 seconds + exponential backoff on 429

## Implementasi Plan

### Phase 1: Document Parsers (PHP Libraries)
- `DocumentParserInterface` - contract untuk semua parser
- `PdfParser` - smalot/pdfparser untuk PDF
- `DocxParser` - phpoffice/phpword untuk DOCX 
- `SpreadsheetParser` - phpoffice/phpspreadsheet untuk XLSX/CSV
- Composer dependencies + config

### Phase 2: Token-aware Chunking Service
- `TokenCounter` - estimate token menggunakan karakter/4 (Bahasa Indonesia)
- `TextChunker` - RecursiveCharacterTextSplitter equivalent PHP
- `PdrChunker` - parent/child chunking dengan metadata
- Konfigurasi dari config/ai.php

### Phase 3: Ingest Job dengan Throttling
- `IngestDocumentJob` - job baru untuk proses dokumen lengkap
- Throttling service - batch delay, rate-limit guard
- Retry with exponential backoff (429 handling)
- Chunk metadata persistence untuk PDR

### Phase 4: Modify Existing ProcessDocument Job
- Update `ProcessDocument` untuk gunakan Laravel-only pipeline
- Fallback to Python jika parser gagal

## File Baru
- `app/Services/Document/Parsing/DocumentParserInterface.php`
- `app/Services/Document/Parsing/PdfParser.php`
- `app/Services/Document/Parsing/DocxParser.php`
- `app/Services/Document/Parsing/SpreadsheetParser.php`
- `app/Services/Document/Chunking/TokenCounter.php`
- `app/Services/Document/Chunking/TextChunker.php`
- `app/Services/Document/Chunking/PdrChunker.php`
- `app/Services/Document/IngestThrottleService.php`
- `app/Jobs/IngestDocumentJob.php`

## Perubahan File
- `app/Jobs/ProcessDocument.php` - update handle() ke pipeline Laravel
- `config/ai.php` - tambah konfigurasi parsing/chunking
- `composer.json` - tambah dependencies

## Test Plan
- Test parser untuk PDF, DOCX, XLSX, CSV (unit)
- Test token counting accuracy
- Test chunking dengan overlap preservation
- Test batch throttling dan retry logic
- Test PDR metadata persistence

## Risiko
1. PHP libraries parsing quality mungkin berbeda dari Python
2. Token estimation di PHP mungkin tidak seakurat tiktoken
3. Performa ingest dokumen besar perlu benchmark
4. Fallback ke Python service harus dipertahankan untuk backward compatibility