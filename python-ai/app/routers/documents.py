from fastapi import APIRouter, UploadFile, File, Depends, HTTPException, Header, Form
import os
import shutil
import uuid
from pydantic import BaseModel
from app.services.rag_service import process_document, delete_document_vectors, get_document_chunks_for_summarization

try:
    from app.config_loader import get_summarize_single_prompt, get_summarize_partial_prompt, get_summarize_final_prompt
    CONFIG_AVAILABLE = True
except ImportError:
    CONFIG_AVAILABLE = False

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
    
    def get_single_prompt(doc_content: str) -> str:
        if CONFIG_AVAILABLE:
            try:
                template = get_summarize_single_prompt()
                if template:
                    return template.format(document=doc_content or "")
            except Exception:
                pass
        return f"""Buatkan ringkasan yang jelas dan padat dari dokumen berikut. 
Ringkasan harus mencakup poin-poin utama dan informasi penting.

Dokumen:
{doc_content}

---

Buat ringkasan dalam Bahasa Indonesia (maksimal 500 kata):"""

    def get_partial_prompt(batch: str, part_num: int, total: int) -> str:
        if CONFIG_AVAILABLE:
            try:
                template = get_summarize_partial_prompt()
                if template:
                    return template.format(batch=batch or "", part_number=part_num, total_parts=total)
            except Exception:
                pass
        return f"""Buatkan ringkasan singkat dari bagian dokumen berikut.
Ini adalah bagian {part_num} dari {total} bagian dokumen.

Dokumen:
{batch}

---

Berikan ringkasan singkat (maksimal 100 kata) dari bagian ini dalam Bahasa Indonesia:"""

    def get_final_prompt(combined: str) -> str:
        if CONFIG_AVAILABLE:
            try:
                template = get_summarize_final_prompt()
                if template:
                    return template.format(combined_summaries=combined or "")
            except Exception:
                pass
        return f"""Berdasarkan ringkasan bagian-bagian berikut, buat ringkasan keseluruhan yang komprehensif.

Ringkasan Bagian:
{combined}

---

Buat ringkasan keseluruhan yang jelas dan terstruktur dalam Bahasa Indonesia (maksimal 500 kata):"""

    if len(batches) == 1:
        summarize_prompt = get_single_prompt(batches[0])
        
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
        partial_prompt = get_partial_prompt(batch, i + 1, len(batches))
        
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
    
    final_prompt = get_final_prompt(combined_summaries)
    
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
