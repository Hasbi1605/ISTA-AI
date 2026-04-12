import os
import logging
import requests
import time
import hashlib
from typing import List, Dict, Optional, Tuple
from datetime import datetime
from functools import lru_cache

from app.config_loader import get_web_search_context_prompt, get_assertive_instruction

logger = logging.getLogger(__name__)

LANGSEARCH_TIMEOUT = int(os.getenv("LANGSEARCH_TIMEOUT", "10"))
LANGSEARCH_CACHE_TTL = int(os.getenv("LANGSEARCH_CACHE_TTL", "300"))  # 5 minutes default


class LangSearchService:
    """Service untuk melakukan web search menggunakan LangSearch API."""
    
    def __init__(self):
        self.base_url = "https://api.langsearch.com/v1/web-search"
        self._search_cache: Dict[Tuple[str, int], Tuple[List[Dict], float]] = {}
        self._api_key: Optional[str] = None
    
    @property
    def api_key(self) -> Optional[str]:
        """Lazy load API key from environment."""
        if self._api_key is None:
            self._api_key = os.getenv("LANGSEARCH_API_KEY")
        return self._api_key
    
    def _get_cached_result(self, query: str, time_bucket: int) -> Optional[List[Dict]]:
        """Get cached search result if not expired."""
        key = (query, time_bucket)
        if key in self._search_cache:
            results, timestamp = self._search_cache[key]
            if time.time() - timestamp < LANGSEARCH_CACHE_TTL:
                logger.info(f"📦 LangSearch: cache hit for '{query}'")
                return results
            else:
                del self._search_cache[key]
        return None
    
    def _cache_result(self, query: str, time_bucket: int, results: List[Dict]):
        """Cache search result with timestamp."""
        key = (query, time_bucket)
        self._search_cache[key] = (results, time.time())
    
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
        
        # Check cache first (cache per 5 minutes)
        time_bucket = self._get_time_bucket()
        cached = self._get_cached_result(query, time_bucket)
        if cached is not None:
            return cached
        
        api_keys = [self.api_key]
        backup_key = os.getenv("LANGSEARCH_API_KEY_BACKUP")
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
                logger.error(f"⏱️ LangSearch: query='{query}', timeout after {LANGSEARCH_TIMEOUT}s")
                return []
            except requests.exceptions.RequestException as e:
                status_code = getattr(getattr(e, 'response', None), 'status_code', None)
                if i < len(api_keys) - 1 and (status_code in (401, 403, 429) or (status_code and status_code >= 500)):
                    logger.warning(f"⚠️ LangSearch: attempt {i+1} failed ({status_code}). Retrying with backup key...")
                    continue
                logger.error(f"❌ LangSearch: query='{query}', error={str(e)}")
                return []
            except Exception as e:
                logger.error(f"❌ LangSearch: query='{query}', unexpected error={str(e)}")
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
                "snippet": item.get("snippet", item.get("summary", "")),
                "url": item.get("url", ""),
                "datePublished": item.get("datePublished", "")
            })
        
        logger.info(f"✅ LangSearch: query='{query}', results={len(formatted_results)}")
        
        # Cache the result
        self._cache_result(query, time_bucket, formatted_results)
        
        return formatted_results
    
    def build_search_context(self, results: List[Dict]) -> str:
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
        current_year = datetime.now().year
        
        template = get_web_search_context_prompt()
        
        results_formatted = []
        for idx, result in enumerate(results, 1):
            title = result.get("title", "No title")
            snippet = result.get("snippet", "No description")
            url = result.get("url", "")
            date = result.get("datePublished", "")
            
            result_str = f"""🔍 Hasil #{idx}:
   📌 Judul: {title}
   📝 Isi: {snippet}"""
            if url:
                result_str += f"\n   🔗 Sumber: {url}"
            if date:
                result_str += f"\n   📅 Tanggal Publikasi: {date}"
            results_formatted.append(result_str)
        
        results_str = "\n\n".join(results_formatted)
        
        return template.format(
            current_date=current_date,
            current_year=current_year,
            results=results_str
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
        model = os.getenv("LANGSEARCH_RERANK_MODEL", "langsearch-reranker-v1")
        timeout = int(os.getenv("LANGSEARCH_RERANK_TIMEOUT", "8"))
        
        api_keys = [self.api_key]
        backup_key = os.getenv("LANGSEARCH_API_KEY_BACKUP")
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
                logger.info(f"🔄 LangSearch Rerank: query='{query}', docs={len(documents)}, top_n={top_n}")
                
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
                logger.error(f"⏱️ LangSearch Rerank: query='{query}', timeout after {timeout}s")
                return None
            except requests.exceptions.RequestException as e:
                status_code = getattr(getattr(e, 'response', None), 'status_code', None)
                if i < len(api_keys) - 1 and (status_code in (401, 403, 429) or (status_code and status_code >= 500)):
                    logger.warning(f"⚠️ LangSearch Rerank: attempt {i+1} failed ({status_code}). Retrying with backup key...")
                    continue
                logger.error(f"❌ LangSearch Rerank: query='{query}', error={str(e)}")
                return None
            except Exception as e:
                logger.error(f"❌ LangSearch Rerank: query='{query}', unexpected error={str(e)}")
                return None
                
        if not data:
            return None
            
        results = data.get("results", [])
        logger.info(f"✅ LangSearch Rerank: query='{query}', returned {len(results)} results")
        
        return results


def get_langsearch_service() -> LangSearchService:
    """Initialize dan return LangSearch service instance."""
    return LangSearchService()
