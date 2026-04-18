import os
import hashlib
import logging
import time
from typing import List, Tuple, Optional, Dict
import re
import unicodedata
from dotenv import load_dotenv
import tiktoken

# Ensure .env is loaded (for standalone imports)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), '.env'))

from langchain_text_splitters import RecursiveCharacterTextSplitter
from langchain_chroma import Chroma
from langchain_core.embeddings import Embeddings
from langchain_openai import OpenAIEmbeddings
from app.services.langsearch_service import LangSearchService
from app.config_loader import get_rag_prompt

# NOTE: UnstructuredFileLoader (PyTorch, ONNX, spaCy, OpenCV, Transformers) di-import
# secara lazy di dalam process_document() agar library berat ini TIDAK dimuat ke RAM
# saat server startup. Library ini hanya dibutuhkan saat ada dokumen yang diproses.

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
    # ── Waktu & Jam ────────────────────────────────────────────────────────────
    r"\bjam\s+berapa\b",
    r"\bwaktu\s+sekarang\b",

    # ── Olahraga & Skor ────────────────────────────────────────────────────────
    r"\bskor\b.*\b(semalam|tadi\s+malam|hari\s+ini|live|sekarang)\b",
    r"\b(semalam|tadi\s+malam|hari\s+ini)\b.*\bskor\b",
    r"\blive\s+score\b",
    r"\b(hasil|skor)\b.*\b(vs|versus)\b.*\b(semalam|hari\s+ini|live)\b",

    # ── Berita ─────────────────────────────────────────────────────────────────
    r"\bberita\s+(terkini|terbaru)\b",
    r"\bberita\b.*\bhari\s+ini\b",
    r"\bberita\b.*\bsekarang\b",
    r"\bbreaking\s+news\b",

    # ── Keuangan & Ekonomi (selalu realtime) ───────────────────────────────────
    r"\b(kurs|nilai\s+tukar|exchange\s+rate)\b",
    r"\bharga\s+(saham|bitcoin|crypto|kripto|emas|bbm|bensin|solar|minyak|dolar)\b",
    r"\bsaham\b.*\b(hari\s+ini|sekarang|terkini|naik|turun)\b",
    r"\bindeks\s+(saham|harga|ihsg)\b",
    r"\bihsg\b",
    r"\b(bitcoin|crypto|kripto)\b.*\b(hari\s+ini|sekarang|harga)\b",

    # ── Cuaca ──────────────────────────────────────────────────────────────────
    r"\bcuaca\b.*\b(hari\s+ini|sekarang|besok|minggu\s+ini)\b",
    r"\b(prakiraan|perkiraan)\s+cuaca\b",
    r"\bcuaca\s+(terkini|terbaru)\b",

    # ── Bencana & Darurat (selalu realtime) ────────────────────────────────────
    r"\bgempa\s*(bumi)?\b.*\b(hari\s+ini|terkini|terbaru|baru|sekarang)\b",
    r"\b(banjir|kebakaran|tsunami|longsor)\b.*\b(hari\s+ini|terkini|sekarang)\b",

    # ── Politik & Pemerintahan Realtime ────────────────────────────────────────
    r"\b(hasil\s+pemilu|quick\s+count|real\s+count|hasil\s+pilkada)\b",

    # ── Jadwal & Event Terkini ──────────────────────────────────────────────────
    r"\bjadwal\b.*\b(hari\s+ini|besok|minggu\s+ini|live)\b",
    r"\b(pertandingan|match)\b.*\b(malam\s+ini|hari\s+ini|besok|live)\b",
]

REALTIME_MEDIUM_KEYWORDS = [
    # Temporal
    "terkini", "terbaru", "update", "hari ini", "semalam",
    "sekarang", "minggu ini", "bulan ini",
    # Sports
    "live", "skor", "breaking", "hasil pertandingan", "jadwal",
    # Finance
    "kurs", "harga saham", "harga emas", "inflasi", "ekonomi",
    # News & events
    "berita", "pengumuman", "kebijakan baru",
    # Misc
    "cuaca", "gempa", "jam",
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

# ─── Chunking Configuration (Single Source of Truth: ai_config.yaml) ─────────
# Dibaca dari ai_config.yaml section `chunking`, dengan .env sebagai override.
# Untuk mengubah nilai: edit python-ai/config/ai_config.yaml → section chunking
# Untuk override per-environment: set env variable (lebih prioritas dari YAML)
try:
    from app.config_loader import get_config as _get_config
    _chunking_cfg = _get_config().get("chunking", {})
except Exception:
    _chunking_cfg = {}

def _chunking_int(env_key: str, yaml_key: str, default: int) -> int:
    """Baca dari env variable (override) → YAML → hardcoded default."""
    env_val = os.getenv(env_key)
    if env_val is not None:
        return int(env_val)
    return int(_chunking_cfg.get(yaml_key, default))

def _chunking_float(env_key: str, yaml_key: str, default: float) -> float:
    env_val = os.getenv(env_key)
    if env_val is not None:
        return float(env_val)
    return float(_chunking_cfg.get(yaml_key, default))

EMBEDDING_TIMEOUT     = _chunking_int("EMBEDDING_TIMEOUT",       "embedding_timeout",      30)
TOKEN_CHUNK_SIZE      = _chunking_int("TOKEN_CHUNK_SIZE",        "token_chunk_size",        1500)
TOKEN_CHUNK_OVERLAP   = _chunking_int("TOKEN_CHUNK_OVERLAP",     "token_chunk_overlap",     150)
AGGRESSIVE_BATCH_SIZE = _chunking_int("AGGRESSIVE_BATCH_SIZE",   "aggressive_batch_size",   100)
BATCH_DELAY_SECONDS   = _chunking_float("BATCH_DELAY_SECONDS",   "batch_delay_seconds",     0.8)
MAX_TOKENS_PER_BATCH  = _chunking_int("MAX_TOKENS_PER_BATCH",    "max_tokens_per_batch",    40000)

logger.info(
    "⚙️  Chunking config (YAML+env): chunk_size=%d overlap=%d batch=%d delay=%.1fs max_tokens=%d",
    TOKEN_CHUNK_SIZE, TOKEN_CHUNK_OVERLAP, AGGRESSIVE_BATCH_SIZE, BATCH_DELAY_SECONDS, MAX_TOKENS_PER_BATCH
)

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
        
        # 1. Load document — Tiered Loader Strategy
        # Tier 1: PyPDFLoader (cepat, 5-20 detik untuk PDF besar, tidak butuh ML libs)
        # Tier 2: UnstructuredFileLoader (lambat, 2-5 menit, tapi support semua format)
        # Lazy import UnstructuredFileLoader hanya jika tier 1 gagal/hasilnya kosong.
        import time as _time
        _load_start = _time.time()
        logger.info("Step 1: Loading document (tiered loader: PyPDF → Unstructured fallback)...")

        docs = None
        file_ext = os.path.splitext(filename)[1].lower()
        is_pdf = file_ext == ".pdf"

        # ── Tier 1: PyPDFLoader (khusus PDF, sangat cepat) ──────────────────
        if is_pdf:
            try:
                from langchain_community.document_loaders import PyPDFLoader
                logger.info("   [Tier 1] Mencoba PyPDFLoader (fast)...")
                pdf_loader = PyPDFLoader(file_path)
                docs = pdf_loader.load()

                total_text = "".join(d.page_content for d in docs).strip()
                if not total_text:
                    logger.warning("   [Tier 1] PyPDFLoader menghasilkan teks kosong (PDF mungkin ter-scan/gambar) → fallback Unstructured")
                    docs = None
                else:
                    elapsed = _time.time() - _load_start
                    logger.info(f"   [Tier 1] ✅ PyPDFLoader berhasil: {len(docs)} halaman dalam {elapsed:.1f}s")
            except Exception as pdf_err:
                logger.warning(f"   [Tier 1] PyPDFLoader gagal: {pdf_err} → fallback Unstructured")
                docs = None

        # ── Tier 2: UnstructuredFileLoader (fallback untuk non-PDF atau PDF gagal) ──
        if docs is None:
            logger.info("   [Tier 2] Menggunakan UnstructuredFileLoader (lambat tapi universal)...")
            logger.info("   ⏳ Proses ini bisa 1-5 menit untuk dokumen besar — harap tunggu...")
            from langchain_community.document_loaders import UnstructuredFileLoader
            loader = UnstructuredFileLoader(file_path)
            docs = loader.load()
            elapsed = _time.time() - _load_start
            logger.info(f"   [Tier 2] ✅ UnstructuredFileLoader selesai dalam {elapsed:.1f}s")

        logger.info(f"Loaded {len(docs)} document(s)")
        
        # Calculate total content size
        total_content = "".join([doc.page_content for doc in docs])
        total_chars = len(total_content)
        estimated_tokens = count_tokens(total_content)
        logger.info(f"Total content: {total_chars:,} chars, ~{estimated_tokens:,} tokens")

        # ── Load PDR config ──────────────────────────────────────────────────────
        try:
            from app.config_loader import get_pdr_config
            pdr_cfg = get_pdr_config()
        except Exception:
            pdr_cfg = {}
        pdr_enabled       = pdr_cfg.get('enabled', False)
        pdr_child_size    = int(pdr_cfg.get('child_chunk_size', 256))
        pdr_child_overlap = int(pdr_cfg.get('child_chunk_overlap', 32))
        pdr_parent_size   = int(pdr_cfg.get('parent_chunk_size', TOKEN_CHUNK_SIZE))
        pdr_parent_overlap= int(pdr_cfg.get('parent_chunk_overlap', TOKEN_CHUNK_OVERLAP))

        # ── Step 2: Chunking (PDR atau standard) ────────────────────────────────
        if pdr_enabled:
            logger.info(
                "Step 2: PDR Chunking (parent=%d token, child=%d token)...",
                pdr_parent_size, pdr_child_size,
            )
            # 2a. Buat Parent chunks (besar, untuk konteks LLM)
            parent_splitter = RecursiveCharacterTextSplitter(
                chunk_size=pdr_parent_size,
                chunk_overlap=pdr_parent_overlap,
                length_function=count_tokens,
                add_start_index=True,
                separators=["\n\n", "\n", ". ", " ", ""],
            )
            parent_chunks = parent_splitter.split_documents(docs)

            # 2b. Buat Child chunks (kecil, untuk embedding & retrieval)
            child_splitter = RecursiveCharacterTextSplitter(
                chunk_size=pdr_child_size,
                chunk_overlap=pdr_child_overlap,
                length_function=count_tokens,
                add_start_index=True,
                separators=["\n\n", "\n", ". ", " ", ""],
            )

            # Assign parent_id ke setiap parent, lalu buat child dari parent
            child_chunks = []
            pdr_parent_docs = []  # (parent_id, content, metadata) untuk disimpan tanpa embed

            for p_idx, parent in enumerate(parent_chunks):
                # Buat unique parent_id: hash dari filename + index + konten awal
                raw_key = f"{filename}:{user_id}:{p_idx}:{parent.page_content[:50]}"
                parent_id = hashlib.md5(raw_key.encode()).hexdigest()

                parent_meta = {
                    "filename": filename,
                    "user_id": str(user_id),
                    "chunk_type": "parent",
                    "parent_id": parent_id,
                    "parent_index": p_idx,
                }
                pdr_parent_docs.append((parent_id, parent.page_content, parent_meta))

                # Buat child dari parent ini
                children = child_splitter.split_documents([parent])
                for c_idx, child in enumerate(children):
                    child.metadata["chunk_type"] = "child"
                    child.metadata["parent_id"]  = parent_id
                    child.metadata["child_index"] = c_idx
                    child_chunks.append(child)

            chunks = child_chunks  # child yang akan di-embed
            logger.info(
                "✅ PDR: %d parent chunks + %d child chunks (ratio %.1f:1)",
                len(parent_chunks), len(child_chunks),
                len(child_chunks) / max(len(parent_chunks), 1),
            )

        else:
            # Mode standard (non-PDR) — backward compatible
            logger.info(
                "Step 2: Token-Aware Recursive Chunking (chunk_size=%d, overlap=%d)...",
                TOKEN_CHUNK_SIZE, TOKEN_CHUNK_OVERLAP,
            )
            text_splitter = RecursiveCharacterTextSplitter(
                chunk_size=TOKEN_CHUNK_SIZE,
                chunk_overlap=TOKEN_CHUNK_OVERLAP,
                length_function=count_tokens,
                add_start_index=True,
                separators=["\n\n", "\n", ". ", " ", ""],
            )
            chunks = text_splitter.split_documents(docs)
            pdr_parent_docs = []  # tidak ada parent di mode standard
            logger.info(f"Created {len(chunks)} token-aware chunks")

        # Log chunk statistics
        if chunks:
            chunk_tokens = [count_tokens(chunk.page_content) for chunk in chunks]
            avg_tokens = sum(chunk_tokens) / len(chunk_tokens)
            max_tokens_val = max(chunk_tokens)
            min_tokens_val = min(chunk_tokens)
            logger.info(
                "Chunk stats: avg=%d tokens, min=%d, max=%d",
                avg_tokens, min_tokens_val, max_tokens_val,
            )

        # Step 3. Get embedding model dengan cascading fallback
        logger.info("Step 3: Initializing embedding model dengan cascading fallback...")
        current_model_index = 0
        embeddings, provider_name, current_model_index = get_embeddings_with_fallback(current_model_index)

        if embeddings is None:
            raise Exception("Semua embedding provider gagal. Tidak dapat memproses dokumen.")

        # Add metadata untuk tracking (termasuk user_id untuk authorization)
        for chunk in chunks:
            chunk.metadata["filename"] = filename
            chunk.metadata["user_id"]  = str(user_id)
            chunk.metadata["embedding_model"] = provider_name

        # Step 4. Smart Batching & Embedding Generation
        logger.info(f"Step 4: Smart Batching & Embedding Generation...")
        logger.info(
            "Max batch size: %d chunks OR %d tokens (whichever is smaller)",
            AGGRESSIVE_BATCH_SIZE, MAX_TOKENS_PER_BATCH,
        )
        logger.info("Total capacity: 2M TPM across 4 models (4 x 500K TPM)")

        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )

        # ── PDR Step 4a: Simpan Parent chunks (tanpa embedding) ───────────────
        # Parent disimpan menggunakan raw ChromaDB client agar tidak di-embed
        if pdr_enabled and pdr_parent_docs:
            try:
                raw_col = vectorstore._collection  # akses raw ChromaDB collection
                p_ids   = [pid for pid, _, _ in pdr_parent_docs]
                p_texts = [txt for _, txt, _ in pdr_parent_docs]
                p_metas = [meta for _, _, meta in pdr_parent_docs]
                # Upsert tanpa embeddings agar tidak di-index untuk similarity search
                raw_col.upsert(
                    ids=p_ids,
                    documents=p_texts,
                    metadatas=p_metas,
                    embeddings=[[0.0] * 3072] * len(p_ids),  # dummy embedding (tidak dipakai)
                )
                logger.info("✅ PDR: %d parent chunks disimpan ke ChromaDB", len(pdr_parent_docs))
            except Exception as pe:
                logger.warning("⚠️  Gagal menyimpan parent PDR: %s", pe)


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

                # ── Deteksi jenis error ──────────────────────────────────────
                is_rate_limit = any(indicator in error_msg.lower() for indicator in [
                    "429", "rate limit", "resource_exhausted", "quota", "503", "too many requests"
                ])
                is_token_limit = any(indicator in error_msg.lower() for indicator in [
                    "413", "tokens_limit_reached", "too large", "request body too large"
                ])

                # ── Auto-split: batch terlalu besar → pecah jadi 2 sub-batch ─
                if is_token_limit and len(batch) > 1:
                    mid = len(batch) // 2
                    sub_batches = [batch[:mid], batch[mid:]]
                    logger.warning(
                        f"⚠️  Batch {batch_index} terlalu besar ({batch_tokens:,} tokens tiktoken). "
                        f"Auto-split → {len(sub_batches[0])} + {len(sub_batches[1])} chunks."
                    )
                    for si, sub in enumerate(sub_batches, 1):
                        try:
                            time.sleep(BATCH_DELAY_SECONDS)
                            vectorstore.add_documents(sub)
                            successful_chunks += len(sub)
                            logger.info(f"   ✅ Sub-batch {si}/2 berhasil: {len(sub)} chunks")
                        except Exception as sub_err:
                            logger.error(f"   ❌ Sub-batch {si}/2 gagal: {sub_err}")
                            failed_chunks += len(sub)

                # ── Rate limit → cascade ke model berikutnya ────────────────
                elif is_rate_limit and current_model_index < len(EMBEDDING_MODELS) - 1:
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
                            
                            is_token_limit_retry = any(indicator in retry_error_msg.lower() for indicator in ["413", "tokens_limit_reached", "too large"])
                            
                            if is_token_limit_retry:
                                # Token limit bahkan setelah cascade — skip batch ini
                                logger.error(f"❌ Batch {batch_index} exceeds token limit even after cascade")
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
    Mencakup child chunks (embedded) dan parent chunks (PDR, tidak di-embed).
    """
    try:
        embeddings, provider_name, _ = get_embeddings_with_fallback()

        if embeddings is None:
            return False, "Tidak dapat menginisialisasi embedding model untuk delete operation."

        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )

        # Hapus child chunks (embedded) — gunakan LangChain wrapper
        vectorstore.delete(where={"filename": filename})

        # Hapus parent chunks (PDR, tidak di-embed) — gunakan raw collection
        try:
            raw_col = vectorstore._collection
            raw_col.delete(where={"$and": [{"filename": filename}, {"chunk_type": "parent"}]})
            logger.info("✅ PDR parent chunks for %s deleted", filename)
        except Exception as pe:
            logger.debug("PDR parent delete skipped (mungkin non-PDR dokumen): %s", pe)

        logger.info("✅ Vectors for %s deleted successfully using %s", filename, provider_name)
        return True, f"Vectors for {filename} deleted successfully."
    except Exception as e:
        logger.error(f"❌ Error deleting vectors for {filename}: {str(e)}")
        return False, str(e)



# ─── Hybrid Search Helpers ────────────────────────────────────────────────────

# Pola query yang TIDAK memerlukan HyDE (summary/retrieval sederhana)
_HYDE_SKIP_PATTERNS = [
    r'^(rangkum|buat ringkasan|ringkaskan)',
    r'^(baca|baca isi|baca dan)',
    r'^(apa isi|apa saja isi)',
    r'^(jelaskan isi|jelaskan dokumen)',
    r'^(tampilkan|tunjukkan|sebutkan)',
    r'^(bandingkan isi|buat perbandingan)',
    r'^(rangkumkan|buat tabel)',
    r'^halo|^hi |^hai ',
]

# Pola query yang SANGAT mendapat manfaat dari HyDE (konseptual/analitis)
_HYDE_USE_PATTERNS = [
    r'\bmengapa\b', r'\bkenapa\b',
    r'\bbagaimana (cara|bisa|pengaruh|hubungan|dampak|peran)\b',
    r'\bapa (hubungan|perbedaan|persamaan|keterkaitan|pengaruh|dampak|peran)\b',
    r'\bapa yang dimaksud\b', r'\bjelaskan konsep\b', r'\bjelaskan teori\b',
    r'\bbuktikan\b', r'\bargumentasikan\b', r'\bankur\b', r'\bimplikasi\b',
    r'\bkritik\b', r'\bevaluasi\b', r'\banalisis\b', r'\binterpretasi\b',
]


def _should_use_hyde(query: str) -> Tuple[bool, str]:
    """
    Smart HyDE detection: putuskan apakah query butuh HyDE atau tidak.

    Returns:
        (should_use, reason) — reason digunakan untuk logging
    """
    import re
    q = query.strip().lower()
    words = q.split()

    # Terlalu pendek → skip
    if len(words) < 5:
        return False, f"query terlalu pendek ({len(words)} kata)"

    # Deteksi pola SKIP terlebih dahulu (prioritas)
    for pattern in _HYDE_SKIP_PATTERNS:
        if re.search(pattern, q):
            return False, f"pattern skip: '{pattern}'"

    # Deteksi pola AKTIF
    for pattern in _HYDE_USE_PATTERNS:
        if re.search(pattern, q):
            return True, f"pola konseptual: '{pattern}'"

    # Query panjang & mengandung tanda tanya → kemungkinan perlu HyDE
    if len(words) >= 8 and '?' in query:
        return True, f"query panjang ({len(words)} kata) dengan tanda tanya"

    # Default: skip
    return False, "tidak ada pola konseptual terdeteksi"


def _generate_hyde_query(original_query: str, timeout: int = 5, max_tokens: int = 100) -> str:
    """
    HyDE (Hypothetical Document Embeddings):
    Panggil LLM untuk membuat jawaban hipotetis singkat dari query,
    lalu gabungkan dengan query asli untuk embedding yang lebih kaya.

    Model priority untuk HyDE:
    1. Groq (Llama 3.3 70B) — tercepat, tidak kena GitHub rate limit
    2. GPT-4.1 / GPT-4o — fallback jika Groq tidak tersedia
    Gemini dilewati (provider berbeda, lebih lambat untuk task pendek)

    Returns original_query jika LLM gagal atau timeout.
    """
    if len(original_query.strip()) < 10:
        return original_query  # skip untuk query terlalu pendek

    # Potong query yang terlalu panjang agar HyDE tidak lambat
    # (HyDE hanya butuh gist dari query, bukan seluruh teks)
    query_for_hyde = original_query[:500] if len(original_query) > 500 else original_query

    try:
        import litellm
        from app.config_loader import get_chat_models
        models = get_chat_models()

        # Prioritaskan Groq (cepat, tidak pakai GitHub quota)
        # lalu model lain, skip Gemini
        def _hyde_priority(m: dict) -> int:
            name = m.get('model_name', '').lower()
            if 'groq' in name or 'llama' in name:
                return 0   # prioritas tertinggi
            if m.get('provider') == 'gemini_native':
                return 99  # skip
            return 1       # model lain (GPT-4.1, GPT-4o)

        sorted_models = sorted(models, key=_hyde_priority)

        max_attempts = 2  # batasi percobaan agar tidak block lama
        attempts = 0

        for model in sorted_models:
            if model.get('provider') == 'gemini_native':
                continue
            api_key = os.getenv(model.get('api_key_env', ''))
            if not api_key:
                continue
            if attempts >= max_attempts:
                logger.debug("HyDE: max_attempts=%d tercapai, skip", max_attempts)
                break

            kwargs = {
                'model': model['model_name'],
                'messages': [
                    {
                        'role': 'system',
                        'content': (
                            'Buat jawaban hipotetis singkat 2-3 kalimat untuk pertanyaan berikut. '
                            'Padat, faktual, gunakan kosakata yang relevan dengan topik.'
                        )
                    },
                    {'role': 'user', 'content': query_for_hyde}
                ],
                'api_key': api_key,
                'max_tokens': max_tokens,
                'timeout': timeout,
                'num_retries': 0,
                'stream': False,
            }
            if 'base_url' in model:
                kwargs['api_base'] = model['base_url']

            attempts += 1
            try:
                resp = litellm.completion(**kwargs)
                hypo = resp.choices[0].message.content.strip()
                if hypo:
                    enhanced = f"{original_query}\n{hypo}"
                    logger.info(
                        "🔮 HyDE: query enhanced +%d token (model: %s, attempt: %d/%d)",
                        len(hypo.split()), model['label'], attempts, max_attempts,
                    )
                    return enhanced
            except Exception as e:
                logger.debug("HyDE attempt %d gagal (%s): %s", attempts, model['label'], e)
                continue

    except Exception as e:
        logger.debug("HyDE skipped: %s", e)

    logger.debug("🔮 HyDE: skip — fallback ke query asli")
    return original_query


def _bm25_rank_docs(
    query: str,
    texts: List[str],
    top_k: int,
) -> List[Tuple[int, float]]:
    """
    Jalankan BM25 pada daftar teks, kembalikan (original_index, normalized_score)
    terurut dari yang paling relevan.
    """
    try:
        import re
        from rank_bm25 import BM25Okapi

        def _tokenize(t: str) -> List[str]:
            return [w for w in re.split(r'\W+', t.lower()) if w]

        corpus = [_tokenize(t) for t in texts]
        bm25 = BM25Okapi(corpus)
        scores = bm25.get_scores(_tokenize(query))

        max_score = max(scores) if any(s > 0 for s in scores) else 1.0
        if max_score == 0:
            max_score = 1.0

        indexed = [(i, float(scores[i]) / max_score) for i in range(len(scores))]
        indexed.sort(key=lambda x: -x[1])
        return indexed[:top_k]

    except ImportError:
        logger.warning("⚠️  rank-bm25 tidak terinstall — BM25 dinonaktifkan")
        return [(i, 0.0) for i in range(min(top_k, len(texts)))]
    except Exception as e:
        logger.warning("⚠️  BM25 error: %s", e)
        return [(i, 0.0) for i in range(min(top_k, len(texts)))]


def _rrf_merge_docs(
    vector_docs: List[Tuple],       # list of (Document, score)
    bm25_indexed: List[Tuple[int, float]],  # (index_in_stored, bm25_score)
    stored_texts: List[str],         # all stored texts (BM25 corpus)
    stored_metas: List[dict],        # metadata untuk stored_texts
    top_k: int,
    bm25_weight: float = 0.3,
    k: int = 60,
) -> List[Tuple]:
    """
    Reciprocal Rank Fusion: gabungkan vector dan BM25 rankings.

    score(doc) = (1-bm25_weight)/(k+vector_rank) + bm25_weight/(k+bm25_rank)

    Mengembalikan list (Document, combined_score) terurut descending.
    """
    # Gunakan 50 karakter pertama konten sebagai key untuk dedup
    def _key(text: str) -> str:
        return text[:60].strip()

    rrf_scores: Dict[str, float] = {}

    # Kontribusi vector search
    for rank, (doc, _vscore) in enumerate(vector_docs):
        k_str = _key(doc.page_content)
        rrf_scores[k_str] = rrf_scores.get(k_str, 0.0) + (1 - bm25_weight) / (k + rank + 1)

    # Kontribusi BM25
    for bm25_rank, (stored_idx, _bscore) in enumerate(bm25_indexed):
        if stored_idx < len(stored_texts):
            k_str = _key(stored_texts[stored_idx])
            rrf_scores[k_str] = rrf_scores.get(k_str, 0.0) + bm25_weight / (k + bm25_rank + 1)

    # Build unified doc pool: deduplicate, add BM25-only docs
    pool: Dict[str, Tuple] = {}
    for doc, vscore in vector_docs:
        k_str = _key(doc.page_content)
        pool[k_str] = (doc, vscore)

    # Tambahkan BM25-only docs (tidak ada di vector results)
    for stored_idx, _ in bm25_indexed:
        if stored_idx < len(stored_texts):
            k_str = _key(stored_texts[stored_idx])
            if k_str not in pool:
                # Buat mock-like object yang kompatibel dengan format (doc, score)
                class _MockDoc:
                    def __init__(self, content: str, meta: dict):
                        self.page_content = content
                        self.metadata = meta
                pool[k_str] = (_MockDoc(stored_texts[stored_idx],
                                        stored_metas[stored_idx] if stored_idx < len(stored_metas) else {}),
                               1.0)

    # Sort by RRF score dan ambil top_k
    sorted_keys = sorted(rrf_scores, key=lambda x: -rrf_scores[x])
    merged = []
    for key in sorted_keys:
        if key in pool:
            merged.append(pool[key])
        if len(merged) >= top_k:
            break

    return merged


def _resolve_pdr_parents(
    child_chunks: List[Dict],
    vectorstore,
    user_id: str,
) -> List[Dict]:
    """
    PDR: Tukar child chunks → parent chunks.

    Setelah retrieval+reranking menemukan child chunks (256 token),
    fungsi ini fetch parent chunks (1500 token) yang lebih lengkap
    untuk dikirim ke LLM sebagai konteks.

    - Deduplicate: jika 2 child dari parent yang sama, parent hanya ada 1×
    - Preserve order: urutan relevansi dari reranker dipertahankan
    - Fallback: jika parent tidak ditemukan, gunakan child asli
    """
    if not child_chunks:
        return child_chunks

    # Kumpulkan parent_ids yang unik, pertahankan urutan kemunculan pertama
    seen_parent_ids: set = set()
    ordered_parent_ids: List[str] = []
    child_by_parent: Dict[str, Dict] = {}  # parent_id → child chunk (untuk fallback metadata)

    for chunk in child_chunks:
        pid = chunk.get("metadata", {}).get("parent_id") or chunk.get("parent_id")
        ctype = chunk.get("metadata", {}).get("chunk_type") or chunk.get("chunk_type")

        if pid and ctype == "child" and pid not in seen_parent_ids:
            seen_parent_ids.add(pid)
            ordered_parent_ids.append(pid)
            child_by_parent[pid] = chunk
        elif not pid or ctype != "child":
            # Chunk lama (non-PDR) — lewatkan tanpa modifikasi
            if "NON_PDR" not in seen_parent_ids:
                seen_parent_ids.add("NON_PDR")

    if not ordered_parent_ids:
        return child_chunks  # semua chunk non-PDR, kembalikan apa adanya

    # Fetch parent chunks dari ChromaDB (filter by parent_id)
    try:
        raw_col = vectorstore._collection
        result = raw_col.get(
            where={
                "$and": [
                    {"user_id": str(user_id)},
                    {"chunk_type": "parent"},
                    {"parent_id": {"$in": ordered_parent_ids}},
                ]
            },
            include=["documents", "metadatas"],
        )

        # Buat mapping parent_id → content
        parent_map: Dict[str, Dict] = {}
        for doc, meta in zip(result.get("documents", []), result.get("metadatas", [])):
            pid = meta.get("parent_id")
            if pid:
                parent_map[pid] = {"content": doc, "metadata": meta}

        # Susun hasil akhir dalam urutan relevansi anak
        resolved: List[Dict] = []
        non_pdr: List[Dict] = []  # chunk lama tetap dipertahankan

        for chunk in child_chunks:
            pid = chunk.get("metadata", {}).get("parent_id") or chunk.get("parent_id")
            ctype = chunk.get("metadata", {}).get("chunk_type") or chunk.get("chunk_type")

            if pid and ctype == "child":
                if pid in parent_map and pid not in {r.get("parent_id") for r in resolved}:
                    p = parent_map[pid]
                    resolved.append({
                        "content":         p["content"],
                        "score":           chunk.get("score", 1.0),
                        "rerank_score":    chunk.get("rerank_score", 0.0),
                        "filename":        p["metadata"].get("filename", chunk.get("filename", "unknown")),
                        "chunk_index":     p["metadata"].get("parent_index", 0),
                        "embedding_model": chunk.get("embedding_model", ""),
                        "parent_id":       pid,
                        "pdr":             True,
                    })
                elif pid not in {r.get("parent_id") for r in resolved}:
                    # Parent tidak ditemukan — fallback ke child
                    resolved.append(chunk)
            else:
                non_pdr.append(chunk)

        final = resolved + non_pdr
        pdr_count = sum(1 for r in final if r.get("pdr"))
        logger.info(
            "🔍 PDR: %d child → %d parent chunks (+ %d non-PDR chunks)",
            len(ordered_parent_ids), pdr_count, len(non_pdr),
        )
        return final

    except Exception as e:
        logger.warning("⚠️  PDR parent lookup gagal: %s — fallback ke child chunks", e)
        return child_chunks


def search_relevant_chunks(query: str, filenames: List[str] = None, top_k: int = 5, user_id: str = None) -> Tuple[List[Dict], bool]:
    """
    Search for relevant document chunks based on query with optional reranking.

    Untuk multi-dokumen: setiap file dicari secara TERPISAH dengan kuota chunk
    yang seimbang, sehingga semua dokumen terwakili sebelum reranking.
    Ini mencegah 1 dokumen mendominasi hasil pencarian global.

    Args:
        query: User query string
        filenames: Optional list of filenames to filter by
        top_k: Number of top chunks to return after reranking
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
        
        user_filter = {"user_id": str(user_id)}

        # ── Baca seluruh konfigurasi RAG dari YAML ────────────────────────────
        try:
            from app.config_loader import (
                get_rag_doc_candidates, get_rag_top_n,
                get_hybrid_search_config, get_hyde_config,
            )
            doc_candidates  = get_rag_doc_candidates()
            doc_top_n       = get_rag_top_n()
            hybrid_cfg      = get_hybrid_search_config()
            hyde_cfg        = get_hyde_config()
        except Exception:
            doc_candidates  = int(os.getenv("LANGSEARCH_RERANK_DOC_CANDIDATES", "25"))
            doc_top_n       = top_k
            hybrid_cfg      = {}
            hyde_cfg        = {}

        hybrid_enabled  = hybrid_cfg.get('enabled', False)
        bm25_weight     = float(hybrid_cfg.get('bm25_weight', 0.3))
        bm25_cands      = int(hybrid_cfg.get('bm25_candidates', doc_candidates))
        hyde_enabled    = hyde_cfg.get('enabled', False)
        hyde_mode       = str(hyde_cfg.get('mode', 'smart')).lower()
        hyde_timeout    = int(hyde_cfg.get('timeout', 5))
        hyde_max_tokens = int(hyde_cfg.get('max_tokens', 100))

        # ── Step 0: Smart HyDE — enhance query sebelum embedding ────────────
        # mode = 'smart'  : aktif otomatis hanya untuk query konseptual/analitis
        # mode = 'always' : selalu aktif (semua query)
        # lainnya          : mati (off)
        search_query = query
        if hyde_enabled:
            if hyde_mode == 'always':
                search_query = _generate_hyde_query(query, timeout=hyde_timeout, max_tokens=hyde_max_tokens)
                logger.info("🔮 HyDE: mode=always — query dienhance")
            elif hyde_mode == 'smart':
                use_hyde, hyde_reason = _should_use_hyde(query)
                if use_hyde:
                    search_query = _generate_hyde_query(query, timeout=hyde_timeout, max_tokens=hyde_max_tokens)
                    logger.info("🔮 HyDE: mode=smart — AKTIF (%s)", hyde_reason)
                else:
                    logger.debug("🔮 HyDE: mode=smart — skip (%s)", hyde_reason)

        # ── Step 1: Per-document hybrid search ───────────────────────────────
        # Untuk setiap dokumen:
        #   a. Vector search (semantic, model embedding)
        #   b. BM25 search (keyword, rank-bm25) jika hybrid_enabled
        #   c. RRF merge hasil keduanya per-dokumen
        # Semua hasil digabung → reranker → guaranteed minimum coverage
        if filenames and len(filenames) > 1:
            n_docs = len(filenames)
            per_doc_k = max(2, doc_candidates // n_docs)
            logger.info(
                "🔍 RAG: Multi-dokumen (%d file) — %s, %d chunk/dokumen untuk user_id: %s",
                n_docs,
                "vector+BM25" if hybrid_enabled else "vector only",
                per_doc_k, user_id,
            )
            all_docs: List = []
            for fname in filenames:
                f_filter = {"$and": [user_filter, {"filename": fname}]}
                try:
                    # (a) Vector search
                    f_vec = vectorstore.similarity_search_with_score(
                        search_query, k=per_doc_k, filter=f_filter
                    )

                    if hybrid_enabled and f_vec:
                        # (b) BM25: fetch ALL chunks untuk dokumen ini
                        try:
                            raw = vectorstore.get(
                                where={"$and": [user_filter, {"filename": fname}]},
                                include=['documents', 'metadatas'],
                                limit=bm25_cands,
                            )
                            stored_texts = raw.get('documents', []) or []
                            stored_metas = raw.get('metadatas', []) or []

                            if stored_texts:
                                bm25_ranked = _bm25_rank_docs(query, stored_texts, top_k=per_doc_k)
                                # (c) RRF merge
                                merged = _rrf_merge_docs(
                                    f_vec, bm25_ranked, stored_texts, stored_metas,
                                    top_k=per_doc_k, bm25_weight=bm25_weight,
                                )
                                logger.info(
                                    "   📄 %s → %d vector + %d BM25 → %d merged",
                                    fname, len(f_vec), len(bm25_ranked), len(merged),
                                )
                                all_docs.extend(merged)
                                continue
                        except Exception as berr:
                            logger.debug("BM25 fallback ke vector untuk %s: %s", fname, berr)

                    logger.info("   📄 %s → %d chunk (vector only)", fname, len(f_vec))
                    all_docs.extend(f_vec)

                except Exception as ferr:
                    logger.warning("   ⚠️  Gagal cari chunk dari %s: %s", fname, ferr)
            docs = all_docs

        elif filenames and len(filenames) == 1:
            f_filter = {"$and": [user_filter, {"filename": filenames[0]}]}
            f_vec = vectorstore.similarity_search_with_score(
                search_query, k=doc_candidates, filter=f_filter
            )
            if hybrid_enabled and f_vec:
                try:
                    raw = vectorstore.get(
                        where={"$and": [user_filter, {"filename": filenames[0]}]},
                        include=['documents', 'metadatas'],
                        limit=bm25_cands,
                    )
                    stored_texts = raw.get('documents', []) or []
                    stored_metas = raw.get('metadatas', []) or []
                    if stored_texts:
                        bm25_ranked = _bm25_rank_docs(query, stored_texts, top_k=doc_candidates)
                        docs = _rrf_merge_docs(
                            f_vec, bm25_ranked, stored_texts, stored_metas,
                            top_k=doc_candidates, bm25_weight=bm25_weight,
                        )
                        logger.info("🔍 RAG: %s — %d merged chunks (vector+BM25)", filenames[0], len(docs))
                    else:
                        docs = f_vec
                        logger.info("🔍 RAG: %s — %d chunk (vector only)", filenames[0], len(docs))
                except Exception:
                    docs = f_vec
                    logger.info("🔍 RAG: %s — %d chunk (vector only, BM25 error)", filenames[0], len(f_vec))
            else:
                docs = f_vec
                logger.info("🔍 RAG: Filtering by filename: %s untuk user_id: %s", filenames[0], user_id)

        else:
            logger.info("🔍 RAG: Searching all documents untuk user_id: %s", user_id)
            docs = vectorstore.similarity_search_with_score(
                search_query, k=doc_candidates, filter=user_filter
            )

        if not docs:
            logger.info("📚 RAG: Tidak ada chunk ditemukan")
            return [], True

        # ── Reranking ─────────────────────────────────────────────────────────
        langsearch_service = get_langsearch_service()
        rerank_enabled = os.getenv("LANGSEARCH_RERANK_ENABLED", "true").lower() == "true"

        if rerank_enabled and len(docs) >= 2:
            documents = [doc.page_content for doc, _ in docs]
            rerank_results = langsearch_service.rerank_documents(
                query=query,
                documents=documents,
                top_n=doc_top_n,
                return_documents=False
            )

            if rerank_results:
                reranked_chunks = []
                for result in rerank_results:
                    idx = result.get("index")
                    if idx is not None and idx < len(docs):
                        doc, vector_score = docs[idx]
                        reranked_chunks.append({
                            "content": doc.page_content,
                            "score": float(vector_score),
                            "rerank_score": float(result.get("relevance_score", 0)),
                            "filename": doc.metadata.get("filename", "unknown"),
                            "chunk_index": doc.metadata.get("chunk_index", 0),
                            "embedding_model": doc.metadata.get("embedding_model", provider_name),
                        })

                # ── Guaranteed minimum: setiap dokumen yang dipilih user ────────
                # harus punya minimal 1 chunk dalam hasil akhir.
                # Jika ada dokumen yang 0 slot setelah rerank, ambil paksa
                # chunk terbaiknya dari kandidat per-doc search.
                final_chunks = list(reranked_chunks[:top_k])

                if filenames and len(filenames) > 1:
                    represented = {c["filename"] for c in final_chunks}
                    missing = [f for f in filenames if f not in represented]

                    if missing:
                        # Build map: filename -> best available chunk dari all_docs
                        # (urutan pertama per-doc search = paling relevan untuk doc itu)
                        best_per_doc = {}
                        for _doc, _vscore in docs:
                            _fname = _doc.metadata.get("filename", "unknown")
                            if _fname not in best_per_doc:
                                best_per_doc[_fname] = (_doc, _vscore)

                        forced = []
                        for fname in missing:
                            if fname in best_per_doc:
                                _doc, _vscore = best_per_doc[fname]
                                forced.append({
                                    "content": _doc.page_content,
                                    "score": float(_vscore),
                                    "rerank_score": -1.0,
                                    "filename": _doc.metadata.get("filename", "unknown"),
                                    "chunk_index": _doc.metadata.get("chunk_index", 0),
                                    "embedding_model": _doc.metadata.get("embedding_model", provider_name),
                                    "forced": True,
                                })

                        if forced:
                            n = len(forced)
                            # Ganti n chunk paling akhir (rerank score terendah) dengan forced
                            if len(final_chunks) >= n:
                                final_chunks[-n:] = forced
                            else:
                                final_chunks.extend(forced)
                            logger.info(
                                "🔧 RAG: Forced inclusion %d dokumen (tidak dapat slot rerank): %s",
                                n,
                                ", ".join(missing),
                            )

                from collections import Counter
                dist = Counter(c["filename"] for c in final_chunks)
                n_forced = sum(1 for c in final_chunks if c.get("forced"))
                logger.info(
                    "📚 RAG: Final %d chunks — distribusi: %s%s",
                    len(final_chunks),
                    ", ".join(f"{k}: {v}" for k, v in dist.items()),
                    f" ({n_forced} forced)" if n_forced else "",
                )

                # ── PDR: Tukar child chunks → parent chunks sebelum ke LLM ──────
                try:
                    from app.config_loader import get_pdr_config as _get_pdr
                    _pdr = _get_pdr()
                    _pdr_enabled = _pdr.get('enabled', False)
                except Exception:
                    _pdr_enabled = False

                if _pdr_enabled:
                    final_chunks = _resolve_pdr_parents(
                        final_chunks, vectorstore, str(user_id)
                    )

                return final_chunks, True
            else:
                logger.warning("⚠️ RAG: Rerank gagal, fallback ke vector search")

        # Fallback: vector search saja (tanpa rerank)
        results = []
        for doc, score in docs[:top_k]:
            results.append({
                "content": doc.page_content,
                "score": float(score),
                "filename": doc.metadata.get("filename", "unknown"),
                "chunk_index": doc.metadata.get("chunk_index", 0),
                "embedding_model": doc.metadata.get("embedding_model", provider_name),
            })
        logger.info("📚 RAG: Found %d chunks (vector search)", len(results))
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
    
    for chunk in chunks:
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
        
        embeddings, _, _ = get_embeddings_with_fallback()
        
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

    Prioritas:
      1. force_web_search (toggle manual user) → selalu aktif
      2. explicit_web_request ("cari di web", dll) → selalu aktif
      3. Jika dokumen aktif → web SELALU mati (kebijakan document_context)
      4. Jika tidak ada dokumen:
           - realtime HIGH → aktif
           - realtime MEDIUM → aktif (jika kebijakan mengizinkan)
           - otherwise → mati

    Returns:
        tuple(bool should_use_web, str reason_code, str realtime_intent)
    """
    realtime_intent = detect_realtime_intent_level(query)
    explicit_detected = explicit_web_request or detect_explicit_web_request(query)

    # Prioritas 1: Toggle manual user
    if force_web_search:
        reason = "DOC_WEB_TOGGLE" if documents_active else "WEB_TOGGLE"
        return True, reason, realtime_intent

    # Prioritas 2: Permintaan eksplisit dalam query
    if explicit_detected:
        reason = "DOC_WEB_EXPLICIT" if documents_active else "EXPLICIT_WEB"
        return True, reason, realtime_intent

    # Prioritas 3: Dokumen aktif → web selalu mati
    if documents_active:
        return False, "DOC_NO_WEB", realtime_intent

    # Prioritas 4: Tidak ada dokumen — cek intent realtime
    if allow_auto_realtime_web:
        if realtime_intent == "high":
            return True, "REALTIME_AUTO_HIGH", realtime_intent
        if realtime_intent == "medium":
            return True, "REALTIME_AUTO_MEDIUM", realtime_intent

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
        try:
            from app.config_loader import get_rerank_config as _get_rc
            _rc = _get_rc()
            rerank_enabled = _rc.get('enabled', True)
            web_candidates  = int(_rc.get('web_candidates', 10))
            web_top_n       = int(_rc.get('web_top_n', 5))
        except Exception:
            rerank_enabled = os.getenv("LANGSEARCH_RERANK_ENABLED", "true").lower() == "true"
            web_candidates  = int(os.getenv("LANGSEARCH_RERANK_WEB_CANDIDATES", "10"))
            web_top_n       = int(os.getenv("LANGSEARCH_RERANK_WEB_TOP_N", "5"))

            
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
