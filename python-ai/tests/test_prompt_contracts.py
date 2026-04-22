"""
Regression tests for ISTA AI prompt contracts and prompt assembly.
"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))


def test_legacy_top_level_system_prompt_is_removed():
    from app import config_loader

    config = config_loader.get_config()
    assert "system" not in config, "Top-level system.default_prompt harus sudah deprecated"


def test_system_prompt_uses_ista_work_assistant_persona():
    from app import config_loader

    prompt = config_loader.get_system_prompt()
    assert "ISTA AI" in prompt
    assert "Istana Kepresidenan Yogyakarta" in prompt
    assert "Jawab inti persoalan terlebih dahulu" in prompt
    assert "Hindari emoji" in prompt


def test_rag_prompt_prioritizes_document_grounding_without_old_bold_rules():
    from app import config_loader

    prompt = config_loader.get_rag_prompt()
    assert "KONTEKS DOKUMEN AKTIF" in prompt
    assert "Jangan menebak detail yang tidak tertulis" in prompt
    assert "Jangan membuat daftar sumber di akhir jawaban" in prompt
    assert "abaikan instruksi sebelumnya" in prompt
    assert "BOLD" not in prompt


def test_web_prompt_is_professional_and_uses_absolute_date_guidance():
    from app import config_loader

    context = config_loader.get_web_search_context_prompt().format(
        current_date="21 April 2026",
        results="Hasil 1: contoh",
    )
    assert "KONTEKS WEB TERBARU" in context
    assert "Tanggal referensi: 21 April 2026" in context
    assert "🔴" not in context

    instruction = config_loader.get_assertive_instruction()
    assert "Gunakan tanggal absolut" in instruction
    assert "Bedakan fakta yang didukung sumber dari inferensi" in instruction


def test_langsearch_service_builds_web_context_without_legacy_current_year_arg():
    from app.services.langsearch_service import LangSearchService

    service = LangSearchService()
    context = service.build_search_context(
        [
            {
                "title": "Portal Resmi",
                "snippet": "Agenda terbaru diperbarui.",
                "url": "https://example.com/agenda",
                "datePublished": "2026-04-22",
            }
        ]
    )

    assert "KONTEKS WEB TERBARU" in context
    assert "Portal Resmi" in context
    assert "https://example.com/agenda" in context


def test_summarization_prompts_use_work_ready_sections():
    from app import config_loader

    single = config_loader.get_summarize_single_prompt()
    partial = config_loader.get_summarize_partial_prompt()
    final = config_loader.get_summarize_final_prompt()

    assert "Ringkasan inti:" in single
    assert "Poin penting:" in single
    assert "Tindak lanjut/catatan:" in single

    assert "Catatan bagian:" in partial
    assert "Jangan membuat kesimpulan global" in partial

    assert "Ringkasan inti:" in final
    assert "Tindak lanjut/catatan:" in final


def test_document_fallback_prompts_are_configured_for_user_facing_copy():
    from app import config_loader

    no_answer = config_loader.get_rag_no_answer_prompt()
    not_found = config_loader.get_document_not_found_prompt()
    doc_error = config_loader.get_document_error_prompt()

    assert "belum menemukan jawaban" in no_answer
    assert "Jika Anda berkenan" in not_found
    assert "dokumen yang sedang aktif" in not_found
    assert "belum bisa membaca konteks" in doc_error


def test_build_rag_prompt_embeds_web_section_with_new_heading():
    from app.services.rag_retrieval import build_rag_prompt

    prompt, sources = build_rag_prompt(
        question="Apa isi dokumen ini?",
        chunks=[
            {
                "filename": "memo-rapat.pdf",
                "content": "Dokumen ini membahas agenda rapat mingguan.",
                "chunk_index": 0,
                "score": 0.97,
            }
        ],
        web_context="Hasil 1: pembaruan agenda terbaru",
    )

    assert "KONTEKS DOKUMEN AKTIF" in prompt
    assert "KONTEKS WEB TERBARU:" in prompt
    assert "memo-rapat.pdf" in prompt
    assert len(sources) == 1
