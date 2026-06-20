"""Prompy Studio prompt-package generation (#263).

Menyusun paket prompt profesional siap salin-tempel untuk platform AI eksternal
(GPT Image 2, Gemini/Nano Banana, Canva AI, Google Flow, Generic) berdasarkan ide
Bahasa Indonesia dari pengguna. ISTA AI TIDAK memanggil platform tersebut; service
ini hanya merangkai teks prompt melalui LLM lalu mengembalikan JSON terstruktur.

Privasi: jangan log isi ide, catatan, atau hasil prompt. Hanya field non-sensitif
yang boleh diolah/disimpan oleh pemanggil.
"""
from __future__ import annotations

import json
import re
from dataclasses import dataclass, field
from typing import Any, Callable, Mapping

from app.config_loader import (
    get_prompt_studio_platforms,
    get_prompt_studio_prompt,
    get_prompt_studio_types,
)
from app.runtime_config import render_prompt_template, runtime_prompt

IDEA_MAX_LENGTH = 4000
CONTEXT_NOTES_MAX_LENGTH = 4000
SOURCE_CONTEXT_MAX_LENGTH = 8000
MAIN_PROMPT_MAX_LENGTH = 6000
NOTES_MAX_LENGTH = 4000
MAX_VARIANTS = 4
MAX_SETTINGS = 12


@dataclass(slots=True)
class PromptPackage:
    platform: str
    platform_label: str
    prompt_type: str
    prompt_type_label: str
    main_prompt: str
    variants: list[str] = field(default_factory=list)
    negative_prompt: str = ""
    recommended_settings: dict[str, str] = field(default_factory=dict)
    notes_id: str = ""
    model_label: str = ""

    def to_dict(self) -> dict[str, Any]:
        return {
            "platform": self.platform,
            "platform_label": self.platform_label,
            "prompt_type": self.prompt_type,
            "prompt_type_label": self.prompt_type_label,
            "main_prompt": self.main_prompt,
            "variants": self.variants,
            "negative_prompt": self.negative_prompt,
            "recommended_settings": self.recommended_settings,
            "notes_id": self.notes_id,
            "model_label": self.model_label,
        }


def _profile_map(profiles: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    result: dict[str, dict[str, Any]] = {}
    for profile in profiles:
        key = str(profile.get("key", "")).strip().lower()
        if key:
            result[key] = profile
    return result


def resolve_platform(platform: str) -> dict[str, Any]:
    platforms = _profile_map(get_prompt_studio_platforms())
    key = (platform or "").strip().lower()
    if key in platforms:
        return platforms[key]
    if "generic" in platforms:
        return platforms["generic"]
    raise ValueError("Platform tidak dikenal.")


def resolve_type(prompt_type: str) -> dict[str, Any]:
    types = _profile_map(get_prompt_studio_types())
    key = (prompt_type or "").strip().lower()
    if key in types:
        return types[key]
    if "image" in types:
        return types["image"]
    raise ValueError("Jenis prompt tidak dikenal.")


def build_prompt_studio_prompt(
    *,
    idea: str,
    prompt_type_profile: Mapping[str, Any],
    platform_profile: Mapping[str, Any],
    context_notes: str = "",
    source_context: str = "",
    has_reference_image: bool = False,
    runtime_config: Mapping[str, Any] | None = None,
) -> str:
    reference_image_context = (
        "Pengguna mengunggah gambar referensi privat di ISTA AI. Anda tidak menerima file gambar ini; "
        "susun prompt agar pengguna mengunggah gambar referensi yang sama secara manual di platform target."
        if has_reference_image
        else "-"
    )
    template_values = {
        "idea": idea.strip(),
        "context_notes": (context_notes or "").strip() or "-",
        "source_context": (source_context or "").strip() or "-",
        "reference_image_context": reference_image_context,
        "platform_label": str(platform_profile.get("label", "Generic")),
        "platform_guidance": str(platform_profile.get("guidance", "")).strip() or "-",
        "prompt_type_label": str(prompt_type_profile.get("label", "")),
        "type_guidance": str(prompt_type_profile.get("guidance", "")).strip() or "-",
    }
    runtime_template = runtime_prompt(runtime_config, "prompt_studio", "body")
    return render_prompt_template(
        runtime_template,
        get_prompt_studio_prompt(),
        **template_values,
    )


def generate_prompt_package(
    *,
    idea: str,
    platform: str,
    prompt_type: str,
    context_notes: str = "",
    source_context: str = "",
    has_reference_image: bool = False,
    text_generator: Callable[[str], str] | None = None,
    runtime_config: Mapping[str, Any] | None = None,
) -> PromptPackage:
    clean_idea = (idea or "").strip()
    if not clean_idea:
        raise ValueError("Ide prompt wajib diisi.")
    if len(clean_idea) > IDEA_MAX_LENGTH:
        clean_idea = clean_idea[:IDEA_MAX_LENGTH]

    clean_notes = (context_notes or "").strip()[:CONTEXT_NOTES_MAX_LENGTH]
    clean_source_context = (source_context or "").strip()[:SOURCE_CONTEXT_MAX_LENGTH]

    platform_profile = resolve_platform(platform)
    type_profile = resolve_type(prompt_type)

    prompt = build_prompt_studio_prompt(
        idea=clean_idea,
        prompt_type_profile=type_profile,
        platform_profile=platform_profile,
        context_notes=clean_notes,
        source_context=clean_source_context,
        has_reference_image=has_reference_image,
        runtime_config=runtime_config,
    )

    generator = text_generator or (
        lambda body: _default_text_generator(body, runtime_config=runtime_config)
    )
    raw = generator(prompt)
    model_label = _extract_model_label(raw)
    payload = _parse_package_json(raw)

    return PromptPackage(
        platform=str(platform_profile.get("key", platform)),
        platform_label=str(platform_profile.get("label", "Generic")),
        prompt_type=str(type_profile.get("key", prompt_type)),
        prompt_type_label=str(type_profile.get("label", "")),
        main_prompt=_clean_text(payload.get("main_prompt"), MAIN_PROMPT_MAX_LENGTH),
        variants=_clean_variants(payload.get("variants")),
        negative_prompt=_clean_text(payload.get("negative_prompt"), MAIN_PROMPT_MAX_LENGTH),
        recommended_settings=_clean_settings(payload.get("recommended_settings")),
        notes_id=_clean_text(payload.get("notes_id"), NOTES_MAX_LENGTH),
        model_label=model_label,
    )


def _default_text_generator(prompt: str, runtime_config: Mapping[str, Any] | None = None) -> str:
    from app.llm_manager import get_llm_stream

    chunks: list[str] = []
    for chunk in get_llm_stream(
        [{"role": "user", "content": prompt}],
        allow_auto_realtime_web=False,
        runtime_config=dict(runtime_config or {}),
    ):
        chunks.append(chunk)

    return "".join(chunks)


def _extract_model_label(text: str) -> str:
    match = re.match(r"^\s*\[MODEL:(?P<label>[^\]\r\n]{1,200})\]\s*", text or "", flags=re.IGNORECASE)
    if not match:
        return ""
    return re.sub(r"\s+", " ", match.group("label")).strip()


def _strip_model_label(text: str) -> str:
    return re.sub(r"^\s*\[MODEL:[^\]\r\n]{1,200}\]\s*", "", text or "", flags=re.IGNORECASE)


def _parse_package_json(raw: str) -> dict[str, Any]:
    text = _strip_model_label(raw).strip()
    if not text:
        raise ValueError("AI tidak menghasilkan paket prompt.")

    # Hilangkan pagar kode markdown bila ada.
    fenced = re.match(r"^```(?:json)?\s*(?P<body>.+?)\s*```$", text, flags=re.IGNORECASE | re.DOTALL)
    if fenced:
        text = fenced.group("body").strip()

    candidate = _extract_first_json_object(text)
    if candidate is None:
        raise ValueError("Hasil paket prompt tidak valid.")

    try:
        parsed = json.loads(candidate)
    except json.JSONDecodeError as exc:
        raise ValueError("Hasil paket prompt tidak valid.") from exc

    if not isinstance(parsed, dict):
        raise ValueError("Hasil paket prompt tidak valid.")

    if not _clean_text(parsed.get("main_prompt"), MAIN_PROMPT_MAX_LENGTH):
        raise ValueError("Hasil paket prompt tidak memuat prompt utama.")

    return parsed


def _extract_first_json_object(text: str) -> str | None:
    start = text.find("{")
    if start == -1:
        return None

    depth = 0
    in_string = False
    escaped = False
    for index in range(start, len(text)):
        char = text[index]
        if in_string:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                in_string = False
            continue

        if char == '"':
            in_string = True
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return text[start : index + 1]

    return None


def _clean_text(value: Any, max_length: int) -> str:
    if not isinstance(value, str):
        if value is None:
            return ""
        value = str(value)
    clean = value.strip()
    return clean[:max_length]


def _clean_variants(value: Any) -> list[str]:
    if not isinstance(value, list):
        return []
    variants: list[str] = []
    for item in value:
        clean = _clean_text(item, MAIN_PROMPT_MAX_LENGTH)
        if clean:
            variants.append(clean)
        if len(variants) >= MAX_VARIANTS:
            break
    return variants


def _clean_settings(value: Any) -> dict[str, str]:
    if not isinstance(value, dict):
        return {}
    settings: dict[str, str] = {}
    for key, raw in value.items():
        clean_key = _clean_text(key, 80)
        clean_value = _clean_text(raw, 300)
        if clean_key and clean_value:
            settings[clean_key] = clean_value
        if len(settings) >= MAX_SETTINGS:
            break
    return settings
