# Issue #56: Refactor Bertahap - Modularisasi RAG Service Python

## Tujuan
Memecah `rag_service.py` (~1956 baris) menjadi modul-modul lebih kecil dengan tanggung jawab fokus, sambil menjaga backward compatibility.

## Boundary Modul yang Direncanakan

### 1. `rag_config.py` - Konfigurasi & Constants
- Chunking config dari YAML
- Embedding models & fallback constants
- Pattern detection constants (WEB_PATTERNS, REALTIME_PATTERNS, dll)

### 2. `rag_embeddings.py` - Embedding Management
- count_tokens()
- EMBEDDING_MODELS
- get_embeddings_with_fallback()

### 3. `rag_ingest.py` - Ingest Pipeline
- process_document()
- delete_document_vectors()

### 4. `rag_retrieval.py` - Retrieval Pipeline
- search_relevant_chunks()
- build_rag_prompt()

### 5. `rag_summarization.py` - Summarization
- get_document_chunks_for_summarization()

### 6. `rag_hybrid.py` - Hybrid Search Helpers
- HyDE, BM25, RRF functions
- PDR resolve parents

### 7. `rag_policy.py` - Web Search Policy
- detect_explicit_web_request()
- detect_realtime_intent_level()
- should_use_web_search()
- get_context_for_query()
- Query helper utilities

### 8. `rag_service.py` - Facade
- Import semua fungsi dari submodule
- Re-export untuk backward compatibility

## Tahap Implementasi

### Tahap 1: Ekstrak Low-Risk Helpers
- **Target**: `rag_config.py` + `rag_embeddings.py`
- **Risiko**: Rendah - mostly constants dan pure functions
- **Verifikasi**: Test existing tetap lulus

### Tahap 2: Ekstrak Policy & Context
- **Target**: `rag_policy.py`
- **Risiko**: Rendah - fungsi independientes
- **Verifikasi**: Test web detection tetap lulus

### Tahap 3: Ekstrak Hybrid Search
- **Target**: `rag_hybrid.py`
- **Risiko**: Sedang - fungsi helper retrieval
- **Verifikasi**: Retrieval test tetap berfungsi

### Tahap 4: Ekstrak Ingest
- **Target**: `rag_ingest.py`
- **Risiko**: Tinggi - core logic
- **Verifikasi**: Document processing test

### Tahap 5: Ekstrak Retrieval & Summarization
- **Target**: `rag_retrieval.py` + `rag_summarization.py`
- **Risiko**: Tinggi - main search functions
- **Verifikasi**: Full search flow test

### Tahap 6: Setup Facade & Cleanup
- **Target**: `rag_service.py` sebagai facade
- **Risiko**: Rendah - re-export saja
- **Verifikasi**: Full test suite

## Files Terkait Setelah Refactor
```
python-ai/app/services/
├── __init__.py
├── rag_service.py          #Facade (re-export)
├── rag_config.py          #Constants & config
├── rag_embeddings.py     #Embedding management
├── rag_ingest.py        #Document processing
├── rag_retrieval.py     #Search & RAG prompt
├── rag_summarization.py #Summarization
├── rag_hybrid.py        #Hybrid search helpers
├── rag_policy.py        #Web search policy
└── langsearch_service.py
```

## Backward Compatibility
Semua fungsi yang saat ini di-export harus tetap tersedia melalui:
- Import直接从 submodule
- Re-export di `rag_service.py` facade

## Test Strategy
- Test existing tetap berjalan tanpa modifikasi
- Tambah test baru untuk setiap submodule
- Integration test untuk full flow

## Kriteria Selesai
- [x] rag_service.py tidak lagi satu file besar (>2000 baris)
- [x] Caller existing (main.py) tetap bekerja
- [x] Semua test lulus
- [x] Backward compatibility terjaga

## Hasil Implementasi

### Struktur File Baru
```
python-ai/app/services/
├── rag_service.py          #Facade: 59 baris (re-export)
├── rag_config.py          #Constants: 142 baris
├── rag_embeddings.py    #Embeddings: 61 baris
├── rag_ingest.py         #Ingest: 399 baris
├── rag_retrieval.py     #Retrieval: 333 baris
├── rag_summarization.py#Summarization: 80 baris
├── rag_hybrid.py        #Hybrid search: 312 baris
├── rag_policy.py       #Policy: 367 baris
```

### Total
- Sebelum: 1956 baris (single file)
- Sesudah: 1753 baris (8 modul)

### Test Results
- 37 tests PASSED
- Backward compatibility: TERJAGA