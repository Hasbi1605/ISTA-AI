import os
import logging
from dotenv import load_dotenv

# Load .env from the project root (python-ai/.env)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(__file__)), '.env'))

import socket
from typing import List, Dict, Optional, Tuple
from fastapi import FastAPI, Depends, HTTPException, Header
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from app.llm_manager import get_llm_stream, get_llm_stream_with_sources
from app.routers import documents
from app.services.rag_service import (
    search_relevant_chunks,
    build_rag_prompt,
    detect_explicit_web_request,
    should_use_web_search,
    get_context_for_query,
)
try:
    from app.config_loader import get_rag_top_k as _get_rag_top_k
except Exception:
    _get_rag_top_k = lambda: 5  # fallback jika config tidak tersedia

app = FastAPI(title="ISTA AI Microservice", version="1.1.5")
logger = logging.getLogger(__name__)

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
    force_web_search: bool = False
    source_policy: Optional[str] = None
    allow_auto_realtime_web: bool = True
    explicit_web_request: bool = False


def _resolve_policy_flags(request: ChatRequest, documents_active: bool) -> Tuple[bool, str]:
    """
    Resolve routing policy flags so Laravel source_policy is applied consistently.
    """
    source_policy = (request.source_policy or "").strip().lower()
    allow_auto_realtime_web = request.allow_auto_realtime_web

    if source_policy == "document_context":
        # Document-first mode should not auto-escalate to web unless explicitly requested.
        allow_auto_realtime_web = False
    elif source_policy == "hybrid_realtime_auto":
        allow_auto_realtime_web = True
    elif source_policy:
        logger.warning("Unknown source_policy received: %s", source_policy)

    if source_policy == "document_context" and not documents_active:
        logger.warning("source_policy=document_context received without active document_filenames")

    return allow_auto_realtime_web, source_policy


def _get_latest_user_query(messages: List[Dict[str, str]]) -> str:
    for msg in reversed(messages):
        if msg.get("role") == "user":
            return (msg.get("content") or "").strip()
    return ""


def _document_permission_message() -> str:
    return (
        "Informasi yang Anda tanyakan tidak ditemukan pada dokumen yang aktif. "
        "Apakah Anda mengizinkan saya menggunakan web search atau pengetahuan umum untuk melanjutkan?"
    )


def _document_context_error_message() -> str:
    return (
        "Saya belum bisa mengambil konteks dari dokumen yang dipilih. "
        "Apakah Anda mengizinkan saya menggunakan web search atau pengetahuan umum untuk melanjutkan?"
    )

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
    query = _get_latest_user_query(request.messages)
    documents_active = bool(request.document_filenames)
    allow_auto_realtime_web, source_policy = _resolve_policy_flags(request, documents_active)
    explicit_web_request = request.explicit_web_request or detect_explicit_web_request(query)

    should_web_search, reason_code, realtime_intent = should_use_web_search(
        query=query,
        force_web_search=request.force_web_search,
        explicit_web_request=explicit_web_request,
        allow_auto_realtime_web=allow_auto_realtime_web,
        documents_active=documents_active,
    )
    if logger.isEnabledFor(logging.DEBUG):
        logger.debug(
            "Policy reason=%s realtime_intent=%s docs_active=%s explicit_web=%s source_policy=%s",
            reason_code,
            realtime_intent,
            documents_active,
            explicit_web_request,
            source_policy or "unset",
        )

    # RAG mode with document-first guardrails
    if documents_active and query:
        chunks, success = search_relevant_chunks(
            query,
            request.document_filenames,
            top_k=_get_rag_top_k(),
            user_id=request.user_id,
        )

        if success and chunks:
            web_context = ""
            if should_web_search:
                context_data = get_context_for_query(
                    query,
                    force_web_search=request.force_web_search,
                    allow_auto_realtime_web=allow_auto_realtime_web,
                    documents_active=True,
                    explicit_web_request=explicit_web_request,
                )
                web_context = context_data.get("search_context", "")

            rag_prompt, sources = build_rag_prompt(query, chunks, web_context=web_context)

            messages_with_rag = [{"role": "system", "content": rag_prompt}] + request.messages
            return StreamingResponse(
                get_llm_stream_with_sources(messages_with_rag, sources),
                media_type="text/event-stream",
            )

        if success and not chunks:
            if should_web_search:
                return StreamingResponse(
                    get_llm_stream(
                        request.messages,
                        force_web_search=request.force_web_search,
                        allow_auto_realtime_web=allow_auto_realtime_web,
                        documents_active=True,
                        explicit_web_request=explicit_web_request,
                    ),
                    media_type="text/event-stream",
                )

            def document_not_found_stream():
                yield _document_permission_message()

            return StreamingResponse(document_not_found_stream(), media_type="text/event-stream")

        # RAG search failed (authorization, vector db issue, etc.)
        if should_web_search:
            return StreamingResponse(
                get_llm_stream(
                    request.messages,
                    force_web_search=request.force_web_search,
                    allow_auto_realtime_web=allow_auto_realtime_web,
                    documents_active=True,
                    explicit_web_request=explicit_web_request,
                ),
                media_type="text/event-stream",
            )

        def document_error_stream():
            yield _document_context_error_message()

        return StreamingResponse(document_error_stream(), media_type="text/event-stream")

    # Regular chat mode (no document context)
    return StreamingResponse(
        get_llm_stream(
            request.messages,
            force_web_search=request.force_web_search,
            allow_auto_realtime_web=allow_auto_realtime_web,
            documents_active=False,
            explicit_web_request=explicit_web_request,
        ),
        media_type="text/event-stream",
    )

if __name__ == "__main__":
    import uvicorn
    # In production, this would be handled by Gunicorn/Uvicorn in the Docker container.
    uvicorn.run(app, host="0.0.0.0", port=8001)
