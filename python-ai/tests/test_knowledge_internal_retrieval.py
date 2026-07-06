import asyncio
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app import chat_api
from app.services import knowledge_retrieval


class _DummyStreamingResponse:
    def __init__(self, body, media_type=None):
        self.body_iterator = body
        self.media_type = media_type


class _FakeRequest:
    class _Headers:
        def get(self, key, default=None):
            if key == "X-Request-ID":
                return "rid-knowledge-test"
            return default

    headers = _Headers()


class _Doc:
    def __init__(self, content, metadata):
        self.page_content = content
        self.metadata = metadata


def _collect_stream(it):
    return list(it)


def test_internal_knowledge_intent_detector_is_conservative():
    assert knowledge_retrieval.should_use_internal_knowledge("Apa SOP penerimaan tamu di Istana?")
    assert knowledge_retrieval.should_use_internal_knowledge("Bagaimana alur surat dinas internal?")
    assert not knowledge_retrieval.should_use_internal_knowledge("Buatkan puisi pendek tentang pagi")
    assert not knowledge_retrieval.should_use_internal_knowledge("Berapa skor pertandingan hari ini?")


def test_knowledge_filter_uses_global_active_namespace():
    assert knowledge_retrieval.build_knowledge_filter() == {
        "$and": [
            {"user_id": "__knowledge__"},
            {"scope": "global_internal"},
            {"knowledge_status": "active"},
        ],
    }


def test_search_internal_knowledge_filters_by_safe_metadata(monkeypatch):
    captured = {}

    class FakeChroma:
        def __init__(self, collection_name, embedding_function, persist_directory):
            captured["collection_name"] = collection_name
            captured["embedding_function"] = embedding_function
            captured["persist_directory"] = persist_directory

        def similarity_search_with_score(self, query, k, filter):
            captured["query"] = query
            captured["k"] = k
            captured["filter"] = filter
            return [
                (
                    _Doc(
                        "SOP penerimaan tamu wajib melalui meja registrasi.",
                        {
                            "filename": "sop-tamu.pdf",
                            "chunk_index": 1,
                            "knowledge_source_id": "ks-7",
                            "knowledge_title": "SOP Tamu",
                            "scope": "global_internal",
                            "knowledge_status": "active",
                            "user_id": "__knowledge__",
                        },
                    ),
                    0.24,
                ),
                (
                    _Doc(
                        "Chunk terlalu jauh",
                        {
                            "filename": "old.pdf",
                            "knowledge_status": "active",
                            "scope": "global_internal",
                            "user_id": "__knowledge__",
                        },
                    ),
                    2.5,
                ),
            ]

    monkeypatch.setenv("KNOWLEDGE_INTERNAL_TOP_K", "3")
    monkeypatch.setenv("KNOWLEDGE_INTERNAL_CANDIDATES", "9")
    monkeypatch.setenv("KNOWLEDGE_INTERNAL_MAX_DISTANCE", "1.0")
    monkeypatch.setattr(knowledge_retrieval, "get_chroma_store", lambda *args, **kwargs: FakeChroma(*args, **kwargs))
    monkeypatch.setattr(knowledge_retrieval, "get_embeddings_with_fallback", lambda: ("emb", "fake-embedding", 0))
    monkeypatch.setattr(knowledge_retrieval, "_exclude_parent_search_results", lambda docs: docs)

    chunks, success = knowledge_retrieval.search_internal_knowledge("Apa SOP tamu?", request_id="rid-1")

    assert success is True
    assert captured["filter"] == knowledge_retrieval.build_knowledge_filter()
    assert captured["k"] == 9
    assert len(chunks) == 1
    assert chunks[0]["type"] == "knowledge"
    assert chunks[0]["filename"] == "sop-tamu.pdf"
    assert chunks[0]["knowledge_source_id"] == "ks-7"
    assert chunks[0]["title"] == "SOP Tamu"


def test_build_knowledge_prompt_marks_sources_as_knowledge():
    prompt, sources = knowledge_retrieval.build_knowledge_prompt(
        "Apa SOP tamu?",
        [
            {
                "content": "Tamu harus registrasi.",
                "filename": "sop-tamu.pdf",
                "title": "SOP Tamu",
                "chunk_index": 0,
                "score": 0.2,
                "knowledge_source_id": "ks-7",
            }
        ],
    )

    assert "KONTEKS PENGETAHUAN INTERNAL" in prompt
    assert "Tamu harus registrasi." in prompt
    assert sources == [
        {
            "type": "knowledge",
            "title": "SOP Tamu",
            "filename": "sop-tamu.pdf",
            "chunk_index": 0,
            "relevance_score": 0.2,
            "knowledge_source_id": "ks-7",
        }
    ]


def test_chat_stream_uses_knowledge_for_relevant_general_chat(monkeypatch):
    captured = {}
    req = chat_api.ChatRequest(
        messages=[{"role": "user", "content": "Apa SOP penerimaan tamu?"}],
        user_id="15",
    )

    def fake_policy_helpers():
        return (
            lambda q: False,
            lambda **kwargs: (False, "NO_WEB", "low"),
            lambda *args, **kwargs: {"search_context": ""},
        )

    def fake_streamers():
        def _general(*args, **kwargs):
            raise AssertionError("general chat should not be used when knowledge chunks exist")

        def _with_sources(messages, sources):
            captured["messages"] = messages
            captured["sources"] = sources
            yield "jawaban knowledge"

        return _general, _with_sources

    def fake_knowledge_helpers():
        def _search(query, top_k=None, request_id=None):
            captured["request_id"] = request_id
            return ([{"content": "SOP tamu", "filename": "sop.pdf", "title": "SOP"}], True)

        return (
            lambda: True,
            lambda query: True,
            _search,
            lambda query, chunks: ("PROMPT KNOWLEDGE", [{"type": "knowledge", "filename": "sop.pdf"}]),
        )

    monkeypatch.setattr(chat_api, "StreamingResponse", _DummyStreamingResponse)
    monkeypatch.setattr(chat_api, "_get_rag_policy_helpers", fake_policy_helpers)
    monkeypatch.setattr(chat_api, "_get_chat_streamers", fake_streamers)
    monkeypatch.setattr(chat_api, "_get_knowledge_helpers", fake_knowledge_helpers)

    response = asyncio.run(chat_api.chat_stream(req, _FakeRequest()))
    chunks = _collect_stream(response.body_iterator)

    assert chunks == ["jawaban knowledge"]
    assert captured["request_id"] == "rid-knowledge-test"
    assert captured["messages"][0] == {"role": "system", "content": "PROMPT KNOWLEDGE"}
    assert captured["sources"][0]["type"] == "knowledge"


def test_chat_stream_prioritizes_knowledge_over_auto_web_for_internal_query(monkeypatch):
    captured = {}
    req = chat_api.ChatRequest(
        messages=[{"role": "user", "content": "Apa SOP terbaru penerimaan tamu?"}],
        user_id="15",
        explicit_web_request=False,
        force_web_search=False,
    )

    def fake_policy_helpers():
        return (
            lambda q: False,
            lambda **kwargs: (True, "AUTO_REALTIME_MEDIUM", "medium"),
            lambda *args, **kwargs: {"search_context": "WEB"},
        )

    def fake_streamers():
        def _general(*args, **kwargs):
            raise AssertionError("auto web should not override internal knowledge intent")

        def _with_sources(messages, sources):
            captured["messages"] = messages
            captured["sources"] = sources
            yield "jawaban knowledge"

        return _general, _with_sources

    def fake_knowledge_helpers():
        return (
            lambda: True,
            lambda query: True,
            lambda query, top_k=None, request_id=None: ([{"content": "SOP", "filename": "sop.pdf"}], True),
            lambda query, chunks: ("PROMPT KNOWLEDGE", [{"type": "knowledge", "filename": "sop.pdf"}]),
        )

    monkeypatch.setattr(chat_api, "StreamingResponse", _DummyStreamingResponse)
    monkeypatch.setattr(chat_api, "_get_rag_policy_helpers", fake_policy_helpers)
    monkeypatch.setattr(chat_api, "_get_chat_streamers", fake_streamers)
    monkeypatch.setattr(chat_api, "_get_knowledge_helpers", fake_knowledge_helpers)

    response = asyncio.run(chat_api.chat_stream(req, _FakeRequest()))
    chunks = _collect_stream(response.body_iterator)

    assert chunks == ["jawaban knowledge"]
    assert captured["messages"][0]["content"] == "PROMPT KNOWLEDGE"
    assert captured["sources"][0]["type"] == "knowledge"


def test_chat_stream_respects_explicit_web_request_over_knowledge(monkeypatch):
    captured = {"knowledge_search": 0, "general": 0}
    req = chat_api.ChatRequest(
        messages=[{"role": "user", "content": "Cari di web SOP penerimaan tamu terbaru"}],
        user_id="15",
        explicit_web_request=True,
    )

    def fake_policy_helpers():
        return (
            lambda q: True,
            lambda **kwargs: (True, "EXPLICIT_WEB", "high"),
            lambda *args, **kwargs: {"search_context": "WEB"},
        )

    def fake_streamers():
        def _general(*args, **kwargs):
            captured["general"] += 1
            yield "jawaban web"

        def _with_sources(*args, **kwargs):
            raise AssertionError("explicit web should bypass knowledge retrieval")

        return _general, _with_sources

    def fake_knowledge_helpers():
        def _search(*args, **kwargs):
            captured["knowledge_search"] += 1
            return ([{"content": "SOP", "filename": "sop.pdf"}], True)

        return (lambda: True, lambda query: True, _search, lambda query, chunks: ("", []))

    monkeypatch.setattr(chat_api, "StreamingResponse", _DummyStreamingResponse)
    monkeypatch.setattr(chat_api, "_get_rag_policy_helpers", fake_policy_helpers)
    monkeypatch.setattr(chat_api, "_get_chat_streamers", fake_streamers)
    monkeypatch.setattr(chat_api, "_get_knowledge_helpers", fake_knowledge_helpers)

    response = asyncio.run(chat_api.chat_stream(req, _FakeRequest()))
    chunks = _collect_stream(response.body_iterator)

    assert chunks == ["jawaban web"]
    assert captured["knowledge_search"] == 0
    assert captured["general"] == 1


def test_chat_stream_skips_knowledge_for_generic_general_chat(monkeypatch):
    captured = {"knowledge_search": 0, "general": 0}
    req = chat_api.ChatRequest(messages=[{"role": "user", "content": "Halo, apa kabar?"}], user_id="15")

    def fake_policy_helpers():
        return (
            lambda q: False,
            lambda **kwargs: (False, "NO_WEB", "low"),
            lambda *args, **kwargs: {"search_context": ""},
        )

    def fake_streamers():
        def _general(*args, **kwargs):
            captured["general"] += 1
            yield "jawaban biasa"

        def _with_sources(*args, **kwargs):
            raise AssertionError("knowledge sources should not be used")

        return _general, _with_sources

    def fake_knowledge_helpers():
        def _search(*args, **kwargs):
            captured["knowledge_search"] += 1
            return ([], True)

        return (lambda: True, lambda query: False, _search, lambda query, chunks: ("", []))

    monkeypatch.setattr(chat_api, "StreamingResponse", _DummyStreamingResponse)
    monkeypatch.setattr(chat_api, "_get_rag_policy_helpers", fake_policy_helpers)
    monkeypatch.setattr(chat_api, "_get_chat_streamers", fake_streamers)
    monkeypatch.setattr(chat_api, "_get_knowledge_helpers", fake_knowledge_helpers)

    response = asyncio.run(chat_api.chat_stream(req, _FakeRequest()))
    chunks = _collect_stream(response.body_iterator)

    assert chunks == ["jawaban biasa"]
    assert captured["knowledge_search"] == 0
    assert captured["general"] == 1
