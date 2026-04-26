# Issue #89: HyDE, Query Expansion, dan Document-vs-Web Policy Parity

## Tujuan
Mempertahankan kualitas retrieval konseptual dan policy dokumen-vs-web seperti Python.

## Scope
1. Implementasikan HyDE/query expansion Laravel-only
2. Pertahankan policy document-first, explicit web, realtime auto
3. Pastikan dokumen aktif tidak otomatis dikalahkan web search
4. Tambahkan test untuk pertanyaan dokumen, realtime, no-answer

## Analisis Codebase Saat Ini

### Sudah Ada:
- `HybridRetrievalService.php` - hybrid search (vector + BM25), PDR
- `DocumentPolicyService.php` - document-vs-web policy, explicit web detection, realtime intent
- `LaravelChatService.php` - policy integration, no-answer handling
- `config/ai.php` - RAG config, hybrid config, prompts

### Belum Ada:
- HyDE / Query Expansion service
- HyDE configuration di config/ai.php
- Test untuk acceptance criteria

## Rencana Implementasi

### Step 1: Tambah Konfigurasi HyDE
File: `laravel/config/ai.php`
- Tambah section `hyde` dengan enabled, mode, timeout, max_tokens

### Step 2: Buat HyDE Query Expansion Service
File: `laravel/app/Services/Document/HydeQueryExpansionService.php`
- `shouldUseHyde()` - cek apakah query butuh enhancement (konseptual)
- `generateEnhancedQuery()` - generate hypothetical answer untuk query enhancement
- Pattern detection dari Python: skip patterns & use patterns

### Step 3: Integrasi HyDE ke HybridRetrievalService
- Override `search()` untuk gunakan HyDE query jika mode = 'always' atau 'smart'
- Maintain user isolation di semua query

### Step 4: Tambah/Update Test
- Test HyDE detection (conceptual query)
- Test HyDE skip (summarization, direct commands)
- Test document-first policy dengan explicit web
- Test realtime auto detection
- Test no-answer handling (tidak halusinasi)

## Acceptance Criteria Verification

| Skenario | Test |
|----------|------|
| Pertanyaan konseptual pada dokumen | `test_hyde_enhances_conceptual_queries` |
| Explicit web saat dokumen aktif | `test_explicit_web_works_with_documents_active` |
| Realtime auto hanya sesuai skenario | `test_realtime_auto_only_on_relevant_queries` |
| No-answer tidak memaksa halusinasi | `test_no_answer_does_not_hallucinate` |

## Risiko
- HyDE generation menambah latency (timeout handling perlu robust)
- Fallback ke query asli jika HyDE gagal