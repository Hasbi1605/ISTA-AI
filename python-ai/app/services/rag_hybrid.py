import os
import re
import logging
from typing import List, Tuple, Dict, Optional

from langchain_chroma import Chroma

from app.services.rag_config import CHROMA_PATH, PARENT_COLLECTION_NAME

logger = logging.getLogger(__name__)

_HYDE_SKIP_PATTERNS = [
    r'^(rangkum|buat ringkasan|ringkaskan)',
    r'^(baca|baca isi|baca dan)',
    r'^(apa isi|apa saja isi)',
    r'^(jelaskan isi|jelaskan dokumen)',
    r'^(tampilkan|tunjukkan|sebutkan)',
    r'^(bandingkan isi|buat perbandingan)',
    r'^(rangkumkan|buat tabel)',
    r'^halo|^hi |^hai ',
]

_HYDE_USE_PATTERNS = [
    r'\bmengapa\b', r'\bkenapa\b',
    r'\bbagaimana (cara|bisa|pengaruh|hubungan|dampak|peran)\b',
    r'\bapa (hubungan|perbedaan|persamaan|keterkaitan|pengaruh|dampak|peran)\b',
    r'\bapa yang dimaksud\b', r'\bjelaskan konsep\b', r'\bjelaskan teori\b',
    r'\bbuktikan\b', r'\bargumentasikan\b', r'\bankur\b', r'\bimplikasi\b',
    r'\bkritik\b', r'\bevaluasi\b', r'\banalisis\b', r'\binterpretasi\b',
]


def _should_use_hyde(query: str) -> Tuple[bool, str]:
    q = query.strip().lower()
    words = q.split()

    if len(words) < 5:
        return False, f"query terlalu pendek ({len(words)} kata)"

    for pattern in _HYDE_SKIP_PATTERNS:
        if re.search(pattern, q):
            return False, f"pattern skip: '{pattern}'"

    for pattern in _HYDE_USE_PATTERNS:
        if re.search(pattern, q):
            return True, f"pola konseptual: '{pattern}'"

    if len(words) >= 8 and '?' in query:
        return True, f"query panjang ({len(words)} kata) dengan tanda tanya"

    return False, "tidak ada pola konseptual terdeteksi"


def _generate_hyde_query(original_query: str, timeout: int = 5, max_tokens: int = 100) -> str:
    if len(original_query.strip()) < 10:
        return original_query

    query_for_hyde = original_query[:500] if len(original_query) > 500 else original_query

    try:
        import litellm
        from app.config_loader import get_chat_models
        models = get_chat_models()

        def _hyde_priority(m: dict) -> int:
            name = m.get('model_name', '').lower()
            if 'groq' in name or 'llama' in name:
                return 0
            if m.get('provider') == 'gemini_native':
                return 99
            return 1

        sorted_models = sorted(models, key=_hyde_priority)

        max_attempts = 2
        attempts = 0

        for model in sorted_models:
            if model.get('provider') == 'gemini_native':
                continue
            api_key = os.getenv(model.get('api_key_env', ''))
            if not api_key:
                continue
            if attempts >= max_attempts:
                logger.debug("HyDE: max_attempts=%d tercapai, skip", max_attempts)
                break

            kwargs = {
                'model': model['model_name'],
                'messages': [
                    {
                        'role': 'system',
                        'content': (
                            'Buat jawaban hipotetis singkat 2-3 kalimat untuk pertanyaan berikut. '
                            'Padat, faktual, gunakan kosakata yang relevan dengan topik.'
                        )
                    },
                    {'role': 'user', 'content': query_for_hyde}
                ],
                'api_key': api_key,
                'max_tokens': max_tokens,
                'timeout': timeout,
                'num_retries': 0,
                'stream': False,
            }
            if 'base_url' in model:
                kwargs['api_base'] = model['base_url']

            attempts += 1
            try:
                resp = litellm.completion(**kwargs)
                hypo = resp.choices[0].message.content.strip()
                if hypo:
                    enhanced = f"{original_query}\n{hypo}"
                    logger.info(
                        "🔮 HyDE: query enhanced +%d token (model: %s, attempt: %d/%d)",
                        len(hypo.split()), model['label'], attempts, max_attempts,
                    )
                    return enhanced
            except Exception as e:
                logger.debug("HyDE attempt %d gagal (%s): %s", attempts, model['label'], e)
                continue

    except Exception as e:
        logger.debug("HyDE skipped: %s", e)

    logger.debug("🔮 HyDE: skip — fallback ke query asli")
    return original_query


def _bm25_rank_docs(
    query: str,
    texts: List[str],
    top_k: int,
) -> List[Tuple[int, float]]:
    try:
        from rank_bm25 import BM25Okapi

        def _tokenize(t: str) -> List[str]:
            return [w for w in re.split(r'\W+', t.lower()) if w]

        corpus = [_tokenize(t) for t in texts]
        bm25 = BM25Okapi(corpus)
        scores = bm25.get_scores(_tokenize(query))

        max_score = max(scores) if any(s > 0 for s in scores) else 1.0
        if max_score == 0:
            max_score = 1.0

        indexed = [(i, float(scores[i]) / max_score) for i in range(len(scores))]
        indexed.sort(key=lambda x: -x[1])
        return indexed[:top_k]

    except ImportError:
        logger.warning("⚠️  rank-bm25 tidak terinstall — BM25 dinonaktifkan")
        return [(i, 0.0) for i in range(min(top_k, len(texts)))]
    except Exception as e:
        logger.warning("⚠️  BM25 error: %s", e)
        return [(i, 0.0) for i in range(min(top_k, len(texts)))]


def _rrf_merge_docs(
    vector_docs: List[Tuple],
    bm25_indexed: List[Tuple[int, float]],
    stored_texts: List[str],
    stored_metas: List[dict],
    top_k: int,
    bm25_weight: float = 0.3,
    k: int = 60,
) -> List[Tuple]:
    def _key(text: str) -> str:
        return text[:60].strip()

    rrf_scores: Dict[str, float] = {}

    for rank, (doc, _vscore) in enumerate(vector_docs):
        k_str = _key(doc.page_content)
        rrf_scores[k_str] = rrf_scores.get(k_str, 0.0) + (1 - bm25_weight) / (k + rank + 1)

    for bm25_rank, (stored_idx, _bscore) in enumerate(bm25_indexed):
        if stored_idx < len(stored_texts):
            k_str = _key(stored_texts[stored_idx])
            rrf_scores[k_str] = rrf_scores.get(k_str, 0.0) + bm25_weight / (k + bm25_rank + 1)

    pool: Dict[str, Tuple] = {}
    for doc, vscore in vector_docs:
        k_str = _key(doc.page_content)
        pool[k_str] = (doc, vscore)

    for stored_idx, _ in bm25_indexed:
        if stored_idx < len(stored_texts):
            k_str = _key(stored_texts[stored_idx])
            if k_str not in pool:
                class _MockDoc:
                    def __init__(self, content: str, meta: dict):
                        self.page_content = content
                        self.metadata = meta
                pool[k_str] = (_MockDoc(stored_texts[stored_idx],
                                        stored_metas[stored_idx] if stored_idx < len(stored_metas) else {}),
                               1.0)

    sorted_keys = sorted(rrf_scores, key=lambda x: -rrf_scores[x])
    merged = []
    for key in sorted_keys:
        if key in pool:
            merged.append(pool[key])
        if len(merged) >= top_k:
            break

    return merged


def _exclude_parent_search_results(results: List[Tuple]) -> List[Tuple]:
    return [
        (doc, score)
        for doc, score in results
        if doc.metadata.get("chunk_type") != "parent"
    ]


def _exclude_parent_corpus(documents: List[str], metadatas: List[dict]) -> Tuple[List[str], List[dict]]:
    filtered_documents: List[str] = []
    filtered_metadatas: List[dict] = []

    for document, metadata in zip(documents, metadatas):
        meta = metadata or {}
        if meta.get("chunk_type") == "parent":
            continue
        filtered_documents.append(document)
        filtered_metadatas.append(meta)

    return filtered_documents, filtered_metadatas


def _resolve_pdr_parents(
    child_chunks: List[Dict],
    vectorstore,
    user_id: str,
) -> List[Dict]:
    if not child_chunks:
        return child_chunks

    seen_parent_ids: set = set()
    ordered_parent_ids: List[str] = []
    child_by_parent: Dict[str, Dict] = {}

    for chunk in child_chunks:
        pid = chunk.get("metadata", {}).get("parent_id") or chunk.get("parent_id")
        ctype = chunk.get("metadata", {}).get("chunk_type") or chunk.get("chunk_type")

        if pid and ctype == "child" and pid not in seen_parent_ids:
            seen_parent_ids.add(pid)
            ordered_parent_ids.append(pid)
            child_by_parent[pid] = chunk
        elif not pid or ctype != "child":
            if "NON_PDR" not in seen_parent_ids:
                seen_parent_ids.add("NON_PDR")

    if not ordered_parent_ids:
        return child_chunks

    parent_collections = []

    try:
        parent_store = Chroma(
            collection_name=PARENT_COLLECTION_NAME,
            persist_directory=CHROMA_PATH,
        )
        parent_collections.append(parent_store._collection)
    except Exception as e:
        logger.debug("⚠️  Parent collection unavailable: %s", e)

    legacy_collection = getattr(vectorstore, "_collection", None)
    if legacy_collection is not None:
        parent_collections.append(legacy_collection)

    try:
        parent_map: Dict[str, Dict] = {}
        for raw_col in parent_collections:
            result = raw_col.get(
                where={
                    "$and": [
                        {"user_id": str(user_id)},
                        {"chunk_type": "parent"},
                        {"parent_id": {"$in": ordered_parent_ids}},
                    ]
                },
                include=["documents", "metadatas"],
            )

            for doc, meta in zip(result.get("documents", []), result.get("metadatas", [])):
                pid = meta.get("parent_id")
                if pid and pid not in parent_map:
                    parent_map[pid] = {"content": doc, "metadata": meta}

            if len(parent_map) >= len(ordered_parent_ids):
                break

        resolved: List[Dict] = []
        non_pdr: List[Dict] = []

        for chunk in child_chunks:
            pid = chunk.get("metadata", {}).get("parent_id") or chunk.get("parent_id")
            ctype = chunk.get("metadata", {}).get("chunk_type") or chunk.get("chunk_type")

            if pid and ctype == "child":
                if pid in parent_map and pid not in {r.get("parent_id") for r in resolved}:
                    p = parent_map[pid]
                    resolved.append({
                        "content":         p["content"],
                        "score":           chunk.get("score", 1.0),
                        "rerank_score":    chunk.get("rerank_score", 0.0),
                        "filename":        p["metadata"].get("filename", chunk.get("filename", "unknown")),
                        "chunk_index":     p["metadata"].get("parent_index", 0),
                        "embedding_model": chunk.get("embedding_model", ""),
                        "parent_id":       pid,
                        "pdr":             True,
                    })
                elif pid not in {r.get("parent_id") for r in resolved}:
                    resolved.append(chunk)
            else:
                non_pdr.append(chunk)

        final = resolved + non_pdr
        pdr_count = sum(1 for r in final if r.get("pdr"))
        logger.info(
            "🔍 PDR: %d child → %d parent chunks (+ %d non-PDR chunks)",
            len(ordered_parent_ids), pdr_count, len(non_pdr),
        )
        return final

    except Exception as e:
        logger.warning("⚠️  PDR parent lookup gagal: %s — fallback ke child chunks", e)
        return child_chunks
