import os
import logging
from typing import List, Tuple, Dict
from collections import Counter

from langchain_chroma import Chroma
from langchain_core.documents import Document

from app.services.rag_config import CHROMA_PATH, VECTOR_COLLECTION_NAME
from app.services.rag_embeddings import get_embeddings_with_fallback
from app.services.rag_hybrid import (
    _should_use_hyde,
    _generate_hyde_query,
    _bm25_rank_docs,
    _rrf_merge_docs,
    _exclude_parent_search_results,
    _exclude_parent_corpus,
    _resolve_pdr_parents,
)
from app.config_loader import get_rag_prompt
from app.services.rag_policy import get_langsearch_service

logger = logging.getLogger(__name__)


def _get_child_corpus(vectorstore, where: Dict, limit: int) -> Tuple[List[str], List[dict]]:
    raw = vectorstore.get(
        where=where,
        include=['documents', 'metadatas'],
        limit=limit,
    )
    stored_texts = raw.get('documents', []) or []
    stored_metas = raw.get('metadatas', []) or []
    return _exclude_parent_corpus(stored_texts, stored_metas)


def _bm25_child_fallback(
    query: str,
    stored_texts: List[str],
    stored_metas: List[dict],
    top_k: int,
) -> List[Tuple]:
    if not stored_texts:
        return []

    ranked = _bm25_rank_docs(query, stored_texts, top_k=top_k)
    fallback_docs: List[Tuple] = []
    for idx, score in ranked:
        if idx >= len(stored_texts):
            continue
        fallback_docs.append((
            Document(
                page_content=stored_texts[idx],
                metadata=stored_metas[idx] if idx < len(stored_metas) else {},
            ),
            float(score),
        ))
    return fallback_docs


def search_relevant_chunks(query: str, filenames: List[str] = None, top_k: int = 5, user_id: str = None) -> Tuple[List[Dict], bool]:
    try:
        embeddings, provider_name, _ = get_embeddings_with_fallback()

        if embeddings is None:
            return [], False

        vectorstore = Chroma(
            collection_name=VECTOR_COLLECTION_NAME,
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )

        user_filter = {"user_id": str(user_id)}

        try:
            from app.config_loader import (
                get_rag_doc_candidates, get_rag_top_n,
                get_hybrid_search_config, get_hyde_config,
            )
            doc_candidates  = get_rag_doc_candidates()
            doc_top_n       = get_rag_top_n()
            hybrid_cfg      = get_hybrid_search_config()
            hyde_cfg        = get_hyde_config()
        except Exception:
            doc_candidates  = int(os.getenv("LANGSEARCH_RERANK_DOC_CANDIDATES", "25"))
            doc_top_n       = top_k
            hybrid_cfg      = {}
            hyde_cfg        = {}

        hybrid_enabled  = hybrid_cfg.get('enabled', False)
        bm25_weight     = float(hybrid_cfg.get('bm25_weight', 0.3))
        bm25_cands      = int(hybrid_cfg.get('bm25_candidates', doc_candidates))
        hyde_enabled    = hyde_cfg.get('enabled', False)
        hyde_mode       = str(hyde_cfg.get('mode', 'smart')).lower()
        hyde_timeout    = int(hyde_cfg.get('timeout', 5))
        hyde_max_tokens = int(hyde_cfg.get('max_tokens', 100))

        search_query = query
        if hyde_enabled:
            if hyde_mode == 'always':
                search_query = _generate_hyde_query(query, timeout=hyde_timeout, max_tokens=hyde_max_tokens)
                logger.info("🔮 HyDE: mode=always — query dienhance")
            elif hyde_mode == 'smart':
                use_hyde, hyde_reason = _should_use_hyde(query)
                if use_hyde:
                    search_query = _generate_hyde_query(query, timeout=hyde_timeout, max_tokens=hyde_max_tokens)
                    logger.info("🔮 HyDE: mode=smart — AKTIF (%s)", hyde_reason)
                else:
                    logger.debug("🔮 HyDE: mode=smart — skip (%s)", hyde_reason)

        if filenames and len(filenames) > 1:
            n_docs = len(filenames)
            per_doc_k = max(2, doc_candidates // n_docs)
            logger.info(
                "🔍 RAG: Multi-dokumen (%d file) — %s, %d chunk/dokumen untuk user_id: %s",
                n_docs,
                "vector+BM25" if hybrid_enabled else "vector only",
                per_doc_k, user_id,
            )
            all_docs: List = []
            for fname in filenames:
                f_filter = {"$and": [user_filter, {"filename": fname}]}
                try:
                    f_vec = vectorstore.similarity_search_with_score(
                        search_query, k=per_doc_k, filter=f_filter
                    )
                    f_vec = _exclude_parent_search_results(f_vec)
                    stored_texts: List[str] = []
                    stored_metas: List[dict] = []

                    if hybrid_enabled:
                        try:
                            stored_texts, stored_metas = _get_child_corpus(
                                vectorstore,
                                {"$and": [user_filter, {"filename": fname}]},
                                bm25_cands,
                            )
                            if stored_texts:
                                bm25_ranked = _bm25_rank_docs(query, stored_texts, top_k=per_doc_k)
                                merged = _rrf_merge_docs(
                                    f_vec, bm25_ranked, stored_texts, stored_metas,
                                    top_k=per_doc_k, bm25_weight=bm25_weight,
                                )
                                logger.info(
                                    "   📄 %s → %d vector + %d BM25 → %d merged",
                                    fname, len(f_vec), len(bm25_ranked), len(merged),
                                )
                                all_docs.extend(merged)
                                continue
                        except Exception as berr:
                            logger.debug("BM25 fallback ke vector untuk %s: %s", fname, berr)

                    if not f_vec:
                        if not stored_texts:
                            try:
                                stored_texts, stored_metas = _get_child_corpus(
                                    vectorstore,
                                    {"$and": [user_filter, {"filename": fname}]},
                                    per_doc_k,
                                )
                            except Exception as cerr:
                                logger.debug("Child corpus fallback gagal untuk %s: %s", fname, cerr)
                        fallback_docs = _bm25_child_fallback(query, stored_texts, stored_metas, per_doc_k)
                        if fallback_docs:
                            logger.info("   📄 %s → %d chunk (child-corpus fallback)", fname, len(fallback_docs))
                            all_docs.extend(fallback_docs)
                            continue

                    logger.info("   📄 %s → %d chunk (vector only)", fname, len(f_vec))
                    all_docs.extend(f_vec)

                except Exception as ferr:
                    logger.warning("   ⚠️  Gagal cari chunk dari %s: %s", fname, ferr)
            docs = all_docs

        elif filenames and len(filenames) == 1:
            logger.info("🔍 RETRIEVAL: Filtering by filename='%s', user_id='%s'", filenames[0], user_id)
            f_filter = {"$and": [user_filter, {"filename": filenames[0]}]}
            f_vec = vectorstore.similarity_search_with_score(
                search_query, k=doc_candidates, filter=f_filter
            )
            f_vec = _exclude_parent_search_results(f_vec)
            stored_texts: List[str] = []
            stored_metas: List[dict] = []
            if hybrid_enabled:
                try:
                    stored_texts, stored_metas = _get_child_corpus(
                        vectorstore,
                        {"$and": [user_filter, {"filename": filenames[0]}]},
                        bm25_cands,
                    )
                    if stored_texts:
                        bm25_ranked = _bm25_rank_docs(query, stored_texts, top_k=doc_candidates)
                        docs = _rrf_merge_docs(
                            f_vec, bm25_ranked, stored_texts, stored_metas,
                            top_k=doc_candidates, bm25_weight=bm25_weight,
                        )
                        logger.info("🔍 RAG: %s — %d merged chunks (vector+BM25)", filenames[0], len(docs))
                    else:
                        docs = f_vec
                        logger.info("🔍 RAG: %s — %d chunk (vector only)", filenames[0], len(docs))
                except Exception:
                    docs = f_vec
                    logger.info("🔍 RAG: %s — %d chunk (vector only, BM25 error)", filenames[0], len(f_vec))
            else:
                docs = f_vec
                logger.info("🔍 RAG: Filtering by filename: %s untuk user_id: %s", filenames[0], user_id)

            if not docs:
                if not stored_texts:
                    try:
                        stored_texts, stored_metas = _get_child_corpus(
                            vectorstore,
                            {"$and": [user_filter, {"filename": filenames[0]}]},
                            doc_candidates,
                        )
                    except Exception as cerr:
                        logger.debug("Child corpus fallback gagal untuk %s: %s", filenames[0], cerr)
                docs = _bm25_child_fallback(query, stored_texts, stored_metas, doc_candidates)
                if docs:
                    logger.info("🔍 RAG: %s — %d chunk (child-corpus fallback)", filenames[0], len(docs))

        else:
            logger.info("🔍 RAG: Searching all documents untuk user_id: %s", user_id)
            docs = vectorstore.similarity_search_with_score(
                search_query, k=doc_candidates, filter=user_filter
            )
            docs = _exclude_parent_search_results(docs)

        if not docs:
            logger.info("📚 RAG: Tidak ada chunk ditemukan - filename='%s', user_id='%s'", filenames[0] if filenames else None, user_id)
            try:
                all_data = vectorstore.get(where={"user_id": str(user_id)}, include=['metadatas'], limit=100)
                stored_filenames = set(m.get('filename') for m in (all_data.get('metadatas') or []) if m.get('filename'))
                logger.info("🔍 DEBUG: Available filenames in Chroma for user_id=%s: %s", user_id, list(stored_filenames))
            except Exception as de:
                logger.debug("DEBUG: Could not query Chroma: %s", de)
            return [], True

        langsearch_service = get_langsearch_service()
        rerank_enabled = os.getenv("LANGSEARCH_RERANK_ENABLED", "true").lower() == "true"

        if rerank_enabled and len(docs) >= 2:
            documents = [doc.page_content for doc, _ in docs]
            rerank_results = langsearch_service.rerank_documents(
                query=query,
                documents=documents,
                top_n=doc_top_n,
                return_documents=False
            )

            if rerank_results:
                reranked_chunks = []
                for result in rerank_results:
                    idx = result.get("index")
                    if idx is not None and idx < len(docs):
                        doc, vector_score = docs[idx]
                        reranked_chunks.append({
                            "content": doc.page_content,
                            "score": float(vector_score),
                            "rerank_score": float(result.get("relevance_score", 0)),
                            "filename": doc.metadata.get("filename", "unknown"),
                            "chunk_index": doc.metadata.get("chunk_index", 0),
                            "embedding_model": doc.metadata.get("embedding_model", provider_name),
                            "metadata": dict(doc.metadata or {}),
                        })

                final_chunks = list(reranked_chunks[:top_k])

                if filenames and len(filenames) > 1:
                    represented = {c["filename"] for c in final_chunks}
                    missing = [f for f in filenames if f not in represented]

                    if missing:
                        best_per_doc = {}
                        for _doc, _vscore in docs:
                            _fname = _doc.metadata.get("filename", "unknown")
                            if _fname not in best_per_doc:
                                best_per_doc[_fname] = (_doc, _vscore)

                        forced = []
                        for fname in missing:
                            if fname in best_per_doc:
                                _doc, _vscore = best_per_doc[fname]
                                forced.append({
                                    "content": _doc.page_content,
                                    "score": float(_vscore),
                                    "rerank_score": -1.0,
                                    "filename": _doc.metadata.get("filename", "unknown"),
                                    "chunk_index": _doc.metadata.get("chunk_index", 0),
                                    "embedding_model": _doc.metadata.get("embedding_model", provider_name),
                                    "metadata": dict(_doc.metadata or {}),
                                    "forced": True,
                                })

                        if forced:
                            n = len(forced)
                            if len(final_chunks) >= n:
                                final_chunks[-n:] = forced
                            else:
                                final_chunks.extend(forced)
                            logger.info(
                                "🔧 RAG: Forced inclusion %d dokumen (tidak dapat slot rerank): %s",
                                n,
                                ", ".join(missing),
                            )

                dist = Counter(c["filename"] for c in final_chunks)
                n_forced = sum(1 for c in final_chunks if c.get("forced"))
                logger.info(
                    "📚 RAG: Final %d chunks — distribusi: %s%s",
                    len(final_chunks),
                    ", ".join(f"{k}: {v}" for k, v in dist.items()),
                    f" ({n_forced} forced)" if n_forced else "",
                )

                try:
                    from app.config_loader import get_pdr_config as _get_pdr
                    _pdr = _get_pdr()
                    _pdr_enabled = _pdr.get('enabled', False)
                except Exception:
                    _pdr_enabled = False

                if _pdr_enabled:
                    final_chunks = _resolve_pdr_parents(
                        final_chunks, vectorstore, str(user_id)
                    )

                return final_chunks, True
            else:
                logger.warning("⚠️ RAG: Rerank gagal, fallback ke vector search")

        results = []
        for doc, score in docs[:top_k]:
            results.append({
                "content": doc.page_content,
                "score": float(score),
                "filename": doc.metadata.get("filename", "unknown"),
                "chunk_index": doc.metadata.get("chunk_index", 0),
                "embedding_model": doc.metadata.get("embedding_model", provider_name),
                "metadata": dict(doc.metadata or {}),
            })
        logger.info("📚 RAG: Found %d chunks (vector search)", len(results))

        try:
            from app.config_loader import get_pdr_config as _get_pdr
            _pdr = _get_pdr()
            _pdr_enabled = _pdr.get('enabled', False)
        except Exception:
            _pdr_enabled = False

        if _pdr_enabled:
            results = _resolve_pdr_parents(results, vectorstore, str(user_id))

        return results, True

    except Exception as e:
        logger.error(f"❌ Error searching chunks: {str(e)}")
        return [], False


def build_rag_prompt(
    question: str,
    chunks: List[Dict],
    include_sources: bool = True,
    web_context: str = "",
) -> Tuple[str, List[Dict]]:
    if not chunks:
        return question, []

    context_parts = []
    sources = []

    for chunk in chunks:
        filename = chunk.get("filename", "Dokumen Tidak Diketahui")
        context_parts.append(f"--- Referensi dari Dokumen: {filename} ---")
        context_parts.append(chunk.get("content", ""))
        context_parts.append("")

        if include_sources:
            sources.append({
                "filename": chunk.get("filename", "unknown"),
                "chunk_index": chunk.get("chunk_index", 0),
                "relevance_score": chunk.get("score", 0)
            })

    context_str = "\n".join(context_parts)
    web_section = ""
    if web_context.strip():
        web_section = f"""
Informasi web terbaru (digunakan jika relevan):
{web_context}
"""

    rag_prompt_template = get_rag_prompt()
    rag_prompt = rag_prompt_template.format(
        context_str=context_str or "",
        web_section=web_section or "",
        question=question or ""
    )

    return rag_prompt, sources
