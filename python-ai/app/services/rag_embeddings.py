import os
import logging
from typing import Tuple, Optional

import tiktoken
from langchain_core.embeddings import Embeddings
from langchain_openai import OpenAIEmbeddings

from app.services.rag_config import (
    EMBEDDING_MODELS,
    MAX_EMBEDDING_DIM,
)

logger = logging.getLogger(__name__)

try:
    TIKTOKEN_ENCODER = tiktoken.get_encoding("cl100k_base")
    logger.info("✅ Tiktoken encoder initialized (cl100k_base)")
except Exception as e:
    logger.error(f"❌ Failed to initialize tiktoken: {e}")
    TIKTOKEN_ENCODER = None


def count_tokens(text: str) -> int:
    if TIKTOKEN_ENCODER is None:
        return len(text) // 4

    try:
        return len(TIKTOKEN_ENCODER.encode(text))
    except Exception as e:
        logger.warning(f"⚠️ Token counting failed: {e}, using fallback estimate")
        return len(text) // 4


def get_embeddings_with_fallback(model_index: int = 0) -> Tuple[Optional[Embeddings], str, int]:
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
                    openai_api_key=api_key,
                    dimensions=MAX_EMBEDDING_DIM
                )
                _ = embeddings.embed_query("test")
                logger.info(f"✅ Menggunakan {model_config['name']} (TPM: {model_config['tpm_limit']:,}, Dim: {MAX_EMBEDDING_DIM})")
                return embeddings, model_config["name"], idx

        except Exception as e:
            error_msg = str(e)
            logger.warning(f"⚠️ {model_config['name']} gagal: {error_msg}")

    logger.error("❌ Semua embedding provider gagal! Total kapasitas: 2M TPM habis atau tidak tersedia")
    return None, "none", -1
