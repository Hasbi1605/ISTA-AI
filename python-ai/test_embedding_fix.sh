#!/bin/bash

# Script untuk testing perbaikan embedding error 413
# Usage: ./test_embedding_fix.sh

echo "=========================================="
echo "Testing Embedding Fix - Error 413"
echo "=========================================="
echo ""

# Check if service is running
echo "1. Checking if Python AI service is running..."
if curl -s http://localhost:8001/health > /dev/null 2>&1; then
    echo "✅ Service is running on port 8001"
else
    echo "❌ Service is NOT running!"
    echo "Please start the service first:"
    echo "  cd python-ai"
    echo "  uvicorn app.main:app --reload --port 8001"
    exit 1
fi

echo ""
echo "2. Checking ChromaDB directory..."
if [ -d "chroma_data" ]; then
    echo "✅ ChromaDB directory exists"
    echo "   Location: $(pwd)/chroma_data"
    echo "   Size: $(du -sh chroma_data | cut -f1)"
else
    echo "⚠️  ChromaDB directory not found (will be created on first upload)"
fi

echo ""
echo "3. Checking environment configuration..."
if [ -f ".env" ]; then
    echo "✅ .env file exists"
    
    # Check key configurations
    TOKEN_CHUNK_SIZE=$(grep "^TOKEN_CHUNK_SIZE=" .env | cut -d'=' -f2)
    AGGRESSIVE_BATCH_SIZE=$(grep "^AGGRESSIVE_BATCH_SIZE=" .env | cut -d'=' -f2)
    BATCH_DELAY=$(grep "^BATCH_DELAY_SECONDS=" .env | cut -d'=' -f2)
    
    echo "   TOKEN_CHUNK_SIZE: ${TOKEN_CHUNK_SIZE:-not set (using default 1500)}"
    echo "   AGGRESSIVE_BATCH_SIZE: ${AGGRESSIVE_BATCH_SIZE:-not set (using default 200)}"
    echo "   BATCH_DELAY_SECONDS: ${BATCH_DELAY:-not set (using default 0.5)}"
    
    # Check if GitHub tokens are set
    if grep -q "^GITHUB_TOKEN=your_github_token" .env; then
        echo "   ⚠️  GITHUB_TOKEN not configured (still using placeholder)"
    else
        echo "   ✅ GITHUB_TOKEN configured"
    fi
else
    echo "❌ .env file not found!"
    echo "Please copy .env.example to .env and configure it"
    exit 1
fi

echo ""
echo "4. Testing recommendations..."
echo ""
echo "For documents < 100 KB:"
echo "  TOKEN_CHUNK_SIZE=1500"
echo "  AGGRESSIVE_BATCH_SIZE=200"
echo ""
echo "For documents 100-500 KB:"
echo "  TOKEN_CHUNK_SIZE=1200"
echo "  AGGRESSIVE_BATCH_SIZE=100"
echo ""
echo "For documents > 500 KB:"
echo "  TOKEN_CHUNK_SIZE=1000"
echo "  AGGRESSIVE_BATCH_SIZE=50"
echo "  BATCH_DELAY_SECONDS=1.0"
echo ""

echo "=========================================="
echo "Next Steps:"
echo "=========================================="
echo "1. Upload a test document through the UI"
echo "2. Monitor the logs for:"
echo "   - 'Created X smart batches (token-aware)'"
echo "   - 'Processing batch X/Y: N chunks, M tokens...'"
echo "   - '✅ Batch X/Y success'"
echo "   - 'Success: X/X chunks (100.0%)'"
echo ""
echo "3. If you see error 413, reduce:"
echo "   - TOKEN_CHUNK_SIZE (e.g., from 1500 to 1200)"
echo "   - AGGRESSIVE_BATCH_SIZE (e.g., from 200 to 50)"
echo ""
echo "4. Test queries:"
echo "   - 'apa isi dokumen tersebut'"
echo "   - 'jelaskan isi dari pasal 18'"
echo ""
echo "=========================================="
