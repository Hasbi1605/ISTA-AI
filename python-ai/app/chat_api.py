import asyncio
import logging
import os
from typing import Dict, List, Optional, Tuple

from dotenv import load_dotenv
from fastapi import Depends, FastAPI, Request, Response
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

from app.api_shared import HealthResponse, build_health_payload, build_ready_payload, verify_token
from app.services.latency_logger import LatencyTracker

# Load .env from the project root (python-ai/.env)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(__file__)), ".env"))

logger = logging.getLogger(__name__)

try:
    from app.config_loader import (
        get_document_error_prompt as _get_document_error_prompt,
        get_rag_no_answer_prompt as _get_rag_no_answer_prompt,
        get_rag_top_k as _get_rag_top_k,
    )
except Exception:
    _get_rag_top_k = lambda: 5
    _get_rag_no_answer_prompt = lambda: ""
    _get_document_error_prompt = lambda: ""


app = FastAPI(title="ISTA AI Chat Microservice", version="1.2.0")

ERROR_SENTINEL = "[ISTA_AI_ERROR]"


class ChatRequest(BaseModel):
    messages: List[Dict[str, str]]
    document_filenames: Optional[List[str]] = None
    document_ids: Optional[List[str]] = None
    user_id: Optional[str] = None
    force_web_search: bool = False
    source_policy: Optional[str] = None
    allow_auto_realtime_web: bool = True
    explicit_web_request: bool = False


def _resolve_policy_flags(request: ChatRequest, documents_active: bool) -> Tuple[bool, str]:
    source_policy = (request.source_policy or "").strip().lower()
    allow_auto_realtime_web = request.allow_auto_realtime_web

    if source_policy == "document_context":
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
    try:
        prompt = _get_rag_no_answer_prompt()
        if prompt:
            return prompt
    except Exception:
        pass

    return (
        "Saya belum menemukan informasi tersebut pada dokumen yang sedang aktif. "
        "Jika Anda berkenan, saya bisa melanjutkan dengan web search atau pengetahuan umum."
    )


def _document_context_error_message() -> str:
    try:
        prompt = _get_document_error_prompt()
        if prompt:
            return prompt
    except Exception:
        pass

    return (
        "Saya belum bisa membaca konteks dari dokumen yang dipilih saat ini. "
        "Jika Anda berkenan, saya bisa melanjutkan dengan web search atau pengetahuan umum."
    )


def _get_chat_streamers():
    from app.llm_manager import get_llm_stream, get_llm_stream_with_sources

    return get_llm_stream, get_llm_stream_with_sources


def _get_rag_policy_helpers():
    from app.services.rag_policy import (
        detect_explicit_web_request,
        get_context_for_query,
        should_use_web_search,
    )

    return (
        detect_explicit_web_request,
        should_use_web_search,
        get_context_for_query,
    )


def _get_rag_document_helpers():
    from app.retrieval_runner import run_retrieval_search
    from app.services.rag_retrieval import build_rag_prompt

    return run_retrieval_search, build_rag_prompt

def _get_knowledge_helpers():
    from app.services.knowledge_retrieval import (
        build_knowledge_prompt,
        knowledge_internal_enabled,
        search_internal_knowledge,
        should_use_internal_knowledge,
    )

    return (
        knowledge_internal_enabled,
        should_use_internal_knowledge,
        search_internal_knowledge,
        build_knowledge_prompt,
    )


@app.get("/api/health", response_model=HealthResponse)
async def health_check():
    return build_health_payload()


@app.get("/api/ready")
async def ready_check(response: Response):
    payload = build_ready_payload()
    if not payload.get("ready"):
        response.status_code = 503
    return payload


@app.post("/api/chat", dependencies=[Depends(verify_token)])
async def chat_stream(request: ChatRequest, http_request: Request):
    # Extract correlation ID from Laravel (X-Request-ID header)
    request_id: Optional[str] = http_request.headers.get("X-Request-ID") or None
    tracker = LatencyTracker(request_id=request_id)
    tracker.event("request_received", extra={"docs_active": bool(request.document_filenames)})

    (
        detect_explicit_web_request,
        should_use_web_search,
        get_context_for_query,
    ) = _get_rag_policy_helpers()
    get_llm_stream, get_llm_stream_with_sources = _get_chat_streamers()

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

    if documents_active and query:
        search_relevant_chunks, build_rag_prompt = _get_rag_document_helpers()
        if should_web_search:
            tracker.start("retrieval")
            chunks, success = await asyncio.to_thread(
                search_relevant_chunks,
                query,
                request.document_filenames,
                _get_rag_top_k(),
                request.user_id,
                request.document_ids,
                tracker.request_id,
            )
            tracker.end("retrieval", extra={"chunks": len(chunks), "success": success})

            web_context = ""
            if success and chunks:
                tracker.start("web_search")
                context_data = await asyncio.to_thread(
                    get_context_for_query,
                    query,
                    request.force_web_search,
                    allow_auto_realtime_web,
                    True,
                    explicit_web_request,
                    tracker.request_id,
                )
                tracker.end("web_search", extra={"reason": reason_code})
                web_context = context_data.get("search_context", "") if isinstance(context_data, dict) else ""
        else:
            tracker.start("retrieval")
            chunks, success = await asyncio.to_thread(
                search_relevant_chunks,
                query,
                request.document_filenames,
                _get_rag_top_k(),
                request.user_id,
                request.document_ids,
                tracker.request_id,
            )
            tracker.end("retrieval", extra={"chunks": len(chunks), "success": success})
            web_context = ""

        if success and chunks:
            rag_prompt, sources = build_rag_prompt(query, chunks, web_context=web_context)

            messages_with_rag = [{"role": "system", "content": rag_prompt}] + request.messages
            tracker.end_total("request_routed", extra={"mode": "rag_with_sources"})
            return StreamingResponse(
                _wrap_stream_with_ttft(
                    get_llm_stream_with_sources(messages_with_rag, sources),
                    tracker,
                ),
                media_type="text/event-stream",
            )

        if success and not chunks:
            if should_web_search:
                tracker.end_total("request_routed", extra={"mode": "web_search_fallback"})
                return StreamingResponse(
                    _wrap_stream_with_ttft(
                        get_llm_stream(
                            request.messages,
                            force_web_search=request.force_web_search,
                            allow_auto_realtime_web=allow_auto_realtime_web,
                            documents_active=True,
                            explicit_web_request=explicit_web_request,
                            request_id=tracker.request_id,
                        ),
                        tracker,
                    ),
                    media_type="text/event-stream",
                )

            tracker.end_total("request_routed", extra={"mode": "doc_not_found"})

            def document_not_found_stream():
                yield _document_permission_message()

            return StreamingResponse(document_not_found_stream(), media_type="text/event-stream")

        if should_web_search:
            tracker.end_total("request_routed", extra={"mode": "web_search_error_fallback"})
            return StreamingResponse(
                _wrap_stream_with_ttft(
                    get_llm_stream(
                        request.messages,
                        force_web_search=request.force_web_search,
                        allow_auto_realtime_web=allow_auto_realtime_web,
                        documents_active=True,
                        explicit_web_request=explicit_web_request,
                        request_id=tracker.request_id,
                    ),
                    tracker,
                ),
                media_type="text/event-stream",
            )

        tracker.end_total("request_routed", extra={"mode": "doc_error"})

        def document_error_stream():
            yield ERROR_SENTINEL + _document_context_error_message()

        return StreamingResponse(document_error_stream(), media_type="text/event-stream")

    if query and not documents_active and not request.force_web_search and not explicit_web_request:
        (
            knowledge_enabled,
            should_use_knowledge,
            search_internal_knowledge,
            build_knowledge_prompt,
        ) = _get_knowledge_helpers()

        if knowledge_enabled() and should_use_knowledge(query):
            tracker.start("knowledge_retrieval")
            chunks, success = await asyncio.to_thread(
                search_internal_knowledge,
                query,
                request_id=tracker.request_id,
            )
            tracker.end("knowledge_retrieval", extra={"chunks": len(chunks), "success": success})

            if success and chunks:
                knowledge_prompt, sources = build_knowledge_prompt(query, chunks)
                messages_with_knowledge = [{"role": "system", "content": knowledge_prompt}] + request.messages
                tracker.end_total("request_routed", extra={"mode": "knowledge_internal"})

                return StreamingResponse(
                    _wrap_stream_with_ttft(
                        get_llm_stream_with_sources(messages_with_knowledge, sources),
                        tracker,
                    ),
                    media_type="text/event-stream",
                )

            if not success:
                logger.warning("Knowledge retrieval failed; falling back to general chat")

    tracker.end_total("request_routed", extra={"mode": "general_chat", "reason": reason_code})
    return StreamingResponse(
        _wrap_stream_with_ttft(
            get_llm_stream(
                request.messages,
                force_web_search=request.force_web_search,
                allow_auto_realtime_web=allow_auto_realtime_web,
                documents_active=False,
                explicit_web_request=explicit_web_request,
                request_id=tracker.request_id,
            ),
            tracker,
        ),
        media_type="text/event-stream",
    )


def _wrap_stream_with_ttft(
    generator,
    tracker: LatencyTracker,
):
    """
    Wrap generator LLM untuk mengukur TTFT (time to first token) dan total LLM duration.
    Tidak log isi token — hanya timing metadata.
    Hanya mendukung sync generator — get_llm_stream dan get_llm_stream_with_sources
    keduanya bertipe Generator[str, None, None] sehingga path async tidak diperlukan.
    """
    import time

    from app.services.latency_logger import log_latency

    first_chunk = True
    t_llm_start = time.perf_counter()
    chunk_count = 0

    for chunk in generator:
        if first_chunk:
            ttft_ms = (time.perf_counter() - t_llm_start) * 1000.0
            log_latency("llm_first_chunk", ttft_ms, request_id=tracker.request_id)
            first_chunk = False
        chunk_count += 1
        yield chunk

    llm_total_ms = (time.perf_counter() - t_llm_start) * 1000.0
    log_latency(
        "llm_stream_end",
        llm_total_ms,
        request_id=tracker.request_id,
        extra={"chunks": chunk_count},
    )
