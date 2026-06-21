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


def test_embedding_models_are_loaded_from_yaml_and_match_rag_config():
    from app import config_loader
    from app.services import rag_config

    models = config_loader.get_embedding_models()
    bedrock_models = [model for model in models if model.get("provider") == "bedrock_titan"]

    assert models, "Embedding models harus ada di ai_config.yaml"
    assert models == rag_config.EMBEDDING_MODELS
    assert all("dimensions" in model for model in models)
    assert max(int(model["dimensions"]) for model in models) <= rag_config.MAX_EMBEDDING_DIM
    assert [model["model"] for model in bedrock_models] == ["amazon.titan-embed-text-v2:0"]
    assert all(model["api_key_env"] == "AWS_BEARER_TOKEN_BEDROCK" for model in bedrock_models)


def test_chat_models_include_bedrock_fallback_without_inline_secret():
    from app import config_loader

    models = config_loader.get_chat_models()
    bedrock_models = [model for model in models if model.get("provider") == "bedrock_converse"]
    gemini_models = [model for model in models if model.get("provider") == "gemini_native"]

    assert [model["model_name"] for model in bedrock_models] == [
        "openai.gpt-oss-120b-1:0",
        "zai.glm-4.7-flash",
        "zai.glm-4.7",
        "amazon.nova-micro-v1:0",
    ]
    assert all(model["api_key_env"] == "AWS_BEARER_TOKEN_BEDROCK" for model in bedrock_models)
    assert all("api_key" not in model for model in bedrock_models)
    assert gemini_models == []


def test_chat_models_include_extra_github_fallbacks_with_two_tokens():
    from app import config_loader

    models = config_loader.get_chat_models()
    github_models = [
        (model["model_name"], model["api_key_env"])
        for model in models
        if model.get("provider") == "litellm"
        and model.get("base_url") == "https://models.github.ai/inference"
    ]

    assert github_models[:8] == [
        ("openai/gpt-4.1", "GITHUB_TOKEN"),
        ("openai/gpt-4.1", "GITHUB_TOKEN_2"),
        ("openai/gpt-4o", "GITHUB_TOKEN"),
        ("openai/gpt-4o", "GITHUB_TOKEN_2"),
        ("openai/gpt-4.1-mini", "GITHUB_TOKEN"),
        ("openai/gpt-4.1-mini", "GITHUB_TOKEN_2"),
        ("openai/gpt-4.1-nano", "GITHUB_TOKEN"),
        ("openai/gpt-4.1-nano", "GITHUB_TOKEN_2"),
    ]
    assert ("mistral-ai/mistral-medium-2505", "GITHUB_TOKEN") in github_models
    assert ("mistral-ai/mistral-medium-2505", "GITHUB_TOKEN_2") in github_models
    assert ("mistral-ai/mistral-small-2503", "GITHUB_TOKEN") in github_models
    assert ("mistral-ai/mistral-small-2503", "GITHUB_TOKEN_2") in github_models


def test_system_prompt_uses_ista_work_assistant_persona():
    from app import config_loader

    prompt = config_loader.get_system_prompt()
    assert "ISTA AI" in prompt
    assert "Istana Kepresidenan Yogyakarta" in prompt
    assert "Jawab inti persoalan terlebih dahulu" in prompt
    # Persona baru: gaya santai, emoji relevan sesekali, dan format markdown ringan
    assert "emoji yang relevan sesekali" in prompt
    assert "markdown ringan" in prompt
    # Pujian pembuat tidak boleh template (harus bervariasi, tanpa frasa baku)
    assert "kata-katamu sendiri" in prompt
    assert "berbeda-beda setiap kali" in prompt
    # Identitas pembuat wajib menyebut Hasbi
    assert "Muhammad Hasbi Ash Shiddiqi" in prompt
    # Instagram/portofolio sudah tidak dicantumkan di persona
    assert "@hasbi_shdqi" not in prompt
    assert "hasbi1605.github.io" not in prompt


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
    assert "satu-satunya bahan fakta" in context
    assert "Jangan membuat daftar rujukan" in context
    assert "🔴" not in context

    instruction = config_loader.get_assertive_instruction()
    assert "Gunakan tanggal absolut" in instruction
    assert "Bedakan fakta yang didukung sumber dari inferensi" in instruction
    assert "Jangan mengarang detail real-time" in instruction


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


def test_operational_prompts_are_loaded_from_yaml():
    from app import config_loader

    memo = config_loader.get_memo_generation_prompt()
    knowledge = config_loader.get_knowledge_internal_prompt()
    hyde = config_loader.get_hyde_query_prompt()

    assert "Tulis isi memorandum resmi" in memo
    assert "{memo_type_label}" in memo
    assert "{closing_rule}" in memo
    assert "KONTEKS PENGETAHUAN INTERNAL" in knowledge
    assert "{context_str}" in knowledge
    assert "jawaban hipotetis singkat" in hyde


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

def test_runtime_prompt_template_falls_back_when_placeholder_is_invalid():
    from app.runtime_config import render_prompt_template
    from app.services.rag_retrieval import build_rag_prompt

    rendered = render_prompt_template("Halo {missing}", "Halo {name}", name="ISTA")
    prompt, _sources = build_rag_prompt(
        question="Apa isi dokumen ini?",
        chunks=[
            {
                "filename": "memo-rapat.pdf",
                "content": "Dokumen ini membahas agenda rapat mingguan.",
                "chunk_index": 0,
                "score": 0.97,
            }
        ],
        runtime_config={"prompts": {"rag": {"document": "Prompt rusak {placeholder_tidak_ada}"}}},
    )

    assert rendered == "Halo ISTA"
    assert "KONTEKS DOKUMEN AKTIF" in prompt
    assert "Prompt rusak" not in prompt


def test_prompt_studio_profiles_and_template_are_loaded_from_yaml():
    from app import config_loader

    platforms = {p["key"] for p in config_loader.get_prompt_studio_platforms()}
    types = {t["key"] for t in config_loader.get_prompt_studio_types()}

    # Platform aktif Prompy Studio: Google Flow tidak ditampilkan karena overlap dengan Gemini.
    assert {"gpt_image_2", "gemini_nano_banana", "canva_ai", "generic"} <= platforms
    assert "google_flow" not in platforms
    labels = {p["key"]: p["label"] for p in config_loader.get_prompt_studio_platforms()}
    assert labels["generic"] == "Universal"
    # Jenis prompt yang wajib didukung.
    assert {"image", "presentation", "poster_infographic", "video_storyboard"} <= types

    template = config_loader.get_prompt_studio_prompt()
    assert "{platform_label}" in template
    assert "{prompt_type_label}" in template
    assert "{idea}" in template
    assert "{source_context}" not in template
    assert "{reference_image_analysis}" in template
    assert "main_prompt" in template

    reference_template = config_loader.get_prompt_studio_reference_image_prompt()
    assert "{platform_label}" in reference_template
    assert "{prompt_type_label}" in reference_template
    assert "{idea}" in reference_template
    assert "Orientasi/rasio" in reference_template
    assert "Tipografi/teks terlihat" in reference_template
    assert "Ganti/sesuaikan" in reference_template

    vision_models = config_loader.get_prompt_studio_vision_models()
    assert vision_models
    assert all(model.get("provider") == "litellm" for model in vision_models)


def test_memo_prompt_uses_config_loader_template():
    from app.services.memo_generation import build_memo_prompt

    prompt = build_memo_prompt(
        "memo_internal",
        "Agenda Rapat",
        "Bahas agenda rapat mingguan.",
        {
            "number": "B-1/TEST/05/2026",
            "recipient": "Kepala Unit",
            "sender": "Kepala Istana",
            "subject": "Agenda Rapat",
            "date": "21 Mei 2026",
            "content": "Bahas agenda rapat mingguan.",
        },
    )

    assert "Tulis isi memorandum resmi" in prompt
    assert "Jenis: Memo Internal" in prompt
    assert "Nomor: B-1/TEST/05/2026" in prompt
    assert "{memo_type_label}" not in prompt
