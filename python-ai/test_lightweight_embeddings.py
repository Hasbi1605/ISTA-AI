#!/usr/bin/env python3
"""
Test script for Lightweight Embeddings integration.
Usage: python3 test_lightweight_embeddings.py
"""

import os
import sys
import requests

def test_lightweight_embeddings_api():
    """Test the Lightweight Embeddings API directly."""
    print("=" * 60)
    print("TEST 1: Direct API Connection")
    print("=" * 60)
    
    url = "https://lamhieu-lightweight-embeddings.hf.space/v1/embeddings"
    payload = {
        "model": "bge-m3",
        "input": ["Hello world", "Test embedding", "Bahasa Indonesia"]
    }
    
    try:
        print(f"URL: {url}")
        print(f"Model: bge-m3")
        print(f"Input texts: {payload['input']}")
        print()
        
        response = requests.post(url, json=payload, timeout=30)
        print(f"Status Code: {response.status_code}")
        
        if response.status_code == 200:
            data = response.json()
            print(f"✅ API Connection: SUCCESS")
            print(f"Number of embeddings: {len(data['data'])}")
            print(f"Embedding dimension: {len(data['data'][0]['embedding'])}")
            print()
            return True
        else:
            print(f"❌ API Error: {response.text}")
            return False
            
    except requests.exceptions.Timeout:
        print("❌ Timeout Error: API took too long to respond")
        return False
    except requests.exceptions.ConnectionError as e:
        print(f"❌ Connection Error: {e}")
        return False
    except Exception as e:
        print(f"❌ Unexpected Error: {e}")
        return False


def test_rag_service_integration():
    """Test the RAG service integration."""
    print()
    print("=" * 60)
    print("TEST 2: RAG Service Integration")
    print("=" * 60)
    
    try:
        # Add parent directory to path
        sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
        
        from app.services.rag_service import get_embeddings_with_fallback, EMBEDDING_MODELS
        
        print(f"Available providers: {[m['name'] for m in EMBEDDING_MODELS]}")
        print(f"Primary provider: {EMBEDDING_MODELS[0]['name']}")
        print()
        
        print("Attempting to get embeddings with fallback...")
        embeddings, provider = get_embeddings_with_fallback()
        
        if embeddings is None:
            print("❌ Failed to get embeddings")
            return False
        
        print(f"✅ Successfully connected to: {provider}")
        print(f"Embedding class: {type(embeddings).__name__}")
        print()
        
        # Test embedding
        print("Testing embedding generation...")
        test_text = "Hello, this is a test document."
        embedding = embeddings.embed_query(test_text)
        
        print(f"✅ Embedding generated successfully")
        print(f"Embedding dimension: {len(embedding)}")
        print(f"Sample values: {embedding[:5]}")
        print()
        
        return True
        
    except ImportError as e:
        print(f"❌ Import Error: {e}")
        print("Make sure you're running from the python-ai directory")
        return False
    except Exception as e:
        print(f"❌ Error: {e}")
        import traceback
        traceback.print_exc()
        return False


def main():
    """Run all tests."""
    print("\n" + "=" * 60)
    print("LIGHTWEIGHT EMBEDDINGS INTEGRATION TEST")
    print("=" * 60)
    print()
    
    # Test 1: Direct API
    test1_passed = test_lightweight_embeddings_api()
    
    # Test 2: RAG Service Integration
    test2_passed = test_rag_service_integration()
    
    # Summary
    print()
    print("=" * 60)
    print("TEST SUMMARY")
    print("=" * 60)
    print(f"Test 1 (Direct API):      {'✅ PASSED' if test1_passed else '❌ FAILED'}")
    print(f"Test 2 (RAG Integration): {'✅ PASSED' if test2_passed else '❌ FAILED'}")
    print()
    
    if test1_passed and test2_passed:
        print("🎉 All tests passed! Lightweight Embeddings is ready to use.")
        print()
        print("Next steps:")
        print("1. Upload a document via the Laravel UI")
        print("2. Check logs for 'Lightweight Embeddings' as provider")
        print("3. Verify documents are processed correctly")
        return 0
    else:
        print("⚠️  Some tests failed. Please check the errors above.")
        return 1


if __name__ == "__main__":
    sys.exit(main())
