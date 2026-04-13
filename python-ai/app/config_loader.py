import os
import logging
import yaml
from typing import Dict, Any, List, Optional

logger = logging.getLogger(__name__)

CONFIG_PATH = os.path.join(os.path.dirname(__file__), '..', 'config', 'ai_config.yaml')
CHUNKING_CONFIG_PATH = os.path.join(os.path.dirname(__file__), '..', 'config', 'chunking_summarization.yaml')

_chunking_config_cache: Optional[Dict[str, Any]] = None

_config_cache: Optional[Dict[str, Any]] = None

DEFAULT_PROMPTS = {
    "system": {
        "default": "Anda adalah ISTA AI, asisten virtual istana pintar. Jawablah dengan sopan dan membantu."
    },
    "rag": {
        "document": """Anda adalah asisten AI cerdas. Jawab pertanyaan user berdasarkan referensi berikut.

SUMBER DOKUMEN REFERENSI:
{context_str}
{web_section}
---

Pertanyaan: {question}

INSTRUKSI PENTING UNTUK FORMAT JAWABAN:
1. JANGAN PERNAH menyebut istilah internal seperti "Kutipan 1", "Kutipan Dokumen 2", dsb. Gantikan secara natural dengan menyebut nama file atau merujuk ke isi dokumen tersebut.
2. Jika di dalam teks/isi dokumen rujukan terdapat Judul Dokumen yang spesifik, sebutkan dan cetak TEBAL (BOLD).
3. WAJIB cetak TEBAL (BOLD) setiap kali Anda menyebutkan nama file rujukan (contoh: "Berdasarkan dokumen **nama_dokumen.pdf**, ...").
4. Utamakan informasi dari sumber dokumen di atas. Jika konteks web tersedia, gunakan hanya sebagai tambahan pelengkap yang memperkaya jawaban.
5. Jika jawaban tidak ditemukan sama sekali di dalam dokumen rujukan, katakan secara langsung bahwa informasi tersebut tidak tersedia pada dokumen yang Anda baca.
6. Hindari mencantumkan daftar pustaka di bagian akhir jawaban yang berbentuk "Sumber referensi: Kutipan X". Langsung saja integrasikan penyebutan nama rujukan secara natural ke dalam teks kalimat Anda.

Jawaban:"""
    },
    "web_search": {
        "context": """================================================================================
🔴 INFORMASI TERBARU DARI WEB - PRIORITAS TERTINGGI 🔴
================================================================================

📅 Tanggal Hari Ini: {current_date}

⚠️ PERHATIAN PENTING:
- Pengetahuan internal Anda terakhir diperbarui tahun 2024
- Sekarang adalah tahun {current_year}
- Data di bawah ini adalah informasi TERBARU dari web (real-time)
- WAJIB gunakan informasi ini untuk menjawab pertanyaan tentang:
  * Pejabat pemerintahan (presiden, menteri, gubernur, dll)
  * Berita terkini dan kejadian terbaru
  * Data yang berubah dari waktu ke waktu
  * Informasi yang bersifat real-time
- JANGAN mengandalkan pengetahuan internal untuk fakta yang bisa outdated

📰 HASIL PENCARIAN WEB:
================================================================================

{results}

================================================================================
🔴 AKHIR INFORMASI TERBARU DARI WEB 🔴
================================================================================

""",
        "assertive_instruction": """Instruksi tambahan:
- Gunakan informasi web terbaru di atas hanya jika relevan dengan pertanyaan user.
- Jika sumber web tersedia, utamakan data faktual dari sumber tersebut untuk bagian yang bersifat real-time.
- Jika ada bagian 'FAKTA TERSTRUKTUR' dengan skor pengadilan, sebutkan skor tersebut secara eksplisit.
- Jawab secara ringkas, jelas, dan hindari istilah teknis internal sistem.
"""
    },
    "summarization": {
        "single": """Buatkan ringkasan yang jelas dan padat dari dokumen berikut. 
Ringkasan harus mencakup poin-poin utama dan informasi penting.

Dokumen:
{document}

---

Buat ringkasan dalam Bahasa Indonesia (maksimal 500 kata):""",
        "partial": """Buatkan ringkasan singkat dari bagian dokumen berikut.
Ini adalah bagian {part_number} dari {total_parts} bagian dokumen.

Dokumen:
{batch}

---

Berikan ringkasan singkat (maksimal 100 kata) dari bagian ini dalam Bahasa Indonesia:""",
        "final": """Berdasarkan ringkasan bagian-bagian berikut, buat ringkasan keseluruhan yang komprehensif.

Ringkasan Bagian:
{combined_summaries}

---

Buat ringkasan keseluruhan yang jelas dan terstruktur dalam Bahasa Indonesia (maksimal 500 kata):"""
    }
}


def load_config() -> Dict[str, Any]:
    """Load AI configuration from YAML file."""
    global _config_cache
    
    if _config_cache is not None:
        return _config_cache
    
    try:
        with open(CONFIG_PATH, 'r') as f:
            _config_cache = yaml.safe_load(f)
            return _config_cache
    except FileNotFoundError:
        raise RuntimeError(f"Config file not found: {CONFIG_PATH}")
    except yaml.YAMLError as e:
        raise RuntimeError(f"Failed to parse config: {e}")


def get_config() -> Dict[str, Any]:
    """Get the loaded configuration."""
    return load_config()


def reload_config() -> Dict[str, Any]:
    """Force reload configuration (useful for testing)."""
    global _config_cache
    _config_cache = None
    return load_config()


def get_global_config() -> Dict[str, Any]:
    """Get global settings."""
    config = load_config()
    return config.get('global', {})


def get_chat_models() -> List[Dict[str, Any]]:
    """Get chat lane models."""
    config = load_config()
    return config.get('lanes', {}).get('chat', {}).get('models', [])


def get_reasoning_model() -> Optional[Dict[str, Any]]:
    """Get reasoning lane model (null if not configured)."""
    config = load_config()
    return config.get('lanes', {}).get('reasoning', {}).get('model')


def get_embedding_models() -> List[Dict[str, Any]]:
    """Get embedding lane models."""
    # TODO: implement when needed
    config = load_config()
    return config.get('lanes', {}).get('embedding', {}).get('models', [])


def get_search_config() -> Dict[str, Any]:
    """Get search configuration."""
    config = load_config()
    return config.get('retrieval', {}).get('search', {})


def get_rerank_config() -> Dict[str, Any]:
    """Get semantic rerank configuration."""
    config = load_config()
    return config.get('retrieval', {}).get('semantic_rerank', {})


def get_chunking_config() -> Dict[str, Any]:
    """Get chunking configuration."""
    config = load_config()
    return config.get('chunking', {})


def get_smtp_config() -> Dict[str, Any]:
    """Get SMTP Gmail configuration."""
    config = load_config()
    return config.get('integrations', {}).get('smtp_gmail', {})


def get_system_prompt() -> str:
    """Get default system prompt."""
    config = load_config()
    prompt = config.get('prompts', {}).get('system', {}).get('default', '')
    if not prompt:
        logger.warning("System prompt empty, using default fallback")
        prompt = DEFAULT_PROMPTS.get('system', {}).get('default', '')
    return prompt


def get_rag_prompt() -> str:
    """Get RAG document prompt."""
    config = load_config()
    prompt = config.get('prompts', {}).get('rag', {}).get('document', '')
    if not prompt:
        logger.warning("RAG prompt empty, using default fallback")
        prompt = DEFAULT_PROMPTS.get('rag', {}).get('document', '')
    return prompt


def get_web_search_context_prompt() -> str:
    """Get web search context prompt template."""
    config = load_config()
    prompt = config.get('prompts', {}).get('web_search', {}).get('context', '')
    if not prompt:
        logger.warning("Web search context prompt empty, using default fallback")
        prompt = DEFAULT_PROMPTS.get('web_search', {}).get('context', '')
    return prompt


def get_assertive_instruction() -> str:
    """Get assertive instruction for web search."""
    config = load_config()
    prompt = config.get('prompts', {}).get('web_search', {}).get('assertive_instruction', '')
    if not prompt:
        logger.warning("Assertive instruction prompt empty, using default fallback")
        prompt = DEFAULT_PROMPTS.get('web_search', {}).get('assertive_instruction', '')
    return prompt


def get_summarize_single_prompt() -> str:
    """Get single document summarization prompt."""
    config = load_config()
    prompt = config.get('prompts', {}).get('summarization', {}).get('single', '')
    if not prompt:
        logger.warning("Summarize single prompt empty, using default fallback")
        prompt = DEFAULT_PROMPTS.get('summarization', {}).get('single', '')
    return prompt


def get_summarize_partial_prompt() -> str:
    """Get partial (multi-batch) summarization prompt."""
    config = load_config()
    prompt = config.get('prompts', {}).get('summarization', {}).get('partial', '')
    if not prompt:
        logger.warning("Summarize partial prompt empty, using default fallback")
        prompt = DEFAULT_PROMPTS.get('summarization', {}).get('partial', '')
    return prompt


def get_summarize_final_prompt() -> str:
    """Get final combined summarization prompt."""
    config = load_config()
    prompt = config.get('prompts', {}).get('summarization', {}).get('final', '')
    if not prompt:
        logger.warning("Summarize final prompt empty, using default fallback")
        prompt = DEFAULT_PROMPTS.get('summarization', {}).get('final', '')
    return prompt


# ============================================
# Chunking & Summarization Config Functions
# ============================================

def load_chunking_config() -> Dict[str, Any]:
    """Load chunking and summarization configuration from YAML file."""
    global _chunking_config_cache
    
    if _chunking_config_cache is not None:
        return _chunking_config_cache
    
    try:
        with open(CHUNKING_CONFIG_PATH, 'r') as f:
            _chunking_config_cache = yaml.safe_load(f)
            return _chunking_config_cache
    except FileNotFoundError:
        logger.warning(f"Chunking config file not found: {CHUNKING_CONFIG_PATH}, using internal defaults")
        return {}
    except yaml.YAMLError as e:
        logger.error(f"Failed to parse chunking config: {e}")
        return {}


def get_chunking_profiles() -> Dict[str, Any]:
    """Get chunking profiles for model-aware adaptive chunking."""
    config = load_chunking_config()
    return config.get('chunking_profiles', {})


def get_chunking_profile(profile_name: str) -> Dict[str, Any]:
    """Get a specific chunking profile by name."""
    profiles = get_chunking_profiles()
    return profiles.get(profile_name, {})


def get_embedding_batching_config() -> Dict[str, Any]:
    """Get embedding batching configuration."""
    config = load_chunking_config()
    return config.get('embedding_batching', {})


def get_embedding_fallback_chain() -> List[Dict[str, Any]]:
    """Get embedding model fallback chain."""
    config = load_chunking_config()
    return config.get('embedding_fallback', {}).get('chain', [])


def get_summarization_fallback_chain() -> List[Dict[str, Any]]:
    """Get summarization model fallback chain."""
    config = load_chunking_config()
    return config.get('summarization_fallback', {}).get('chain', [])


def get_summarization_thresholds() -> Dict[str, Any]:
    """Get summarization thresholds configuration."""
    config = load_chunking_config()
    return config.get('summarization_thresholds', {})


def get_semantic_rerank_config() -> Dict[str, Any]:
    """Get semantic rerank configuration."""
    config = load_chunking_config()
    return config.get('semantic_rerank', {})


def get_rerank_config(document: bool = True) -> Dict[str, Any]:
    """Get rerank configuration for documents or web."""
    config = load_chunking_config()
    rerank_config = config.get('rerank', {})
    if document:
        return rerank_config.get('document', {})
    return rerank_config.get('web', {})


def get_fallback_policy() -> Dict[str, Any]:
    """Get fallback policy configuration."""
    config = load_chunking_config()
    return config.get('fallback_policy', {})


def get_chunking_timeouts() -> Dict[str, Any]:
    """Get timeout configuration for chunking operations."""
    config = load_chunking_config()
    return config.get('timeouts', {})


def get_chunking_defaults() -> Dict[str, Any]:
    """Get default chunking configuration."""
    config = load_chunking_config()
    return config.get('defaults', {})


def get_adaptive_chunking_params(
    model_name: str = None,
    document_tokens: int = None
) -> Dict[str, Any]:
    """
    Get adaptive chunking parameters based on model and document size.
    
    Args:
        model_name: Name of the embedding model (e.g., 'text-embedding-3-large')
        document_tokens: Estimated token count of the document
        
    Returns:
        Dict with chunking parameters
    """
    profiles = get_chunking_profiles()
    defaults = get_chunking_defaults()
    
    result = defaults.copy()
    
    # Determine document size category
    doc_category = None
    if document_tokens is not None:
        if document_tokens < profiles.get('short_document', {}).get('max_tokens', 4000):
            doc_category = 'short_document'
        elif document_tokens < profiles.get('medium_document', {}).get('max_tokens', 15000):
            doc_category = 'medium_document'
        elif document_tokens < profiles.get('long_document', {}).get('max_tokens', 50000):
            doc_category = 'long_document'
        else:
            doc_category = 'very_long_document'
    
    # Determine model category
    model_category = None
    if model_name:
        if 'large' in model_name.lower():
            model_category = 'large_model'
        elif 'small' in model_name.lower():
            model_category = 'small_model'
    
    # Apply document-based profile
    if doc_category:
        doc_profile = profiles.get(doc_category, {})
        profile_to_use = doc_profile.get('use_profile', 'large_model')
        profile_params = profiles.get(profile_to_use, {})
        if profile_params:
            result.update({
                'token_chunk_size': profile_params.get('token_chunk_size', result.get('token_chunk_size')),
                'token_chunk_overlap': profile_params.get('token_chunk_overlap', result.get('token_chunk_overlap')),
            })
    
    # Apply model-based profile (overrides document profile if specified)
    if model_category:
        model_profile = profiles.get(model_category, {})
        if model_profile:
            result.update({
                'token_chunk_size': model_profile.get('token_chunk_size', result.get('token_chunk_size')),
                'token_chunk_overlap': model_profile.get('token_chunk_overlap', result.get('token_chunk_overlap')),
            })
    
    return result


def reload_chunking_config() -> Dict[str, Any]:
    """Force reload chunking configuration (useful for testing)."""
    global _chunking_config_cache
    _chunking_config_cache = None
    return load_chunking_config()
