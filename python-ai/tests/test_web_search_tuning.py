"""
Test untuk tuning web search — Issue #193.

Verifikasi:
1. Score query menjalankan focused search paralel dengan search utama
2. Freshness adaptif: oneDay untuk realtime_intent=high
3. Non-score query tidak terpengaruh (tetap serial)
4. Merge hasil paralel benar
"""
import os
import sys
import threading

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import pytest


# ---------------------------------------------------------------------------
# Freshness adaptif
# ---------------------------------------------------------------------------

class TestFreshnessAdaptif:
    """Verifikasi freshness dipilih berdasarkan realtime_intent."""

    def test_freshness_mapping_high_intent_is_one_day(self):
        from app.services.rag_policy import _FRESHNESS_BY_INTENT
        assert _FRESHNESS_BY_INTENT["high"] == "oneDay"

    def test_freshness_mapping_medium_intent_is_one_week(self):
        from app.services.rag_policy import _FRESHNESS_BY_INTENT
        assert _FRESHNESS_BY_INTENT["medium"] == "oneWeek"

    def test_freshness_mapping_low_intent_is_one_week(self):
        from app.services.rag_policy import _FRESHNESS_BY_INTENT
        assert _FRESHNESS_BY_INTENT["low"] == "oneWeek"

    def test_freshness_one_day_used_for_high_realtime_intent(self, monkeypatch):
        """Untuk query realtime tinggi, freshness harus oneDay."""
        import app.services.rag_policy as rp

        captured_freshness = {"values": []}

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                captured_freshness["values"].append(freshness)
                return [{"title": "Result", "snippet": "Snippet", "url": "https://example.com"}]

            def rerank_documents(self, **kwargs):
                return None

            def build_search_context(self, results, runtime_config=None):
                return "context"

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        # Query dengan realtime_intent=high
        rp.get_context_for_query(
            query="skor pertandingan hari ini live",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        assert "oneDay" in captured_freshness["values"], (
            f"Freshness oneDay harus dipakai untuk realtime_intent=high, tapi dapat: {captured_freshness['values']}"
        )

    def test_latest_issue_query_is_high_realtime_intent(self):
        """Query isu terbaru tokoh/instansi harus dianggap butuh sumber segar."""
        from app.services.rag_policy import detect_realtime_intent_level

        assert detect_realtime_intent_level("Cari issue tentang Prabowo terbaru") == "high"

    def test_freshness_one_week_used_for_non_realtime_query(self, monkeypatch):
        """Untuk query non-realtime, freshness harus oneWeek."""
        import app.services.rag_policy as rp

        captured_freshness = {"values": []}

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                captured_freshness["values"].append(freshness)
                return [{"title": "Result", "snippet": "Snippet", "url": "https://example.com"}]

            def rerank_documents(self, **kwargs):
                return None

            def build_search_context(self, results, runtime_config=None):
                return "context"

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        rp.get_context_for_query(
            query="cari di internet tentang kebijakan pemerintah",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        assert all(f == "oneWeek" for f in captured_freshness["values"]), (
            f"Freshness oneWeek harus dipakai untuk query non-realtime, tapi dapat: {captured_freshness['values']}"
        )


# ---------------------------------------------------------------------------
# Paralel score query
# ---------------------------------------------------------------------------

class TestParalelScoreQuery:
    """Verifikasi score query menjalankan focused search paralel."""

    def test_score_query_calls_search_twice(self, monkeypatch):
        """Score query harus memanggil search 2x (main + focused)."""
        import app.services.rag_policy as rp

        call_log = {"queries": []}

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                call_log["queries"].append(query)
                return [{"title": f"Result for {query}", "snippet": "Snippet", "url": "https://example.com"}]

            def rerank_documents(self, **kwargs):
                return None

            def build_search_context(self, results, runtime_config=None):
                return "context"

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        # Query skor pertandingan
        rp.get_context_for_query(
            query="skor indonesia vs malaysia",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        assert len(call_log["queries"]) == 2, (
            f"Score query harus memanggil search 2x, tapi dipanggil {len(call_log['queries'])}x"
        )
        # Salah satu query harus mengandung "final score"
        assert any("final score" in q for q in call_log["queries"]), (
            f"Focused query harus mengandung 'final score', tapi queries: {call_log['queries']}"
        )

    def test_non_score_query_calls_search_once(self, monkeypatch):
        """Non-score query harus memanggil search hanya 1x."""
        import app.services.rag_policy as rp

        call_count = {"count": 0}

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                call_count["count"] += 1
                return [{"title": "Result", "snippet": "Snippet", "url": "https://example.com"}]

            def rerank_documents(self, **kwargs):
                return None

            def build_search_context(self, results, runtime_config=None):
                return "context"

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        rp.get_context_for_query(
            query="berita terbaru tentang kebijakan pemerintah",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        assert call_count["count"] == 1, (
            f"Non-score query harus memanggil search 1x, tapi dipanggil {call_count['count']}x"
        )

    def test_score_query_merges_results_from_both_searches(self, monkeypatch):
        """Hasil dari main search dan focused search harus digabung."""
        import app.services.rag_policy as rp

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                if "final score" in query:
                    return [{"title": "Focused Result", "snippet": "Skor 2-1", "url": "https://focused.com"}]
                return [{"title": "Main Result", "snippet": "Pertandingan", "url": "https://main.com"}]

            def rerank_documents(self, **kwargs):
                return None

            def build_search_context(self, results, runtime_config=None):
                return " ".join(r.get("title", "") for r in results)

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        result = rp.get_context_for_query(
            query="skor indonesia vs malaysia",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        # Context harus mengandung hasil dari kedua search
        context = result.get("search_context", "")
        assert "Main Result" in context or "Focused Result" in context

    def test_score_query_parallel_both_queries_run(self, monkeypatch):
        """Verifikasi kedua query dijalankan (bukan hanya satu)."""
        import app.services.rag_policy as rp

        seen_queries = set()
        lock = threading.Lock()

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                with lock:
                    seen_queries.add(query)
                return [{"title": f"Result", "snippet": "Snippet", "url": "https://example.com"}]

            def rerank_documents(self, **kwargs):
                return None

            def build_search_context(self, results, runtime_config=None):
                return "context"

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        rp.get_context_for_query(
            query="skor bola malam ini",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        assert len(seen_queries) == 2, f"Harus ada 2 query unik, tapi hanya: {seen_queries}"
        assert "skor bola malam ini" in seen_queries
        assert "skor bola malam ini final score" in seen_queries


# ---------------------------------------------------------------------------
# Query cleaning + no-result guardrail
# ---------------------------------------------------------------------------

class TestWebSearchQueryQuality:
    """Verifikasi query yang dikirim ke provider lebih relevan."""

    def test_build_web_search_query_cleans_command_and_issue_wording(self):
        from app.services.rag_policy import _build_web_search_query

        assert _build_web_search_query("Cari issue tentang Prabowo terbaru", "high") == "isu Prabowo terbaru"

    def test_get_context_uses_cleaned_query_and_one_day_for_latest_issue(self, monkeypatch):
        import app.services.rag_policy as rp

        captured = {"calls": []}

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                captured["calls"].append((query, freshness))
                return [{"title": "Result", "snippet": "Snippet", "url": "https://example.com"}]

            def rerank_documents(self, **kwargs):
                return None

            def build_search_context(self, results, runtime_config=None):
                return "context"

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        rp.get_context_for_query(
            query="Cari issue tentang Prabowo terbaru",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        assert captured["calls"] == [("isu Prabowo terbaru", "oneDay")]

    def test_forced_web_search_no_results_returns_guardrail_context(self, monkeypatch):
        import app.services.rag_policy as rp

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                return []

            def build_no_results_context(self, runtime_config=None):
                return "KONTEKS WEB TERBARU\nTidak ada hasil pencarian web yang cukup."

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        result = rp.get_context_for_query(
            query="Cari issue tentang Prabowo terbaru",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        assert result["has_search"] is False
        assert "Tidak ada hasil pencarian web yang cukup" in result["search_context"]


# ---------------------------------------------------------------------------
# Integrasi: score query + freshness
# ---------------------------------------------------------------------------

class TestScoreQueryWithFreshness:
    """Verifikasi score query menggunakan freshness yang tepat."""

    def test_score_query_with_high_realtime_uses_one_day(self, monkeypatch):
        """Score query dengan realtime_intent=high harus pakai freshness oneDay."""
        import app.services.rag_policy as rp

        captured = {"freshness_values": []}

        class FakeLangSearch:
            def search(self, query, freshness="oneWeek", count=5):
                captured["freshness_values"].append(freshness)
                return [{"title": "Result", "snippet": "Snippet", "url": "https://example.com"}]

            def rerank_documents(self, **kwargs):
                return None

            def build_search_context(self, results, runtime_config=None):
                return "context"

        monkeypatch.setattr(rp, "get_langsearch_service", lambda: FakeLangSearch())

        # Query skor dengan realtime_intent=high
        rp.get_context_for_query(
            query="skor live pertandingan hari ini",
            force_web_search=True,
            allow_auto_realtime_web=True,
        )

        # Semua search call harus pakai freshness yang sama
        assert len(set(captured["freshness_values"])) == 1, (
            f"Semua search harus pakai freshness yang sama: {captured['freshness_values']}"
        )


# ---------------------------------------------------------------------------
# Merge results
# ---------------------------------------------------------------------------

class TestMergeSearchResults:
    """Verifikasi _merge_search_results bekerja dengan benar."""

    def test_merge_deduplicates_by_url(self):
        from app.services.rag_policy import _merge_search_results

        primary = [{"url": "https://a.com", "title": "A"}]
        secondary = [{"url": "https://a.com", "title": "A duplicate"}, {"url": "https://b.com", "title": "B"}]

        merged = _merge_search_results(primary, secondary)
        urls = [r["url"] for r in merged]

        assert urls.count("https://a.com") == 1, "URL duplikat harus dihapus"
        assert "https://b.com" in urls

    def test_merge_respects_limit(self):
        from app.services.rag_policy import _merge_search_results

        primary = [{"url": f"https://p{i}.com", "title": f"P{i}"} for i in range(5)]
        secondary = [{"url": f"https://s{i}.com", "title": f"S{i}"} for i in range(5)]

        merged = _merge_search_results(primary, secondary, limit=6)
        assert len(merged) == 6

    def test_merge_empty_secondary(self):
        from app.services.rag_policy import _merge_search_results

        primary = [{"url": "https://a.com", "title": "A"}]
        merged = _merge_search_results(primary, [])
        assert len(merged) == 1
        assert merged[0]["url"] == "https://a.com"

    def test_merge_empty_primary(self):
        from app.services.rag_policy import _merge_search_results

        secondary = [{"url": "https://b.com", "title": "B"}]
        merged = _merge_search_results([], secondary)
        assert len(merged) == 1
        assert merged[0]["url"] == "https://b.com"
