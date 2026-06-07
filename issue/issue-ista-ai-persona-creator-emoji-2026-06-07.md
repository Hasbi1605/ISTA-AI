# ISTA AI — Persona Lebih Santai, Emoji Secukupnya, dan Identitas Pembuat

Tanggal: 2026-06-07
Area: `python-ai` (prompt persona)
Status: Implemented, full test dijalankan, siap deploy

## Tujuan
- Membuat persona chat umum ISTA AI lebih santai, hangat, dan interaktif (boleh emoji secukupnya, tidak berlebihan).
- Menambahkan identitas pembuat: Muhammad Hasbi Ash Shiddiqi, dengan variasi gaya jenaka yang sopan, IG `@hasbi_shdqi`, portofolio `hasbi1605.github.io`.
- Menjaga jalur formal tetap ketat: memo (naskah dinas), RAG document, dan summarization tidak diubah.

## Scope
- Diubah: `python-ai/config/ai_config.yaml` → blok `prompts.system.default` saja.
- Diubah: `python-ai/tests/test_prompt_contracts.py` → assertion persona disesuaikan.
- Tidak disentuh: `prompts.memo_generation`, `prompts.rag.document`, `prompts.summarization.*`, logika kode (`config_loader`, `chat_api`).

## Perubahan Utama
- Aturan `Hindari emoji` diganti menjadi izin emoji secukupnya dan kontekstual.
- Nada gaya respons dilonggarkan dari "serius, tenang" menjadi "santai, hangat, suportif" tetapi tetap fokus dan membantu.
- Ditambah blok IDENTITAS DAN PEMBUAT dengan aturan: wajib menyebut nama Hasbi, boleh variasi jenaka sopan, boleh sertakan IG dan portofolio.

## Verifikasi
- `pytest tests/test_prompt_contracts.py` → 14 passed.
- Full Python: `pytest` → 363 passed, 10 failed (semua pre-existing, lihat bawah).
- Full Laravel: `php artisan test` → 573 passed (2560 assertions).
- Konfirmasi nol regresi: full Python suite dijalankan ulang di `main` bersih (perubahan di-stash) dan menghasilkan 10 kegagalan yang sama persis.

## Evaluasi Test yang Gagal (Pre-existing, di luar scope tugas ini)

Seluruh 10 kegagalan sudah ada di `main` sebelum perubahan persona dan tidak disebabkan oleh perubahan ini.

### 1. `test_prompt_eval_scenarios.py::test_eval_summarize_endpoint_returns_http_exception_when_prompt_placeholder_is_missing`
- Gejala: test mengharapkan `exc.value.detail` memuat string `missing_placeholder`, tetapi implementasi mengembalikan `Gagal merender prompt: Prompt summarization kosong setelah dirender`.
- Akar masalah: di `app/routers/documents.py`, prompt single summarization dirender via `_render_prompt_or_http_exception(runtime_prompt(...), get_summarize_single_prompt(), ...)`. Saat test mem-monkeypatch `get_summarize_single_prompt` jadi `"{missing_placeholder}"`, nilai itu masuk sebagai `fallback_template` (argumen ke-2), sedangkan `template` (runtime) kosong. Di `render_prompt_template`, kandidat dengan placeholder rusak gagal `.format()` (KeyError), lalu fungsi mengembalikan string kosong sehingga `_render_prompt` melempar `RuntimeError("Prompt summarization kosong setelah dirender")`. Nama placeholder tidak ikut terbawa ke pesan error.
- Dampak fungsional: endpoint tetap gagal aman dengan HTTP 500 dan tetap memuat `Gagal merender prompt`. Yang hilang hanya nama placeholder pada detail error. Risiko rendah.
- Evaluasi: ini mismatch ekspektasi test vs implementasi, bukan bug perilaku produksi. Opsi perbaikan (terpisah): perbaiki test agar sesuai pesan aktual, atau ubah `render_prompt_template`/`_render_prompt` agar menyertakan nama placeholder yang gagal pada pesan error.

### 2-10. `test_web_search_tuning.py` (9 test)
- Test yang gagal:
  - `TestFreshnessAdaptif::test_freshness_one_day_used_for_high_realtime_intent`
  - `TestFreshnessAdaptif::test_freshness_one_week_used_for_non_realtime_query`
  - `TestParalelScoreQuery::test_score_query_calls_search_twice`
  - `TestParalelScoreQuery::test_non_score_query_calls_search_once`
  - `TestParalelScoreQuery::test_score_query_merges_results_from_both_searches`
  - `TestParalelScoreQuery::test_score_query_parallel_both_queries_run`
  - `TestWebSearchQueryQuality::test_get_context_uses_cleaned_query_and_one_day_for_latest_issue`
  - `TestWebSearchQueryQuality::test_forced_web_search_no_results_returns_guardrail_context`
  - `TestScoreQueryWithFreshness::test_score_query_with_high_realtime_uses_one_day`
- Gejala: `TypeError: FakeLangSearch.build_search_context() got an unexpected keyword argument 'runtime_config'` di `app/services/rag_policy.py:408`.
- Akar masalah: kode produksi memanggil `langsearch.build_search_context(search_results, runtime_config=runtime_config)`, tetapi kelas `FakeLangSearch` pada test tuning belum diperbarui untuk menerima `runtime_config`. Jadi fake/stub test ketinggalan dari signature produksi.
- Dampak fungsional: tidak ada dampak produksi; ini regresi pada test double, bukan pada perilaku aplikasi.
- Evaluasi: perbaikan terpisah cukup dengan menambahkan parameter `runtime_config=None` pada `FakeLangSearch.build_search_context` di test, lalu jalankan ulang. Risiko rendah, perubahan hanya pada file test.

## Tindak Lanjut (di luar tugas ini)
- Buat perbaikan terpisah untuk 10 test pre-existing di atas (mayoritas hanya penyesuaian test double dan ekspektasi pesan error).
- Tidak memblokir perubahan persona karena tidak ada keterkaitan dan tidak ada regresi baru.
