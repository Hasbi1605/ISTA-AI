import hashlib
import logging
import requests
import time
from typing import List, Dict, Optional, Tuple
from datetime import datetime
from threading import Lock
from collections import OrderedDict

from app.config_loader import get_web_search_context_prompt
from app.env_utils import get_env, get_env_int
from app.runtime_config import render_prompt_template, runtime_prompt

logger = logging.getLogger(__name__)

LANGSEARCH_TIMEOUT = get_env_int("LANGSEARCH_TIMEOUT", 10)
LANGSEARCH_CACHE_TTL = max(1, get_env_int("LANGSEARCH_CACHE_TTL", 300))  # 5 minutes default
LANGSEARCH_CACHE_MAX_SIZE = get_env_int("LANGSEARCH_CACHE_MAX_SIZE", 200)


def _query_log_meta(query: str) -> dict:
    safe_query = query or ""
    return {
        "query_len": len(safe_query),
        "query_hash": hashlib.md5(safe_query.encode()).hexdigest()[:8],
    }


class LangSearchService:
    """Service untuk melakukan web search menggunakan LangSearch API."""
    
    def __init__(self):
        self.base_url = "https://api.langsearch.com/v1/web-search"
        self._search_cache: "OrderedDict[Tuple[str, int], Tuple[List[Dict], float]]" = OrderedDict()
        self._cache_lock = Lock()
        self._api_key: Optional[str] = None
    
    @property
    def api_key(self) -> Optional[str]:
        """Lazy load API key from environment."""
        if self._api_key is None:
            self._api_key = get_env("LANGSEARCH_API_KEY")
        return self._api_key
    
    def _normalize_cache_query(self, query: str) -> str:
        return " ".join((query or "").strip().lower().split())

    def _cache_key(self, query: str, freshness: str, count: int, time_bucket: int) -> tuple:
        """
        Cache key mencakup freshness dan count agar query yang sama dengan
        parameter berbeda tidak saling menimpa hasil cache.
        """
        return (self._normalize_cache_query(query), freshness, count, time_bucket)

    def _get_cached_result(self, query: str, freshness: str, count: int, time_bucket: int) -> Optional[List[Dict]]:
        """Get cached search result if not expired."""
        key = self._cache_key(query, freshness, count, time_bucket)
        now = time.time()
        with self._cache_lock:
            if key in self._search_cache:
                results, timestamp = self._search_cache[key]
                if now - timestamp < LANGSEARCH_CACHE_TTL:
                    self._search_cache.move_to_end(key)
                    meta = _query_log_meta(query)
                    logger.info("📦 LangSearch: cache hit query_len=%s query_hash=%s", meta["query_len"], meta["query_hash"])
                    return results
                del self._search_cache[key]
        return None

    def _cache_result(self, query: str, freshness: str, count: int, time_bucket: int, results: List[Dict]):
        """Cache search result with timestamp."""
        key = self._cache_key(query, freshness, count, time_bucket)
        with self._cache_lock:
            self._search_cache[key] = (results, time.time())
            self._search_cache.move_to_end(key)
            while len(self._search_cache) > max(1, LANGSEARCH_CACHE_MAX_SIZE):
                self._search_cache.popitem(last=False)
    
    def _get_time_bucket(self) -> int:
        """Get current time bucket for caching (5 minute intervals)."""
        return int(time.time() / LANGSEARCH_CACHE_TTL)
    
    def search(self, query: str, freshness: str = "oneWeek", count: int = 5) -> List[Dict]:
        """
        Search the web menggunakan LangSearch API.
        
        Args:
            query: Search query string
            freshness: oneDay, oneWeek, oneMonth, oneYear, noLimit
            count: Number of results (1-10)
            
        Returns:
            List of search results dengan title, snippet, url, datePublished
            Returns empty list on error (graceful fallback)
        """
        if not self.api_key:
            logger.warning("⚠️ LangSearch: API key not configured")
            return []
        
        # Check cache first — key mencakup freshness dan count agar tidak tercampur
        time_bucket = self._get_time_bucket()
        cached = self._get_cached_result(query, freshness, count, time_bucket)
        if cached is not None:
            return cached
        
        api_keys = [self.api_key]
        backup_key = get_env("LANGSEARCH_API_KEY_BACKUP")
        if backup_key:
            api_keys.append(backup_key)
            
        payload = {
            "query": query,
            "freshness": freshness,
            "summary": True,
            "count": count
        }
        
        data = None
        for i, key in enumerate(api_keys):
            headers = {
                "Authorization": f"Bearer {key}",
                "Content-Type": "application/json"
            }
            try:
                response = requests.post(
                    self.base_url,
                    json=payload,
                    headers=headers,
                    timeout=LANGSEARCH_TIMEOUT
                )
                response.raise_for_status()
                data = response.json()
                break
            except requests.exceptions.Timeout:
                if i < len(api_keys) - 1:
                    logger.warning(f"⏱️ LangSearch: attempt {i+1} timeout. Retrying with backup key...")
                    continue
                meta = _query_log_meta(query)
                logger.error(
                    "⏱️ LangSearch: query_len=%s query_hash=%s timeout after %ss",
                    meta["query_len"],
                    meta["query_hash"],
                    LANGSEARCH_TIMEOUT,
                )
                return []
            except requests.exceptions.RequestException as e:
                status_code = getattr(getattr(e, 'response', None), 'status_code', None)
                if i < len(api_keys) - 1 and (status_code in (401, 403, 429) or (status_code and status_code >= 500)):
                    logger.warning(f"⚠️ LangSearch: attempt {i+1} failed ({status_code}). Retrying with backup key...")
                    continue
                meta = _query_log_meta(query)
                logger.error(
                    "❌ LangSearch: query_len=%s query_hash=%s error=%s",
                    meta["query_len"],
                    meta["query_hash"],
                    str(e),
                )
                return []
            except Exception as e:
                meta = _query_log_meta(query)
                logger.error(
                    "❌ LangSearch: query_len=%s query_hash=%s unexpected error=%s",
                    meta["query_len"],
                    meta["query_hash"],
                    str(e),
                )
                return []
                
        if not data:
            return []
            
        # LangSearch response format: data.webPages.value[]
        web_pages = data.get("data", {}).get("webPages", {})
        results = web_pages.get("value", [])
        
        formatted_results = []
        for item in results:
            formatted_results.append({
                "title": item.get("name", ""),
                "snippet": item.get("snippet") or item.get("summary", ""),
                "url": item.get("url", ""),
                "datePublished": item.get("datePublished", "")
            })
        
        meta = _query_log_meta(query)
        logger.info(
            "✅ LangSearch: query_len=%s query_hash=%s results=%s",
            meta["query_len"],
            meta["query_hash"],
            len(formatted_results),
        )
        
        # Cache the result — key mencakup freshness dan count
        self._cache_result(query, freshness, count, time_bucket, formatted_results)
        
        return formatted_results
    
    def build_search_context(self, results: List[Dict], runtime_config: Optional[Dict] = None) -> str:
        """
        Build formatted string dari search results untuk inject ke system prompt.
        
        Args:
            results: List of search result dicts
            
        Returns:
            Formatted string untuk system prompt with strong emphasis
        """
        if not results:
            return ""
        
        current_date = datetime.now().strftime("%A, %d %B %Y")
        
        template = runtime_prompt(runtime_config, "web_search", "context")
        fallback_template = get_web_search_context_prompt()
        
        results_formatted = []
        for idx, result in enumerate(results, 1):
            title = result.get("title", "No title")
            # Gunakan summary sebagai fallback jika snippet kosong
            snippet = result.get("snippet", "") or result.get("summary", "") or "No description"
            url = result.get("url", "")
            date = result.get("datePublished", "")
            
            result_str = f"""Hasil {idx}:
Judul: {title}
Ringkasan: {snippet}"""
            if url:
                result_str += f"\nSumber: {url}"
            if date:
                result_str += f"\nTanggal publikasi: {date}"
            results_formatted.append(result_str)
        
        results_str = "\n\n".join(results_formatted)
        
        return render_prompt_template(
            template,
            fallback_template,
            current_date=current_date,
            results=results_str
        )

    def build_no_results_context(self, runtime_config: Optional[Dict] = None) -> str:
        """
        Build web context when search was requested but no result is available.

        Tetap mengirim konteks ke LLM agar jawaban real-time tidak jatuh ke
        pengetahuan umum tanpa sumber.
        """
        current_date = datetime.now().strftime("%A, %d %B %Y")
        template = runtime_prompt(runtime_config, "web_search", "context")
        fallback_template = get_web_search_context_prompt()

        return render_prompt_template(
            template,
            fallback_template,
            current_date=current_date,
            results=(
                "Tidak ada hasil pencarian web yang cukup untuk menjawab "
                "pertanyaan ini. Jangan menyebut fakta real-time sebagai "
                "kepastian tanpa sumber web yang tersedia."
            )
        )

    def rerank_documents(
        self,
        query: str,
        documents: List[str],
        top_n: Optional[int] = None,
        return_documents: bool = False
    ) -> Optional[List[Dict]]:
        """
        Rerank documents menggunakan LangSearch Semantic Rerank API.
        
        Args:
            query: Search query string
            documents: List of document strings to rerank
            top_n: Number of top results to return (default: all)
            return_documents: Whether to return documents in response (default: False)
            
        Returns:
            List of rerank results with index and relevance_score, or None on error
        """
        if not self.api_key:
            logger.warning("⚠️ LangSearch: API key not configured for rerank")
            return None
            
        if not documents or len(documents) < 2:
            logger.info("🔄 LangSearch Rerank: skipping rerank (documents < 2)")
            return None
            
        # Limit documents to max 50 as per API specification
        if len(documents) > 50:
            logger.warning(f"⚠️ LangSearch Rerank: truncating documents from {len(documents)} to 50")
            documents = documents[:50]
            
        # Get configuration from environment with defaults
        model = get_env("LANGSEARCH_RERANK_MODEL", "langsearch-reranker-v1")
        timeout = get_env_int("LANGSEARCH_RERANK_TIMEOUT", 8)
        
        api_keys = [self.api_key]
        backup_key = get_env("LANGSEARCH_API_KEY_BACKUP")
        if backup_key:
            api_keys.append(backup_key)
        
        payload = {
            "model": model,
            "query": query,
            "documents": documents,
        }
        
        if top_n is not None:
            payload["top_n"] = top_n
        if return_documents:
            payload["return_documents"] = return_documents
            
        data = None
        for i, key in enumerate(api_keys):
            headers = {
                "Authorization": f"Bearer {key}",
                "Content-Type": "application/json"
            }
            try:
                meta = _query_log_meta(query)
                logger.info(
                    "🔄 LangSearch Rerank: query_len=%s query_hash=%s docs=%s top_n=%s",
                    meta["query_len"],
                    meta["query_hash"],
                    len(documents),
                    top_n,
                )
                
                response = requests.post(
                    "https://api.langsearch.com/v1/rerank",
                    json=payload,
                    headers=headers,
                    timeout=timeout
                )
                response.raise_for_status()
                data = response.json()
                
                if data.get("code") != 200:
                    status_code = data.get("code")
                    if i < len(api_keys) - 1 and status_code in (401, 403, 429):
                        logger.warning(f"⚠️ LangSearch Rerank: API error {status_code}. Retrying with backup key...")
                        continue
                    logger.error(f"❌ LangSearch Rerank: API error code={status_code}, msg={data.get('msg')}")
                    return None
                    
                break
            except requests.exceptions.Timeout:
                if i < len(api_keys) - 1:
                    logger.warning(f"⏱️ LangSearch Rerank: attempt {i+1} timeout. Retrying with backup key...")
                    continue
                meta = _query_log_meta(query)
                logger.error(
                    "⏱️ LangSearch Rerank: query_len=%s query_hash=%s timeout after %ss",
                    meta["query_len"],
                    meta["query_hash"],
                    timeout,
                )
                return None
            except requests.exceptions.RequestException as e:
                status_code = getattr(getattr(e, 'response', None), 'status_code', None)
                if i < len(api_keys) - 1 and (status_code in (401, 403, 429) or (status_code and status_code >= 500)):
                    logger.warning(f"⚠️ LangSearch Rerank: attempt {i+1} failed ({status_code}). Retrying with backup key...")
                    continue
                meta = _query_log_meta(query)
                logger.error(
                    "❌ LangSearch Rerank: query_len=%s query_hash=%s error=%s",
                    meta["query_len"],
                    meta["query_hash"],
                    str(e),
                )
                return None
            except Exception as e:
                meta = _query_log_meta(query)
                logger.error(
                    "❌ LangSearch Rerank: query_len=%s query_hash=%s unexpected error=%s",
                    meta["query_len"],
                    meta["query_hash"],
                    str(e),
                )
                return None
                
        if not data:
            return None
            
        results = data.get("results", [])
        meta = _query_log_meta(query)
        logger.info(
            "✅ LangSearch Rerank: query_len=%s query_hash=%s returned %s results",
            meta["query_len"],
            meta["query_hash"],
            len(results),
        )
        
        return results


def get_langsearch_service() -> LangSearchService:
    """Initialize dan return LangSearch service instance."""
    return LangSearchService()
