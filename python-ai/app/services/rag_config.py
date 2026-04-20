import os
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

CHROMA_PATH = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "chroma_data")
VECTOR_COLLECTION_NAME = "documents_collection"
PARENT_COLLECTION_NAME = "documents_parent_collection"

try:
    from app.config_loader import get_config as _get_config
    _chunking_cfg = _get_config().get("chunking", {})
except Exception:
    _chunking_cfg = {}

def _chunking_int(env_key: str, yaml_key: str, default: int) -> int:
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

MAX_EMBEDDING_DIM = 3072

EMBEDDING_MODELS = [
    {
        "name": "GitHub Models (OpenAI Large) - Primary",
        "provider": "github",
        "model": "text-embedding-3-large",
        "api_key_env": "GITHUB_TOKEN",
        "tpm_limit": 500000,
        "dimensions": 3072,
    },
    {
        "name": "GitHub Models (OpenAI Large) - Backup",
        "provider": "github",
        "model": "text-embedding-3-large",
        "api_key_env": "GITHUB_TOKEN_2",
        "tpm_limit": 500000,
        "dimensions": 3072,
    },
    {
        "name": "GitHub Models (OpenAI Small) - Fallback 1",
        "provider": "github",
        "model": "text-embedding-3-small",
        "api_key_env": "GITHUB_TOKEN",
        "tpm_limit": 500000,
        "dimensions": 1536,
    },
    {
        "name": "GitHub Models (OpenAI Small) - Fallback 2",
        "provider": "github",
        "model": "text-embedding-3-small",
        "api_key_env": "GITHUB_TOKEN_2",
        "tpm_limit": 500000,
        "dimensions": 1536,
    }
]

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
    r"\bskor\b.*\b(semalam|tadi\s+malam|hari\s+ini|live|sekarang)\b",
    r"\b(semalam|tadi\s+malam|hari\s+ini)\b.*\bskor\b",
    r"\blive\s+score\b",
    r"\b(hasil|skor)\b.*\b(vs|versus)\b.*\b(semalam|hari\s+ini|live)\b",
    r"\bberita\s+(terkini|terbaru)\b",
    r"\bberita\b.*\bhari\s+ini\b",
    r"\bberita\b.*\bsekarang\b",
    r"\bbreaking\s+news\b",
    r"\b(kurs|nilai\s+tukar|exchange\s+rate)\b",
    r"\bharga\s+(saham|bitcoin|crypto|kripto|emas|bbm|bensin|solar|minyak|dolar)\b",
    r"\bsaham\b.*\b(hari\s+ini|sekarang|terkini|naik|turun)\b",
    r"\bindeks\s+(saham|harga|ihsg)\b",
    r"\bihsg\b",
    r"\b(bitcoin|crypto|kripto)\b.*\b(hari\s+ini|sekarang|harga)\b",
    r"\bcuaca\b.*\b(hari\s+ini|sekarang|besok|minggu\s+ini)\b",
    r"\b(prakiraan|perkiraan)\s+cuaca\b",
    r"\bcuaca\s+(terkini|terbaru)\b",
    r"\bgempa\s*(bumi)?\b.*\b(hari\s+ini|terkini|terbaru|baru|sekarang)\b",
    r"\b(banjir|kebakaran|tsunami|longsor)\b.*\b(hari\s+ini|terkini|sekarang)\b",
    r"\b(hasil\s+pemilu|quick\s+count|real\s+count|hasil\s+pilkada)\b",
    r"\bjadwal\b.*\b(hari\s+ini|besok|minggu\s+ini|live)\b",
    r"\b(pertandingan|match)\b.*\b(malam\s+ini|hari\s+ini|besok|live)\b",
]

REALTIME_MEDIUM_KEYWORDS = [
    "terkini", "terbaru", "update", "hari ini", "sekarang",
    "minggu ini", "bulan ini",
    "live", "skor", "breaking", "hasil pertandingan", "jadwal",
    "kurs", "harga saham", "harga emas", "inflasi", "ekonomi",
    "berita", "pengumuman", "kebijakan baru",
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

CANONICAL_TEAM_GROUPS = {
    "barcelona": ["barcelona", "barca", "fc barcelona"],
    "atletico_madrid": ["atletico madrid", "atletico de madrid", "atletico", "atm"],
    "real_madrid": ["real madrid", "madrid"],
    "psg": ["psg", "paris saint germain"],
}
