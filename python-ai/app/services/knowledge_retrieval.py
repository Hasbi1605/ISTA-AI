from __future__ import annotations

import logging
import re
import unicodedata
from typing import Any, Dict, List, Tuple

from app.services.chroma_store import get_chroma_store
from app.config_loader import get_knowledge_internal_prompt
from app.env_utils import get_env_bool, get_env_float, get_env_int
from app.runtime_config import render_prompt_template, runtime_prompt
from app.services.rag_config import CHROMA_PATH, VECTOR_COLLECTION_NAME
from app.services.rag_embeddings import get_embeddings_with_fallback
from app.services.rag_hybrid import _exclude_parent_search_results

logger = logging.getLogger(__name__)

KNOWLEDGE_USER_ID = "__knowledge__"
KNOWLEDGE_SCOPE = "global_internal"
KNOWLEDGE_STATUS_ACTIVE = "active"

DEFAULT_TOP_K = 3
MAX_TOP_K = 8

DIRECT_INTERNAL_PATTERNS = [
    r"\bsop\b",
    r"\bstandar\s+operasional\b",
    r"\bprosedur\s+internal\b",
    r"\bkebijakan\s+internal\b",
    r"\bpengetahuan\s+internal\b",
    r"\bknowledge\s+internal\b",
    r"\bperaturan\s+internal\b",
    r"\btata\s+tertib\b",
    r"\bprotokol\s+(istana|internal|kepresidenan)\b",
]

INTERNAL_CONTEXT_TERMS = [
    "istana",
    "kepresidenan",
    "pegawai",
    "kepegawaian",
    "unit kerja",
    "layanan internal",
    "administrasi internal",
    "surat dinas",
    "memo dinas",
    "disposisi",
    "arsip internal",
    "tamu dinas",
    "agenda internal",
    "rapat internal",
]

ACTION_TERMS = [
    "bagaimana",
    "cara",
    "alur",
    "aturan",
    "ketentuan",
    "syarat",
    "panduan",
    "prosedur",
    "format",
    "template",
    "siapa",
    "kapan",
    "dimana",
    "di mana",
]


def knowledge_internal_enabled() -> bool:
    return get_env_bool("KNOWLEDGE_INTERNAL_ENABLED", False)


def knowledge_top_k() -> int:
    configured = get_env_int("KNOWLEDGE_INTERNAL_TOP_K", DEFAULT_TOP_K)
    return max(1, min(MAX_TOP_K, configured))


def knowledge_candidate_k(top_k: int) -> int:
    configured = get_env_int("KNOWLEDGE_INTERNAL_CANDIDATES", max(top_k * 3, top_k))
    return max(top_k, min(24, configured))


def knowledge_max_distance() -> float:
    return get_env_float("KNOWLEDGE_INTERNAL_MAX_DISTANCE", 1.35)


def _normalize_text(text: str) -> str:
    normalized = unicodedata.normalize("NFKD", text.lower())
    normalized = "".join(ch for ch in normalized if not unicodedata.combining(ch))
    return re.sub(r"\s+", " ", normalized).strip()


def should_use_internal_knowledge(query: str) -> bool:
    normalized = _normalize_text(query)
    if len(normalized) < 8:
        return False

    for pattern in DIRECT_INTERNAL_PATTERNS:
        if re.search(pattern, normalized):
            return True

    has_context = any(term in normalized for term in INTERNAL_CONTEXT_TERMS)
    has_action = any(term in normalized for term in ACTION_TERMS)

    return has_context and has_action


def build_knowledge_filter() -> Dict[str, Any]:
    return {
        "$and": [
            {"user_id": KNOWLEDGE_USER_ID},
            {"scope": KNOWLEDGE_SCOPE},
            {"knowledge_status": KNOWLEDGE_STATUS_ACTIVE},
        ],
    }


def _passes_distance(score: float | int | None) -> bool:
    max_distance = knowledge_max_distance()
    if max_distance <= 0 or score is None:
        return True

    return float(score) <= max_distance


def _source_label(metadata: Dict[str, Any], filename: str) -> str:
    title = str(metadata.get("knowledge_title") or "").strip()
    if title:
        return title

    return filename


def _chunk_payload(doc, score: float | int | None, provider_name: str) -> Dict[str, Any]:
    metadata = dict(doc.metadata or {})
    filename = str(metadata.get("filename") or "unknown")
    source_id = str(metadata.get("knowledge_source_id") or "").strip()

    payload = {
        "type": "knowledge",
        "content": doc.page_content,
        "score": float(score) if score is not None else 0.0,
        "filename": filename,
        "title": _source_label(metadata, filename),
        "chunk_index": metadata.get("chunk_index", metadata.get("child_index", 0)),
        "embedding_model": metadata.get("embedding_model", provider_name),
        "metadata": metadata,
    }

    if source_id:
        payload["knowledge_source_id"] = source_id

    return payload


def search_internal_knowledge(
    query: str,
    top_k: int | None = None,
    request_id: str | None = None,
) -> Tuple[List[Dict[str, Any]], bool]:
    resolved_top_k = max(1, min(MAX_TOP_K, int(top_k or knowledge_top_k())))

    try:
        embeddings, provider_name, _ = get_embeddings_with_fallback()
        if embeddings is None:
            logger.warning("Knowledge retrieval skipped: embedding provider unavailable")
            return [], False

        vectorstore = get_chroma_store(
            VECTOR_COLLECTION_NAME,
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH,
        )

        raw_docs = vectorstore.similarity_search_with_score(
            query,
            k=knowledge_candidate_k(resolved_top_k),
            filter=build_knowledge_filter(),
        )
        docs = _exclude_parent_search_results(raw_docs)

        chunks: List[Dict[str, Any]] = []
        for doc, score in docs:
            if not _passes_distance(score):
                continue

            chunks.append(_chunk_payload(doc, score, provider_name))
            if len(chunks) >= resolved_top_k:
                break

        logger.info(
            "Knowledge retrieval request_id=%s chunks=%d top_k=%d",
            request_id,
            len(chunks),
            resolved_top_k,
        )

        return chunks, True
    except Exception:
        logger.exception("Knowledge retrieval failed request_id=%s", request_id)
        return [], False


def build_knowledge_prompt(
    question: str,
    chunks: List[Dict[str, Any]],
    runtime_config: Dict[str, Any] | None = None,
) -> Tuple[str, List[Dict[str, Any]]]:
    if not chunks:
        return question, []

    context_parts: List[str] = []
    sources: List[Dict[str, Any]] = []

    for chunk in chunks:
        title = str(chunk.get("title") or chunk.get("filename") or "Knowledge internal")
        context_parts.append(f"--- Pengetahuan Internal: {title} ---")
        context_parts.append(str(chunk.get("content") or ""))
        context_parts.append("")

        source = {
            "type": "knowledge",
            "title": title,
            "filename": chunk.get("filename", "unknown"),
            "chunk_index": chunk.get("chunk_index", 0),
            "relevance_score": chunk.get("score", 0),
        }
        if chunk.get("knowledge_source_id"):
            source["knowledge_source_id"] = str(chunk["knowledge_source_id"])
        sources.append(source)

    context_str = "\n".join(context_parts).strip()
    prompt = render_prompt_template(
        runtime_prompt(runtime_config, "knowledge_internal", "answer"),
        get_knowledge_internal_prompt(),
        context_str=context_str,
        question=question,
    )

    return prompt, sources
