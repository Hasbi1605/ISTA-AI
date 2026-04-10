"""
Bug Condition Exploration Test for RAG Document Control Fix

**Validates: Requirements 1.1, 1.2, 1.3, 2.1, 2.2, 2.3**

This test demonstrates that RAG retrieval is properly disabled in get_context_for_query().

IMPORTANT: get_context_for_query() no longer performs RAG retrieval for security reasons.
RAG retrieval is handled separately by search_relevant_chunks() with proper user_id
and document filtering.

Expected behavior:
- get_embeddings_with_fallback() should NOT be called in get_context_for_query()
- vectorstore.similarity_search() should NOT be called in get_context_for_query()
- result["has_rag"] should be false
- result["rag_documents"] should be empty
- result["rag_reason_code"] should be "RAG_DISABLED_FUNCTION_SCOPE"
"""

import pytest
from unittest.mock import patch, MagicMock
from hypothesis import given, strategies as st, settings, Phase
from app.services.rag_service import get_context_for_query


# Test queries from the design document
BUG_CONDITION_QUERIES = [
    "hello",
    "what is AI?",
    "tell me a joke",
]


class TestBugConditionExploration:
    """
    Bug Condition Exploration: RAG Retrieval Invoked Without Documents
    
    This test verifies that the bug exists on unfixed code by checking that
    get_embeddings_with_fallback() and similarity_search() ARE called even
    when documents_active=false.
    
    When the fix is implemented, this test will pass because the functions
    will NOT be called.
    """
    
    @pytest.mark.parametrize("query", BUG_CONDITION_QUERIES)
    def test_bug_condition_rag_called_without_documents(self, query):
        """
        Test that demonstrates the bug: RAG retrieval is invoked without documents.
        
        Bug behavior (UNFIXED code):
        - get_embeddings_with_fallback() IS called when documents_active=false
        - vectorstore.similarity_search() IS called when documents_active=false
        
        Expected behavior (FIXED code):
        - get_embeddings_with_fallback() should NOT be called
        - vectorstore.similarity_search() should NOT be called
        - result["has_rag"] should be false
        - result["rag_documents"] should be empty
        """
        with patch('app.services.rag_service.get_embeddings_with_fallback') as mock_embeddings, \
             patch('app.services.rag_service.Chroma') as mock_chroma:
            
            # Setup mocks to simulate the bug behavior
            mock_embedding_obj = MagicMock()
            mock_embeddings.return_value = (mock_embedding_obj, "test_provider")
            
            mock_vectorstore = MagicMock()
            mock_chroma.return_value = mock_vectorstore
            mock_vectorstore.similarity_search.return_value = []
            
            # Call the function with documents_active=false
            result = get_context_for_query(
                query=query,
                documents_active=False,
                force_web_search=False,
                allow_auto_realtime_web=False,
                explicit_web_request=False
            )
            
            # EXPECTED BEHAVIOR (after fix):
            # These assertions will FAIL on unfixed code (proving the bug exists)
            # and PASS on fixed code (proving the fix works)
            
            # Assert that get_embeddings_with_fallback() was NOT called
            assert not mock_embeddings.called, (
                f"BUG DETECTED: get_embeddings_with_fallback() was called for query '{query}' "
                f"with documents_active=false. This should NOT happen. "
                f"Expected: NOT called. Actual: called {mock_embeddings.call_count} time(s)."
            )
            
            # Assert that Chroma vectorstore was NOT instantiated
            assert not mock_chroma.called, (
                f"BUG DETECTED: Chroma vectorstore was instantiated for query '{query}' "
                f"with documents_active=false. This should NOT happen. "
                f"Expected: NOT called. Actual: called {mock_chroma.call_count} time(s)."
            )
            
            # Assert that similarity_search was NOT called
            assert not mock_vectorstore.similarity_search.called, (
                f"BUG DETECTED: similarity_search() was called for query '{query}' "
                f"with documents_active=false. This should NOT happen. "
                f"Expected: NOT called. Actual: called {mock_vectorstore.similarity_search.call_count} time(s)."
            )
            
            # Assert that result indicates no RAG retrieval occurred
            assert result["has_rag"] is False, (
                f"BUG DETECTED: result['has_rag'] is True for query '{query}' "
                f"with documents_active=false. Expected: False. Actual: {result['has_rag']}."
            )
            
            # Assert that rag_documents is empty
            assert len(result["rag_documents"]) == 0, (
                f"BUG DETECTED: result['rag_documents'] is not empty for query '{query}' "
                f"with documents_active=false. Expected: empty list. Actual: {len(result['rag_documents'])} documents."
            )
            
            # Assert that rag_reason_code indicates RAG is disabled at function scope
            assert result["rag_reason_code"] == "RAG_DISABLED_FUNCTION_SCOPE", (
                f"Expected rag_reason_code to be 'RAG_DISABLED_FUNCTION_SCOPE' for query '{query}' "
                f"with documents_active=false. Actual: {result.get('rag_reason_code')}."
            )


    @given(query=st.text(min_size=1, max_size=100))
    @settings(
        max_examples=20,
        phases=[Phase.generate, Phase.target],
        deadline=None
    )
    def test_property_no_rag_without_documents(self, query):
        """
        Property-based test: For ANY query with documents_active=false,
        RAG retrieval should NOT be invoked.
        
        **Validates: Requirements 2.1, 2.2, 2.3**
        
        This property test generates random queries and verifies that
        get_embeddings_with_fallback() and similarity_search() are NOT called
        when documents_active=false.
        
        CRITICAL: This test will FAIL on unfixed code (proving the bug exists)
        and PASS on fixed code (proving the fix works).
        """
        with patch('app.services.rag_service.get_embeddings_with_fallback') as mock_embeddings, \
             patch('app.services.rag_service.Chroma') as mock_chroma:
            
            # Setup mocks
            mock_embedding_obj = MagicMock()
            mock_embeddings.return_value = (mock_embedding_obj, "test_provider")
            
            mock_vectorstore = MagicMock()
            mock_chroma.return_value = mock_vectorstore
            mock_vectorstore.similarity_search.return_value = []
            
            # Call the function with documents_active=false
            result = get_context_for_query(
                query=query,
                documents_active=False,
                force_web_search=False,
                allow_auto_realtime_web=False,
                explicit_web_request=False
            )
            
            # Property: RAG retrieval should NOT be invoked
            assert not mock_embeddings.called, (
                f"Property violation: get_embeddings_with_fallback() called for query '{query[:50]}...' "
                f"with documents_active=false"
            )
            
            assert not mock_chroma.called, (
                f"Property violation: Chroma vectorstore instantiated for query '{query[:50]}...' "
                f"with documents_active=false"
            )
            
            assert result["has_rag"] is False, (
                f"Property violation: has_rag is True for query '{query[:50]}...' "
                f"with documents_active=false"
            )
            
            assert len(result["rag_documents"]) == 0, (
                f"Property violation: rag_documents not empty for query '{query[:50]}...' "
                f"with documents_active=false"
            )
            
            # Property: rag_reason_code should indicate function scope disable
            assert result["rag_reason_code"] == "RAG_DISABLED_FUNCTION_SCOPE", (
                f"Property violation: rag_reason_code is '{result.get('rag_reason_code')}' "
                f"for query '{query[:50]}...', expected 'RAG_DISABLED_FUNCTION_SCOPE'"
            )


if __name__ == "__main__":
    pytest.main([__file__, "-v", "-s"])
