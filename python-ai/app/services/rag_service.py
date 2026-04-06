import os
import json
import logging
import time
import requests
from typing import List, Tuple, Optional
from langchain_community.document_loaders import UnstructuredFileLoader
from langchain_text_splitters import RecursiveCharacterTextSplitter
from langchain_google_genai import GoogleGenerativeAIEmbeddings
from langchain_chroma import Chroma
from langchain_core.embeddings import Embeddings
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type
import shutil

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

CHROMA_PATH = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "chroma_data")
EMBEDDING_TIMEOUT = int(os.getenv("EMBEDDING_TIMEOUT", "30"))

# Embedding model list untuk fallback
EMBEDDING_MODELS = [
    # Lightweight Embeddings - PRIMARY (FREE UNLIMITED)
    {
        "name": "Lightweight Embeddings",
        "provider": "lightweight",
        "model": os.getenv("LIGHTWEIGHT_EMBEDDINGS_MODEL", "bge-m3"),
        "api_key_env": None,  # No API key required!
    },
    # Fallback providers
    {
        "name": "Gemini",
        "provider": "google",
        "model": "models/gemini-embedding-001",
        "api_key_env": "GEMINI_API_KEY",
    },
    {
        "name": "Jina AI",
        "provider": "jina",
        "model": "jina-embeddings-v5-text-small",
        "api_key_env": "JINA_API_KEY",
    },
    {
        "name": "Qwen",
        "provider": "qwen",
        "model": "text-embedding-v3",
        "api_key_env": "QWEN_API_KEY",
    }
]

class JinaAIEmbeddings(Embeddings):
    """Custom Jina AI Embeddings implementation menggunakan REST API."""
    
    def __init__(self, api_key: str, model: str = "jina-embeddings-v5-text-small"):
        self.api_key = api_key
        self.model = model
        self.api_url = "https://api.jina.ai/v1/embeddings"
        
    def embed_documents(self, texts: List[str]) -> List[List[float]]:
        """Embed daftar dokumen menggunakan Jina AI API."""
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.api_key}"
        }
        
        payload = {
            "model": self.model,
            "task": "retrieval.passage",  # Optimize untuk dokumen yang akan di-retrieve
            "normalized": True,  # L2 normalization untuk cosine similarity
            "input": texts
        }
        
        response = requests.post(self.api_url, json=payload, headers=headers, timeout=EMBEDDING_TIMEOUT)
        response.raise_for_status()
        
        data = response.json()
        embeddings = [item["embedding"] for item in data["data"]]
        return embeddings
    
    def embed_query(self, text: str) -> List[float]:
        """Embed single query menggunakan Jina AI API."""
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.api_key}"
        }
        
        payload = {
            "model": self.model,
            "task": "retrieval.query",  # Optimize untuk search query
            "normalized": True,
            "input": [text]
        }
        
        response = requests.post(self.api_url, json=payload, headers=headers, timeout=EMBEDDING_TIMEOUT)
        response.raise_for_status()
        
        data = response.json()
        return data["data"][0]["embedding"]


class QwenEmbeddings(Embeddings):
    """
    Custom Qwen/DashScope Embeddings implementation menggunakan OpenAI-compatible API.
    
    Model: text-embedding-v3
    Dimensi: 1024 (default)
    Free Quota: 500,000 token (90 hari)
    Rate Limit: Lebih tinggi dari Gemini free tier
    """
    
    def __init__(self, api_key: str, model: str = "text-embedding-v3"):
        self.api_key = api_key
        self.model = model
        # DashScope OpenAI-compatible endpoint
        self.api_url = "https://dashscope.aliyuncs.com/compatible-mode/v1/embeddings"
        
    def embed_documents(self, texts: List[str]) -> List[List[float]]:
        """Embed daftar dokumen menggunakan Qwen/DashScope API."""
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.api_key}"
        }
        
        payload = {
            "model": self.model,
            "input": texts,
            "encoding_format": "float"
        }
        
        response = requests.post(self.api_url, json=payload, headers=headers, timeout=EMBEDDING_TIMEOUT)
        response.raise_for_status()
        
        data = response.json()
        embeddings = [item["embedding"] for item in data["data"]]
        return embeddings
    
    def embed_query(self, text: str) -> List[float]:
        """Embed single query menggunakan Qwen/DashScope API."""
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.api_key}"
        }
        
        payload = {
            "model": self.model,
            "input": [text],
            "encoding_format": "float"
        }
        
        response = requests.post(self.api_url, json=payload, headers=headers, timeout=EMBEDDING_TIMEOUT)
        response.raise_for_status()
        
        data = response.json()
        return data["data"][0]["embedding"]


class LightweightEmbeddings(Embeddings):
    """
    Lightweight Embeddings - FREE UNLIMITED API service.
    
    Host: HuggingFace Spaces
    URL: https://lamhieu-lightweight-embeddings.hf.space
    Models: bge-m3, multilingual-e5-large, snowflake-arctic-embed-l-v2.0, etc.
    
    Features:
    - No API key required
    - No rate limits
    - 100+ languages
    - Max 8192 tokens (bge-m3)
    """
    
    def __init__(self, model: str = "bge-m3"):
        self.model = model
        self.api_url = os.getenv(
            "LIGHTWEIGHT_EMBEDDINGS_URL",
            "https://lamhieu-lightweight-embeddings.hf.space/v1/embeddings"
        )
        
    def embed_documents(self, texts: List[str]) -> List[List[float]]:
        """Embed daftar dokumen menggunakan Lightweight Embeddings API."""
        headers = {
            "Content-Type": "application/json"
        }
        
        payload = {
            "model": self.model,
            "input": texts
        }
        
        try:
            response = requests.post(
                self.api_url, 
                json=payload, 
                headers=headers, 
                timeout=EMBEDDING_TIMEOUT
            )
            response.raise_for_status()
            
            try:
                data = response.json()
            except json.JSONDecodeError as e:
                raise ValueError(f"Invalid JSON response from Lightweight API: {e}")
            
            if "data" not in data or not data["data"]:
                raise ValueError("Invalid response structure from Lightweight API")
            
            embeddings = [item["embedding"] for item in data["data"]]
            return embeddings
        except requests.exceptions.RequestException as e:
            logger.error(f"Lightweight Embeddings API error: {e}")
            raise
    
    def embed_query(self, text: str) -> List[float]:
        """Embed single query menggunakan Lightweight Embeddings API."""
        headers = {
            "Content-Type": "application/json"
        }
        
        payload = {
            "model": self.model,
            "input": [text]
        }
        
        try:
            response = requests.post(
                self.api_url, 
                json=payload, 
                headers=headers, 
                timeout=EMBEDDING_TIMEOUT
            )
            response.raise_for_status()
            
            try:
                data = response.json()
            except json.JSONDecodeError as e:
                raise ValueError(f"Invalid JSON response from Lightweight API: {e}")
            
            if "data" not in data or not data["data"]:
                raise ValueError("Invalid response structure from Lightweight API")
            
            return data["data"][0]["embedding"]
        except requests.exceptions.RequestException as e:
            logger.error(f"Lightweight Embeddings API error: {e}")
            raise


def get_embeddings():
    """Initializes and returns the Google Generative AI embeddings model."""
    # Model yang tersedia: gemini-embedding-001 (stable), gemini-embedding-2-preview (preview)
    return GoogleGenerativeAIEmbeddings(model="models/gemini-embedding-001")

def get_embeddings_with_fallback() -> Tuple[Optional[Embeddings], str]:
    """
    Mendapatkan embedding model dengan fallback mechanism.
    Urutan: Lightweight Embeddings → Gemini → Jina AI → Qwen
    
    Returns:
        Tuple[Optional[Embeddings], str]: (embedding_object, provider_name)
    """
    for model_config in EMBEDDING_MODELS:
        # Lightweight Embeddings tidak memerlukan API key
        if model_config["api_key_env"] is not None:
            api_key = os.getenv(model_config["api_key_env"])
            if not api_key:
                logger.warning(f"⚠️ {model_config['name']}: API key tidak ditemukan, skip...")
                continue
        else:
            api_key = None  # No API key needed (e.g., Lightweight Embeddings)
        
        try:
            # Lightweight Embeddings handler - PRIMARY (FREE UNLIMITED)
            if model_config["provider"] == "lightweight":
                embeddings = LightweightEmbeddings(model=model_config["model"])
                # Test dengan embedding sederhana
                _ = embeddings.embed_query("test")
                logger.info(f"✅ Menggunakan {model_config['name']} untuk embeddings (FREE UNLIMITED)")
                return embeddings, model_config["name"]
                
            elif model_config["provider"] == "google":
                embeddings = GoogleGenerativeAIEmbeddings(model=model_config["model"])
                # Test dengan embedding sederhana
                _ = embeddings.embed_query("test")
                logger.info(f"✅ Menggunakan {model_config['name']} untuk embeddings")
                return embeddings, model_config["name"]
                
            elif model_config["provider"] == "jina":
                embeddings = JinaAIEmbeddings(api_key=api_key, model=model_config["model"])
                # Test dengan embedding sederhana
                _ = embeddings.embed_query("test")
                logger.info(f"✅ Menggunakan {model_config['name']} untuk embeddings")
                return embeddings, model_config["name"]
            
            elif model_config["provider"] == "qwen":
                embeddings = QwenEmbeddings(api_key=api_key, model=model_config["model"])
                # Test dengan embedding sederhana
                _ = embeddings.embed_query("test")
                logger.info(f"✅ Menggunakan {model_config['name']} untuk embeddings")
                return embeddings, model_config["name"]
                
        except Exception as e:
            error_msg = str(e)
            logger.warning(f"⚠️ {model_config['name']} gagal: {error_msg}")
            
            # Check jika rate limit error
            if "429" in error_msg or "RESOURCE_EXHAUSTED" in error_msg or "rate limit" in error_msg.lower():
                logger.warning(f"🚫 {model_config['name']} rate limit tercapai, mencoba provider berikutnya...")
            continue
    
    # Semua provider gagal
    logger.error("❌ Semua embedding provider gagal!")
    return None, "none"

def process_document(file_path: str, filename: str):
    """
    Parses a document, splits it into chunks, generates embeddings,
    and stores them in ChromaDB.
    
    Dengan fallback mechanism dan rate limiting untuk mencegah quota exhausted.
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
        
        # Add metadata untuk tracking
        for chunk in chunks:
            chunk.metadata["filename"] = filename
            chunk.metadata["embedding_model"] = provider_name
            
        # 4. Store in ChromaDB dengan rate limiting
        logger.info(f"Step 4: Generating embeddings dan storing ke ChromaDB...")
        logger.info(f"Using provider: {provider_name}")
        logger.info(f"Rate limiting: 600ms delay per chunk untuk mencegah rate limit...")
        
        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )
        
        # Process chunks dengan rate limiting (600ms delay antar chunk)
        # Ini membatasi ke ~100 requests/minute (safe untuk free tier)
        successful_chunks = 0
        failed_chunks = 0
        
        for i, chunk in enumerate(chunks):
            try:
                # Add delay только untuk non-Lightweight providers (yang butuh rate limiting)
                if i > 0 and provider_name != "Lightweight Embeddings":
                    time.sleep(0.6)  # 600ms delay
                
                vectorstore.add_documents([chunk])
                successful_chunks += 1
                
                # Log progress setiap 5 chunks
                if (i + 1) % 5 == 0:
                    logger.info(f"Progress: {i + 1}/{len(chunks)} chunks processed...")
                    
            except Exception as chunk_error:
                failed_chunks += 1
                error_msg = str(chunk_error)
                logger.error(f"❌ Error processing chunk {i + 1}: {error_msg}")
                
                # Jika rate limit, coba fallback ke provider berikutnya
                if "429" in error_msg or "RESOURCE_EXHAUSTED" in error_msg:
                    logger.warning(f"🚫 Rate limit detected pada chunk {i + 1}, mencoba fallback...")
                    
                    # Coba dapatkan provider berikutnya
                    embeddings, provider_name = get_embeddings_with_fallback()
                    if embeddings is None:
                        raise Exception(f"Semua embedding provider gagal setelah {successful_chunks} chunks berhasil.")
                    
                    # Update vectorstore dengan embedding model baru
                    vectorstore = Chroma(
                        collection_name="documents_collection",
                        embedding_function=embeddings,
                        persist_directory=CHROMA_PATH
                    )
                    
                    # Update metadata
                    for remaining_chunk in chunks[i:]:
                        remaining_chunk.metadata["embedding_model"] = provider_name
                    
                    # Retry chunk yang gagal
                    try:
                        time.sleep(1)  # Extra delay setelah rate limit
                        vectorstore.add_documents([chunk])
                        successful_chunks += 1
                        logger.info(f"✅ Chunk {i + 1} berhasil dengan {provider_name}")
                    except Exception as retry_error:
                        logger.error(f"❌ Chunk {i + 1} tetap gagal setelah fallback: {retry_error}")
                        continue
        
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
