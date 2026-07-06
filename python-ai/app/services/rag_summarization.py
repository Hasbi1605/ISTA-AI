import logging
from typing import Tuple, List

from app.env_utils import get_env_int
from app.services.chroma_store import get_chroma_store
from app.services.rag_config import CHROMA_PATH, VECTOR_COLLECTION_NAME
from app.services.rag_embeddings import get_embeddings_with_fallback, count_tokens
from app.services.rag_hybrid import _exclude_parent_corpus

logger = logging.getLogger(__name__)


def get_max_summarize_chunks() -> int:
    return get_env_int("MAX_SUMMARIZE_CHUNKS", 500)


def get_document_chunks_for_summarization(
    filename: str,
    user_id: str = None,
    document_id: str | None = None,
    max_tokens: int = 8000,
) -> Tuple[bool, List[str], int]:
    try:
        logger.info(f"=== Getting chunks for summarization: {filename} ===")

        if user_id is None:
            return False, [], 0

        embeddings, _, _ = get_embeddings_with_fallback()

        if embeddings is None:
            return False, [], 0

        vectorstore = get_chroma_store(
            VECTOR_COLLECTION_NAME,
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH,
        )

        if document_id:
            where_filter = {"$and": [{"document_id": str(document_id)}, {"user_id": str(user_id)}]}
        else:
            where_filter = {"$and": [{"filename": filename}, {"user_id": str(user_id)}]}

        docs = vectorstore.get(where=where_filter)

        if not docs or not docs.get("documents"):
            return False, [], 0

        docs_list = list(zip(docs.get("documents", []) or [], docs.get("metadatas", []) or []))
        docs_list.sort(key=lambda x: int((x[1] or {}).get("chunk_index", 0)))

        sorted_documents = [doc for doc, _ in docs_list if doc is not None]
        sorted_metadatas = [metadata or {} for doc, metadata in docs_list if doc is not None]

        chunks, _ = _exclude_parent_corpus(sorted_documents, sorted_metadatas)

        if not chunks:
            return False, [], 0

        max_chunks = get_max_summarize_chunks()
        if len(chunks) > max_chunks:
            logger.warning(
                "Summarization chunk count exceeds limit; truncating from %d to %d chunks",
                len(chunks),
                max_chunks,
            )
            chunks = chunks[:max_chunks]

        total_chunks = len(chunks)
        logger.info(f"Found {total_chunks} chunks for summarization")

        est_tokens = sum(count_tokens(c) for c in chunks)
        logger.info(f"Estimated tokens: {est_tokens:,}")

        if est_tokens <= max_tokens:
            all_content = "\n\n".join([f"--- Bagian {i+1} ---\n{c}" for i, c in enumerate(chunks)])
            return True, [all_content], total_chunks

        logger.info(f"Document too large ({est_tokens:,} tokens), implementing chunked summarization...")

        batches = []
        current_batch = []
        current_tokens = 0

        for chunk in chunks:
            chunk_tokens = count_tokens(chunk)

            if current_tokens + chunk_tokens > max_tokens and current_batch:
                batch_content = "\n\n".join([f"--- Bagian {j+1} ---\n{c}" for j, c in enumerate(current_batch)])
                batches.append(batch_content)
                current_batch = [chunk]
                current_tokens = chunk_tokens
            else:
                current_batch.append(chunk)
                current_tokens += chunk_tokens

        if current_batch:
            batch_content = "\n\n".join([f"--- Bagian {j+1} ---\n{c}" for j, c in enumerate(current_batch)])
            batches.append(batch_content)

        logger.info(f"Created {len(batches)} batches for hierarchical summarization")
        return True, batches, total_chunks

    except Exception as e:
        logger.error(f"❌ Error getting chunks for summarization: {str(e)}")
        return False, [], 0
