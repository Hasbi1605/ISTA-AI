# Issue #99 - Config Parity: ai_config.yaml → Laravel Config

## Tujuan
Memigrasikan konfigurasi runtime dari `python-ai/config/ai_config.yaml` ke Laravel-only. Fokus pada menambahkan section yang belum ada di Laravel.

## Audit Mapping

| Section | Status Laravel | Catatan |
|---------|---------------|---------|
| `global` | ✓ Ada (ai.php) | Sudah ada |
| `lanes.chat` | ✓ Ada (cascade) | Sudah ada |
| `lanes.reasoning` | **TIDAK ADA** | Perlu ditambahkan |
| `lanes.embedding` | ✓ Ada (embedding_cascade) | Sudah ada |
| `retrieval.search` | ✓ Ada (langsearch) | Sudah ada |
| `retrieval.semantic_rerank` | ✓ Ada (langsearch) | Sudah ada |
| `retrieval.hybrid_search` | ✓ Ada (rag.hybrid) | Sudah ada |
| `retrieval.hyde` | ✓ Ada (rag.hyde) | Sudah ada |
| `chunking` | ✓ Ada (rag) | Sudah ada |
| `chunking.pdr` | ✓ Ada (rag.pdr) | Sudah ada |
| `integrations.smtp_gmail` | ✓ Ada (mail.php) | Sudah ada |
| `prompts.system` | **TIDAK ADA** | Perlu ditambahkan |
| `prompts.web_search` | **TIDAK ADA** | Perlu ditambahkan |
| `prompts.summarization` | **TIDAK ADA** | Perlu ditambahkan |
| `prompts.fallback` | **TIDAK ADA** | Perlu ditambahkan |

## Rencana Implementasi

### 1. Tambahkan lanes.reasoning
Struktur mirip cascade tapi untuk reasoning model:
- enabled (env)
- model (env, null = disabled)
- cascade array (node list)

### 2. Tambahkan prompts
- `prompts.system.default` - prompt sistem utama
- `prompts.web_search.context` - konteks web search
- `prompts.web_search.assertive_instruction` - instruksi tambahan
- `prompts.summarization.single` - ringkasan satu dokumen
- `prompts.summarization.partial` - ringkasan bagian dokumen
- `prompts.summarization.final` - ringkasan akhir
- `prompts.fallback.document_not_found` - fallback saat dokumen tidak ditemukan
- `prompts.fallback.document_error` - fallback saat error dokumen

## Perubahan File
1. `/Users/macbookair/Magang-Istana/laravel/config/ai.php` - tambahkan section baru

## Status: COMPLETED ✓

### Perubahan yang Dilakukan

**File**: `/laravel/config/ai.php`

1. **lanes.reasoning** (baris 28-47)
   - `enabled` - env AI_REASONING_ENABLED (default: false)
   - `model` - env AI_REASONING_MODEL (default: null = disabled)
   - `cascade` - array DeepSeek R1 Primary & Backup nodes

2. **prompts.system** (baris 248-264)
   - Prompt sistem utama ISTA AI

3. **prompts.web_search** (baris 293-316)
   - `context` - konteks web search dengan {current_date}, {results}
   - `assertive_instruction` - instruksi tambahan untuk real-time info

4. **prompts.summarization** (baris 317-392)
   - `single` - ringkasan satu dokumen lengkap
   - `partial` - ringkasan bagian dokumen ({part_number}, {total_parts}, {batch})
   - `final` - ringkasan akhir ({combined_summaries})

5. **prompts.fallback** (baris 394-397)
   - `document_not_found` - fallback saat dokumen tidak ditemukan
   - `document_error` - fallback saat error membaca dokumen

### Verifikasi
- Laravel tests: 237 passed ✓
- No breaking changes

### Risiko
- Tidak ada breaking changes - hanya menambahkan config baru
- Konvensi mengikuti style Laravel yang sudah ada