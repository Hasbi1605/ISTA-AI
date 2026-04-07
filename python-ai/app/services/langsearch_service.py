import os
import logging
import requests
import time
from typing import List, Dict, Optional, Tuple
from datetime import datetime
from functools import lru_cache

logger = logging.getLogger(__name__)

LANGSEARCH_TIMEOUT = int(os.getenv("LANGSEARCH_TIMEOUT", "10"))
LANGSEARCH_CACHE_TTL = int(os.getenv("LANGSEARCH_CACHE_TTL", "300"))  # 5 minutes default


class LangSearchService:
    """Service untuk melakukan web search menggunakan LangSearch API."""
    
    def __init__(self):
        self.api_key = os.getenv("LANGSEARCH_API_KEY")
        self.base_url = "https://api.langsearch.com/v1/web-search"
        self._search_cache: Dict[Tuple[str, int], Tuple[List[Dict], float]] = {}
    
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
        
        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json"
        }
        
        payload = {
            "query": query,
            "freshness": freshness,
            "summary": True,
            "count": count
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
            
        except requests.exceptions.Timeout:
            logger.error(f"⏱️ LangSearch: query='{query}', timeout after {LANGSEARCH_TIMEOUT}s")
            return []
        except requests.exceptions.RequestException as e:
            logger.error(f"❌ LangSearch: query='{query}', error={str(e)}")
            return []
        except Exception as e:
            logger.error(f"❌ LangSearch: query='{query}', unexpected error={str(e)}")
            return []
    
    def build_search_context(self, results: List[Dict]) -> str:
        """
        Build formatted string dari search results untuk inject ke system prompt.
        
        Args:
            results: List of search result dicts
            
        Returns:
            Formatted string untuk system prompt
        """
        if not results:
            return ""
        
        current_date = datetime.now().strftime("%A, %d %B %Y")
        
        prompt_parts = [
            f"Today is {current_date}.",
            "",
            "Recent web search results:",
            ""
        ]
        
        for idx, result in enumerate(results, 1):
            title = result.get("title", "No title")
            snippet = result.get("snippet", "No description")
            url = result.get("url", "")
            date = result.get("datePublished", "")
            
            prompt_parts.append(f"{idx}. {title}")
            prompt_parts.append(f"   {snippet}")
            if url:
                prompt_parts.append(f"   Source: {url}")
            if date:
                prompt_parts.append(f"   Published: {date}")
            prompt_parts.append("")
        
        prompt_parts.append("---")
        prompt_parts.append("")
        
        return "\n".join(prompt_parts)


def get_langsearch_service() -> LangSearchService:
    """Initialize dan return LangSearch service instance."""
    return LangSearchService()
