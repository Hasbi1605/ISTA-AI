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
