"""Knowledge base ingest API router.

This module exposes endpoints used by the Laravel admin to ingest internal
knowledge documents into Chroma. The ingest pipeline is shared with the
per-user document ingest, but every chunk is tagged with metadata that
clearly differentiates it from personal documents:

  - ``scope`` = ``global_internal``
  - ``audience`` = ``all_users``
  - ``status`` = ``active``
  - ``user_id`` = the synthetic ``__knowledge__`` namespace, which guarantees
    that personal-document filters (``user_id == real_user_id``) cannot match
    knowledge vectors.
"""

from __future__ import annotations

import os
import uuid

from fastapi import APIRouter, Depends, File, Form, HTTPException, Query, UploadFile

from app.api_shared import verify_token
from app.routers.documents import (
    ALLOWED_EXTENSIONS,
    MAX_UPLOAD_BYTES,
    _require_safe_filename,
    _require_supported_extension,
    _stream_upload_with_limit,
)
from app.services import rag_ingest

router = APIRouter(prefix="/api/knowledge", tags=["Knowledge"])


KNOWLEDGE_USER_ID = "__knowledge__"
DEFAULT_SCOPE = "global_internal"
DEFAULT_AUDIENCE = "all_users"
DEFAULT_STATUS = "active"


def _process_knowledge_document(
    file_path: str,
    filename: str,
    document_id: str,
    *,
    scope: str = DEFAULT_SCOPE,
    audience: str = DEFAULT_AUDIENCE,
    status: str = DEFAULT_STATUS,
    knowledge_source_id: str | None = None,
    title: str | None = None,
):
    """Ingest a knowledge document via the shared rag pipeline.

    The chunks emitted by ``rag_ingest.process_document`` get extra metadata
    for the knowledge namespace appended through ``process_document`` metadata overrides.
    """

    overrides = {
        "scope": scope,
        "audience": audience,
        "knowledge_status": status,
    }
    if knowledge_source_id:
        overrides["knowledge_source_id"] = knowledge_source_id
    if title:
        overrides["knowledge_title"] = title[:191]

    return rag_ingest.process_document(
        file_path=file_path,
        filename=filename,
        user_id=KNOWLEDGE_USER_ID,
        document_id=document_id,
        metadata_overrides=overrides,
    )


def _delete_knowledge_vectors(
    filename: str,
    document_id: str,
    cleanup_legacy: bool = False,
):
    return rag_ingest.delete_document_vectors(
        filename,
        user_id=KNOWLEDGE_USER_ID,
        document_id=document_id if document_id else None,
        cleanup_legacy=cleanup_legacy,
    )


@router.post("/process", dependencies=[Depends(verify_token)])
def upload_knowledge(
    file: UploadFile = File(...),
    document_id: str = Form(...),
    knowledge_source_id: str = Form(""),
    scope: str = Form(DEFAULT_SCOPE),
    audience: str = Form(DEFAULT_AUDIENCE),
    title: str = Form(""),
):
    """Ingest a knowledge document and tag it with knowledge metadata."""

    if not document_id:
        raise HTTPException(status_code=400, detail="document_id is required for knowledge ingest.")

    safe_filename = _require_safe_filename(file.filename)
    _require_supported_extension(safe_filename)

    temp_dir = "temp_files"
    os.makedirs(temp_dir, exist_ok=True)

    file_id = str(uuid.uuid4())
    temp_file_path = os.path.join(temp_dir, f"knowledge_{file_id}_{safe_filename}")

    try:
        _stream_upload_with_limit(file, temp_file_path)

        success, message = _process_knowledge_document(
            temp_file_path,
            safe_filename,
            document_id=document_id,
            scope=scope or DEFAULT_SCOPE,
            audience=audience or DEFAULT_AUDIENCE,
            knowledge_source_id=knowledge_source_id or None,
            title=title or None,
        )

        if success:
            return {
                "status": "success",
                "message": message,
                "filename": safe_filename,
                "scope": scope or DEFAULT_SCOPE,
                "audience": audience or DEFAULT_AUDIENCE,
                "document_id": document_id,
            }

        raise HTTPException(status_code=500, detail=message)
    except HTTPException:
        raise
    except Exception as exc:  # pragma: no cover — defensive guard
        raise HTTPException(status_code=500, detail=str(exc)) from exc
    finally:
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)


@router.delete("/{filename}", dependencies=[Depends(verify_token)])
async def delete_knowledge(
    filename: str,
    document_id: str = Query(""),
    cleanup_legacy: bool = Query(False),
):
    if not document_id:
        raise HTTPException(status_code=400, detail="document_id is required to delete knowledge vectors.")

    success, message = _delete_knowledge_vectors(
        filename,
        document_id=document_id,
        cleanup_legacy=cleanup_legacy,
    )

    if success:
        return {"status": "success", "message": message}

    raise HTTPException(status_code=500, detail=message)


__all__ = [
    "router",
    "KNOWLEDGE_USER_ID",
    "DEFAULT_SCOPE",
    "DEFAULT_AUDIENCE",
    "DEFAULT_STATUS",
    "_process_knowledge_document",
    "_delete_knowledge_vectors",
]
