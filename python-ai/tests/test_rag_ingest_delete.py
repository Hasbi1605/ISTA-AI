import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))


def test_delete_document_vectors_requires_user_id():
    from app.services.rag_ingest import delete_document_vectors

    success, message = delete_document_vectors("agenda.pdf")

    assert success is False
    assert "user_id is required" in message


def test_delete_document_vectors_scopes_filename_by_user(monkeypatch):
    from app.services import rag_ingest

    calls = {"vector": [], "parent": []}

    class FakeCollection:
        def delete(self, where):
            calls["parent"].append(where)

    class FakeChroma:
        def __init__(self, collection_name, **kwargs):
            self.collection_name = collection_name
            self._collection = FakeCollection()

        def delete(self, where):
            calls["vector"].append(where)

    monkeypatch.setattr(rag_ingest, "get_embeddings_with_fallback", lambda: (object(), "fake-provider", 0))
    monkeypatch.setattr(rag_ingest, "Chroma", FakeChroma)

    success, message = rag_ingest.delete_document_vectors("agenda.pdf", user_id="42")

    assert success is True
    assert "agenda.pdf" in message
    assert calls["vector"] == [{
        "$and": [
            {"filename": "agenda.pdf"},
            {"user_id": "42"},
        ],
    }]
    assert calls["parent"] == [{
        "$and": [
            {"filename": "agenda.pdf"},
            {"user_id": "42"},
            {"chunk_type": "parent"},
        ],
    }]


def test_delete_chroma_ids_with_retry_recovers_from_transient_failure(monkeypatch):
    from app.services import rag_ingest

    monkeypatch.setattr(rag_ingest.time, "sleep", lambda _seconds: None)

    calls = {"count": 0, "ids": None}

    def flaky_delete(*, ids):
        calls["count"] += 1
        calls["ids"] = ids
        if calls["count"] == 1:
            raise RuntimeError("transient chroma delete failure")

    success = rag_ingest._delete_chroma_ids_with_retry(
        flaky_delete,
        ["old-parent-1", "old-parent-2"],
        "stale parent chunks",
        "doc-123",
    )

    assert success is True
    assert calls == {"count": 2, "ids": ["old-parent-1", "old-parent-2"]}


def test_process_document_returns_false_on_partial_batch_failure(monkeypatch, tmp_path):
    """
    Regression for H2: when at least one embedding batch fails during ingest,
    process_document must return (False, ...) — not (True, ...).
    Previously the function always returned True regardless of failed_chunks.
    """
    from langchain_core.documents import Document as LCDoc

    # Fake embeddings that return a plausible embedding vector.
    class FakeEmbeddings:
        def embed_documents(self, texts):
            return [[0.1] * 3072 for _ in texts]

        def embed_query(self, text):
            return [0.1] * 3072

    # Fake Chroma whose add_documents always raises, simulating every batch
    # failing at the embedding provider level.
    class FakeChromaAlwaysFail:
        def __init__(self, *a, **kw):
            pass

        def add_documents(self, *a, **kw):
            raise Exception("Fake provider error — batch rejected")

        def delete(self, *a, **kw):
            pass

        @property
        def _collection(self):
            return None

    # Patch get_embeddings_with_fallback.
    monkeypatch.setattr(
        "app.services.rag_ingest.get_embeddings_with_fallback",
        lambda *args, **kwargs: (FakeEmbeddings(), "fake-provider", 0),
    )

    # Patch the lightweight loader (imported as _load_documents_lightweight in rag_ingest).
    monkeypatch.setattr(
        "app.services.rag_ingest._load_documents_lightweight",
        lambda *args, **kwargs: [LCDoc(page_content="chunk one"), LCDoc(page_content="chunk two")],
    )

    # Patch delete_document_vectors (called on re-ingest) to be a no-op.
    monkeypatch.setattr(
        "app.services.rag_ingest.delete_document_vectors",
        lambda *args, **kwargs: (True, "deleted"),
    )

    # Replace the Chroma name in rag_ingest's module namespace so both
    # the main vectorstore and the PDR parent store use the failing fake.
    monkeypatch.setattr("app.services.rag_ingest.Chroma", FakeChromaAlwaysFail)

    # Minimal temp file — content doesn't matter because the loader is patched.
    test_file = tmp_path / "sample.txt"
    test_file.write_text("placeholder", encoding="utf-8")

    from app.services.rag_ingest import process_document

    success, message = process_document(
        str(test_file),
        "sample.txt",
        user_id="user-test-42",
        document_id="99",
    )

    # H2 fix: partial failure must return (False, ...).
    assert success is False, f"Expected False but got True with message: {message}"
    # The message should mention chunks failing.
    assert any(kw in message.lower() for kw in ("chunk", "gagal", "partial", "fail", "ingest"))


def test_strict_delete_does_not_delete_by_filename(monkeypatch):
    """
    Regression for H3/H4: when delete_document_vectors is called with
    document_id but cleanup_legacy=False (default / strict mode), only the
    document_id-based filter should be applied.
    The filename-based filter must NOT be sent — it would otherwise delete
    vectors belonging to a newly uploaded document with the same filename.

    This covers the skenario:
    - Old 'agenda.pdf' (document_id=5) is being processed.
    - User deletes it; user re-uploads 'agenda.pdf' (document_id=9).
    - Old ProcessDocument job finishes and calls deleteVectorsForDocument
      (strict = cleanup_legacy omitted / False).
    - Only document_id=5 chunks must be removed; document_id=9 must survive.
    """
    from app.services.rag_ingest import delete_document_vectors

    delete_calls: list = []

    class FakeCollection:
        def delete(self, where):
            delete_calls.append({"type": "parent", "where": where})

    class FakeChroma:
        def __init__(self, collection_name, **kwargs):
            self.collection_name = collection_name
            self._collection = FakeCollection()

        def delete(self, where):
            delete_calls.append({"type": "main", "where": where})

    monkeypatch.setattr("app.services.rag_ingest.Chroma", FakeChroma)
    monkeypatch.setattr(
        "app.services.rag_ingest.get_embeddings_with_fallback",
        lambda *a, **kw: (object(), "fake", 0),
    )

    delete_document_vectors(
        "agenda.pdf",
        user_id="user-1",
        document_id="5",
        cleanup_legacy=False,   # strict mode — simulates ProcessDocument job
    )

    # Exactly one main delete call should have been issued.
    main_deletes = [c for c in delete_calls if c["type"] == "main"]
    assert len(main_deletes) == 1, f"Expected 1 main delete call, got {len(main_deletes)}"

    # The single delete must filter by document_id, NOT by filename.
    filter_where = str(main_deletes[0]["where"])
    assert "document_id" in filter_where, "Delete must include document_id filter"
    assert "filename" not in filter_where, \
        "Strict delete must NOT filter by filename — would delete new document's vectors"


def test_legacy_cleanup_delete_removes_both_new_and_legacy_chunks(monkeypatch):
    """
    Regression: when cleanup_legacy=True (user-initiated delete), both
    document_id-based AND filename-based filters must be applied to clean
    up legacy chunks that pre-date document_id tracking.
    """
    from app.services.rag_ingest import delete_document_vectors

    delete_calls: list = []

    class FakeCollection:
        def delete(self, where):
            delete_calls.append({"type": "parent", "where": where})

    class FakeChroma:
        def __init__(self, collection_name, **kwargs):
            self.collection_name = collection_name
            self._collection = FakeCollection()

        def delete(self, where):
            delete_calls.append({"type": "main", "where": where})

    monkeypatch.setattr("app.services.rag_ingest.Chroma", FakeChroma)
    monkeypatch.setattr(
        "app.services.rag_ingest.get_embeddings_with_fallback",
        lambda *a, **kw: (object(), "fake", 0),
    )

    delete_document_vectors(
        "agenda.pdf",
        user_id="user-1",
        document_id="5",
        cleanup_legacy=True,    # full cleanup — simulates user-initiated delete
    )

    main_deletes = [c for c in delete_calls if c["type"] == "main"]
    assert len(main_deletes) == 2, f"Expected 2 main delete calls (new + legacy), got {len(main_deletes)}"

    filters_str = " ".join(str(c["where"]) for c in main_deletes)
    assert "document_id" in filters_str, "One pass must delete by document_id"
    assert "filename" in filters_str, "One pass must delete by filename for legacy cleanup"


# ── Embedding consistency guard ──────────────────────────────────────────────

def test_process_document_fails_closed_on_embedding_provider_mismatch(monkeypatch):
    """process_document must return (False, error_message) when existing vectors
    were embedded with a different provider than the current one."""
    import os as _os
    from app.services import rag_ingest

    # Make the function believe the file exists and has content.
    monkeypatch.setattr(_os.path, "exists", lambda p: True)
    monkeypatch.setattr(_os.path, "getsize", lambda p: 1024)

    fake_embeddings = object()  # sentinel — not actually called in this path

    # Return a different provider name than what's stored in the old vectors.
    monkeypatch.setattr(
        "app.services.rag_ingest.get_embeddings_with_fallback",
        lambda *a, **kw: (fake_embeddings, "new-provider-openai", 0),
    )
    monkeypatch.setattr(
        "app.services.rag_ingest._load_documents_lightweight",
        lambda *a, **kw: [type("Doc", (), {"page_content": "text", "metadata": {}})()],
    )

    # Simulate: existing vectors use "old-provider-gemini" stored in metadata.
    class FakeCollection:
        def get(self, where=None, ids=None, include=None):
            if ids is not None:
                return {"ids": ids, "metadatas": [{"embedding_model": "old-provider-gemini"}]}
            return {"ids": ["chunk-1"], "metadatas": [{"embedding_model": "old-provider-gemini"}]}

        def upsert(self, **kw):
            pass

        def delete(self, where=None, ids=None):
            pass

    class FakeChroma:
        def __init__(self, collection_name=None, embedding_function=None, persist_directory=None):
            self._collection = FakeCollection()

        def get(self, where=None, ids=None, include=None):
            return FakeCollection().get(where=where, ids=ids, include=include)

        def delete(self, **kw):
            pass

        def add_documents(self, docs):
            pass

    monkeypatch.setattr("app.services.rag_ingest.Chroma", FakeChroma)
    monkeypatch.setattr("app.services.rag_ingest.count_tokens", lambda text: len(text.split()))

    success, message = rag_ingest.process_document(
        file_path="/fake/doc.pdf",
        filename="doc.pdf",
        user_id="user-1",
        document_id="42",
    )

    assert success is False, f"Expected False but got {success!r}"
    assert "mismatch" in message.lower() or "incompatib" in message.lower(), (
        f"Expected mismatch/incompatibility message, got: {message!r}"
    )


def test_process_document_fails_closed_on_mid_ingest_provider_switch(monkeypatch):
    """After at least one chunk is written with Provider A, a rate-limit
    that would trigger a switch to Provider B must abort ingest with an
    error rather than mixing embeddings mid-collection."""
    import os as _os
    from app.services import rag_ingest

    monkeypatch.setattr(_os.path, "exists", lambda p: True)
    monkeypatch.setattr(_os.path, "getsize", lambda p: 1024)

    # Force 1 chunk per batch so the second batch triggers a rate-limit error
    # after the first batch already wrote 1 chunk with "provider-1".
    monkeypatch.setattr("app.services.rag_ingest.AGGRESSIVE_BATCH_SIZE", 1)
    monkeypatch.setattr("app.services.rag_ingest.MAX_TOKENS_PER_BATCH", 1)

    # Simulate multiple providers available for cascade
    from app.services import rag_config as _rc
    monkeypatch.setattr(
        "app.services.rag_ingest.EMBEDDING_MODELS",
        [{"provider": "provider-1"}, {"provider": "provider-2"}],
    )

    call_count = {"n": 0}

    def fake_get_embeddings(start_index=0, **kw):
        call_count["n"] += 1
        return (object(), f"provider-{call_count['n']}", start_index)

    monkeypatch.setattr("app.services.rag_ingest.get_embeddings_with_fallback", fake_get_embeddings)
    monkeypatch.setattr(
        "app.services.rag_ingest._load_documents_lightweight",
        lambda *a, **kw: [
            type("Doc", (), {"page_content": f"chunk{i}", "metadata": {}})()
            for i in range(3)
        ],
    )

    batch_call_count = {"n": 0}

    class FakeCollection:
        def get(self, where=None, ids=None, include=None):
            return {"ids": [], "metadatas": []}

        def upsert(self, **kw):
            pass

        def delete(self, where=None, ids=None):
            pass

    class FakeChroma:
        def __init__(self, **kw):
            self._collection = FakeCollection()

        def get(self, **kw):
            return {"ids": [], "metadatas": []}

        def delete(self, **kw):
            pass

        def add_documents(self, docs):
            batch_call_count["n"] += 1
            if batch_call_count["n"] == 1:
                # First batch: succeed (writes 1 chunk with provider-1)
                return
            # Second batch: simulate rate limit to trigger provider switch
            raise Exception("429 rate limit exceeded: quota exhausted")

    monkeypatch.setattr("app.services.rag_ingest.Chroma", FakeChroma)
    # count_tokens returns 1 per chunk so batching by MAX_TOKENS_PER_BATCH=1 works
    monkeypatch.setattr("app.services.rag_ingest.count_tokens", lambda text: 1)

    success, message = rag_ingest.process_document(
        file_path="/fake/doc.pdf",
        filename="doc.pdf",
        user_id="user-1",
        document_id="99",
    )

    assert success is False, f"Expected False but got {success!r}"
    # The error must explicitly mention that chunks were already written
    # with the original provider and a provider switch was blocked.
    assert (
        "provider" in message.lower()
        or "switch" in message.lower()
        or "incompatib" in message.lower()
        or "already written" in message.lower()
    ), f"Expected provider-switch message, got: {message!r}"
