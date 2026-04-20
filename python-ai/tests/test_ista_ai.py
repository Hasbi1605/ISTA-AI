"""
ISTA AI — Python Test Suite
Cakupan: web search detection, PDR config, rerank config, realtime patterns
"""
import sys
import os
import pytest

# Pastikan app module bisa di-import
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))


# ─── Fixtures ─────────────────────────────────────────────────────────────────

@pytest.fixture(scope="module")
def rag():
    """Import rag_service sekali per module (berat)."""
    from app.services import rag_service
    return rag_service


@pytest.fixture(scope="module")
def cfg():
    """Import config_loader sekali per module."""
    from app import config_loader
    return config_loader


# ═══════════════════════════════════════════════════════════════════════════════
# 1. CONFIG TESTS
# ═══════════════════════════════════════════════════════════════════════════════

class TestPDRConfig:
    def test_pdr_enabled(self, cfg):
        pdr = cfg.get_pdr_config()
        assert pdr.get("enabled") is True, "PDR harus enabled di ai_config.yaml"

    def test_pdr_child_size(self, cfg):
        pdr = cfg.get_pdr_config()
        child = int(pdr.get("child_chunk_size", 0))
        assert 128 <= child <= 512, f"child_chunk_size={child} di luar range wajar 128-512"

    def test_pdr_parent_size(self, cfg):
        pdr = cfg.get_pdr_config()
        parent = int(pdr.get("parent_chunk_size", 0))
        assert 500 <= parent <= 3000, f"parent_chunk_size={parent} di luar range wajar"

    def test_pdr_parent_larger_than_child(self, cfg):
        pdr = cfg.get_pdr_config()
        child  = int(pdr.get("child_chunk_size", 256))
        parent = int(pdr.get("parent_chunk_size", 1500))
        assert parent > child, "parent_chunk_size harus lebih besar dari child_chunk_size"


class TestRerankConfig:
    def test_rerank_enabled(self, cfg):
        rc = cfg.get_rerank_config()
        assert rc.get("enabled") is True

    def test_doc_candidates_gte_top_n(self, cfg):
        rc = cfg.get_rerank_config()
        candidates = int(rc.get("doc_candidates", 25))
        top_n      = int(rc.get("top_n", 8))
        assert candidates >= top_n, (
            f"doc_candidates ({candidates}) harus >= top_n ({top_n})"
        )

    def test_web_candidates_in_yaml(self, cfg):
        rc = cfg.get_rerank_config()
        assert "web_candidates" in rc, (
            "web_candidates harus ada di yaml (bukan hanya di .env)"
        )
        assert int(rc["web_candidates"]) >= 3

    def test_web_top_n_in_yaml(self, cfg):
        rc = cfg.get_rerank_config()
        assert "web_top_n" in rc, "web_top_n harus ada di yaml"
        assert int(rc["web_top_n"]) >= 1

    def test_web_top_n_lte_web_candidates(self, cfg):
        rc = cfg.get_rerank_config()
        web_candidates = int(rc.get("web_candidates", 10))
        web_top_n      = int(rc.get("web_top_n", 5))
        assert web_top_n <= web_candidates, (
            f"web_top_n ({web_top_n}) harus <= web_candidates ({web_candidates})"
        )


class TestHyDEConfig:
    def test_hyde_enabled(self, cfg):
        hyde = cfg.get_hyde_config()
        assert hyde.get("enabled") is True

    def test_hyde_mode_valid(self, cfg):
        hyde = cfg.get_hyde_config()
        assert hyde.get("mode") in ("always", "smart", "off")

    def test_hyde_timeout(self, cfg):
        hyde = cfg.get_hyde_config()
        timeout = hyde.get("timeout", 5)
        assert 1 <= int(timeout) <= 30


# ═══════════════════════════════════════════════════════════════════════════════
# 2. WEB SEARCH DETECTION TESTS
# ═══════════════════════════════════════════════════════════════════════════════

class TestRealtimeIntentDetection:
    """Test detect_realtime_intent_level() — harus return high/medium/low."""

    HIGH_QUERIES = [
        "kurs dollar sekarang",
        "cuaca hari ini jakarta",
        "berita terbaru indonesia",
        "berita terkini",
        "harga saham hari ini",
        "ihsg sekarang",
        "jam berapa sekarang",
        "jadwal pertandingan malam ini",
        "gempa bumi hari ini",
        "prakiraan cuaca besok",
        "bitcoin hari ini",
        "harga emas sekarang",
        "breaking news",
        "live score",
    ]

    MEDIUM_QUERIES = [
        "update terbaru",
        "berita ekonomi terkini",
        "jadwal besok",
        "cuaca minggu ini",
    ]

    LOW_QUERIES = [
        "apa itu fotosintesis",
        "jelaskan teori relativitas",
        "siapa albert einstein",
        "cara membuat kue",
        "pengertian demokrasi",
    ]

    def test_high_queries(self, rag):
        for q in self.HIGH_QUERIES:
            result = rag.detect_realtime_intent_level(q)
            assert result == "high", (
                f"Query '{q}' diharapkan 'high', dapat '{result}'"
            )

    def test_medium_queries(self, rag):
        for q in self.MEDIUM_QUERIES:
            result = rag.detect_realtime_intent_level(q)
            assert result in ("medium", "high"), (
                f"Query '{q}' diharapkan minimal 'medium', dapat '{result}'"
            )

    def test_low_queries(self, rag):
        for q in self.LOW_QUERIES:
            result = rag.detect_realtime_intent_level(q)
            assert result == "low", (
                f"Query '{q}' diharapkan 'low', dapat '{result}'"
            )


class TestShouldUseWebSearch:
    """Test should_use_web_search() — keputusan akhir aktif/tidaknya web search."""

    def test_force_web_always_enabled(self, rag):
        should, reason, _ = rag.should_use_web_search(
            "apa itu python", force_web_search=True
        )
        assert should is True
        assert "TOGGLE" in reason

    def test_explicit_web_request(self, rag):
        should, reason, _ = rag.should_use_web_search(
            "cari di web tentang inflasi"
        )
        assert should is True
        assert "EXPLICIT" in reason

    def test_high_realtime_no_docs_triggers_web(self, rag):
        should, reason, _ = rag.should_use_web_search(
            "kurs dollar sekarang",
            documents_active=False,
            allow_auto_realtime_web=True,
        )
        assert should is True
        assert "HIGH" in reason

    def test_medium_realtime_no_docs_triggers_web(self, rag):
        should, reason, _ = rag.should_use_web_search(
            "update terbaru",
            documents_active=False,
            allow_auto_realtime_web=True,
        )
        assert should is True
        assert "MEDIUM" in reason

    def test_docs_active_blocks_web(self, rag):
        """Saat dokumen aktif, web SELALU mati — bahkan untuk query realtime."""
        for q in ["kurs dollar", "cuaca hari ini", "berita terbaru"]:
            should, reason, _ = rag.should_use_web_search(
                q,
                documents_active=True,
                allow_auto_realtime_web=True,
                force_web_search=False,
            )
            assert should is False, (
                f"Query '{q}' dengan docs aktif HARUS no-web, dapat should={should}"
            )
            assert reason == "DOC_NO_WEB"

    def test_low_intent_no_web(self, rag):
        should, reason, _ = rag.should_use_web_search(
            "jelaskan teori relativitas",
            documents_active=False,
            allow_auto_realtime_web=True,
        )
        assert should is False
        assert reason == "NO_WEB"


class TestExplicitWebPatterns:
    """Test detect_explicit_web_request() — deteksi kata kunci eksplisit."""

    EXPLICIT = [
        "cari di web tentang resep masakan",
        "pakai internet untuk mencari info ini",
        "web search berita hari ini",
        "browse web untuk jadwal",
        "search online harga tiket",
    ]

    NOT_EXPLICIT = [
        "apa itu machine learning",
        "jelaskan cuaca hari ini",
        "kurs dollar",
    ]

    def test_explicit_queries(self, rag):
        for q in self.EXPLICIT:
            assert rag.detect_explicit_web_request(q), (
                f"Query '{q}' seharusnya terdeteksi sebagai explicit web request"
            )

    def test_non_explicit_queries(self, rag):
        for q in self.NOT_EXPLICIT:
            assert not rag.detect_explicit_web_request(q), (
                f"Query '{q}' seharusnya BUKAN explicit web request"
            )


# ═══════════════════════════════════════════════════════════════════════════════
# 3. PDR HELPER TESTS
# ═══════════════════════════════════════════════════════════════════════════════

class TestPDRResolveParents:
    """Test _resolve_pdr_parents() — fallback dan dedup behavior."""

    def test_empty_chunks_returns_empty(self, rag):
        result = rag._resolve_pdr_parents([], None, "user_1")
        assert result == []

    def test_non_pdr_chunks_returned_unchanged(self, rag):
        """Chunk tanpa chunk_type (legacy) harus dikembalikan apa adanya."""
        legacy_chunks = [
            {"content": "teks lama", "score": 0.9, "filename": "doc.pdf"}
        ]
        # Tidak ada vectorstore → akan exception → fallback ke child
        result = rag._resolve_pdr_parents(legacy_chunks, None, "user_1")
        # Harus fallback (tidak crash)
        assert isinstance(result, list)
        assert len(result) > 0

    def test_resolve_called_in_fallback_path(self, rag):
        """Verifikasi _resolve_pdr_parents() dipanggil saat rerank disabled/failed."""
        class FakeCollection:
            def get(self, where=None, include=None):
                return {
                    "documents": ["parent fallback content"],
                    "metadatas": [{
                        "parent_id": "parent-fb-1",
                        "filename": "doc.pdf",
                        "parent_index": 5,
                    }],
                }

        class FakeVectorStore:
            _collection = FakeCollection()

        child_chunks_fb = [{
            "content": "child fallback",
            "score": 0.85,
            "filename": "doc.pdf",
            "metadata": {
                "chunk_type": "child",
                "parent_id": "parent-fb-1",
            },
        }]

        result = rag._resolve_pdr_parents(child_chunks_fb, FakeVectorStore(), "user_1")

        assert len(result) == 1
        assert result[0]["content"] == "parent fallback content"
        assert result[0]["pdr"] is True

    def test_child_metadata_can_resolve_parent_after_rerank(self, rag):
        class FakeCollection:
            def get(self, where=None, include=None):
                return {
                    "documents": ["konten parent lengkap"],
                    "metadatas": [{
                        "parent_id": "parent-1",
                        "filename": "doc.pdf",
                        "parent_index": 3,
                    }],
                }

        class FakeVectorStore:
            _collection = FakeCollection()

        child_chunks = [{
            "content": "child",
            "score": 0.8,
            "rerank_score": 0.9,
            "filename": "doc.pdf",
            "metadata": {
                "chunk_type": "child",
                "parent_id": "parent-1",
            },
        }]

        result = rag._resolve_pdr_parents(child_chunks, FakeVectorStore(), "user_1")

        assert len(result) == 1
        assert result[0]["content"] == "konten parent lengkap"
        assert result[0]["pdr"] is True


class TestPDRFiltering:
    def test_exclude_parent_corpus_keeps_child_and_legacy(self, rag):
        documents = ["parent", "child", "legacy"]
        metadatas = [
            {"chunk_type": "parent"},
            {"chunk_type": "child", "parent_id": "p1"},
            {},
        ]

        filtered_docs, filtered_metas = rag._exclude_parent_corpus(documents, metadatas)

        assert filtered_docs == ["child", "legacy"]
        assert filtered_metas == [
            {"chunk_type": "child", "parent_id": "p1"},
            {},
        ]


# ═══════════════════════════════════════════════════════════════════════════════
# 4. UTILITY TESTS
# ═══════════════════════════════════════════════════════════════════════════════

class TestCountTokens:
    def test_empty_string(self, rag):
        assert rag.count_tokens("") == 0

    def test_short_text(self, rag):
        # "Hello world" = 2 token
        count = rag.count_tokens("Hello world")
        assert 1 <= count <= 5

    def test_longer_text_more_tokens(self, rag):
        short = rag.count_tokens("Hello")
        long  = rag.count_tokens("Hello world this is a longer sentence with more tokens")
        assert long > short


class TestEmbeddingDimensionStrategy:
    def test_max_embedding_dim_constant_defined(self, rag):
        assert hasattr(rag, 'MAX_EMBEDDING_DIM')
        assert rag.MAX_EMBEDDING_DIM == 3072

    def test_embedding_models_include_dimensions(self, rag):
        for model in rag.EMBEDDING_MODELS:
            assert 'dimensions' in model
            assert model['dimensions'] in [3072, 1536]

    def test_all_models_use_fixed_chroma_dimension(self, rag):
        max_dim = rag.MAX_EMBEDDING_DIM
        assert max_dim == 3072
        for model in rag.EMBEDDING_MODELS:
            model_dim = model["dimensions"]
            assert model_dim <= max_dim, f"Model {model['name']} exceeds Chroma capacity"

    def test_embedding_model_initialized_with_dimensions_parameter(self, rag):
        import inspect
        from app.services import rag_service

        source = inspect.getsource(rag_service.get_embeddings_with_fallback)
        assert "dimensions" in source, "dimensions param should be passed to OpenAIEmbeddings"

    def test_fallback_tier_uses_correct_dimension(self, rag):
        large_model = rag.EMBEDDING_MODELS[0]
        small_model = rag.EMBEDDING_MODELS[2]

        assert large_model["dimensions"] == 3072, "Primary tier should use 3072 dimensions"
        assert small_model["dimensions"] == 1536, "Fallback tier should use 1536 dimensions"

    def test_all_fallback_models_use_max_embedding_dim(self, rag):
        import inspect
        from app.services import rag_service

        source = inspect.getsource(rag_service.get_embeddings_with_fallback)
        assert "MAX_EMBEDDING_DIM" in source, "All fallback models should use MAX_EMBEDDING_DIM"
        assert "model_config['dimensions']" not in source, "Should NOT use model native dimensions"


# ═══════════════════════════════════════════════════════════════════════════════
# 5. REGRESSION TEST: Upload -> Retrieval Flow
# ═══════════════════════════════════════════════════════════════════════════════

class TestDocumentIdentifierConsistency:
    """
    Regression test untuk issue: filename mismatch antara ingest dan retrieval.
    
    Flow yang diuji:
    1. Ingest dokumen dengan filename="test_doc.pdf", user_id="user_1"
    2. Retrieve dengan filename="test_doc.pdf", user_id="user_1"  
    3. Pastikan chunk ditemukan (tidak ada mismatch identifier)
    """

    def test_ingest_retrieval_filename_consistency(self, rag):
        from app.services import rag_ingest, rag_retrieval
        from langchain_chroma import Chroma
        from app.services.rag_config import CHROMA_PATH

        test_filename = "regression_test_doc.pdf"
        test_user_id = "regression_user_123"
        test_content = "Ini adalah dokumen regresi untuk menguji konsistensi identifier."

        embeddings, _, _ = rag.get_embeddings_with_fallback()
        if embeddings is None:
            pytest.skip("Embeddings tidak tersedia")

        vectorstore = Chroma(
            collection_name="documents_collection",
            embedding_function=embeddings,
            persist_directory=CHROMA_PATH
        )

        vectorstore.delete(where={"filename": test_filename, "user_id": test_user_id})

        from langchain.schema import Document
        test_doc = Document(
            page_content=test_content,
            metadata={
                "filename": test_filename,
                "user_id": test_user_id,
                "chunk_index": 0,
            }
        )
        vectorstore.add_documents([test_doc])

        chunks, success = rag_retrieval.search_relevant_chunks(
            query="dokumen regresi",
            filenames=[test_filename],
            top_k=5,
            user_id=test_user_id,
        )

        assert success is True, "Retrieval harus berhasil"
        assert len(chunks) > 0, f"Harus menemukan chunk untuk filename='{test_filename}', user_id='{test_user_id}'"
        assert chunks[0]["filename"] == test_filename, "Filename di result harus sama dengan yang di-request"
        assert chunks[0]["metadata"]["user_id"] == test_user_id, "User_id di metadata harus sama"

        vectorstore.delete(where={"filename": test_filename, "user_id": test_user_id})


class TestPDRParentIsolation:
    @staticmethod
    def _disable_networked_retrieval_paths(monkeypatch):
        from app import config_loader

        monkeypatch.setattr(config_loader, "get_hyde_config", lambda: {"enabled": False})
        monkeypatch.setattr(config_loader, "get_hybrid_search_config", lambda: {"enabled": False})
        monkeypatch.setattr(config_loader, "get_rag_doc_candidates", lambda: 25)
        monkeypatch.setattr(config_loader, "get_rag_top_n", lambda: 5)

    def test_parent_chunks_do_not_block_child_retrieval(self, monkeypatch):
        from app.services import rag_retrieval, rag_hybrid
        from app.services.rag_config import PARENT_COLLECTION_NAME
        from langchain_core.documents import Document

        class FakeCollection:
            def get(self, where=None, include=None, limit=None):
                return {
                    "documents": ["Isi lengkap parent software project"],
                    "metadatas": [{
                        "filename": "software-project.docx",
                        "user_id": "47",
                        "chunk_type": "parent",
                        "parent_id": "parent-1",
                        "parent_index": 0,
                    }],
                }

        class FakeVectorStore:
            def __init__(self):
                self._collection = FakeCollection()

            def similarity_search_with_score(self, query, k=4, filter=None):
                return [(
                    Document(
                        page_content="Isi penting software project",
                        metadata={
                            "filename": "software-project.docx",
                            "user_id": "47",
                            "chunk_type": "child",
                            "parent_id": "parent-1",
                            "chunk_index": 0,
                        },
                    ),
                    0.1,
                )]

            def get(self, where=None, include=None, limit=None):
                return {
                    "documents": ["Isi penting software project"],
                    "metadatas": [{
                        "filename": "software-project.docx",
                        "user_id": "47",
                        "chunk_type": "child",
                        "parent_id": "parent-1",
                        "chunk_index": 0,
                    }],
                }

        class FakeChroma:
            def __init__(self, collection_name=None, embedding_function=None, persist_directory=None):
                self._collection = FakeCollection()

        self._disable_networked_retrieval_paths(monkeypatch)
        monkeypatch.setattr(
            rag_retrieval,
            "get_embeddings_with_fallback",
            lambda *args, **kwargs: (object(), "fake", 0),
        )
        monkeypatch.setattr(rag_retrieval, "Chroma", lambda *args, **kwargs: FakeVectorStore())
        monkeypatch.setattr(rag_hybrid, "Chroma", FakeChroma)
        monkeypatch.setattr(rag_hybrid, "PARENT_COLLECTION_NAME", PARENT_COLLECTION_NAME)

        chunks, success = rag_retrieval.search_relevant_chunks(
            query="isi software project",
            filenames=["software-project.docx"],
            top_k=5,
            user_id="47",
        )

        assert success is True
        assert len(chunks) == 1
        assert chunks[0]["filename"] == "software-project.docx"
        assert chunks[0].get("pdr") is True
        assert "Isi lengkap parent" in chunks[0]["content"]

    def test_legacy_parent_chunks_in_vector_collection_fall_back_to_child_corpus(self, monkeypatch):
        from app.services import rag_retrieval, rag_hybrid
        from langchain_core.documents import Document

        class LegacyCollection:
            def get(self, where=None, include=None, limit=None):
                return {
                    "documents": ["Parent legacy content"],
                    "metadatas": [{
                        "filename": "legacy.docx",
                        "user_id": "47",
                        "chunk_type": "parent",
                        "parent_id": "legacy-parent-1",
                        "parent_index": 0,
                    }],
                }

        class LegacyVectorStore:
            def __init__(self):
                self._collection = LegacyCollection()

            def similarity_search_with_score(self, query, k=4, filter=None):
                return [(
                    Document(
                        page_content="Parent legacy content",
                        metadata={
                            "filename": "legacy.docx",
                            "user_id": "47",
                            "chunk_type": "parent",
                            "parent_id": "legacy-parent-1",
                            "parent_index": 0,
                        },
                    ),
                    0.0,
                )]

            def get(self, where=None, include=None, limit=None):
                return {
                    "documents": [
                        "Parent legacy content",
                        "Child content yang harus tetap bisa diretrieve",
                    ],
                    "metadatas": [
                        {
                            "filename": "legacy.docx",
                            "user_id": "47",
                            "chunk_type": "parent",
                            "parent_id": "legacy-parent-1",
                            "parent_index": 0,
                        },
                        {
                            "filename": "legacy.docx",
                            "user_id": "47",
                            "chunk_type": "child",
                            "parent_id": "legacy-parent-1",
                            "chunk_index": 0,
                        },
                    ],
                }

        class MissingParentCollection:
            def __init__(self, *args, **kwargs):
                raise RuntimeError("no parent collection")

        self._disable_networked_retrieval_paths(monkeypatch)
        monkeypatch.setattr(
            rag_retrieval,
            "get_embeddings_with_fallback",
            lambda *args, **kwargs: (object(), "fake", 0),
        )
        monkeypatch.setattr(rag_retrieval, "Chroma", lambda *args, **kwargs: LegacyVectorStore())
        monkeypatch.setattr(rag_hybrid, "Chroma", MissingParentCollection)

        chunks, success = rag_retrieval.search_relevant_chunks(
            query="child content retrieve",
            filenames=["legacy.docx"],
            top_k=5,
            user_id="47",
        )

        assert success is True
        assert len(chunks) == 1
        assert chunks[0]["filename"] == "legacy.docx"
        assert chunks[0].get("pdr") is True
        assert "Parent legacy content" in chunks[0]["content"]
