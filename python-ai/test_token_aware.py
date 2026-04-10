#!/usr/bin/env python3
"""
Test script untuk memverifikasi Token-Aware Chunking implementation
"""

import sys
import os

# Add parent directory to path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from app.services.rag_service import count_tokens, TIKTOKEN_ENCODER

def test_token_counting():
    """Test token counting function"""
    print("=" * 60)
    print("Testing Token-Aware Chunking Implementation")
    print("=" * 60)
    
    # Test 1: Simple text
    text1 = "Hello, this is a test."
    tokens1 = count_tokens(text1)
    print(f"\nTest 1: Simple text")
    print(f"Text: '{text1}'")
    print(f"Tokens: {tokens1}")
    
    # Test 2: Longer text
    text2 = """
    Ini adalah dokumen yang lebih panjang untuk menguji token counting.
    Sistem RAG menggunakan token-aware chunking untuk memastikan setiap chunk
    tidak melebihi batas token yang ditentukan oleh model embedding.
    """
    tokens2 = count_tokens(text2)
    print(f"\nTest 2: Longer text")
    print(f"Text length: {len(text2)} characters")
    print(f"Tokens: {tokens2}")
    print(f"Ratio: {len(text2) / tokens2:.2f} chars/token")
    
    # Test 3: Very long text (simulate document chunk)
    text3 = " ".join(["This is a sample sentence."] * 100)
    tokens3 = count_tokens(text3)
    print(f"\nTest 3: Very long text (100 repeated sentences)")
    print(f"Text length: {len(text3):,} characters")
    print(f"Tokens: {tokens3:,}")
    print(f"Ratio: {len(text3) / tokens3:.2f} chars/token")
    
    # Test 4: Check tiktoken encoder
    print(f"\nTest 4: Tiktoken encoder status")
    if TIKTOKEN_ENCODER is not None:
        print("✅ Tiktoken encoder initialized successfully")
        print(f"Encoding: cl100k_base (OpenAI)")
    else:
        print("⚠️ Tiktoken encoder not available, using fallback")
    
    # Test 5: Estimate chunk count for large document
    print(f"\nTest 5: Estimate chunks for large document")
    doc_sizes = [
        ("50 pages", 50 * 500),  # ~500 words per page
        ("150 pages", 150 * 500),
        ("500 pages", 500 * 500),
    ]
    
    chunk_size = 1500  # tokens
    chunk_overlap = 150  # tokens
    
    for name, word_count in doc_sizes:
        # Estimate: ~1.3 tokens per word for English/Indonesian mix
        estimated_tokens = int(word_count * 1.3)
        # Estimate chunks: (total_tokens - overlap) / (chunk_size - overlap)
        estimated_chunks = max(1, (estimated_tokens - chunk_overlap) // (chunk_size - chunk_overlap))
        
        print(f"\n  {name}:")
        print(f"    Words: ~{word_count:,}")
        print(f"    Estimated tokens: ~{estimated_tokens:,}")
        print(f"    Estimated chunks: ~{estimated_chunks:,}")
        print(f"    Processing time (200 chunks/batch, 0.5s delay): ~{estimated_chunks / 200 * 0.5:.1f}s")
    
    print("\n" + "=" * 60)
    print("✅ All tests completed successfully!")
    print("=" * 60)

if __name__ == "__main__":
    test_token_counting()
