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

# Ensure .env is loaded (for standalone imports)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), '.env'))

from langchain_community.document_loaders import UnstructuredFileLoader
from langchain_text_splitters import RecursiveCharacterTextSplitter
from langchain_chroma import Chroma
from langchain_core.embeddings import Embeddings
from langchain_openai import OpenAIEmbeddings
from langchain_core.documents import Document
from app.services.langsearch_service import LangSearchService

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
        context_parts.append(f"--- Kutipan Dokumen {i+1} ---")
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

    rag_prompt = f"""Jawab pertanyaan user berdasarkan referensi berikut.

Kutipan dokumen yang menjadi referensi utama:
{context_str}
{web_section}
---

Pertanyaan: {question}

Instruksi:
- Utamakan informasi dari kutipan dokumen.
- Jika ada konteks web, gunakan hanya sebagai pelengkap yang relevan.
- Jika jawaban tidak ditemukan di dokumen, katakan bahwa tidak ada informasi terkait di dokumen.
- Cantumkan nama dokumen yang menjadi sumber referensi.
- Jangan menyebut istilah teknis internal sistem retrieval.

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
                rerank_results = langsearch_service.rerank_documents(
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
                logger.info("📚 RAG: Found %s relevant documents (%s)", len(docs), _query_log_meta(query))
    except Exception as e:
        logger.warning(f"⚠️ RAG search failed: {str(e)}")
    
    return {
        "search_results": search_results,
        "rag_documents": rag_documents,
        "has_search": has_search,
        "has_rag": has_rag,
        "search_context": search_context,
        "score_signal": score_signal,
        "reason_code": reason_code,
        "realtime_intent": realtime_intent,
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
    
    Args:
        query: User query
        base_rag_prompt: Existing RAG prompt from embeddings (optional)
    
    Returns:
        Formatted context string untuk system prompt
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
    
    # Add RAG documents only if no search results
    if context_data["has_rag"] and not context_data["has_search"] and not documents_active:
        if base_rag_prompt:
            result_parts.append(base_rag_prompt)
        else:
            result_parts.append("Relevant documents from knowledge base:")
            result_parts.append("")
            for doc in context_data["rag_documents"]:
                result_parts.append(f"- {doc.page_content[:200]}...")
            result_parts.append("")
    
    return "\n".join(result_parts)
