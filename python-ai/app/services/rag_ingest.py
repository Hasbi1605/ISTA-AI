import os
import hashlib
import logging
import time

from langchain_chroma import Chroma

from app.services.rag_config import (
    CHROMA_PATH,
    VECTOR_COLLECTION_NAME,
    PARENT_COLLECTION_NAME,
    TOKEN_CHUNK_SIZE,
    TOKEN_CHUNK_OVERLAP,
    AGGRESSIVE_BATCH_SIZE,
    BATCH_DELAY_SECONDS,
    MAX_TOKENS_PER_BATCH,
    MAX_EMBEDDING_DIM,
    EMBEDDING_MODELS,
)
from app.services.rag_embeddings import count_tokens, get_embeddings_with_fallback
from app.services.document_loaders import load_documents_lightweight as _load_documents_lightweight
from app.services.lightweight_text_splitter import LightweightRecursiveTextSplitter

logger = logging.getLogger(__name__)


# ── Optional metadata overrides ──────────────────────────────────────────────
# Callers (e.g. the knowledge ingest router) can pass a dict that will be
# merged into every chunk's metadata. Keep the values request-local; a module
# global would be unsafe when multiple FastAPI requests ingest concurrently.
def _apply_metadata_overrides(chunk_metadata: dict, metadata_overrides: dict | None = None) -> None:
    overrides = metadata_overrides
    if not overrides:
        return

    for key, value in overrides.items():
        if value is None:
            continue
        chunk_metadata[key] = value


def _delete_chroma_ids_with_retry(
    delete_callable,
    ids: list[str],
    description: str,
    document_id: str | None,
    attempts: int = 3,
    delay_seconds: float = 0.2,
) -> bool:
    if not ids:
        return True

    last_error: Exception | None = None

    for attempt in range(1, attempts + 1):
        try:
            delete_callable(ids=ids)
            if attempt > 1:
                logger.info(
                    "Post-ingest cleanup: %s delete succeeded on retry %d for document_id=%s",
                    description,
                    attempt,
                    document_id,
                )

            return True
        except Exception as exc:
            last_error = exc
            if attempt >= attempts:
                break

            logger.warning(
                "Post-ingest cleanup: %s delete attempt %d/%d failed for document_id=%s: %s",
                description,
                attempt,
                attempts,
                document_id,
                exc,
            )
            time.sleep(delay_seconds)

    logger.warning(
        "Post-ingest cleanup: %s delete failed after %d attempts for document_id=%s: %s",
        description,
        attempts,
        document_id,
        last_error,
    )

    return False


def process_document(
    file_path: str,
    filename: str,
    user_id: str = "unknown",
    document_id: str | None = None,
    metadata_overrides: dict | None = None,
    return_metrics: bool = False,
):
    def _result(success: bool, message: str, metrics: dict | None = None):
        if return_metrics:
            return success, message, (metrics or {})

        return success, message

    try:
        logger.info("=== Processing document: %s ===", filename)
        logger.info("File path: %s", file_path)
        logger.info("File exists: %s", os.path.exists(file_path))
        if os.path.exists(file_path):
            file_size = os.path.getsize(file_path)
            logger.info("File size: %s bytes (%.2f MB)", f"{file_size:,}", file_size / 1024 / 1024)

        # ── Ingest atomicity: snapshot old vector IDs before ingest ──────────
        # We query the IDs of any existing vectors for this document_id BEFORE
        # writing new ones. If the new ingest succeeds, we delete ONLY those
        # old IDs afterwards (post-ingest cleanup). If the ingest fails at any
        # point, nothing has been deleted and the user's last valid retrieval
        # context is preserved intact.
        #
        # This replaces the previous pre-ingest delete, which could silently
        # erase all context if the embedding service was temporarily unavailable.
        old_vector_ids: list[str] = []
        old_parent_ids: list[str] = []

        logger.info("Querying existing vectors before re-ingest for filename='%s', user_id='%s'", filename, user_id)

        import time as _time
        _load_start = _time.time()
        logger.info("Step 1: Loading document dengan parser ringan lokal...")
        docs = _load_documents_lightweight(file_path, filename)
        elapsed = _time.time() - _load_start
        logger.info(f"   ✅ Lightweight loader selesai: {len(docs)} bagian dalam {elapsed:.1f}s")

        if not docs:
            raise ValueError(
                "Tidak ada teks yang dapat diekstrak dari dokumen ini. "
                "PDF scan/gambar memerlukan pipeline OCR terpisah."
            )

        logger.info(f"Loaded {len(docs)} document(s)")

        total_content = "".join([doc.page_content for doc in docs])
        total_chars = len(total_content)
        estimated_tokens = count_tokens(total_content)
        logger.info(f"Total content: {total_chars:,} chars, ~{estimated_tokens:,} tokens")

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

        if pdr_enabled:
            logger.info(
                "Step 2: PDR Chunking (parent=%d token, child=%d token)...",
                pdr_parent_size, pdr_child_size,
            )
            parent_splitter = LightweightRecursiveTextSplitter(
                chunk_size=pdr_parent_size,
                chunk_overlap=pdr_parent_overlap,
                length_function=count_tokens,
                add_start_index=True,
                separators=["\n\n", "\n", ". ", " ", ""],
            )
            parent_chunks = parent_splitter.split_documents(docs)

            child_splitter = LightweightRecursiveTextSplitter(
                chunk_size=pdr_child_size,
                chunk_overlap=pdr_child_overlap,
                length_function=count_tokens,
                add_start_index=True,
                separators=["\n\n", "\n", ". ", " ", ""],
            )

            child_chunks = []
            pdr_parent_docs = []

            for p_idx, parent in enumerate(parent_chunks):
                raw_key = f"{filename}:{user_id}:{p_idx}:{parent.page_content[:50]}"
                parent_id = hashlib.md5(raw_key.encode()).hexdigest()

                parent_meta = {
                    "filename": filename,
                    "user_id": str(user_id),
                    "chunk_type": "parent",
                    "parent_id": parent_id,
                    "parent_index": p_idx,
                }
                if document_id:
                    parent_meta["document_id"] = str(document_id)
                _apply_metadata_overrides(parent_meta, metadata_overrides)
                pdr_parent_docs.append((parent_id, parent.page_content, parent_meta))

                children = child_splitter.split_documents([parent])
                for c_idx, child in enumerate(children):
                    child.metadata["chunk_type"] = "child"
                    child.metadata["parent_id"]  = parent_id
                    child.metadata["child_index"] = c_idx
                    child_chunks.append(child)

            chunks = child_chunks
            logger.info(
                "✅ PDR: %d parent chunks + %d child chunks (ratio %.1f:1)",
                len(parent_chunks), len(child_chunks),
                len(child_chunks) / max(len(parent_chunks), 1),
            )

        else:
            logger.info(
                "Step 2: Token-Aware Recursive Chunking (chunk_size=%d, overlap=%d)...",
                TOKEN_CHUNK_SIZE, TOKEN_CHUNK_OVERLAP,
            )
            text_splitter = LightweightRecursiveTextSplitter(
                chunk_size=TOKEN_CHUNK_SIZE,
                chunk_overlap=TOKEN_CHUNK_OVERLAP,
                length_function=count_tokens,
                add_start_index=True,
                separators=["\n\n", "\n", ". ", " ", ""],
            )
            chunks = text_splitter.split_documents(docs)
            pdr_parent_docs = []
            logger.info(f"Created {len(chunks)} token-aware chunks")

        if chunks:
            chunk_tokens = [count_tokens(chunk.page_content) for chunk in chunks]
            avg_tokens = sum(chunk_tokens) / len(chunk_tokens)
            max_tokens_val = max(chunk_tokens)
            min_tokens_val = min(chunk_tokens)
            logger.info(
                "Chunk stats: avg=%d tokens, min=%d, max=%d",
                avg_tokens, min_tokens_val, max_tokens_val,
            )

        logger.info("Step 3: Initializing embedding model dengan cascading fallback...")
        current_model_index = 0
        embeddings, provider_name, current_model_index = get_embeddings_with_fallback(current_model_index)

        if embeddings is None:
            raise Exception("Semua embedding provider gagal. Tidak dapat memproses dokumen.")

        current_embedding_dim = pdr_cfg.get('embedding_dim', MAX_EMBEDDING_DIM)

        for idx, chunk in enumerate(chunks):
            chunk.metadata["filename"] = filename
            chunk.metadata["user_id"]  = str(user_id)
            chunk.metadata["embedding_model"] = provider_name
            chunk.metadata["chunk_index"] = idx
            if document_id:
                chunk.metadata["document_id"] = str(document_id)
            _apply_metadata_overrides(chunk.metadata, metadata_overrides)
            if idx == 0:
                logger.info("🔍 INGEST: Storing chunk metadata - filename='%s', user_id='%s'", filename, str(user_id))

        logger.info(f"Step 4: Smart Batching & Embedding Generation...")
        logger.info(
            "Max batch size: %d chunks OR %d tokens (whichever is smaller)",
            AGGRESSIVE_BATCH_SIZE, MAX_TOKENS_PER_BATCH,
        )
        logger.info("Total capacity: 2M TPM across 4 models (4 x 500K TPM)")

        vectorstore = Chroma(
            collection_name=VECTOR_COLLECTION_NAME,
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )

        if pdr_enabled and pdr_parent_docs:
            try:
                parent_store = Chroma(
                    collection_name=PARENT_COLLECTION_NAME,
                    persist_directory=CHROMA_PATH,
                )
                raw_col = parent_store._collection

                # Snapshot old parent IDs before upsert (post-ingest cleanup)
                if document_id:
                    try:
                        _old_p = raw_col.get(
                            where={"$and": [{"document_id": str(document_id)}, {"user_id": str(user_id)}, {"chunk_type": "parent"}]},
                            include=[],
                        )
                        old_parent_ids = (_old_p.get("ids") or [])
                    except Exception as _psnap_err:
                        logger.debug("Could not snapshot old parent IDs: %s", _psnap_err)
                # NOTE: parent upsert is intentionally deferred to AFTER all child
                # vectors are written successfully. This ensures parent chunks are
                # only updated when the full ingest is complete — if child ingest
                # fails partway, the old parent context remains intact.
                # See pdr_parent_upsert_pending below.
            except Exception as pe:
                logger.warning("⚠️  Gagal membuka parent store PDR: %s", pe)

        successful_chunks = 0
        failed_chunks = 0

        # ── Snapshot old vector IDs (for post-ingest atomic cleanup) ─────────
        # Query existing IDs BEFORE writing any new vectors. After a successful
        # ingest we delete ONLY these old IDs so that a partial/failed ingest
        # never erases the user's last valid retrieval context.
        if document_id:
            try:
                _old = vectorstore.get(
                    where={"$and": [{"document_id": str(document_id)}, {"user_id": str(user_id)}]},
                    include=[],
                )
                old_vector_ids = _old.get("ids") or []
                logger.info(
                    "📥 Ingest atomicity: found %d existing vectors for document_id=%s (will delete after successful ingest)",
                    len(old_vector_ids), document_id,
                )
            except Exception as _snap_err:
                logger.warning("Could not snapshot existing vector IDs: %s — will skip post-ingest cleanup", _snap_err)

        # ── Embedding consistency check ────────────────────────────────────────
        # Fail-close before writing any new vectors if existing chunks used a
        # different embedding provider. Mixing incompatible embedding spaces
        # makes cosine-distance comparisons meaningless and corrupts retrieval.
        if old_vector_ids:
            try:
                _sample = vectorstore.get(ids=old_vector_ids[:1], include=["metadatas"])
                _sample_metas = (_sample.get("metadatas") or [{}])
                _existing_model = (_sample_metas[0] or {}).get("embedding_model", "")
                if _existing_model and _existing_model != provider_name:
                    logger.error(
                        "⚠️  EMBEDDING INCOMPATIBILITY for document_id=%s: "
                        "existing='%s' vs current='%s'. "
                        "Delete existing vectors before re-ingesting.",
                        document_id, _existing_model, provider_name,
                    )
                    return _result(
                        False,
                        f"Embedding provider mismatch: existing='{_existing_model}' vs current='{provider_name}'. "
                        "Delete existing vectors for this document before re-ingesting to avoid mixing incompatible embedding spaces.",
                    )
                elif _existing_model:
                    logger.info("✅ Embedding consistency OK: provider='%s'", provider_name)
            except Exception as _compat_err:
                logger.debug("Embedding consistency check skipped: %s", _compat_err)

        smart_batches = []
        current_batch = []
        current_batch_tokens = 0

        for chunk in chunks:
            chunk_tokens = count_tokens(chunk.page_content)

            would_exceed_tokens = (current_batch_tokens + chunk_tokens) > MAX_TOKENS_PER_BATCH
            would_exceed_count = len(current_batch) >= AGGRESSIVE_BATCH_SIZE

            if (would_exceed_tokens or would_exceed_count) and current_batch:
                smart_batches.append((current_batch, current_batch_tokens))
                current_batch = [chunk]
                current_batch_tokens = chunk_tokens
            else:
                current_batch.append(chunk)
                current_batch_tokens += chunk_tokens

        if current_batch:
            smart_batches.append((current_batch, current_batch_tokens))

        total_batches = len(smart_batches)
        logger.info(f"Created {total_batches} smart batches (token-aware)")

        for batch_index, (batch, batch_tokens) in enumerate(smart_batches, 1):
            try:
                if batch_index > 1:
                    time.sleep(BATCH_DELAY_SECONDS)

                logger.info(f"Processing batch {batch_index}/{total_batches}: {len(batch)} chunks, {batch_tokens:,} tokens...")
                vectorstore.add_documents(batch)
                successful_chunks += len(batch)
                logger.info(f"✅ Batch {batch_index}/{total_batches} success | Progress: {successful_chunks}/{len(chunks)} chunks")

            except Exception as batch_error:
                error_msg = str(batch_error)
                logger.error(f"❌ Batch {batch_index} error: {error_msg}")

                is_rate_limit = any(indicator in error_msg.lower() for indicator in [
                    "429", "rate limit", "resource_exhausted", "quota", "503", "too many requests"
                ])
                is_token_limit = any(indicator in error_msg.lower() for indicator in [
                    "413", "tokens_limit_reached", "too large", "request body too large"
                ])

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

                elif is_rate_limit and current_model_index < len(EMBEDDING_MODELS) - 1:
                    if current_model_index < len(EMBEDDING_MODELS) - 1:
                        # ── Embedding consistency: fail-close mid-ingest provider switch ──
                        # If any chunks have already been written with the current provider,
                        # switching to a different provider would leave the collection with
                        # chunks from two incompatible embedding spaces. Fail here so the
                        # operator can delete the document's vectors and retry from scratch.
                        if successful_chunks > 0:
                            logger.error(
                                "❌ EMBEDDING CONSISTENCY: cannot switch provider mid-ingest — "
                                "%d chunk(s) already written with '%s'. "
                                "Delete this document's vectors and re-ingest to use a single provider.",
                                successful_chunks, provider_name,
                            )
                            raise Exception(
                                f"Embedding provider switch blocked mid-ingest: {successful_chunks} chunks "
                                f"already written with '{provider_name}'. "
                                "Delete existing vectors for this document and retry."
                            )

                        logger.warning(f"🚫 Rate limit detected! Cascading to next model tier...")

                        current_model_index += 1
                        embeddings, provider_name, current_model_index = get_embeddings_with_fallback(current_model_index)

                        if embeddings is None:
                            failed_chunks += len(batch)
                            logger.error(f"❌ All 4 models exhausted! Failed after {successful_chunks} chunks")
                            raise Exception(f"Semua 4 embedding models gagal (2M TPM exhausted) setelah {successful_chunks} chunks berhasil.")

                        vectorstore = Chroma(
                            collection_name=VECTOR_COLLECTION_NAME,
                            embedding_function=embeddings,
                            persist_directory=CHROMA_PATH
                        )

                        remaining_start = sum(len(b[0]) for b in smart_batches[:batch_index-1])
                        for remaining_chunk in chunks[remaining_start:]:
                            remaining_chunk.metadata["embedding_model"] = provider_name

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
                                    logger.error(f"❌ Batch {batch_index} exceeds token limit even after cascade")
                                    failed_chunks += len(batch)
                                    break

                                is_rate_limit_retry = any(indicator in retry_error_msg.lower() for indicator in ["429", "rate limit", "resource_exhausted"])
                                if is_rate_limit_retry:
                                    if retry < max_retries - 1:
                                        retry_delay *= 2
                                        continue
                                    else:
                                        # EMBEDDING_MODELS is imported at module level; using it here
                                        # directly avoids the UnboundLocalError that a local
                                        # `from ... import EMBEDDING_MODELS` would cause.
                                        if current_model_index < len(EMBEDDING_MODELS) - 1:
                                            current_model_index += 1
                                            embeddings, provider_name, current_model_index = get_embeddings_with_fallback(current_model_index)
                                            if embeddings:
                                                vectorstore = Chroma(
                                                    collection_name=VECTOR_COLLECTION_NAME,
                                                    embedding_function=embeddings,
                                                    persist_directory=CHROMA_PATH
                                                )
                                                remaining_start = sum(len(b[0]) for b in smart_batches[:batch_index-1])
                                                for remaining_chunk in chunks[remaining_start:]:
                                                    remaining_chunk.metadata["embedding_model"] = provider_name
                                                continue

                                if retry == max_retries - 1:
                                    logger.error(f"❌ Batch {batch_index} gagal setelah {max_retries} retries")
                                    failed_chunks += len(batch)
                                break
                else:
                    is_token_limit = any(indicator in error_msg.lower() for indicator in ["413", "tokens_limit_reached", "too large", "body too large"])

                    if is_token_limit:
                        logger.error(f"❌ Batch {batch_index} exceeds token limit ({batch_tokens:,} tokens)")
                        logger.error(f"💡 Suggestion: Reduce TOKEN_CHUNK_SIZE or AGGRESSIVE_BATCH_SIZE in .env")
                        failed_chunks += len(batch)
                    else:
                        logger.error(f"❌ Batch {batch_index} gagal (non-rate-limit atau no fallback)")
                        failed_chunks += len(batch)

        success_rate = (successful_chunks / len(chunks) * 100) if len(chunks) > 0 else 0
        logger.info(f"{'='*60}")
        logger.info(f"✅ Document '{filename}' processing completed")
        logger.info(f"Success: {successful_chunks}/{len(chunks)} chunks ({success_rate:.1f}%)")
        logger.info(f"Failed: {failed_chunks} chunks")
        logger.info(f"Final embedding model: {provider_name}")
        logger.info(f"Total tokens processed: ~{estimated_tokens:,}")
        logger.info(f"{'='*60}")

        metrics = {
            "chunk_count": len(chunks),
            "successful_chunks": successful_chunks,
            "failed_chunks": failed_chunks,
            "embedding_provider": provider_name,
        }

        if failed_chunks > 0:
            return _result(
                False,
                f"Ingest partial: {failed_chunks}/{len(chunks)} chunks gagal saat embedding.",
                metrics,
            )

        # ── PDR parent upsert (deferred until child ingest succeeds) ─────────
        # Parents are written HERE — after all child vectors are durably stored.
        # Writing parents before child ingest would leave the parent collection
        # in an updated state even if child ingest subsequently fails.
        if pdr_enabled and pdr_parent_docs:
            try:
                _p_store = Chroma(
                    collection_name=PARENT_COLLECTION_NAME,
                    persist_directory=CHROMA_PATH,
                )
                _p_col = _p_store._collection
                p_ids   = [pid for pid, _, _ in pdr_parent_docs]
                p_texts = [txt for _, txt, _ in pdr_parent_docs]
                p_metas = [meta for _, _, meta in pdr_parent_docs]
                _p_col.upsert(
                    ids=p_ids,
                    documents=p_texts,
                    metadatas=p_metas,
                    embeddings=[[0.0] * MAX_EMBEDDING_DIM] * len(p_ids),
                )
                logger.info("✅ PDR: %d parent chunks disimpan ke ChromaDB (post-ingest)", len(pdr_parent_docs))
            except Exception as _pe:
                logger.warning("⚠️  Gagal menyimpan parent PDR: %s", _pe)
        else:
            p_ids = []

        # ── Post-ingest atomic cleanup ────────────────────────────────────────
        # Now that the new vectors are durably written, delete the OLD vector IDs
        # that were snapshotted before the ingest started. This is safe because:
        #   - If we reach here, all new chunks are already queryable.
        #   - We delete by specific IDs, so the new chunks are untouched.
        #   - If this cleanup fails, the old chunks remain as stale duplicates
        #     but retrieval continues to work (new chunks are preferred by score).
        if old_vector_ids:
            if _delete_chroma_ids_with_retry(
                vectorstore.delete,
                old_vector_ids,
                "stale vectors",
                document_id,
            ):
                logger.info(
                    "✅ Post-ingest cleanup: deleted %d stale vectors for document_id=%s",
                    len(old_vector_ids), document_id,
                )

        if old_parent_ids:
            try:
                _parent_vs = Chroma(
                    collection_name=PARENT_COLLECTION_NAME,
                    persist_directory=CHROMA_PATH,
                )
                # Exclude IDs that were just upserted. PDR parent IDs are
                # deterministic (content-hash-based), so re-ingesting the same
                # content produces the same IDs. Deleting them would erase the
                # data we just wrote.
                new_p_ids_set: set[str] = set(p_ids) if p_ids else set()
                stale_parent_ids = [pid for pid in old_parent_ids if pid not in new_p_ids_set]
                if stale_parent_ids:
                    if _delete_chroma_ids_with_retry(
                        _parent_vs._collection.delete,
                        stale_parent_ids,
                        "stale parent chunks",
                        document_id,
                    ):
                        logger.info(
                            "✅ Post-ingest cleanup: deleted %d stale parent chunks for document_id=%s",
                            len(stale_parent_ids), document_id,
                        )
                else:
                    logger.info("Post-ingest parent cleanup: no stale IDs to delete (all IDs reused)")
            except Exception as _pcleanup_err:
                logger.warning("Post-ingest parent cleanup failed: %s", _pcleanup_err)

        return _result(
            True,
            "Document processed successfully dengan Token-Aware Chunking & Aggressive Batching.",
            metrics,
        )

    except Exception as e:
        logger.error(f"❌ Error processing document '{filename}': {type(e).__name__}: {str(e)}")
        return _result(False, str(e))


def delete_document_vectors(
    filename: str,
    user_id: str | None = None,
    document_id: str | None = None,
    cleanup_legacy: bool = False,
):
    try:
        from app.services.rag_config import (
            CHROMA_PATH,
            VECTOR_COLLECTION_NAME,
            PARENT_COLLECTION_NAME,
        )
        if not user_id:
            return False, "user_id is required to delete document vectors safely."

        embeddings, provider_name, _ = get_embeddings_with_fallback()

        if embeddings is None:
            return False, "Tidak dapat menginisialisasi embedding model untuk delete operation."

        vectorstore = Chroma(
            collection_name=VECTOR_COLLECTION_NAME,
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )

        if document_id:
            # Always delete new-style chunks by document_id (strict).
            vectorstore.delete(where={"$and": [{"document_id": str(document_id)}, {"user_id": str(user_id)}]})
            if cleanup_legacy:
                # Also delete legacy chunks (only have filename+user_id, no document_id).
                # Only safe when the caller KNOWS the filename is being permanently
                # retired (user-initiated delete or pre-ingest cleanup).
                # Must NOT be used from ProcessDocument job cleanup — that job runs
                # after the old document was deleted and a new document with the same
                # filename may already have been uploaded.
                _delete_legacy_chunks_by_filename(vectorstore._collection, filename, str(user_id))
        else:
            vectorstore.delete(where={"$and": [{"filename": filename}, {"user_id": str(user_id)}]})

        try:
            parent_store = Chroma(
                collection_name=PARENT_COLLECTION_NAME,
                persist_directory=CHROMA_PATH,
            )
            raw_col = parent_store._collection
            if document_id:
                raw_col.delete(where={
                    "$and": [{"document_id": str(document_id)}, {"user_id": str(user_id)}, {"chunk_type": "parent"}],
                })
                if cleanup_legacy:
                    _delete_legacy_chunks_by_filename(raw_col, filename, str(user_id), chunk_type="parent")
            else:
                raw_col.delete(where={
                    "$and": [{"filename": filename}, {"user_id": str(user_id)}, {"chunk_type": "parent"}],
                })
            logger.info("✅ PDR parent chunks for %s deleted for user_id=%s", filename, user_id)
        except Exception as pe:
            logger.debug("PDR parent delete skipped (mungkin non-PDR dokumen): %s", pe)

        logger.info("✅ Vectors for %s deleted successfully for user_id=%s using %s", filename, user_id, provider_name)
        return True, f"Vectors for {filename} deleted successfully."
    except Exception as e:
        logger.error(f"❌ Error deleting vectors for {filename}: {str(e)}")
        return False, str(e)


def _delete_legacy_chunks_by_filename(
    collection,
    filename: str,
    user_id: str,
    chunk_type: str | None = None,
) -> int:
    """Delete filename-scoped chunks only when they pre-date document_id metadata."""
    where_conditions = [{"filename": filename}, {"user_id": str(user_id)}]
    if chunk_type is not None:
        where_conditions.append({"chunk_type": chunk_type})

    where = {"$and": where_conditions}

    try:
        results = collection.get(where=where, include=["metadatas"])
    except Exception as exc:
        logger.warning("Legacy vector lookup skipped for %s user_id=%s: %s", filename, user_id, exc)
        return 0

    ids = results.get("ids", []) if isinstance(results, dict) else []
    metadatas = results.get("metadatas", []) if isinstance(results, dict) else []
    legacy_ids: list[str] = []

    for chunk_id, metadata in zip(ids, metadatas):
        if not isinstance(metadata, dict):
            continue

        if str(metadata.get("document_id") or "").strip() == "":
            legacy_ids.append(str(chunk_id))

    if legacy_ids:
        collection.delete(ids=legacy_ids)
        logger.info(
            "✅ Legacy cleanup: deleted %d filename-only chunks for %s user_id=%s",
            len(legacy_ids),
            filename,
            user_id,
        )

    return len(legacy_ids)
