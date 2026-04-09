import os
import json
import logging
import time
import requests
from typing import List, Tuple, Optional, Dict
import re
from dotenv import load_dotenv

# Ensure .env is loaded (for standalone imports)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), '.env'))

from langchain_community.document_loaders import UnstructuredFileLoader
from langchain_text_splitters import RecursiveCharacterTextSplitter
from langchain_chroma import Chroma
from langchain_core.embeddings import Embeddings
from langchain_openai import OpenAIEmbeddings
from langchain_core.documents import Document
from app.services.langsearch_service import LangSearchService

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

CHROMA_PATH = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "chroma_data")
EMBEDDING_TIMEOUT = int(os.getenv("EMBEDDING_TIMEOUT", "30"))

# Embedding model list untuk fallback
EMBEDDING_MODELS = [
    {
        "name": "GitHub Models (OpenAI Large) - Primary",
        "provider": "github",
        "model": "text-embedding-3-large",
        "api_key_env": "GITHUB_TOKEN",
    },
    {
        "name": "GitHub Models (OpenAI Large) - Backup",
        "provider": "github",
        "model": "text-embedding-3-large",
        "api_key_env": "GITHUB_TOKEN_2",
    },
    {
        "name": "GitHub Models (OpenAI Small) - Backup 2",
        "provider": "github",
        "model": "text-embedding-3-small",
        "api_key_env": "GITHUB_TOKEN",
    },
    {
        "name": "GitHub Models (OpenAI Small) - Backup 3",
        "provider": "github",
        "model": "text-embedding-3-small",
        "api_key_env": "GITHUB_TOKEN_2",
    }
]



def get_embeddings_with_fallback() -> Tuple[Optional[Embeddings], str]:
    """
    Mendapatkan embedding model dengan fallback mechanism.
    Urutan: GitHub Models (OpenAI text-embedding-3-large)
    
    Returns:
        Tuple[Optional[Embeddings], str]: (embedding_object, provider_name)
    """
    for model_config in EMBEDDING_MODELS:
        api_key = os.getenv(model_config["api_key_env"])
        if not api_key:
            logger.warning(f"⚠️ {model_config['name']}: API key tidak ditemukan, pastikan GITHUB_TOKEN ada di .env")
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
                logger.info(f"✅ Menggunakan {model_config['name']} untuk embeddings (Copilot Pro - 10M Tokens/min)")
                return embeddings, model_config["name"]
                
        except Exception as e:
            error_msg = str(e)
            logger.warning(f"⚠️ {model_config['name']} gagal: {error_msg}")
            
    # Semua provider gagal
    logger.error("❌ Semua embedding provider gagal!")
    return None, "none"

def process_document(file_path: str, filename: str, user_id: str = "unknown"):
    """
    Parses a document, splits it into chunks, generates embeddings,
    and stores them in ChromaDB.
    
    Dengan fallback mechanism dan rate limiting untuk mencegah quota exhausted.
    
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
            logger.info(f"File size: {os.path.getsize(file_path)} bytes")
        
        # 1. Load document
        logger.info("Step 1: Loading document...")
        loader = UnstructuredFileLoader(file_path)
        docs = loader.load()
        logger.info(f"Loaded {len(docs)} document(s)")
        
        # 2. Split text into chunks
        logger.info("Step 2: Splitting text into chunks...")
        text_splitter = RecursiveCharacterTextSplitter(
            chunk_size=1000,
            chunk_overlap=200,
            add_start_index=True
        )
        chunks = text_splitter.split_documents(docs)
        logger.info(f"Created {len(chunks)} chunks")
        
        # 3. Get embedding model dengan fallback
        logger.info("Step 3: Initializing embedding model dengan fallback...")
        embeddings, provider_name = get_embeddings_with_fallback()
        
        if embeddings is None:
            raise Exception("Semua embedding provider gagal. Tidak dapat memproses dokumen.")
        
        # Add metadata untuk tracking (including user_id for authorization)
        for chunk in chunks:
            chunk.metadata["filename"] = filename
            chunk.metadata["user_id"] = str(user_id)
            chunk.metadata["embedding_model"] = provider_name
            
        # 4. Store in ChromaDB dengan rate limiting
        logger.info(f"Step 4: Generating embeddings dan storing ke ChromaDB...")
        logger.info(f"Using provider: {provider_name}")
        logger.info(f"Batching: 10 chunks per request, 1.5s delay antar batch")
        
        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )
        
        # Memproses chunk dalam batching (batch size 10) untuk efisiensi HTTP API 
        # dan mencegah HF Spaces Nginx Drop / Rate Limits
        batch_size = 10
        successful_chunks = 0
        failed_chunks = 0
        
        for i in range(0, len(chunks), batch_size):
            batch = chunks[i:i+batch_size]
            try:
                # Add delay untuk setiap batch untuk mencegah rate limit HF Spaces / provider lain
                if i > 0 and provider_name != "GitHub Models (OpenAI Large)":
                    time.sleep(1.5)
                
                vectorstore.add_documents(batch)
                successful_chunks += len(batch)
                logger.info(f"Progress: {successful_chunks}/{len(chunks)} chunks processed...")
                    
            except Exception as batch_error:
                error_msg = str(batch_error)
                logger.error(f"❌ Error processing batch {i//batch_size + 1}: {error_msg}")
                
                # Jika error atau rate limit, coba fallback ke provider berikutnya
                if "429" in error_msg or "RESOURCE_EXHAUSTED" in error_msg or "rate limit" in error_msg.lower() or "503" in error_msg:
                    logger.warning(f"🚫 Rate limit detected pada batch {i//batch_size + 1}, mencoba fallback...")
                    
                    # Coba dapatkan provider berikutnya
                    embeddings, provider_name = get_embeddings_with_fallback()
                    if embeddings is None:
                        failed_chunks += len(batch)
                        raise Exception(f"Semua embedding provider gagal setelah {successful_chunks} chunks berhasil.")
                    
                    # Update vectorstore dengan embedding model baru
                    vectorstore = Chroma(
                        collection_name="documents_collection",
                        embedding_function=embeddings,
                        persist_directory=CHROMA_PATH
                    )
                    
                    # Update metadata untuk sisa chunk
                    for remaining_chunk in chunks[i:]:
                        remaining_chunk.metadata["embedding_model"] = provider_name
                    
                    # Retry batch yang gagal
                    try:
                        time.sleep(2)  # Extra delay setelah rate limit
                        vectorstore.add_documents(batch)
                        successful_chunks += len(batch)
                        logger.info(f"✅ Batch {i//batch_size + 1} berhasil dengan {provider_name}")
                    except Exception as retry_error:
                        logger.error(f"❌ Batch {i//batch_size + 1} tetap gagal setelah fallback: {retry_error}")
                        failed_chunks += len(batch)
                        continue
                else:
                    failed_chunks += len(batch)
        
        # Summary
        logger.info(f"✅ Document '{filename}' processed successfully")
        logger.info(f"Summary: {successful_chunks}/{len(chunks)} chunks berhasil, {failed_chunks} gagal")
        logger.info(f"Embedding provider used: {provider_name}")
        
        if failed_chunks > 0:
            return True, f"Document processed dengan {failed_chunks} chunks gagal (total: {len(chunks)})"
        
        return True, "Document processed successfully."
        
    except Exception as e:
        logger.error(f"❌ Error processing document '{filename}': {type(e).__name__}: {str(e)}")
        return False, str(e)

def delete_document_vectors(filename: str):
    """
    Removes all vector embeddings associated with a specific filename from ChromaDB.
    """
    try:
        # Gunakan embedding model dengan fallback
        embeddings, provider_name = get_embeddings_with_fallback()
        
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
    Search for relevant document chunks based on query.
    
    Args:
        query: User query string
        filenames: Optional list of filenames to filter by
        top_k: Number of top chunks to return
        user_id: User ID for authorization filtering (required for security)
    
    Returns:
        Tuple of (list of chunks with metadata, bool indicating success)
    """
    try:
        embeddings, provider_name = get_embeddings_with_fallback()
        
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
        
        # Search for similar documents with filter
        docs = vectorstore.similarity_search_with_score(query, k=top_k, filter=filter_dict)
        
        results = []
        for doc, score in docs:
            chunk_info = {
                "content": doc.page_content,
                "score": float(score),
                "filename": doc.metadata.get("filename", "unknown"),
                "chunk_index": doc.metadata.get("chunk_index", 0),
                "embedding_model": doc.metadata.get("embedding_model", provider_name)
            }
            results.append(chunk_info)
        
        logger.info(f"📚 RAG: Found {len(results)} relevant chunks for query: '{query}'")
        return results, True
        
    except Exception as e:
        logger.error(f"❌ Error searching chunks: {str(e)}")
        return [], False


def build_rag_prompt(question: str, chunks: List[Dict], include_sources: bool = True) -> Tuple[str, List[Dict]]:
    """
    Build RAG prompt from question and relevant chunks.
    
    Args:
        question: User question
        chunks: List of chunk dictionaries from search_relevant_chunks
        include_sources: Whether to include source references
    
    Returns:
        Tuple of (formatted prompt, list of source metadata)
    """
    if not chunks:
        return question, []
    
    # Format chunks as context
    context_parts = []
    sources = []
    
    for i, chunk in enumerate(chunks):
        context_parts.append(f"--- Chunk {i+1} ---")
        context_parts.append(chunk.get("content", ""))
        context_parts.append("")
        
        if include_sources:
            sources.append({
                "filename": chunk.get("filename", "unknown"),
                "chunk_index": chunk.get("chunk_index", 0),
                "relevance_score": chunk.get("score", 0)
            })
    
    context_str = "\n".join(context_parts)
    
    rag_prompt = f"""Berdasarkan dokumen-dokumen berikut, jawab pertanyaan user.

Dokumen yang menjadi referensi:
{context_str}

---

Pertanyaan: {question}

Instruksi: 
- Jawab berdasarkan informasi dari dokumen-dokumen di atas
- Jika jawaban tidak ditemukan di dokumen, katakan bahwa tidak ada informasi terkait
- Cantumkan nama dokumen yang menjadi sumber referensi

Jawaban:"""
    
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
        embeddings, provider_name = get_embeddings_with_fallback()
        
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
        
        embeddings, provider_name = get_embeddings_with_fallback()
        
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
        
        # Estimate tokens (rough: ~4 chars per token for Indonesian/English mix)
        est_tokens = sum(len(c) for c in chunks) // 4
        logger.info(f"Estimated tokens: {est_tokens}")
        
        # If within limit, return all chunks as single batch
        if est_tokens <= max_tokens:
            all_content = "\n\n".join([f"--- Bagian {i+1} ---\n{c}" for i, c in enumerate(chunks)])
            return True, [all_content], total_chunks
        
        # Hierarchical summarization: group chunks and create batches
        logger.info(f"Document too large ({est_tokens} tokens), implementing chunked summarization...")
        
        # Group chunks into batches (approximately max_tokens each)
        batch_size = max(1, len(chunks) // (est_tokens // max_tokens + 1))
        batches = []
        
        for i in range(0, len(chunks), batch_size):
            batch_chunks = chunks[i:i + batch_size]
            batch_content = "\n\n".join([f"--- Bagian {j+1} ---\n{c}" for j, c in enumerate(batch_chunks)])
            batches.append(batch_content)
        
        logger.info(f"Created {len(batches)} batches for hierarchical summarization")
        return True, batches, total_chunks
        
    except Exception as e:
        logger.error(f"❌ Error getting chunks for summarization: {str(e)}")
        return False, [], 0


# LangSearch service instance
_langsearch_service = None

def get_langsearch_service() -> LangSearchService:
    """Get or create LangSearch service instance."""
    global _langsearch_service
    if _langsearch_service is None:
        _langsearch_service = LangSearchService()
    return _langsearch_service


def get_context_for_query(query: str, force_web_search: bool = False) -> Dict:
    """
    Get context for LLM dari LangSearch + RAG documents.
    
    Priority: LangSearch first -> RAG fallback -> LLM knowledge
    
    Returns:
        Dict dengan keys:
            - search_results: List[Dict] dari LangSearch
            - rag_documents: List[Document] dari ChromaDB
            - has_search: bool
            - has_rag: bool
            - search_context: str (formatted untuk system prompt)
    """
    # Step 1: Filter sapaan (Greetings) agar tidak membuang kuota web search
    langsearch = get_langsearch_service()
    search_results = []
    
    query_clean = re.sub(r'[^\w\s]', '', query.lower().strip())
    greetings = ["hai", "halo", "hello", "hi", "siapa kamu", "siapa anda", "kamu siapa", "test", "tes", "terima kasih", "makasih", "ok", "oke", "pagi", "siang", "sore", "malam", "terimakasih"]
    
    is_greeting = query_clean in greetings or (len(query_clean.split()) <= 3 and ("siapa" in query_clean or "hai" in query_clean or "halo" in query_clean))
    
    # Memaksa pencarian web jika tombol dihidupkan, dan bukan sekedar sapaan pendek
    if force_web_search:
        search_results = langsearch.search(query)
    elif not is_greeting and len(query_clean) > 2:
        search_results = langsearch.search(query)
        
    has_search = len(search_results) > 0
    
    # Build search context for system prompt
    search_context = langsearch.build_search_context(search_results) if has_search else ""
    
    # Step 2: Get RAG documents (existing logic)
    rag_documents = []
    has_rag = False
    
    try:
        embeddings, provider_name = get_embeddings_with_fallback()
        if embeddings:
            vectorstore = Chroma(
                collection_name="documents_collection",
                embedding_function=embeddings,
                persist_directory=CHROMA_PATH
            )
            
            # Search for relevant documents
            docs = vectorstore.similarity_search(query, k=3)
            if docs:
                rag_documents = docs
                has_rag = True
                logger.info(f"📚 RAG: Found {len(docs)} relevant documents for query: '{query}'")
    except Exception as e:
        logger.warning(f"⚠️ RAG search failed: {str(e)}")
    
    return {
        "search_results": search_results,
        "rag_documents": rag_documents,
        "has_search": has_search,
        "has_rag": has_rag,
        "search_context": search_context
    }


def get_rag_context_for_prompt(query: str, base_rag_prompt: str = "", force_web_search: bool = False) -> str:
    """
    Build context string untuk inject ke system prompt.
    
    Args:
        query: User query
        base_rag_prompt: Existing RAG prompt from embeddings (optional)
    
    Returns:
        Formatted context string untuk system prompt
    """
    context_data = get_context_for_query(query, force_web_search=force_web_search)
    
    result_parts = []
    
    # Add search context (already formatted with date + results)
    if context_data["search_context"]:
        result_parts.append(context_data["search_context"])
    
    # Add RAG documents only if no search results
    if context_data["has_rag"] and not context_data["has_search"]:
        if base_rag_prompt:
            result_parts.append(base_rag_prompt)
        else:
            result_parts.append("Relevant documents from knowledge base:")
            result_parts.append("")
            for doc in context_data["rag_documents"]:
                result_parts.append(f"- {doc.page_content[:200]}...")
            result_parts.append("")
    
    return "\n".join(result_parts)
