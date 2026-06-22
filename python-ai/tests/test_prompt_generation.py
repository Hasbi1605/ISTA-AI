"""Tests for Prompy Studio prompt-package generation (#263)."""
import json
import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app.services import prompt_generation
from app.services.prompt_generation import (
    analyze_reference_image,
    analyze_reference_images,
    build_prompt_studio_chat_prompt,
    build_prompt_studio_prompt,
    generate_prompt_chat_decision,
    generate_prompt_package,
    resolve_platform,
    resolve_type,
)


def _fake_generator(payload: dict) -> "callable":
    return lambda _prompt: json.dumps(payload)


VALID_PACKAGE = {
    "main_prompt": "A professional poster of the presidential palace at sunrise, golden light.",
    "variants": ["A cinematic wide shot of the palace", "A minimalist flat-design poster"],
    "negative_prompt": "blurry, low quality, watermark",
    "recommended_settings": {"aspect_ratio": "16:9", "quality": "high"},
    "notes_id": "Tempel prompt utama ke platform, sesuaikan rasio bila perlu.",
}


def test_resolve_platform_falls_back_to_generic():
    assert resolve_platform("does-not-exist")["key"] == "generic"
    assert resolve_platform("does-not-exist")["label"] == "Universal"
    assert resolve_platform("gpt_image_2")["label"] == "GPT Image 2"


def test_resolve_type_falls_back_to_image():
    assert resolve_type("does-not-exist")["key"] == "image"
    assert resolve_type("video_storyboard")["label"] == "Video / Storyboard"


def test_build_prompt_embeds_idea_and_platform_guidance():
    prompt = build_prompt_studio_prompt(
        idea="Buat poster acara kenegaraan",
        prompt_type_profile=resolve_type("poster_infographic"),
        platform_profile=resolve_platform("canva_ai"),
        context_notes="Gunakan warna emas",
        reference_image_analysis="Palet dominan biru, layout simetris, tipografi serif elegan.",
    )
    assert "Buat poster acara kenegaraan" in prompt
    assert "Canva AI" in prompt
    assert "Gunakan warna emas" in prompt
    assert "Palet dominan biru" in prompt
    assert "mempertahankan orientasi/rasio aspek" in prompt
    assert "membedakan elemen yang harus ditiru" in prompt
    assert "JSON" in prompt


def test_generate_prompt_package_parses_json():
    package = generate_prompt_package(
        idea="Buat poster acara kenegaraan",
        platform="gpt_image_2",
        prompt_type="poster_infographic",
        text_generator=_fake_generator(VALID_PACKAGE),
    )
    assert package.platform == "gpt_image_2"
    assert package.platform_label == "GPT Image 2"
    assert package.prompt_type == "poster_infographic"
    assert package.main_prompt.startswith("A professional poster")
    assert len(package.variants) == 2
    assert package.negative_prompt == "blurry, low quality, watermark"
    assert package.recommended_settings["aspect_ratio"] == "16:9"
    assert "Tempel prompt utama" in package.notes_id


def test_generate_prompt_package_uses_reference_image_analysis():
    captured_prompt = ""

    def fake_text_generator(prompt: str) -> str:
        nonlocal captured_prompt
        captured_prompt = prompt
        return json.dumps(VALID_PACKAGE)

    package = generate_prompt_package(
        idea="Buat poster dari gambar referensi",
        platform="gpt_image_2",
        prompt_type="poster_infographic",
        reference_image={
            "mime_type": "image/png",
            "data_base64": "aW1hZ2UtYnl0ZXM=",
        },
        vision_generator=lambda _prompt, _image: (
            "Orientasi/rasio: poster vertikal 9:16. "
            "Tipografi/teks terlihat: AZALEEA Laundry dan slogan besar. "
            "Ganti/sesuaikan: nama brand menjadi ISTA Laundry."
        ),
        text_generator=fake_text_generator,
    )

    assert package.reference_image_analyzed is True
    assert "poster vertikal 9:16" in captured_prompt
    assert "AZALEEA Laundry" in captured_prompt
    assert "ISTA Laundry" in captured_prompt


def test_generate_prompt_package_uses_multiple_reference_images_with_numbered_labels():
    captured_prompt = ""
    vision_calls = []

    def fake_vision_generator(prompt: str, image: dict[str, str]) -> str:
        vision_calls.append((prompt, image))
        if "Label gambar: Gambar 2" in prompt:
            return "Gaya visual adalah pas foto formal berlatar merah."
        return "Subjek utama adalah pegawai dengan jas formal."

    def fake_text_generator(prompt: str) -> str:
        nonlocal captured_prompt
        captured_prompt = prompt
        return json.dumps(VALID_PACKAGE)

    package = generate_prompt_package(
        idea="Gunakan Gambar 1 sebagai subjek dan tiru gaya Gambar 2",
        platform="gpt_image_2",
        prompt_type="image",
        reference_images=[
            {
                "label": "Gambar 1",
                "mime_type": "image/png",
                "data_base64": "aW1hZ2UtMQ==",
            },
            {
                "label": "Gambar 2",
                "mime_type": "image/jpeg",
                "data_base64": "aW1hZ2UtMg==",
            },
        ],
        vision_generator=fake_vision_generator,
        text_generator=fake_text_generator,
    )

    assert package.reference_image_analyzed is True
    assert len(vision_calls) == 2
    assert "Label gambar: Gambar 1" in vision_calls[0][0]
    assert "Label gambar: Gambar 2" in vision_calls[1][0]
    assert "Gambar 1:" in captured_prompt
    assert "Subjek utama" in captured_prompt
    assert "Gambar 2:" in captured_prompt
    assert "pas foto formal" in captured_prompt


def test_generate_prompt_package_includes_revision_context():
    captured_prompt = ""

    def fake_text_generator(prompt: str) -> str:
        nonlocal captured_prompt
        captured_prompt = prompt
        return json.dumps(VALID_PACKAGE | {"main_prompt": "A revised prompt."})

    package = generate_prompt_package(
        idea="Buat prompt pas foto",
        platform="generic",
        prompt_type="image",
        current_package={"main_prompt": "Original prompt", "variants": []},
        revision_instruction="Pendekkan dan buat lebih formal.",
        text_generator=fake_text_generator,
    )

    assert package.main_prompt == "A revised prompt."
    assert "Original prompt" in captured_prompt
    assert "Pendekkan dan buat lebih formal." in captured_prompt


def test_default_vision_generator_sends_reference_image_to_model_cascade(monkeypatch):
    captured = {}

    def fake_stream_with_cascade(messages, model_list):
        captured["messages"] = messages
        captured["model_list"] = model_list
        yield "[MODEL: Vision Test]\n"
        yield "Dominan biru, layout tengah, ruang kosong luas."

    monkeypatch.setattr(
        "app.services.llm_streaming.stream_with_cascade",
        fake_stream_with_cascade,
    )

    output = prompt_generation._default_vision_generator(
        "Analisis gambar ini.",
        {"mime_type": "image/png", "data_base64": "aW1hZ2UtYnl0ZXM="},
        runtime_config={
            "prompt_studio_vision_models": [
                {
                    "label": "Vision Test",
                    "provider": "litellm",
                    "model_name": "openai/gpt-4.1",
                    "api_key_env": "GITHUB_TOKEN",
                    "base_url": "https://models.github.ai/inference",
                }
            ]
        },
    )

    assert "Dominan biru" in output
    assert captured["model_list"][0]["model_name"] == "openai/gpt-4.1"
    content = captured["messages"][0]["content"]
    assert content[0] == {"type": "text", "text": "Analisis gambar ini."}
    assert content[1]["type"] == "image_url"
    assert content[1]["image_url"]["url"].startswith("data:image/png;base64,")


def test_reference_image_analysis_rejects_invalid_payload():
    with pytest.raises(ValueError):
        analyze_reference_image(
            reference_image={"mime_type": "application/pdf", "data_base64": "abc"},
            idea="Ide",
            platform_profile=resolve_platform("generic"),
            prompt_type_profile=resolve_type("image"),
            vision_generator=lambda _prompt, _image: "Tidak dipakai",
        )


def test_reference_image_analysis_rejects_more_than_five_images():
    with pytest.raises(ValueError):
        analyze_reference_images(
            reference_images=[
                {"mime_type": "image/png", "data_base64": "aW1hZ2U="}
                for _ in range(6)
            ],
            idea="Ide",
            platform_profile=resolve_platform("generic"),
            prompt_type_profile=resolve_type("image"),
            vision_generator=lambda _prompt, _image: "Tidak dipakai",
        )


def test_generate_handles_model_label_and_code_fence():
    raw = "[MODEL: GPT-4.1]\n```json\n" + json.dumps(VALID_PACKAGE) + "\n```"
    package = generate_prompt_package(
        idea="Ide apapun",
        platform="generic",
        prompt_type="image",
        text_generator=lambda _p: raw,
    )
    assert package.model_label == "GPT-4.1"
    assert package.main_prompt.startswith("A professional poster")


def test_generate_extracts_json_with_surrounding_text():
    raw = "Here is your package: " + json.dumps(VALID_PACKAGE) + " Hope it helps!"
    package = generate_prompt_package(
        idea="Ide apapun",
        platform="generic",
        prompt_type="image",
        text_generator=lambda _p: raw,
    )
    assert package.main_prompt.startswith("A professional poster")


def test_build_prompt_chat_prompt_includes_context_and_guardrail():
    prompt = build_prompt_studio_chat_prompt(
        user_message="Menurutmu apa yang kurang?",
        idea="Poster 1 Muharram",
        platform_label="GPT Image 2",
        prompt_type_label="Poster / Infografis",
        active_version_label="Versi 1",
        current_package={"main_prompt": "A blue minimalist poster", "variants": []},
        chat_messages=[{"role": "assistant", "content": "Prompt awal sudah dibuat."}],
    )

    assert "Menurutmu apa yang kurang?" in prompt
    assert "Poster 1 Muharram" in prompt
    assert "apa yang kurang?" in prompt
    assert "harus answer, bukan revise" in prompt
    assert "A blue minimalist poster" in prompt


def test_prompt_chat_decision_answer_does_not_carry_revision_instruction():
    decision = generate_prompt_chat_decision(
        user_message="Menurutmu prompt ini kurang apa?",
        idea="Poster 1 Muharram",
        platform_label="GPT Image 2",
        prompt_type_label="Poster / Infografis",
        current_package={"main_prompt": "A blue minimalist poster"},
        text_generator=lambda _prompt: json.dumps({
            "intent": "answer",
            "assistant_message": "Prompt sudah cukup kuat; yang bisa ditambah adalah konteks tipografi.",
            "revision_instruction": "ubah prompt",
        }),
    )

    assert decision.intent == "answer"
    assert "cukup kuat" in decision.assistant_message
    assert decision.revision_instruction == ""


def test_prompt_chat_decision_revise_uses_model_instruction():
    decision = generate_prompt_chat_decision(
        user_message="Terapkan saran nomor 2",
        idea="Poster 1 Muharram",
        platform_label="GPT Image 2",
        prompt_type_label="Poster / Infografis",
        current_package={"main_prompt": "A blue minimalist poster"},
        chat_messages=[
            {"role": "assistant", "content": "Saran 2: kurangi ikon bintang dan perbesar white space."},
        ],
        text_generator=lambda _prompt: "[MODEL: GPT-4.1]\n" + json.dumps({
            "intent": "revise",
            "assistant_message": "Saya terapkan saran nomor 2 sebagai versi baru.",
            "revision_instruction": "Kurangi ikon bintang dan perbesar white space.",
        }),
    )

    assert decision.intent == "revise"
    assert decision.revision_instruction == "Kurangi ikon bintang dan perbesar white space."
    assert decision.model_label == "GPT-4.1"


def test_generate_requires_idea():
    with pytest.raises(ValueError):
        generate_prompt_package(
            idea="   ",
            platform="generic",
            prompt_type="image",
            text_generator=_fake_generator(VALID_PACKAGE),
        )


def test_generate_rejects_missing_main_prompt():
    with pytest.raises(ValueError):
        generate_prompt_package(
            idea="Ide",
            platform="generic",
            prompt_type="image",
            text_generator=_fake_generator({"variants": ["x"], "main_prompt": ""}),
        )


def test_generate_rejects_invalid_output():
    with pytest.raises(ValueError):
        generate_prompt_package(
            idea="Ide",
            platform="generic",
            prompt_type="image",
            text_generator=lambda _p: "tidak ada json di sini",
        )


def test_variants_and_settings_are_capped():
    payload = {
        "main_prompt": "main",
        "variants": [f"variant {i}" for i in range(10)],
        "recommended_settings": {f"k{i}": f"v{i}" for i in range(20)},
        "notes_id": "catatan",
    }
    package = generate_prompt_package(
        idea="Ide",
        platform="generic",
        prompt_type="image",
        text_generator=_fake_generator(payload),
    )
    assert len(package.variants) <= 4
    assert len(package.recommended_settings) <= 12
