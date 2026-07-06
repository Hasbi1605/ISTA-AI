"""Process-local Chroma vectorstore cache for retrieval hot paths."""

from __future__ import annotations

from threading import Lock
from typing import Any

from langchain_chroma import Chroma

from app.services.rag_config import CHROMA_PATH

_lock = Lock()
_stores: dict[str, Chroma] = {}


def _embedding_cache_key(embedding_function: Any | None) -> str:
    if embedding_function is None:
        return "none"

    for attr in ("model", "model_name", "deployment", "azure_deployment"):
        value = getattr(embedding_function, attr, None)
        if value:
            return f"{embedding_function.__class__.__name__}:{value}"

    return f"{embedding_function.__class__.__name__}:{id(embedding_function)}"


def get_chroma_store(
    collection_name: str,
    *,
    embedding_function: Any | None = None,
    persist_directory: str = CHROMA_PATH,
) -> Chroma:
    cache_key = f"{collection_name}:{_embedding_cache_key(embedding_function)}:{persist_directory}"

    with _lock:
        cached = _stores.get(cache_key)
        if cached is not None:
            return cached

        kwargs: dict[str, Any] = {
            "collection_name": collection_name,
            "persist_directory": persist_directory,
        }
        if embedding_function is not None:
            kwargs["embedding_function"] = embedding_function

        store = Chroma(**kwargs)
        _stores[cache_key] = store
        return store


def clear_chroma_store_cache() -> None:
    """Test helper: drop cached vectorstore instances."""

    with _lock:
        _stores.clear()
