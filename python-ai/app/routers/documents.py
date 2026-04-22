from fastapi import APIRouter, UploadFile, File, Depends, HTTPException, Header, Form
import os
import shutil
import uuid
from pydantic import BaseModel
from app.config_loader import (
    get_summarize_single_prompt,
    get_summarize_partial_prompt,
    get_summarize_final_prompt,
)
from app.services.rag_service import process_document, delete_document_vectors, get_document_chunks_for_summarization

router = APIRouter(prefix="/api/documents", tags=["Documents"])

# Security
AI_SERVICE_TOKEN = os.getenv("AI_SERVICE_TOKEN", "your_internal_api_secret")

def verify_token(authorization: str = Header(None)):
    """Simple token-based security for internal service communication."""
    if not authorization or authorization != f"Bearer {AI_SERVICE_TOKEN}":
        raise HTTPException(status_code=401, detail="Unauthorized access to AI Service.")

@router.post("/process", dependencies=[Depends(verify_token)])
async def upload_document(
    file: UploadFile = File(...),
    user_id: str = Form(...)
):
    """
    Endpoint for uploading and processing a document into vector embeddings.
    
    Args:
        file: The document file to upload
        user_id: User ID for document ownership tracking
    """
    temp_dir = "temp_files"
    os.makedirs(temp_dir, exist_ok=True)
    
    file_id = str(uuid.uuid4())
    temp_file_path = os.path.join(temp_dir, f"{file_id}_{file.filename}")
    
    try:
        # Save temp file
        with open(temp_file_path, "wb") as buffer:
            shutil.copyfileobj(file.file, buffer)
            
        success, message = process_document(temp_file_path, file.filename, user_id=user_id)
        
        # Cleanup
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)
            
        if success:
            return {"status": "success", "message": message, "filename": file.filename}
        else:
            raise HTTPException(status_code=500, detail=message)
            
    except Exception as e:
        if os.path.exists(temp_file_path):
            os.remove(temp_file_path)
        raise HTTPException(status_code=500, detail=str(e))

@router.delete("/{filename}", dependencies=[Depends(verify_token)])
async def delete_document(filename: str):
    """
    Endpoint for deleting document vector embeddings.
    """
    success, message = delete_document_vectors(filename)
    if success:
        return {"status": "success", "message": message}
    else:
        raise HTTPException(status_code=500, detail=message)


class SummarizeRequest(BaseModel):
    filename: str
    user_id: str


def _render_prompt(template: str, **kwargs) -> str:
    rendered = template.format(**kwargs)
    if not rendered.strip():
        raise RuntimeError("Prompt summarization kosong setelah dirender")
    return rendered


def _render_prompt_or_http_exception(template: str, **kwargs) -> str:
    try:
        return _render_prompt(template, **kwargs)
    except RuntimeError as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc


@router.post("/summarize", dependencies=[Depends(verify_token)])
async def summarize_document_endpoint(request: SummarizeRequest):
    """
    Endpoint for summarizing a document.
    
    Supports hierarchical summarization for large documents that exceed
    the LLM context window limit.
    
    Args:
        request: Contains filename and user_id for authorization
    """
    from app.llm_manager import get_llm_stream
    
    if not request.filename:
        raise HTTPException(status_code=400, detail="filename is required")
    
    if not request.user_id:
        raise HTTPException(status_code=400, detail="user_id is required for authorization")
    
    # Get document chunks with authorization check
    success, batches, total_chunks = get_document_chunks_for_summarization(
        request.filename, 
        user_id=request.user_id,
        max_tokens=8000  # Approximate token limit per LLM batch
    )
    
    if not success:
        raise HTTPException(status_code=403, detail="Dokumen tidak ditemukan atau Anda tidak memiliki akses.")
    
    if len(batches) == 1:
        summarize_prompt = _render_prompt_or_http_exception(
            get_summarize_single_prompt(),
            document=batches[0] or "",
        )
        
        messages = [{"role": "user", "content": summarize_prompt}]
        
        full_response = ""
        for chunk in get_llm_stream(messages):
            full_response += chunk
        
        if "[MODEL:" in full_response:
            full_response = full_response.split("]", 1)[1] if "]" in full_response else full_response
        
        return {
            "status": "success", 
            "summary": full_response, 
            "filename": request.filename,
            "mode": "single",
            "total_chunks": total_chunks
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
        partial_response = ""
        for chunk in get_llm_stream(batch_messages):
            partial_response += chunk
        
        # Remove model prefix
        if "[MODEL:" in partial_response:
            parts = partial_response.split("]", 1)
            if len(parts) > 1:
                partial_response = parts[1]
        
        partial_summaries.append(partial_response.strip())
    
    # Step 2: Combine partial summaries into final summary
    combined_summaries = "\n\n".join([f"Ringkasan Bagian {i+1}:\n{s}" for i, s in enumerate(partial_summaries)])
    
    final_prompt = _render_prompt_or_http_exception(
        get_summarize_final_prompt(),
        combined_summaries=combined_summaries,
    )
    
    final_messages = [{"role": "user", "content": final_prompt}]
    
    full_response = ""
    for chunk in get_llm_stream(final_messages):
        full_response += chunk
    
    # Remove any model prefix
    if "[MODEL:" in full_response:
        full_response = full_response.split("]", 1)[1] if "]" in full_response else full_response
    
    return {
        "status": "success", 
        "summary": full_response, 
        "filename": request.filename,
        "mode": "hierarchical",
        "total_chunks": total_chunks,
        "batches_processed": len(batches),
        "note": "Dokumen terlalu besar, menggunakan summarization bertahap"
    }
