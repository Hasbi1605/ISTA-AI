import os
import json
import hashlib
import logging
import time
import requests
from typing import List, Tuple, Optional, Dict
import re
import unicodedata
from dotenv import load_dotenv
import tiktoken
import asyncio
from concurrent.futures import ThreadPoolExecutor

# Ensure .env is loaded (for standalone imports)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), '.env'))

from langchain_community.document_loaders import UnstructuredFileLoader
from langchain_text_splitters import RecursiveCharacterTextSplitter
from langchain_chroma import Chroma
from langchain_core.embeddings import Embeddings
from langchain_openai import OpenAIEmbeddings
from langchain_core.documents import Document
from app.services.langsearch_service import LangSearchService
from app.config_loader import get_rag_prompt

EXPLICIT_WEB_PATTERNS = [
    r"\bweb\s*search\b",
    r"\bsearch\s*web\b",
    r"\bcari\s+di\s+web\b",
    r"\bcari\s+di\s+internet\b",
    r"\bpakai\s+web\b",
    r"\bpakai\s+internet\b",
    r"\bgunakan\s+web\b",
    r"\bwajib\s+web\s*search\b",
    r"\bharus\s+web\s*search\b",
    r"\bbrowse\s+web\b",
    r"\btelusuri\s+web\b",
    r"\bsearch\s+online\b",
    r"\bcek\s+internet\b",
]

REALTIME_HIGH_PATTERNS = [
    r"\bjam\s+berapa\b",
    r"\bwaktu\s+sekarang\b",
    r"\bskor\b.*\b(semalam|tadi\s+malam|hari\s+ini|live)\b",
    r"\b(semalam|tadi\s+malam|hari\s+ini)\b.*\bskor\b",
    r"\bberita\s+(terkini|terbaru)\b",
    r"\bbreaking\s+news\b",
    r"\blive\s+score\b",
    r"\b(hasil|skor)\b.*\b(vs|versus)\b.*\b(semalam|hari\s+ini|live)\b",
]

REALTIME_MEDIUM_KEYWORDS = [
    "terkini",
    "terbaru",
    "update",
    "hari ini",
    "semalam",
    "sekarang",
    "live",
    "breaking",
    "berita",
    "skor",
    "hasil pertandingan",
    "jam",
]

SCORE_QUERY_KEYWORDS = [
    "skor",
    "score",
    "hasil pertandingan",
    "hasil laga",
    "final score",
    "hasil match",
]

SCORE_PATTERN = re.compile(r"\b(\d{1,2})\s*[-:]\s*(\d{1,2})\b")

CANONICAL_TEAM_GROUPS = {
    "barcelona": ["barcelona", "barca", "fc barcelona"],
    "atletico_madrid": ["atletico madrid", "atletico de madrid", "atletico", "atm"],
    "real_madrid": ["real madrid", "madrid"],
    "psg": ["psg", "paris saint germain"],
}

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

CHROMA_PATH = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "chroma_data")
EMBEDDING_TIMEOUT = int(os.getenv("EMBEDDING_TIMEOUT", "30"))

# Token-aware chunking configuration
TOKEN_CHUNK_SIZE = int(os.getenv("TOKEN_CHUNK_SIZE", "1500"))  # Max tokens per chunk
TOKEN_CHUNK_OVERLAP = int(os.getenv("TOKEN_CHUNK_OVERLAP", "150"))  # Token overlap
AGGRESSIVE_BATCH_SIZE = int(os.getenv("AGGRESSIVE_BATCH_SIZE", "200"))  # Chunks per batch (aggressive)
BATCH_DELAY_SECONDS = float(os.getenv("BATCH_DELAY_SECONDS", "0.5"))  # Delay between batches

# Embedding model list untuk cascading fallback (4-tier system)
EMBEDDING_MODELS = [
    {
        "name": "GitHub Models (OpenAI Large) - Primary",
        "provider": "github",
        "model": "text-embedding-3-large",
        "api_key_env": "GITHUB_TOKEN",
        "tpm_limit": 500000,  # 500K TPM
        "dimensions": 3072,
    },
    {
        "name": "GitHub Models (OpenAI Large) - Backup",
        "provider": "github",
        "model": "text-embedding-3-large",
        "api_key_env": "GITHUB_TOKEN_2",
        "tpm_limit": 500000,  # 500K TPM
        "dimensions": 3072,
    },
    {
        "name": "GitHub Models (OpenAI Small) - Fallback 1",
        "provider": "github",
        "model": "text-embedding-3-small",
        "api_key_env": "GITHUB_TOKEN",
        "tpm_limit": 500000,  # 500K TPM
        "dimensions": 1536,
    },
    {
        "name": "GitHub Models (OpenAI Small) - Fallback 2",
        "provider": "github",
        "model": "text-embedding-3-small",
        "api_key_env": "GITHUB_TOKEN_2",
        "tpm_limit": 500000,  # 500K TPM
        "dimensions": 1536,
    }
]

# Initialize tiktoken encoder for token-aware chunking
try:
    TIKTOKEN_ENCODER = tiktoken.get_encoding("cl100k_base")  # OpenAI's encoding
    logger.info("✅ Tiktoken encoder initialized (cl100k_base)")
except Exception as e:
    logger.error(f"❌ Failed to initialize tiktoken: {e}")
    TIKTOKEN_ENCODER = None



def count_tokens(text: str) -> int:
    """
    Count tokens in text using tiktoken encoder.
    
    Args:
        text: Text to count tokens for
        
    Returns:
        Number of tokens
    """
    if TIKTOKEN_ENCODER is None:
        # Fallback: rough estimate (4 chars per token)
        return len(text) // 4
    
    try:
        return len(TIKTOKEN_ENCODER.encode(text))
    except Exception as e:
        logger.warning(f"⚠️ Token counting failed: {e}, using fallback estimate")
        return len(text) // 4


def get_embeddings_with_fallback(model_index: int = 0) -> Tuple[Optional[Embeddings], str, int]:
    """
    Mendapatkan embedding model dengan cascading fallback mechanism.
    Urutan: 2x Large (3072 dim) → 2x Small (1536 dim)
    Total kapasitas: 2 Juta TPM (4 x 500K TPM)
    
    Args:
        model_index: Starting index in EMBEDDING_MODELS list (for cascading)
    
    Returns:
        Tuple[Optional[Embeddings], str, int]: (embedding_object, provider_name, model_index)
    """
    for idx in range(model_index, len(EMBEDDING_MODELS)):
        model_config = EMBEDDING_MODELS[idx]
        api_key = os.getenv(model_config["api_key_env"])
        
        if not api_key:
            logger.warning(f"⚠️ {model_config['name']}: API key tidak ditemukan")
            continue
        
        try:
            if model_config["provider"] == "github":
                embeddings = OpenAIEmbeddings(
                    model=model_config["model"],
                    openai_api_base="https://models.inference.ai.azure.com",
                    openai_api_key=api_key
                )
                # Test dengan embedding sederhana
                _ = embeddings.embed_query("test")
                logger.info(f"✅ Menggunakan {model_config['name']} (TPM: {model_config['tpm_limit']:,}, Dim: {model_config['dimensions']})")
                return embeddings, model_config["name"], idx
                
        except Exception as e:
            error_msg = str(e)
            logger.warning(f"⚠️ {model_config['name']} gagal: {error_msg}")
            
    # Semua provider gagal
    logger.error("❌ Semua embedding provider gagal! Total kapasitas: 2M TPM habis atau tidak tersedia")
    return None, "none", -1

def process_document(file_path: str, filename: str, user_id: str = "unknown"):
    """
    Parses a document, splits it into chunks using Token-Aware Recursive Chunking,
    generates embeddings with Aggressive Batching, and stores them in ChromaDB.
    
    Implementasi Update Tahap 5:
    - Token-Aware Recursive Chunking (tiktoken cl100k_base)
    - Aggressive Batching (200+ chunks per batch)
    - Cascading Fallback 4-tier (2M TPM total capacity)
    - Circuit Breaker untuk rate limit handling
    
    Args:
        file_path: Path to the document file
        filename: Original filename
        user_id: User ID for authorization filtering
    """
    try:
        logger.info(f"=== Processing document: {filename} ===")
        logger.info(f"File path: {file_path}")
        logger.info(f"File exists: {os.path.exists(file_path)}")
        if os.path.exists(file_path):
            file_size = os.path.getsize(file_path)
            logger.info(f"File size: {file_size:,} bytes ({file_size / 1024 / 1024:.2f} MB)")
        
        # 1. Load document
        logger.info("Step 1: Loading document...")
        loader = UnstructuredFileLoader(file_path)
        docs = loader.load()
        logger.info(f"Loaded {len(docs)} document(s)")
        
        # Calculate total content size
        total_content = "".join([doc.page_content for doc in docs])
        total_chars = len(total_content)
        estimated_tokens = count_tokens(total_content)
        logger.info(f"Total content: {total_chars:,} chars, ~{estimated_tokens:,} tokens")
        
        # 2. Token-Aware Recursive Chunking
        logger.info(f"Step 2: Token-Aware Recursive Chunking (chunk_size={TOKEN_CHUNK_SIZE}, overlap={TOKEN_CHUNK_OVERLAP})...")
        
        # Use RecursiveCharacterTextSplitter with token counting
        text_splitter = RecursiveCharacterTextSplitter(
            chunk_size=TOKEN_CHUNK_SIZE,
            chunk_overlap=TOKEN_CHUNK_OVERLAP,
            length_function=count_tokens,  # Use token-based counting
            add_start_index=True,
            separators=["\n\n", "\n", ". ", " ", ""]  # Prioritize semantic boundaries
        )
        chunks = text_splitter.split_documents(docs)
        logger.info(f"Created {len(chunks)} token-aware chunks")
        
        # Log chunk statistics
        if chunks:
            chunk_tokens = [count_tokens(chunk.page_content) for chunk in chunks]
            avg_tokens = sum(chunk_tokens) / len(chunk_tokens)
            max_tokens = max(chunk_tokens)
            min_tokens = min(chunk_tokens)
            logger.info(f"Chunk stats: avg={avg_tokens:.0f} tokens, min={min_tokens}, max={max_tokens}")
        
        # 3. Get embedding model dengan cascading fallback
        logger.info("Step 3: Initializing embedding model dengan cascading fallback...")
        current_model_index = 0
        embeddings, provider_name, current_model_index = get_embeddings_with_fallback(current_model_index)
        
        if embeddings is None:
            raise Exception("Semua embedding provider gagal. Tidak dapat memproses dokumen.")
        
        # Add metadata untuk tracking (including user_id for authorization)
        for chunk in chunks:
            chunk.metadata["filename"] = filename
            chunk.metadata["user_id"] = str(user_id)
            chunk.metadata["embedding_model"] = provider_name
            
        # 4. Smart Batching dengan Token Limit Validation
        # OpenAI embedding API limit: 64,000 tokens per request (not per minute)
        MAX_TOKENS_PER_BATCH = 60000  # Safe limit below 64K
        logger.info(f"Step 4: Smart Batching & Embedding Generation...")
        logger.info(f"Max batch size: {AGGRESSIVE_BATCH_SIZE} chunks OR {MAX_TOKENS_PER_BATCH:,} tokens (whichever is smaller)")
        logger.info(f"Total capacity: 2M TPM across 4 models (4 x 500K TPM)")
        
        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )
        
        successful_chunks = 0
        failed_chunks = 0
        
        # Create smart batches based on token count
        smart_batches = []
        current_batch = []
        current_batch_tokens = 0
        
        for chunk in chunks:
            chunk_tokens = count_tokens(chunk.page_content)
            
            # Check if adding this chunk would exceed limits
            would_exceed_tokens = (current_batch_tokens + chunk_tokens) > MAX_TOKENS_PER_BATCH
            would_exceed_count = len(current_batch) >= AGGRESSIVE_BATCH_SIZE
            
            if (would_exceed_tokens or would_exceed_count) and current_batch:
                # Save current batch and start new one
                smart_batches.append((current_batch, current_batch_tokens))
                current_batch = [chunk]
                current_batch_tokens = chunk_tokens
            else:
                current_batch.append(chunk)
                current_batch_tokens += chunk_tokens
        
        # Add remaining batch
        if current_batch:
            smart_batches.append((current_batch, current_batch_tokens))
        
        total_batches = len(smart_batches)
        logger.info(f"Created {total_batches} smart batches (token-aware)")
        
        for batch_index, (batch, batch_tokens) in enumerate(smart_batches, 1):
            try:
                # Add minimal delay between batches (aggressive mode)
                if batch_index > 1:
                    time.sleep(BATCH_DELAY_SECONDS)
                
                logger.info(f"Processing batch {batch_index}/{total_batches}: {len(batch)} chunks, {batch_tokens:,} tokens...")
                vectorstore.add_documents(batch)
                successful_chunks += len(batch)
                logger.info(f"✅ Batch {batch_index}/{total_batches} success | Progress: {successful_chunks}/{len(chunks)} chunks")
                    
            except Exception as batch_error:
                error_msg = str(batch_error)
                logger.error(f"❌ Batch {batch_index} error: {error_msg}")
                
                # Circuit Breaker: Detect rate limit and cascade to next model
                is_rate_limit = any(indicator in error_msg.lower() for indicator in [
                    "429", "rate limit", "resource_exhausted", "quota", "503", "too many requests"
                ])
                
                if is_rate_limit and current_model_index < len(EMBEDDING_MODELS) - 1:
                    logger.warning(f"🚫 Rate limit detected! Cascading to next model tier...")
                    
                    # Cascade to next model in the fallback chain
                    current_model_index += 1
                    embeddings, provider_name, current_model_index = get_embeddings_with_fallback(current_model_index)
                    
                    if embeddings is None:
                        failed_chunks += len(batch)
                        logger.error(f"❌ All 4 models exhausted! Failed after {successful_chunks} chunks")
                        raise Exception(f"Semua 4 embedding models gagal (2M TPM exhausted) setelah {successful_chunks} chunks berhasil.")
                    
                    # Update vectorstore dengan embedding model baru
                    vectorstore = Chroma(
                        collection_name="documents_collection",
                        embedding_function=embeddings,
                        persist_directory=CHROMA_PATH
                    )
                    
                    # Update metadata untuk sisa chunk
                    # Calculate starting index based on accumulated batches
                    remaining_start = sum(len(b[0]) for b in smart_batches[:batch_index-1])
                    for remaining_chunk in chunks[remaining_start:]:
                        remaining_chunk.metadata["embedding_model"] = provider_name
                    
                    # Retry batch dengan model baru (dengan exponential backoff)
                    retry_delay = 2.0
                    max_retries = 3
                    
                    for retry in range(max_retries):
                        try:
                            logger.info(f"🔄 Retry {retry + 1}/{max_retries} dengan {provider_name}...")
                            time.sleep(retry_delay)
                            vectorstore.add_documents(batch)
                            successful_chunks += len(batch)
                            logger.info(f"✅ Batch {batch_index} berhasil dengan {provider_name} (retry {retry + 1})")
                            break
                        except Exception as retry_error:
                            retry_error_msg = str(retry_error)
                            logger.warning(f"⚠️ Retry {retry + 1} failed: {retry_error_msg}")
                            
                            # Check if still rate limit or token limit
                            is_rate_limit_retry = any(indicator in retry_error_msg.lower() for indicator in ["429", "rate limit", "quota"])
                            is_token_limit = any(indicator in retry_error_msg.lower() for indicator in ["413", "tokens_limit_reached", "too large"])
                            
                            if is_token_limit:
                                # Token limit error - batch is too large, need to split
                                logger.error(f"❌ Batch {batch_index} exceeds token limit even after smart batching")
                                failed_chunks += len(batch)
                                break
                            
                            if is_rate_limit_retry:
                                if retry < max_retries - 1:
                                    retry_delay *= 2  # Exponential backoff
                                    continue
                                else:
                                    # Try next model if available
                                    if current_model_index < len(EMBEDDING_MODELS) - 1:
                                        current_model_index += 1
                                        embeddings, provider_name, current_model_index = get_embeddings_with_fallback(current_model_index)
                                        if embeddings:
                                            vectorstore = Chroma(
                                                collection_name="documents_collection",
                                                embedding_function=embeddings,
                                                persist_directory=CHROMA_PATH
                                            )
                                            # Update metadata for remaining chunks
                                            remaining_start = sum(len(b[0]) for b in smart_batches[:batch_index-1])
                                            for remaining_chunk in chunks[remaining_start:]:
                                                remaining_chunk.metadata["embedding_model"] = provider_name
                                            continue
                            
                            # Final failure
                            if retry == max_retries - 1:
                                logger.error(f"❌ Batch {batch_index} gagal setelah {max_retries} retries")
                                failed_chunks += len(batch)
                            break
                else:
                    # Check if it's a token limit error (413)
                    is_token_limit = any(indicator in error_msg.lower() for indicator in ["413", "tokens_limit_reached", "too large", "body too large"])
                    
                    if is_token_limit:
                        logger.error(f"❌ Batch {batch_index} exceeds token limit ({batch_tokens:,} tokens)")
                        logger.error(f"💡 Suggestion: Reduce TOKEN_CHUNK_SIZE or AGGRESSIVE_BATCH_SIZE in .env")
                        failed_chunks += len(batch)
                    else:
                        # Non-rate-limit error or no more fallback models
                        logger.error(f"❌ Batch {batch_index} gagal (non-rate-limit atau no fallback)")
                        failed_chunks += len(batch)
        
        # Summary
        success_rate = (successful_chunks / len(chunks) * 100) if len(chunks) > 0 else 0
        logger.info(f"{'='*60}")
        logger.info(f"✅ Document '{filename}' processing completed")
        logger.info(f"Success: {successful_chunks}/{len(chunks)} chunks ({success_rate:.1f}%)")
        logger.info(f"Failed: {failed_chunks} chunks")
        logger.info(f"Final embedding model: {provider_name}")
        logger.info(f"Total tokens processed: ~{estimated_tokens:,}")
        logger.info(f"{'='*60}")
        
        if failed_chunks > 0:
            return True, f"Document processed dengan {failed_chunks}/{len(chunks)} chunks gagal"
        
        return True, "Document processed successfully dengan Token-Aware Chunking & Aggressive Batching."
        
    except Exception as e:
        logger.error(f"❌ Error processing document '{filename}': {type(e).__name__}: {str(e)}")
        return False, str(e)

def delete_document_vectors(filename: str):
    """
    Removes all vector embeddings associated with a specific filename from ChromaDB.
    """
    try:
        # Gunakan embedding model dengan fallback
        embeddings, provider_name, _ = get_embeddings_with_fallback()
        
        if embeddings is None:
            return False, "Tidak dapat menginisialisasi embedding model untuk delete operation."
        
        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )
        
        # In Chroma, we can filter by metadata to delete
        vectorstore.delete(where={"filename": filename})
        logger.info(f"✅ Vectors for {filename} deleted successfully using {provider_name}")
        return True, f"Vectors for {filename} deleted successfully."
    except Exception as e:
        logger.error(f"❌ Error deleting vectors for {filename}: {str(e)}")
        return False, str(e)



def search_relevant_chunks(query: str, filenames: List[str] = None, top_k: int = 5, user_id: str = None) -> Tuple[List[Dict], bool]:
    """
    Search for relevant document chunks based on query with optional reranking.
    
    Args:
        query: User query string
        filenames: Optional list of filenames to filter by
        top_k: Number of top chunks to return
        user_id: User ID for authorization filtering (required for security)
    
    Returns:
        Tuple of (list of chunks with metadata, bool indicating success)
    """
    try:
        embeddings, provider_name, _ = get_embeddings_with_fallback()
        
        if embeddings is None:
            return [], False
        
        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )
        
        # Build filter - ALWAYS require user_id for security
        if user_id is None:
            logger.error("❌ Security: user_id is required for RAG search")
            return [], False
        
        # Start with user_id filter
        filter_dict = {"user_id": str(user_id)}
        
        # Add filename filter if provided
        if filenames and len(filenames) > 0:
            # Combine with user_id using $and
            filename_filter = {"$or": [{"filename": fname} for fname in filenames]} if len(filenames) > 1 else {"filename": filenames[0]}
            filter_dict = {"$and": [filter_dict, filename_filter]}
            logger.info(f"🔍 RAG: Filtering by filenames: {filenames} for user_id: {user_id}")
        else:
            logger.info(f"🔍 RAG: Filtering by user_id: {user_id} (all user documents)")
        
        # Check if rerank is enabled
        langsearch_service = get_langsearch_service()
        rerank_enabled = os.getenv("LANGSEARCH_RERANK_ENABLED", "true").lower() == "true"
        
        if rerank_enabled:
            # Get more candidates for reranking
            doc_candidates = int(os.getenv("LANGSEARCH_RERANK_DOC_CANDIDATES", "20"))
            # Search for more candidates than needed
            docs = vectorstore.similarity_search_with_score(query, k=doc_candidates, filter=filter_dict)
            
            if len(docs) >= 2:
                # Extract document contents for reranking
                documents = [doc.page_content for doc, _ in docs]
                
                # Get rerank results
                doc_top_n = int(os.getenv("LANGSEARCH_RERANK_DOC_TOP_N", str(top_k)))
                rerank_results = langsearch_service.rerank_documents(
                    query=query,
                    documents=documents,
                    top_n=doc_top_n,
                    return_documents=False
                )
                
                if rerank_results:
                    # Map rerank results back to original documents
                    reranked_chunks = []
                    for result in rerank_results:
                        idx = result.get("index")
                        if idx is not None and idx < len(docs):
                            doc, vector_score = docs[idx]
                            chunk_info = {
                                "content": doc.page_content,
                                "score": float(vector_score),  # Original vector score
                                "rerank_score": float(result.get("relevance_score", 0)),  # Rerank score
                                "filename": doc.metadata.get("filename", "unknown"),
                                "chunk_index": doc.metadata.get("chunk_index", 0),
                                "embedding_model": doc.metadata.get("embedding_model", provider_name)
                            }
                            reranked_chunks.append(chunk_info)
                    
                    logger.info(f"📚 RAG: Reranked {len(reranked_chunks)} chunks using LangSearch (%s)", _query_log_meta(query))
                    return reranked_chunks[:top_k], True
                else:
                    logger.warning(f"⚠️ RAG: Rerank failed, falling back to vector search (%s)", _query_log_meta(query))
        
        # Fallback to standard vector search (or if rerank disabled)
        # Use top_k from already fetched docs to avoid redundant query
        fallback_docs = docs[:top_k] if len(docs) >= top_k else docs
        
        results = []
        for doc, score in fallback_docs:
            chunk_info = {
                "content": doc.page_content,
                "score": float(score),
                "filename": doc.metadata.get("filename", "unknown"),
                "chunk_index": doc.metadata.get("chunk_index", 0),
                "embedding_model": doc.metadata.get("embedding_model", provider_name)
            }
            results.append(chunk_info)
        
        logger.info("📚 RAG: Found %s relevant chunks (%s)", len(results), _query_log_meta(query))
        return results, True
        
    except Exception as e:
        logger.error(f"❌ Error searching chunks: {str(e)}")
        return [], False


def build_rag_prompt(
    question: str,
    chunks: List[Dict],
    include_sources: bool = True,
    web_context: str = "",
) -> Tuple[str, List[Dict]]:
    """
    Build RAG prompt from question and relevant chunks.
    
    Args:
        question: User question
        chunks: List of chunk dictionaries from search_relevant_chunks
        include_sources: Whether to include source references.
    
    Returns:
        Tuple of (formatted prompt, list of source metadata)
    """
    if not chunks:
        return question, []
    
    # Format chunks as context
    context_parts = []
    sources = []
    
    for i, chunk in enumerate(chunks):
        filename = chunk.get("filename", "Dokumen Tidak Diketahui")
        context_parts.append(f"--- Referensi dari Dokumen: {filename} ---")
        context_parts.append(chunk.get("content", ""))
        context_parts.append("")
        
        if include_sources:
            sources.append({
                "filename": chunk.get("filename", "unknown"),
                "chunk_index": chunk.get("chunk_index", 0),
                "relevance_score": chunk.get("score", 0)
            })
    
    context_str = "\n".join(context_parts)
    web_section = ""
    if web_context.strip():
        web_section = f"""
Informasi web terbaru (digunakan jika relevan):
{web_context}
"""

    rag_prompt_template = get_rag_prompt()
    rag_prompt = rag_prompt_template.format(
        context_str=context_str or "",
        web_section=web_section or "",
        question=question or ""
    )
    
    return rag_prompt, sources


def summarize_document(filename: str, user_id: str = None) -> Tuple[bool, str]:
    """
    Summarize a document by retrieving all chunks and sending to LLM.
    
    Args:
        filename: The filename of the document to summarize
        user_id: User ID for authorization filtering (required for security)
    
    Returns:
        Tuple of (success: bool, result: str)
    """
    try:
        logger.info(f"=== Summarizing document: {filename} ===")
        
        # Security: require user_id
        if user_id is None:
            return False, "User ID diperlukan untuk mengakses dokumen."
        
        # Get all chunks from ChromaDB for this filename
        embeddings, provider_name, _ = get_embeddings_with_fallback()
        
        if embeddings is None:
            return False, "Tidak dapat menginisialisasi embedding model."
        
        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )
        
        # Get all chunks for this filename with user_id filter for security
        docs = vectorstore.get(where={"$and": [{"filename": filename}, {"user_id": str(user_id)}]})
        
        if not docs or not docs.get("documents"):
            return False, f"Dokumen '{filename}' tidak ditemukan atau Anda tidak memiliki akses."
        
        chunks_content = docs["documents"]
        logger.info(f"Found {len(chunks_content)} chunks for summarization")
        
        # Build context from chunks
        context_parts = []
        for i, chunk in enumerate(chunks_content):
            context_parts.append(f"--- Bagian {i+1} ---\n{chunk}")
        
        context_str = "\n\n".join(context_parts)
        
        # Return the context for LLM processing (actual summarization happens in the API endpoint)
        return True, context_str
        
    except Exception as e:
        logger.error(f"❌ Error summarizing document: {str(e)}")
        return False, str(e)


def get_document_chunks_for_summarization(filename: str, user_id: str = None, max_tokens: int = 8000) -> Tuple[bool, List[str], int]:
    """
    Get document chunks for summarization, with chunking for large documents.
    
    For large documents that exceed max_tokens, this function performs
    hierarchical summarization by summarizing chunks in batches.
    
    Args:
        filename: The filename of the document to summarize
        user_id: User ID for authorization filtering
        max_tokens: Maximum tokens per batch (approximate)
    
    Returns:
        Tuple of (success: bool, list of chunk_contexts, total_chunks)
    """
    try:
        logger.info(f"=== Getting chunks for summarization: {filename} ===")
        
        # Security: require user_id
        if user_id is None:
            return False, [], 0
        
        embeddings, provider_name, _ = get_embeddings_with_fallback()
        
        if embeddings is None:
            return False, [], 0
        
        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )
        
        # Get all chunks with user_id filter
        docs = vectorstore.get(where={"$and": [{"filename": filename}, {"user_id": str(user_id)}]})
        
        if not docs or not docs.get("documents"):
            return False, [], 0
        
        chunks = docs["documents"]
        total_chunks = len(chunks)
        logger.info(f"Found {total_chunks} chunks for summarization")
        
        # Estimate tokens using token counter
        est_tokens = sum(count_tokens(c) for c in chunks)
        logger.info(f"Estimated tokens: {est_tokens:,}")
        
        # If within limit, return all chunks as single batch
        if est_tokens <= max_tokens:
            all_content = "\n\n".join([f"--- Bagian {i+1} ---\n{c}" for i, c in enumerate(chunks)])
            return True, [all_content], total_chunks
        
        # Hierarchical summarization: group chunks and create batches
        logger.info(f"Document too large ({est_tokens:,} tokens), implementing chunked summarization...")
        
        # Group chunks into batches (approximately max_tokens each)
        batches = []
        current_batch = []
        current_tokens = 0
        
        for chunk in chunks:
            chunk_tokens = count_tokens(chunk)
            
            if current_tokens + chunk_tokens > max_tokens and current_batch:
                # Save current batch and start new one
                batch_content = "\n\n".join([f"--- Bagian {j+1} ---\n{c}" for j, c in enumerate(current_batch)])
                batches.append(batch_content)
                current_batch = [chunk]
                current_tokens = chunk_tokens
            else:
                current_batch.append(chunk)
                current_tokens += chunk_tokens
        
        # Add remaining batch
        if current_batch:
            batch_content = "\n\n".join([f"--- Bagian {j+1} ---\n{c}" for j, c in enumerate(current_batch)])
            batches.append(batch_content)
        
        logger.info(f"Created {len(batches)} batches for hierarchical summarization")
        return True, batches, total_chunks
        
    except Exception as e:
        logger.error(f"❌ Error getting chunks for summarization: {str(e)}")
        return False, [], 0


# LangSearch service instance
_langsearch_service = None


def _normalize_query(query: str) -> str:
    return re.sub(r"\s+", " ", (query or "").strip().lower())


def _query_log_meta(query: str) -> str:
    cleaned = (query or "").strip()
    if not cleaned:
        return "query_len=0 query_hash=none"

    query_hash = hashlib.sha256(cleaned.encode("utf-8")).hexdigest()[:10]
    return f"query_len={len(cleaned)} query_hash={query_hash}"


def _normalize_for_match(text: str) -> str:
    if not text:
        return ""

    normalized = unicodedata.normalize("NFKD", text)
    normalized = "".join(ch for ch in normalized if not unicodedata.combining(ch))
    normalized = normalized.lower()
    normalized = normalized.replace("–", "-").replace("—", "-").replace("−", "-")
    normalized = re.sub(r"[^a-z0-9\s:\-]", " ", normalized)
    return re.sub(r"\s+", " ", normalized).strip()


def _is_score_query(query: str) -> bool:
    normalized = _normalize_for_match(query)
    if not normalized:
        return False

    has_keyword = any(keyword in normalized for keyword in SCORE_QUERY_KEYWORDS)
    has_vs = " vs " in f" {normalized} " or " versus " in f" {normalized} "
    return has_keyword or has_vs


def _extract_team_groups_from_query(query: str) -> List[List[str]]:
    normalized = _normalize_for_match(query)
    if not normalized:
        return []

    def detect_group(segment: str) -> Optional[List[str]]:
        segment = segment.strip()
        if not segment:
            return None

        for aliases in CANONICAL_TEAM_GROUPS.values():
            if any(alias in segment for alias in aliases):
                return aliases

        return None

    match = re.search(r"(.+?)\s+(?:vs|versus)\s+(.+)", normalized)
    if match:
        left_group = detect_group(match.group(1))
        right_group = detect_group(match.group(2))
        if left_group and right_group and left_group != right_group:
            return [left_group, right_group]

    detected = []
    for aliases in CANONICAL_TEAM_GROUPS.values():
        positions = [normalized.find(alias) for alias in aliases if alias in normalized]
        if positions:
            detected.append((min(positions), aliases))

    detected.sort(key=lambda item: item[0])

    if len(detected) >= 2:
        first = detected[0][1]
        second = detected[1][1]
        if first != second:
            return [first, second]

    return []


def _result_mentions_teams(text: str, team_groups: List[List[str]]) -> bool:
    if not team_groups:
        return True

    return all(any(alias in text for alias in aliases) for aliases in team_groups)


def extract_match_score_signal(query: str, results: List[Dict]) -> Optional[Dict]:
    """
    Extract the most consistent score found in search results for score-related queries.
    """
    if not results or not _is_score_query(query):
        return None

    team_groups = _extract_team_groups_from_query(query)
    score_counts: Dict[str, int] = {}
    evidence: Dict[str, List[Dict[str, str]]] = {}

    for result in results:
        title = result.get("title", "")
        snippet = result.get("snippet", "")
        url = result.get("url", "")
        source_text = f"{title} {snippet}"
        normalized_text = _normalize_for_match(source_text)

        if not _result_mentions_teams(normalized_text, team_groups):
            continue

        for match in SCORE_PATTERN.finditer(normalized_text):
            left = int(match.group(1))
            right = int(match.group(2))
            if left > 20 or right > 20:
                continue

            score = f"{left}-{right}"
            score_counts[score] = score_counts.get(score, 0) + 1
            evidence.setdefault(score, []).append({
                "title": title,
                "url": url,
            })

    if not score_counts:
        return None

    best_score, support = max(score_counts.items(), key=lambda item: item[1])
    evidences = evidence.get(best_score, [])[:3]

    return {
        "score": best_score,
        "support_count": support,
        "evidence": evidences,
    }


def _merge_search_results(primary: List[Dict], secondary: List[Dict], limit: int = 8) -> List[Dict]:
    merged: List[Dict] = []
    seen_urls = set()

    for item in (primary or []) + (secondary or []):
        url = (item.get("url") or "").strip()
        key = url or f"{item.get('title', '')}|{item.get('snippet', '')}"
        if key in seen_urls:
            continue

        merged.append(item)
        seen_urls.add(key)
        if len(merged) >= limit:
            break

    return merged


def detect_explicit_web_request(query: str) -> bool:
    """Return True when user explicitly asks to use web/internet search."""
    normalized = _normalize_query(query)
    if not normalized:
        return False

    return any(re.search(pattern, normalized) for pattern in EXPLICIT_WEB_PATTERNS)


def detect_realtime_intent_level(query: str) -> str:
    """
    Classify realtime intent level: high, medium, low.
    """
    normalized = _normalize_query(query)
    if not normalized:
        return "low"

    if any(re.search(pattern, normalized) for pattern in REALTIME_HIGH_PATTERNS):
        return "high"

    hits = sum(1 for keyword in REALTIME_MEDIUM_KEYWORDS if keyword in normalized)
    if hits >= 2:
        return "medium"
    if hits == 1 and len(normalized.split()) <= 4:
        return "medium"

    return "low"


def should_use_web_search(
    query: str,
    force_web_search: bool = False,
    explicit_web_request: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
) -> Tuple[bool, str, str]:
    """
    Decide whether web search should be used.

    Returns:
        tuple(bool should_use_web, str reason_code, str realtime_intent)
    """
    realtime_intent = detect_realtime_intent_level(query)
    explicit_detected = explicit_web_request or detect_explicit_web_request(query)

    if force_web_search:
        reason = "DOC_WEB_TOGGLE" if documents_active else "WEB_TOGGLE"
        return True, reason, realtime_intent

    if explicit_detected:
        reason = "DOC_WEB_EXPLICIT" if documents_active else "EXPLICIT_WEB"
        return True, reason, realtime_intent

    if documents_active:
        return False, "DOC_NO_WEB", realtime_intent

    if allow_auto_realtime_web and realtime_intent == "high":
        return True, "REALTIME_AUTO", realtime_intent

    return False, "NO_WEB", realtime_intent

def get_langsearch_service() -> LangSearchService:
    """Get or create LangSearch service instance."""
    global _langsearch_service
    if _langsearch_service is None:
        _langsearch_service = LangSearchService()
    return _langsearch_service


def get_context_for_query(
    query: str,
    force_web_search: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
    explicit_web_request: bool = False,
) -> Dict:
    """
    Get web search context for LLM dari LangSearch.
    
    NOTE: This function handles ONLY web search context.
    RAG document retrieval is handled separately by search_relevant_chunks()
    with proper user_id and document filtering for security.
    
    Returns:
        Dict dengan keys:
            - search_results: List[Dict] dari LangSearch
            - rag_documents: List[Document] (always empty - deprecated)
            - has_search: bool
            - has_rag: bool (always False - deprecated)
            - search_context: str (formatted untuk system prompt)
            - rag_reason_code: str (always RAG_DISABLED_FUNCTION_SCOPE)
    """
    # Step 1: Decide whether web search is allowed for this query.
    langsearch = get_langsearch_service()
    search_results = []

    should_search, reason_code, realtime_intent = should_use_web_search(
        query=query,
        force_web_search=force_web_search,
        explicit_web_request=explicit_web_request,
        allow_auto_realtime_web=allow_auto_realtime_web,
        documents_active=documents_active,
    )

    if should_search:
        logger.info("🌐 Web search enabled (%s, %s)", reason_code, _query_log_meta(query))
        search_results = langsearch.search(query)

        # For score-related questions, retry once with a focused query when no score is found.
        score_signal = extract_match_score_signal(query, search_results)
        if _is_score_query(query) and score_signal is None:
            focused_query = f"{query} final score"
            focused_results = langsearch.search(focused_query)
            search_results = _merge_search_results(search_results, focused_results)
            
        # Apply rerank to web search results if enabled
        rerank_enabled = os.getenv("LANGSEARCH_RERANK_ENABLED", "true").lower() == "true"
        
        if rerank_enabled and len(search_results) >= 2:
            # Check if we should apply rerank based on intent or force
            web_candidates = int(os.getenv("LANGSEARCH_RERANK_WEB_CANDIDATES", "10"))
            web_top_n = int(os.getenv("LANGSEARCH_RERANK_WEB_TOP_N", "5"))
            
            # Limit candidates for reranking
            candidates = search_results[:web_candidates] if len(search_results) > web_candidates else search_results
            
            if len(candidates) >= 2:
                # Prepare documents for reranking (title + snippet)
                documents = []
                for result in candidates:
                    title = result.get("title", "")
                    snippet = result.get("snippet", "")
                    # Combine title and snippet for better reranking
                    doc_text = f"{title}. {snippet}" if snippet else title
                    documents.append(doc_text)
                
                # Get rerank results
                rerank_results = langsearch.rerank_documents(
                    query=query,
                    documents=documents,
                    top_n=web_top_n,
                    return_documents=False
                )
                
                if rerank_results:
                    # Map rerank results back to original search results
                    reranked_search_results = []
                    for result in rerank_results:
                        idx = result.get("index")
                        if idx is not None and idx < len(candidates):
                            original_result = candidates[idx].copy()
                            # Add rerank score to the result
                            original_result["rerank_score"] = float(result.get("relevance_score", 0))
                            reranked_search_results.append(original_result)
                    
                    # Replace search results with reranked results
                    search_results = reranked_search_results
                    logger.info(f"🌐 Web search: Reranked {len(reranked_search_results)} results using LangSearch (%s)", _query_log_meta(query))
                else:
                    logger.warning(f"⚠️ Web search: Rerank failed, using original results (%s)", _query_log_meta(query))
    else:
        logger.info("🚫 Web search skipped (%s, %s)", reason_code, _query_log_meta(query))
        
    has_search = len(search_results) > 0
    
    # Build search context for system prompt
    search_context = langsearch.build_search_context(search_results) if has_search else ""
    score_signal = extract_match_score_signal(query, search_results)

    if score_signal and search_context:
        score_lines = [
            "",
            "FAKTA TERSTRUKTUR (HASIL DETEKSI SKOR PERTANDINGAN):",
            f"- Skor yang paling konsisten: {score_signal['score']} (dukungan {score_signal['support_count']} sumber).",
            "- Jika pertanyaan user menanyakan skor pertandingan ini, gunakan skor di atas secara eksplisit.",
        ]

        for ev in score_signal.get("evidence", []):
            title = ev.get("title", "")
            url = ev.get("url", "")
            score_lines.append(f"- Bukti: {title} | {url}")

        search_context = search_context + "\n" + "\n".join(score_lines)
    
    # Step 2: RAG document retrieval is DISABLED in this function for security
    # RAG retrieval is handled by search_relevant_chunks() with proper user_id/document filtering
    # This prevents potential global document retrieval without authorization
    rag_documents = []
    has_rag = False
    rag_reason_code = "RAG_DISABLED_FUNCTION_SCOPE"
    
    logger.info("RAG_DISABLED_FUNCTION_SCOPE: RAG retrieval not performed in get_context_for_query() - use search_relevant_chunks() instead (%s)", _query_log_meta(query))
    
    return {
        "search_results": search_results,
        "rag_documents": rag_documents,
        "has_search": has_search,
        "has_rag": has_rag,
        "search_context": search_context,
        "score_signal": score_signal,
        "reason_code": reason_code,
        "realtime_intent": realtime_intent,
        "rag_reason_code": rag_reason_code,
    }


def get_rag_context_for_prompt(
    query: str,
    base_rag_prompt: str = "",
    force_web_search: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
    explicit_web_request: bool = False,
) -> str:
    """
    Build context string untuk inject ke system prompt.
    
    NOTE: This function now only handles web search context.
    RAG document context is handled separately in the main flow via search_relevant_chunks().
    
    Args:
        query: User query
        base_rag_prompt: Existing RAG prompt from embeddings (optional, deprecated)
    
    Returns:
        Formatted context string untuk system prompt (web search only)
    """
    context_data = get_context_for_query(
        query,
        force_web_search=force_web_search,
        allow_auto_realtime_web=allow_auto_realtime_web,
        documents_active=documents_active,
        explicit_web_request=explicit_web_request,
    )
    
    result_parts = []
    
    # Add search context (already formatted with date + results)
    if context_data["search_context"]:
        result_parts.append(context_data["search_context"])
    
    # RAG documents are no longer retrieved by get_context_for_query()
    # RAG is handled separately by search_relevant_chunks() with proper filtering
    
    return "\n".join(result_parts)
