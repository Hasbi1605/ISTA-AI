import os
import uuid
from typing import Any

from fastapi import APIRouter, Depends, File, Form, HTTPException, Query, UploadFile
from fastapi.responses import Response
from pydantic import BaseModel, Field

from app.api_shared import verify_token
from app.config_loader import (
    get_summarize_final_prompt,
    get_summarize_partial_prompt,
    get_summarize_single_prompt,
)
from app.document_runner import run_document_process
from app.services.document_content import extract_document_content_html
from app.services.document_export import export_content
from app.services.table_extraction import extract_tables_from_file

router = APIRouter(prefix="/api/documents", tags=["Documents"])

ALLOWED_EXTENSIONS: frozenset[str] = frozenset({"pdf", "docx", "xlsx", "csv"})
MAX_UPLOAD_BYTES = int(os.environ.get("MAX_DOCUMENT_UPLOAD_BYTES", str(50 * 1024 * 1024)))


def _require_safe_filename(filename: str | None) -> str:
    if filename is None:
        raise HTTPException(status_code=400, detail="Nama file tidak valid.")

    cleaned = filename.strip()
    if not cleaned:
        raise HTTPException(status_code=400, detail="Nama file tidak valid.")

    if "/" in cleaned or "\\" in cleaned:
        raise HTTPException(status_code=400, detail="Nama file tidak valid.")

    if "\x00" in cleaned:
        raise HTTPException(status_code=400, detail="Nama file tidak valid.")

    base = os.path.basename(cleaned)
    if base in {"", ".", ".."} or base != cleaned:
        raise HTTPException(status_code=400, detail="Nama file tidak valid.")

    return base


def _require_supported_extension(filename: str) -> str:
    ext = filename.rsplit(".", 1)[-1].lower() if "." in filename else ""
    if ext not in ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=400,
            detail=f"Tipe file .{ext} tidak didukung. Gunakan: {', '.join(sorted(ALLOWED_EXTENSIONS))}",
        )

    return ext


def _stream_upload_with_limit(upload_file: UploadFile, dest_path: str) -> None:
    """Write *upload_file* to *dest_path* in chunks.

    Raises HTTP 413 before writing more than ``MAX_UPLOAD_BYTES`` so the server
    never buffers an unbounded upload fully to disk.
    """
    written = 0
    with open(dest_path, "wb") as buffer:
        while True:
            chunk_data = upload_file.file.read(1024 * 1024)
            if not chunk_data:
                break
            written += len(chunk_data)
            if written > MAX_UPLOAD_BYTES:
                raise HTTPException(
                    status_code=413,
                    detail=f"File melebihi batas maksimum {MAX_UPLOAD_BYTES // (1024 * 1024)} MB.",
                )
            buffer.write(chunk_data)


def delete_document_vectors(*args, **kwargs):
    from app.services.rag_ingest import delete_document_vectors as _delete_document_vectors

    return _delete_document_vectors(*args, **kwargs)


def get_document_chunks_for_summarization(*args, **kwargs):
    from app.services.rag_summarization import (
        get_document_chunks_for_summarization as _get_document_chunks_for_summarization,
    )

    return _get_document_chunks_for_summarization(*args, **kwargs)


@router.post("/process", dependencies=[Depends(verify_token)])
def upload_document(
    file: UploadFile = File(...),
    user_id: str = Form(...),
    document_id: str = Form(""),
):
    """
    Endpoint for uploading and processing a document into vector embeddings.

    Heavy ingest runs in a subprocess so OCR / ML libraries do not stay resident
    in the lightweight API worker after the request completes.
    """
    temp_dir = "temp_files"
    os.makedirs(temp_dir, exist_ok=True)

    file_id = str(uuid.uuid4())
    safe_filename = _require_safe_filename(file.filename)
    _require_supported_extension(safe_filename)
    temp_file_path = os.path.join(temp_dir, f"{file_id}_{safe_filename}")

    try:
        with open(temp_file_path, "wb") as buffer:
            written = 0
            while True:
                chunk_data = file.file.read(1024 * 1024)
                if not chunk_data:
                    break
                written += len(chunk_data)
                if written > MAX_UPLOAD_BYTES:
                    raise HTTPException(
                        status_code=413,
                        detail=f"File melebihi batas maksimum {MAX_UPLOAD_BYTES // (1024 * 1024)} MB.",
                    )
                buffer.write(chunk_data)

        success, message, metrics = run_document_process(
            temp_file_path,
            safe_filename,
            user_id,
            document_id=document_id,
        )

        if success:
            return {
                "status": "success",
                "message": message,
                "filename": safe_filename,
                **metrics,
            }

        raise HTTPException(status_code=500, detail=message)

    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
    finally:
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)


@router.delete("/{filename}", dependencies=[Depends(verify_token)])
async def delete_document(
    filename: str,
    user_id: str = Query(..., min_length=1),
    document_id: str = Query(""),
    cleanup_legacy: bool = Query(False),
):
    success, message = delete_document_vectors(
        filename,
        user_id=user_id,
        document_id=document_id if document_id else None,
        cleanup_legacy=cleanup_legacy,
    )
    if success:
        return {"status": "success", "message": message}
    raise HTTPException(status_code=500, detail=message)


class SummarizeRequest(BaseModel):
    filename: str
    user_id: str
    document_id: str = ""
    runtime_config: dict[str, Any] | None = None


class ExportRequest(BaseModel):
    content_html: str = Field(..., max_length=512000)
    target_format: str
    file_name: str | None = None


def _render_prompt(template: str, **kwargs) -> str:
    rendered = template.format(**kwargs)
    if not rendered.strip():
        raise RuntimeError("Prompt summarization kosong setelah dirender")
    return rendered


def _render_prompt_or_http_exception(template: str, **kwargs) -> str:
    try:
        return _render_prompt(template, **kwargs)
    except (RuntimeError, KeyError, IndexError) as exc:
        raise HTTPException(status_code=500, detail=f"Gagal merender prompt: {exc}") from exc

def _generate_summary_text(
    get_llm_stream,
    messages: list[dict[str, str]],
    runtime_config: dict[str, Any] | None,
) -> str:
    full_response = ""
    kwargs = {"runtime_config": runtime_config} if runtime_config else {}
    for chunk in get_llm_stream(messages, **kwargs):
        full_response += chunk

    if "[MODEL:" in full_response:
        full_response = full_response.split("]", 1)[1] if "]" in full_response else full_response

    return full_response


@router.post("/extract-tables", dependencies=[Depends(verify_token)])
def extract_tables_endpoint(file: UploadFile = File(...)):
    temp_dir = "temp_files"
    os.makedirs(temp_dir, exist_ok=True)

    file_id = str(uuid.uuid4())
    safe_filename = _require_safe_filename(file.filename)
    _require_supported_extension(safe_filename)
    temp_file_path = os.path.join(temp_dir, f"{file_id}_{safe_filename}")

    try:
        _stream_upload_with_limit(file, temp_file_path)

        tables = extract_tables_from_file(temp_file_path)

        return {
            "status": "success",
            "filename": safe_filename,
            "tables": tables,
        }
    except HTTPException:
        raise
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    finally:
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)


@router.post("/extract-content", dependencies=[Depends(verify_token)])
def extract_content_endpoint(file: UploadFile = File(...)):
    temp_dir = "temp_files"
    os.makedirs(temp_dir, exist_ok=True)

    file_id = str(uuid.uuid4())
    safe_filename = _require_safe_filename(file.filename)
    _require_supported_extension(safe_filename)
    temp_file_path = os.path.join(temp_dir, f"{file_id}_{safe_filename}")

    try:
        _stream_upload_with_limit(file, temp_file_path)

        content_html = extract_document_content_html(temp_file_path, filename=safe_filename)

        return {
            "status": "success",
            "filename": safe_filename,
            "content_html": content_html,
        }
    except HTTPException:
        raise
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    finally:
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)


@router.post("/export", dependencies=[Depends(verify_token)])
def export_document_endpoint(request: ExportRequest):
    try:
        artifact = export_content(
            request.content_html,
            request.target_format,
            file_name=request.file_name,
        )
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    headers = {
        "Content-Disposition": f'attachment; filename="{artifact.filename}"',
        "X-Content-Type-Options": "nosniff",
        "Cache-Control": "no-store",
    }

    return Response(content=artifact.content, media_type=artifact.mime_type, headers=headers)


@router.post("/summarize", dependencies=[Depends(verify_token)])
def summarize_document_endpoint(request: SummarizeRequest):
    from app.llm_manager import get_llm_stream

    if not request.filename:
        raise HTTPException(status_code=400, detail="filename is required")

    if not request.user_id:
        raise HTTPException(status_code=400, detail="user_id is required for authorization")

    success, batches, total_chunks = get_document_chunks_for_summarization(
        request.filename,
        user_id=request.user_id,
        document_id=request.document_id if request.document_id else None,
        max_tokens=8000,
    )

    if not success:
        raise HTTPException(status_code=403, detail="Dokumen tidak ditemukan atau Anda tidak memiliki akses.")

    if len(batches) == 1:
        summarize_prompt = _render_prompt_or_http_exception(
            get_summarize_single_prompt(),
            document=batches[0] or "",
        )

        messages = [{"role": "user", "content": summarize_prompt}]

        full_response = _generate_summary_text(get_llm_stream, messages, request.runtime_config)

        return {
            "status": "success",
            "summary": full_response,
            "filename": request.filename,
            "mode": "single",
            "total_chunks": total_chunks,
        }

    partial_summaries = []
    for i, batch in enumerate(batches):
        partial_prompt = _render_prompt_or_http_exception(
            get_summarize_partial_prompt(),
            batch=batch or "",
            part_number=i + 1,
            total_parts=len(batches),
        )

        batch_messages = [{"role": "user", "content": partial_prompt}]
        partial_response = _generate_summary_text(get_llm_stream, batch_messages, request.runtime_config)

        partial_summaries.append(partial_response.strip())

    combined_summaries = "\n\n".join([f"Ringkasan Bagian {i + 1}:\n{s}" for i, s in enumerate(partial_summaries)])

    final_prompt = _render_prompt_or_http_exception(
        get_summarize_final_prompt(),
        combined_summaries=combined_summaries,
    )

    final_messages = [{"role": "user", "content": final_prompt}]

    full_response = _generate_summary_text(get_llm_stream, final_messages, request.runtime_config)

    return {
        "status": "success",
        "summary": full_response,
        "filename": request.filename,
        "mode": "hierarchical",
        "total_chunks": total_chunks,
        "batches_processed": len(batches),
        "note": "Dokumen terlalu besar, menggunakan summarization bertahap",
    }
