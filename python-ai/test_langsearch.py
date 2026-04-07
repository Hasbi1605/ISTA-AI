import os
import sys
import pytest
from unittest.mock import Mock, patch, MagicMock
from datetime import datetime

# Add parent directory to path
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

from app.services.langsearch_service import LangSearchService


class TestLangSearchService:
    """Unit tests for LangSearchService."""
    
    @pytest.fixture
    def mock_env_vars(self, monkeypatch):
        """Set up mock environment variables."""
        monkeypatch.setenv("LANGSEARCH_API_KEY", "test_api_key")
        monkeypatch.setenv("LANGSEARCH_TIMEOUT", "10")
        monkeypatch.setenv("LANGSEARCH_CACHE_TTL", "300")
    
    def test_init_without_api_key(self, monkeypatch):
        """Test initialization without API key."""
        monkeypatch.delenv("LANGSEARCH_API_KEY", raising=False)
        service = LangSearchService()
        assert service.api_key is None
    
    def test_init_with_api_key(self, mock_env_vars):
        """Test initialization with API key."""
        service = LangSearchService()
        assert service.api_key == "test_api_key"
        assert service.base_url == "https://api.langsearch.com/v1/web-search"
    
    def test_search_no_api_key(self):
        """Test search returns empty list when no API key."""
        with patch.dict(os.environ, {}, clear=True):
            service = LangSearchService()
            results = service.search("test query")
            assert results == []
    
    @patch('app.services.langsearch_service.requests.post')
    def test_search_success(self, mock_post, mock_env_vars):
        """Test successful search returns formatted results."""
        mock_response = Mock()
        mock_response.status_code = 200
        mock_response.json.return_value = {
            "data": {
                "webPages": {
                    "value": [
                        {
                            "name": "Test Title",
                            "url": "https://example.com",
                            "snippet": "Test snippet",
                            "summary": "Test summary",
                            "datePublished": "2026-04-07"
                        }
                    ]
                }
            }
        }
        mock_post.return_value = mock_response
        
        service = LangSearchService()
        results = service.search("test query")
        
        assert len(results) == 1
        assert results[0]["title"] == "Test Title"
        assert results[0]["url"] == "https://example.com"
        assert results[0]["snippet"] == "Test snippet"
    
    @patch('app.services.langsearch_service.requests.post')
    def test_search_timeout(self, mock_post, mock_env_vars):
        """Test graceful handling of timeout."""
        import requests
        mock_post.side_effect = requests.Timeout()
        
        service = LangSearchService()
        results = service.search("test query")
        
        assert results == []
    
    @patch('app.services.langsearch_service.requests.post')
    def test_search_request_exception(self, mock_post, mock_env_vars):
        """Test graceful handling of request exception."""
        import requests
        mock_post.side_effect = requests.RequestException("Connection error")
        
        service = LangSearchService()
        results = service.search("test query")
        
        assert results == []
    
    @patch('app.services.langsearch_service.requests.post')
    def test_search_empty_results(self, mock_post, mock_env_vars):
        """Test handling of empty search results."""
        mock_response = Mock()
        mock_response.status_code = 200
        mock_response.json.return_value = {
            "data": {
                "webPages": {
                    "value": []
                }
            }
        }
        mock_post.return_value = mock_response
        
        service = LangSearchService()
        results = service.search("test query")
        
        assert results == []
    
    def test_build_search_context_empty(self):
        """Test building context with empty results."""
        service = LangSearchService()
        context = service.build_search_context([])
        assert context == ""
    
    def test_build_search_context_with_results(self):
        """Test building context with results."""
        results = [
            {
                "title": "Test Title",
                "snippet": "Test snippet",
                "url": "https://example.com",
                "datePublished": "2026-04-07"
            }
        ]
        
        service = LangSearchService()
        context = service.build_search_context(results)
        
        assert "Today is" in context
        assert "Test Title" in context
        assert "Test snippet" in context
        assert "https://example.com" in context
    
    def test_cache_functionality(self, mock_env_vars):
        """Test that caching works correctly."""
        service = LangSearchService()
        
        # First search should not be cached
        cached = service._get_cached_result("test query", 1)
        assert cached is None
        
        # Cache a result
        mock_results = [{"title": "Cached"}]
        service._cache_result("test query", 1, mock_results)
        
        # Second search should return cached result
        cached = service._get_cached_result("test query", 1)
        assert cached == mock_results
    
    def test_cache_expiration(self, mock_env_vars, monkeypatch):
        """Test that cache expires after TTL."""
        monkeypatch.setenv("LANGSEARCH_CACHE_TTL", "1")  # 1 second TTL
        
        service = LangSearchService()
        
        # Cache a result
        mock_results = [{"title": "Cached"}]
        service._cache_result("test query", 1, mock_results)
        
        # Wait for cache to expire
        import time
        time.sleep(1.1)
        
        # Cache should be expired
        cached = service._get_cached_result("test query", 1)
        assert cached is None
    
    @patch('app.services.langsearch_service.requests.post')
    def test_search_with_caching(self, mock_post, mock_env_vars):
        """Test that search results are cached."""
        mock_response = Mock()
        mock_response.status_code = 200
        mock_response.json.return_value = {
            "data": {
                "webPages": {
                    "value": [{"name": "Result"}]
                }
            }
        }
        mock_post.return_value = mock_response
        
        service = LangSearchService()
        
        # First call
        results1 = service.search("test query")
        
        # Second call should use cache
        results2 = service.search("test query")
        
        # Should only have one API call
        assert mock_post.call_count == 1
        assert results1 == results2


if __name__ == "__main__":
    pytest.main([__file__, "-v"])
