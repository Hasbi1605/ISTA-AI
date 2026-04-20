"""
RAG Service - Facade untuk modularized RAG components.

Semua fungsi di-re-export dari submodule untuk backward compatibility.
Caller yang ada tidak perlu berubah.
"""

from app.services.rag_config import (
    CHROMA_PATH,
    TOKEN_CHUNK_SIZE,
    TOKEN_CHUNK_OVERLAP,
    AGGRESSIVE_BATCH_SIZE,
    BATCH_DELAY_SECONDS,
    MAX_TOKENS_PER_BATCH,
    MAX_EMBEDDING_DIM,
    EMBEDDING_MODELS,
    EXPLICIT_WEB_PATTERNS,
    REALTIME_HIGH_PATTERNS,
    REALTIME_MEDIUM_KEYWORDS,
    SCORE_QUERY_KEYWORDS,
    CANONICAL_TEAM_GROUPS,
)

from app.services.rag_embeddings import (
    count_tokens,
    get_embeddings_with_fallback,
    TIKTOKEN_ENCODER,
)

from app.services.rag_ingest import (
    process_document,
    delete_document_vectors,
)

from app.services.rag_retrieval import (
    search_relevant_chunks,
    build_rag_prompt,
)

from app.services.rag_summarization import (
    get_document_chunks_for_summarization,
)

from app.services.rag_policy import (
    detect_explicit_web_request,
    detect_realtime_intent_level,
    should_use_web_search,
    get_context_for_query,
    get_rag_context_for_prompt,
    extract_match_score_signal,
)

from app.services.rag_hybrid import (
    _should_use_hyde,
    _generate_hyde_query,
    _bm25_rank_docs,
    _rrf_merge_docs,
    _exclude_parent_search_results,
    _exclude_parent_corpus,
    _resolve_pdr_parents,
)