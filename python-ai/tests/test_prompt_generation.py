"""Tests for Prompy Studio prompt-package generation (#263)."""
import json
import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app.services.prompt_generation import (
    build_prompt_studio_prompt,
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
        source_context="Dokumen: agenda.pdf\n- Hal. 1: Rapat internal membahas renovasi pendopo.",
        has_reference_image=True,
    )
    assert "Buat poster acara kenegaraan" in prompt
    assert "Canva AI" in prompt
    assert "Gunakan warna emas" in prompt
    assert "Rapat internal membahas renovasi pendopo" in prompt
    assert "mengunggah gambar referensi yang sama" in prompt
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
