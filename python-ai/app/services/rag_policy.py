import os
import re
import hashlib
import unicodedata
import logging
from typing import List, Tuple, Dict, Optional

from app.services.rag_config import (
    EXPLICIT_WEB_PATTERNS,
    REALTIME_HIGH_PATTERNS,
    REALTIME_MEDIUM_KEYWORDS,
    SCORE_QUERY_KEYWORDS,
    CANONICAL_TEAM_GROUPS,
)

logger = logging.getLogger(__name__)

SCORE_PATTERN = re.compile(r"\b(\d{1,2})\s*[-:]\s*(\d{1,2})\b")

_langsearch_service = None


def get_langsearch_service():
    global _langsearch_service
    if _langsearch_service is None:
        from app.services.langsearch_service import LangSearchService
        _langsearch_service = LangSearchService()
    return _langsearch_service


def _normalize_query(query: str) -> str:
    return re.sub(r"\s+", " ", (query or "").strip().lower())


def _query_log_meta(query: str) -> str:
    cleaned = (query or "").strip()
    if not cleaned:
        return "query_len=0 query_hash=none"

    query_hash = hashlib.sha256(cleaned.encode("utf-8")).hexdigest()[:10]
    return f"query_len={len(cleaned)} query_hash={query_hash}"


def _normalize_for_match(text: str) -> str:
    if not text:
        return ""

    normalized = unicodedata.normalize("NFKD", text)
    normalized = "".join(ch for ch in normalized if not unicodedata.combining(ch))
    normalized = normalized.lower()
    normalized = normalized.replace("–", "-").replace("—", "-").replace("−", "-")
    normalized = re.sub(r"[^a-z0-9\s:\-]", " ", normalized)
    return re.sub(r"\s+", " ", normalized).strip()


def _is_score_query(query: str) -> bool:
    normalized = _normalize_for_match(query)
    if not normalized:
        return False

    has_keyword = any(keyword in normalized for keyword in SCORE_QUERY_KEYWORDS)
    has_vs = " vs " in f" {normalized} " or " versus " in f" {normalized} "
    return has_keyword or has_vs


def _extract_team_groups_from_query(query: str) -> List[List[str]]:
    normalized = _normalize_for_match(query)
    if not normalized:
        return []

    def detect_group(segment: str) -> Optional[List[str]]:
        segment = segment.strip()
        if not segment:
            return None

        for aliases in CANONICAL_TEAM_GROUPS.values():
            if any(alias in segment for alias in aliases):
                return aliases

        return None

    match = re.search(r"(.+?)\s+(?:vs|versus)\s+(.+)", normalized)
    if match:
        left_group = detect_group(match.group(1))
        right_group = detect_group(match.group(2))
        if left_group and right_group and left_group != right_group:
            return [left_group, right_group]

    detected = []
    for aliases in CANONICAL_TEAM_GROUPS.values():
        positions = [normalized.find(alias) for alias in aliases if alias in normalized]
        if positions:
            detected.append((min(positions), aliases))

    detected.sort(key=lambda item: item[0])

    if len(detected) >= 2:
        first = detected[0][1]
        second = detected[1][1]
        if first != second:
            return [first, second]

    return []


def _result_mentions_teams(text: str, team_groups: List[List[str]]) -> bool:
    if not team_groups:
        return True

    return all(any(alias in text for alias in aliases) for aliases in team_groups)


def extract_match_score_signal(query: str, results: List[Dict]) -> Optional[Dict]:
    if not results or not _is_score_query(query):
        return None

    team_groups = _extract_team_groups_from_query(query)
    score_counts: Dict[str, int] = {}
    evidence: Dict[str, List[Dict[str, str]]] = {}

    for result in results:
        title = result.get("title", "")
        snippet = result.get("snippet", "")
        url = result.get("url", "")
        source_text = f"{title} {snippet}"
        normalized_text = _normalize_for_match(source_text)

        if not _result_mentions_teams(normalized_text, team_groups):
            continue

        for match in SCORE_PATTERN.finditer(normalized_text):
            left = int(match.group(1))
            right = int(match.group(2))
            if left > 20 or right > 20:
                continue

            score = f"{left}-{right}"
            score_counts[score] = score_counts.get(score, 0) + 1
            evidence.setdefault(score, []).append({
                "title": title,
                "url": url,
            })

    if not score_counts:
        return None

    best_score, support = max(score_counts.items(), key=lambda item: item[1])
    evidences = evidence.get(best_score, [])[:3]

    return {
        "score": best_score,
        "support_count": support,
        "evidence": evidences,
    }


def _merge_search_results(primary: List[Dict], secondary: List[Dict], limit: int = 8) -> List[Dict]:
    merged: List[Dict] = []
    seen_urls = set()

    for item in (primary or []) + (secondary or []):
        url = (item.get("url") or "").strip()
        key = url or f"{item.get('title', '')}|{item.get('snippet', '')}"
        if key in seen_urls:
            continue

        merged.append(item)
        seen_urls.add(key)
        if len(merged) >= limit:
            break

    return merged


def detect_explicit_web_request(query: str) -> bool:
    normalized = _normalize_query(query)
    if not normalized:
        return False

    return any(re.search(pattern, normalized) for pattern in EXPLICIT_WEB_PATTERNS)


def detect_realtime_intent_level(query: str) -> str:
    normalized = _normalize_query(query)
    if not normalized:
        return "low"

    if any(re.search(pattern, normalized) for pattern in REALTIME_HIGH_PATTERNS):
        return "high"

    hits = sum(1 for keyword in REALTIME_MEDIUM_KEYWORDS if keyword in normalized)
    if hits >= 2:
        return "medium"
    if hits == 1 and len(normalized.split()) <= 4:
        return "medium"

    return "low"


def should_use_web_search(
    query: str,
    force_web_search: bool = False,
    explicit_web_request: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
) -> Tuple[bool, str, str]:
    realtime_intent = detect_realtime_intent_level(query)
    explicit_detected = explicit_web_request or detect_explicit_web_request(query)

    if force_web_search:
        reason = "DOC_WEB_TOGGLE" if documents_active else "WEB_TOGGLE"
        return True, reason, realtime_intent

    if explicit_detected:
        reason = "DOC_WEB_EXPLICIT" if documents_active else "EXPLICIT_WEB"
        return True, reason, realtime_intent

    if documents_active:
        return False, "DOC_NO_WEB", realtime_intent

    if allow_auto_realtime_web:
        if realtime_intent == "high":
            return True, "REALTIME_AUTO_HIGH", realtime_intent
        if realtime_intent == "medium":
            return True, "REALTIME_AUTO_MEDIUM", realtime_intent

    return False, "NO_WEB", realtime_intent


def get_context_for_query(
    query: str,
    force_web_search: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
    explicit_web_request: bool = False,
) -> Dict:
    langsearch = get_langsearch_service()
    search_results = []

    should_search, reason_code, realtime_intent = should_use_web_search(
        query=query,
        force_web_search=force_web_search,
        explicit_web_request=explicit_web_request,
        allow_auto_realtime_web=allow_auto_realtime_web,
        documents_active=documents_active,
    )

    if should_search:
        logger.info("🌐 Web search enabled (%s, %s)", reason_code, _query_log_meta(query))
        search_results = langsearch.search(query)

        score_signal = extract_match_score_signal(query, search_results)
        if _is_score_query(query) and score_signal is None:
            focused_query = f"{query} final score"
            focused_results = langsearch.search(focused_query)
            search_results = _merge_search_results(search_results, focused_results)

        try:
            from app.config_loader import get_rerank_config as _get_rc
            _rc = _get_rc()
            rerank_enabled = _rc.get('enabled', True)
            web_candidates = int(_rc.get('web_candidates', 10))
            web_top_n      = int(_rc.get('web_top_n', 5))
        except Exception:
            rerank_enabled = os.getenv("LANGSEARCH_RERANK_ENABLED", "true").lower() == "true"
            web_candidates = int(os.getenv("LANGSEARCH_RERANK_WEB_CANDIDATES", "10"))
            web_top_n      = int(os.getenv("LANGSEARCH_RERANK_WEB_TOP_N", "5"))

        if rerank_enabled and len(search_results) >= 2:
            candidates = search_results[:web_candidates] if len(search_results) > web_candidates else search_results

            if len(candidates) >= 2:
                documents = []
                for result in candidates:
                    title   = result.get("title", "")
                    snippet = result.get("snippet", "")
                    doc_text = f"{title}. {snippet}" if snippet else title
                    documents.append(doc_text)

                rerank_results = langsearch.rerank_documents(
                    query=query,
                    documents=documents,
                    top_n=web_top_n,
                    return_documents=False
                )

                if rerank_results:
                    reranked_search_results = []
                    for result in rerank_results:
                        idx = result.get("index")
                        if idx is not None and idx < len(candidates):
                            original_result = candidates[idx].copy()
                            original_result["rerank_score"] = float(result.get("relevance_score", 0))
                            reranked_search_results.append(original_result)

                    search_results = reranked_search_results
                    logger.info(
                        "🌐 Web search: Reranked %d results (top_%d dari %d kandidat) (%s)",
                        len(reranked_search_results), web_top_n, len(candidates),
                        _query_log_meta(query),
                    )
                else:
                    logger.warning("⚠️ Web search: Rerank failed, using original results (%s)", _query_log_meta(query))
    else:
        logger.info("🚫 Web search skipped (%s, %s)", reason_code, _query_log_meta(query))

    has_search = len(search_results) > 0

    search_context = langsearch.build_search_context(search_results) if has_search else ""
    score_signal = extract_match_score_signal(query, search_results)

    if score_signal and search_context:
        score_lines = [
            "",
            "FAKTA TERSTRUKTUR (HASIL DETEKSI SKOR PERTANDINGAN):",
            f"- Skor yang paling konsisten: {score_signal['score']} (dukungan {score_signal['support_count']} sumber).",
            "- Jika pertanyaan user menanyakan skor pertandingan ini, gunakan skor di atas secara eksplisit.",
        ]

        for ev in score_signal.get("evidence", []):
            title = ev.get("title", "")
            url = ev.get("url", "")
            score_lines.append(f"- Bukti: {title} | {url}")

        search_context = search_context + "\n" + "\n".join(score_lines)

    rag_documents = []
    has_rag = False
    rag_reason_code = "RAG_DISABLED_FUNCTION_SCOPE"

    logger.info("RAG_DISABLED_FUNCTION_SCOPE: RAG retrieval not performed in get_context_for_query() - use search_relevant_chunks() instead (%s)", _query_log_meta(query))

    return {
        "search_results": search_results,
        "rag_documents": rag_documents,
        "has_search": has_search,
        "has_rag": has_rag,
        "search_context": search_context,
        "score_signal": score_signal,
        "reason_code": reason_code,
        "realtime_intent": realtime_intent,
        "rag_reason_code": rag_reason_code,
    }


def get_rag_context_for_prompt(
    query: str,
    base_rag_prompt: str = "",
    force_web_search: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
    explicit_web_request: bool = False,
) -> str:
    context_data = get_context_for_query(
        query,
        force_web_search=force_web_search,
        allow_auto_realtime_web=allow_auto_realtime_web,
        documents_active=documents_active,
        explicit_web_request=explicit_web_request,
    )

    result_parts = []

    if context_data["search_context"]:
        result_parts.append(context_data["search_context"])

    return "\n".join(result_parts)
