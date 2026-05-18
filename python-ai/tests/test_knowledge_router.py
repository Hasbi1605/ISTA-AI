import io
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))


def _build_client(monkeypatch):
    """Return a FastAPI TestClient with knowledge router mounted and auth bypassed."""
    from fastapi import FastAPI
    from fastapi.testclient import TestClient

    # Bypass token check for tests.
    monkeypatch.setattr("app.api_shared.get_internal_service_token", lambda: "test-token")

    app = FastAPI()
    from app.routers import knowledge

    app.include_router(knowledge.router)
    return TestClient(app)


def test_knowledge_process_calls_rag_with_global_internal_metadata(monkeypatch):
    from app.routers import knowledge as knowledge_module
    from app.services import rag_ingest

    captured = {}

    def fake_process_document(file_path, filename, user_id, document_id):
        captured["file_path"] = file_path
        captured["filename"] = filename
        captured["user_id"] = user_id
        captured["document_id"] = document_id
        captured["overrides"] = dict(rag_ingest._knowledge_metadata_overrides or {})
        return True, "Knowledge ingested"

    monkeypatch.setattr(rag_ingest, "process_document", fake_process_document)

    client = _build_client(monkeypatch)

    response = client.post(
        "/api/knowledge/process",
        headers={"Authorization": "Bearer test-token"},
        files={"file": ("sop.pdf", io.BytesIO(b"%PDF-1.4 dummy"), "application/pdf")},
        data={
            "document_id": "42",
            "knowledge_source_id": "7",
            "scope": "global_internal",
            "audience": "all_users",
            "title": "SOP Penerimaan Tamu",
        },
    )

    assert response.status_code == 200, response.text
    body = response.json()
    assert body["status"] == "success"
    assert body["scope"] == "global_internal"
    assert body["audience"] == "all_users"
    assert body["document_id"] == "42"

    assert captured["filename"] == "sop.pdf"
    assert captured["user_id"] == knowledge_module.KNOWLEDGE_USER_ID
    assert captured["document_id"] == "42"
    assert captured["overrides"] == {
        "scope": "global_internal",
        "audience": "all_users",
        "knowledge_status": "active",
        "knowledge_source_id": "7",
        "knowledge_title": "SOP Penerimaan Tamu",
    }

    # Ensure the override sentinel is reset after the call so subsequent ingests
    # do not accidentally inherit knowledge metadata.
    assert getattr(rag_ingest, "_knowledge_metadata_overrides") is None


def test_knowledge_delete_uses_knowledge_user_id(monkeypatch):
    from app.routers import knowledge as knowledge_module
    from app.services import rag_ingest

    captured = {}

    def fake_delete(filename, user_id=None, document_id=None, cleanup_legacy=False):
        captured["filename"] = filename
        captured["user_id"] = user_id
        captured["document_id"] = document_id
        captured["cleanup_legacy"] = cleanup_legacy
        return True, "deleted"

    monkeypatch.setattr(rag_ingest, "delete_document_vectors", fake_delete)

    client = _build_client(monkeypatch)

    response = client.delete(
        "/api/knowledge/sop.pdf?document_id=42&cleanup_legacy=true",
        headers={"Authorization": "Bearer test-token"},
    )

    assert response.status_code == 200
    assert captured["filename"] == "sop.pdf"
    assert captured["user_id"] == knowledge_module.KNOWLEDGE_USER_ID
    assert captured["document_id"] == "42"
    assert captured["cleanup_legacy"] is True


def test_knowledge_process_requires_document_id(monkeypatch):
    from app.services import rag_ingest

    monkeypatch.setattr(rag_ingest, "process_document", lambda *a, **kw: (True, "ok"))

    client = _build_client(monkeypatch)

    # Sending without document_id at all → FastAPI rejects with 422 (validation).
    response_missing = client.post(
        "/api/knowledge/process",
        headers={"Authorization": "Bearer test-token"},
        files={"file": ("sop.pdf", io.BytesIO(b"dummy"), "application/pdf")},
    )
    assert response_missing.status_code == 422

    # Sending an empty document_id → router rejects with 400 from custom guard.
    response_empty = client.post(
        "/api/knowledge/process",
        headers={"Authorization": "Bearer test-token"},
        files={"file": ("sop.pdf", io.BytesIO(b"dummy"), "application/pdf")},
        data={"document_id": ""},
    )
    assert response_empty.status_code in (400, 422)


def test_knowledge_process_rejects_unsupported_extension(monkeypatch):
    from app.services import rag_ingest

    monkeypatch.setattr(rag_ingest, "process_document", lambda *a, **kw: (True, "ok"))

    client = _build_client(monkeypatch)

    response = client.post(
        "/api/knowledge/process",
        headers={"Authorization": "Bearer test-token"},
        files={"file": ("malware.exe", io.BytesIO(b"binary"), "application/x-msdownload")},
        data={"document_id": "42"},
    )

    assert response.status_code == 400


def test_metadata_overrides_apply_to_chunks(monkeypatch):
    """End-to-end check that _apply_metadata_overrides injects knowledge keys."""
    from app.services import rag_ingest

    chunk_meta = {"filename": "sop.pdf", "user_id": "__knowledge__"}

    rag_ingest._knowledge_metadata_overrides = {
        "scope": "global_internal",
        "audience": "all_users",
        "knowledge_status": "active",
    }
    try:
        rag_ingest._apply_metadata_overrides(chunk_meta)
    finally:
        rag_ingest._knowledge_metadata_overrides = None

    assert chunk_meta["scope"] == "global_internal"
    assert chunk_meta["audience"] == "all_users"
    assert chunk_meta["knowledge_status"] == "active"
