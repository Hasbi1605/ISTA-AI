import os
from dotenv import load_dotenv

# Load .env from the project root (python-ai/.env)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(__file__)), '.env'))

import socket
from typing import List, Dict, Optional
from fastapi import FastAPI, Depends, HTTPException, Header
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from app.llm_manager import get_llm_stream, get_llm_stream_with_sources
from app.routers import documents
from app.services.rag_service import search_relevant_chunks, build_rag_prompt

app = FastAPI(title="ISTA AI Microservice", version="1.1.5")

# Include Routers
app.include_router(documents.router)

# Security
AI_SERVICE_TOKEN = os.getenv("AI_SERVICE_TOKEN", "your_internal_api_secret")

def verify_token(authorization: str = Header(None)):
    """Simple token-based security for internal service communication."""
    if not authorization or authorization != f"Bearer {AI_SERVICE_TOKEN}":
        raise HTTPException(status_code=401, detail="Unauthorized access to AI Service.")

class HealthResponse(BaseModel):
    status: str
    host: str

class ChatRequest(BaseModel):
    messages: List[Dict[str, str]]
    document_filenames: Optional[List[str]] = None
    user_id: Optional[str] = None  # For authorization in RAG mode

@app.get("/api/health", response_model=HealthResponse)
async def health_check():
    return {
        "status": "ok",
        "host": socket.gethostname()
    }

@app.post("/api/chat", dependencies=[Depends(verify_token)])
async def chat_stream(request: ChatRequest):
    """
    Endpoint for streaming LLM responses.
    Expects a list of messages (history) and applies fallback logic.
    
    If document_filenames is provided, uses RAG mode to search document chunks
    and include them as context for the LLM.
    
    user_id is required for RAG mode to verify document ownership.
    """
    # Check if RAG mode is requested
    if request.document_filenames:
        # RAG mode: search relevant chunks and include in context
        query = None
        for msg in reversed(request.messages):
            if msg["role"] == "user":
                query = msg["content"]
                break
        
        if query:
            chunks, success = search_relevant_chunks(
                query, 
                request.document_filenames, 
                top_k=5,
                user_id=request.user_id
            )
            
            if success and chunks:
                rag_prompt, sources = build_rag_prompt(query, chunks)
                
                # Insert RAG prompt as system message
                request.messages = [
                    {"role": "system", "content": rag_prompt}
                ] + request.messages
                
                return StreamingResponse(
                    get_llm_stream_with_sources(request.messages, sources),
                    media_type="text/event-stream"
                )
            elif success and not chunks:
                # RAG search succeeded but no relevant chunks found
                def rag_warning_stream():
                    yield "[⚠️ RAG: Tidak ada konteks relevan ditemukan dari dokumen yang dipilih. Menjawab berdasarkan pengetahuan umum...]\n\n"
                    for chunk in get_llm_stream(request.messages):
                        yield chunk
                return StreamingResponse(rag_warning_stream(), media_type="text/event-stream")
            else:
                # RAG search failed - user_id might be missing or other error
                def rag_error_stream():
                    yield "[⚠️ RAG: Tidak dapat mencari konteks dari dokumen. Pastikan Anda memiliki akses ke dokumen yang dipilih.]\n\n"
                    for chunk in get_llm_stream(request.messages):
                        yield chunk
                return StreamingResponse(rag_error_stream(), media_type="text/event-stream")
    
    # Regular chat mode (no RAG)
    return StreamingResponse(get_llm_stream(request.messages), media_type="text/event-stream")

if __name__ == "__main__":
    import uvicorn
    # In production, this would be handled by Gunicorn/Uvicorn in the Docker container.
    uvicorn.run(app, host="0.0.0.0", port=8001)
