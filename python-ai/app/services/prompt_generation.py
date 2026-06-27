"""Prompy Studio prompt-package generation (#263).

Menyusun paket prompt profesional siap salin-tempel untuk platform AI eksternal
(ChatGPT Images/GPT Image, Gemini/Nano Banana, Canva AI, Universal) berdasarkan ide
Bahasa Indonesia dari pengguna. ISTA AI TIDAK memanggil platform tersebut; service
ini hanya merangkai teks prompt melalui LLM lalu mengembalikan JSON terstruktur.

Privasi: jangan log isi ide, catatan, atau hasil prompt. Hanya field non-sensitif
yang boleh diolah/disimpan oleh pemanggil.
"""
from __future__ import annotations

import base64
import binascii
import json
import re
from dataclasses import dataclass, field
from typing import Any, Callable, Mapping

from app.config_loader import (
    get_prompt_studio_platforms,
    get_prompt_studio_chat_prompt,
    get_prompt_studio_prompt,
    get_prompt_studio_reference_image_prompt,
    get_prompt_studio_types,
    get_prompt_studio_vision_models,
)
from app.runtime_config import render_prompt_template, runtime_prompt

IDEA_MAX_LENGTH = 12000
CONTEXT_NOTES_MAX_LENGTH = 12000
REFERENCE_IMAGE_ANALYSIS_MAX_LENGTH = 2500
REFERENCE_IMAGE_BASE64_MAX_LENGTH = 7_100_000
REFERENCE_IMAGE_MAX_COUNT = 5
REFERENCE_IMAGE_ALLOWED_MIME_TYPES = {"image/jpeg", "image/png"}
DEFAULT_MAIN_PROMPT_MAX_LENGTH = 6000
PRESENTATION_MAIN_PROMPT_MAX_LENGTH = 24000
MAIN_PROMPT_MAX_LENGTH = DEFAULT_MAIN_PROMPT_MAX_LENGTH
NOTES_MAX_LENGTH = 4000
MAX_VARIANTS = 4
MAX_SETTINGS = 12
PROMPT_CHAT_MESSAGE_MAX_LENGTH = 8000
PROMPT_CHAT_ASSISTANT_MAX_LENGTH = 2200
PROMPT_CHAT_HISTORY_MAX_MESSAGES = 12
PROMPT_CHAT_INTENTS = {"answer", "clarify", "revise"}


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
    reference_image_analyzed: bool = False

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
            "reference_image_analyzed": self.reference_image_analyzed,
        }


@dataclass(slots=True)
class PromptChatDecision:
    intent: str
    assistant_message: str
    revision_instruction: str = ""
    model_label: str = ""

    def to_dict(self) -> dict[str, str]:
        return {
            "intent": self.intent,
            "assistant_message": self.assistant_message,
            "revision_instruction": self.revision_instruction,
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
    reference_image_analysis: str = "",
    current_package: Mapping[str, Any] | None = None,
    revision_instruction: str = "",
    runtime_config: Mapping[str, Any] | None = None,
) -> str:
    main_prompt_max_length = _main_prompt_max_length(prompt_type_profile)
    template_values = {
        "idea": idea.strip(),
        "context_notes": (context_notes or "").strip() or "-",
        "reference_image_analysis": (reference_image_analysis or "").strip() or "-",
        "current_package": _format_current_package(current_package, main_prompt_max_length),
        "revision_instruction": (revision_instruction or "").strip() or "-",
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


def build_reference_image_analysis_prompt(
    *,
    idea: str,
    prompt_type_profile: Mapping[str, Any],
    platform_profile: Mapping[str, Any],
    reference_label: str = "Gambar referensi",
    runtime_config: Mapping[str, Any] | None = None,
) -> str:
    template_values = {
        "idea": idea.strip(),
        "reference_label": reference_label.strip() or "Gambar referensi",
        "platform_label": str(platform_profile.get("label", "Generic")),
        "prompt_type_label": str(prompt_type_profile.get("label", "")),
    }
    runtime_template = runtime_prompt(runtime_config, "prompt_studio_reference_image", "body")
    return render_prompt_template(
        runtime_template,
        get_prompt_studio_reference_image_prompt(),
        **template_values,
    )


def build_prompt_studio_chat_prompt(
    *,
    user_message: str,
    idea: str,
    platform_label: str,
    prompt_type_label: str,
    active_version_label: str = "Versi aktif",
    current_package: Mapping[str, Any] | None = None,
    chat_messages: list[Mapping[str, Any]] | None = None,
    runtime_config: Mapping[str, Any] | None = None,
) -> str:
    template_values = {
        "user_message": user_message.strip(),
        "idea": (idea or "").strip() or "-",
        "platform_label": (platform_label or "").strip() or "-",
        "prompt_type_label": (prompt_type_label or "").strip() or "-",
        "active_version_label": (active_version_label or "").strip() or "Versi aktif",
        "current_package": _format_current_package(current_package),
        "chat_history": _format_prompt_chat_history(chat_messages or []),
    }
    runtime_template = runtime_prompt(runtime_config, "prompt_studio_chat", "body")
    return render_prompt_template(
        runtime_template,
        get_prompt_studio_chat_prompt(),
        **template_values,
    )


def generate_prompt_package(
    *,
    idea: str,
    platform: str,
    prompt_type: str,
    context_notes: str = "",
    reference_image: Mapping[str, Any] | None = None,
    reference_images: list[Mapping[str, Any]] | None = None,
    current_package: Mapping[str, Any] | None = None,
    revision_instruction: str = "",
    text_generator: Callable[[str], str] | None = None,
    vision_generator: Callable[[str, Mapping[str, str]], str] | None = None,
    runtime_config: Mapping[str, Any] | None = None,
) -> PromptPackage:
    clean_idea = (idea or "").strip()
    if not clean_idea:
        raise ValueError("Ide prompt wajib diisi.")
    if len(clean_idea) > IDEA_MAX_LENGTH:
        clean_idea = clean_idea[:IDEA_MAX_LENGTH]

    clean_notes = (context_notes or "").strip()[:CONTEXT_NOTES_MAX_LENGTH]

    platform_profile = resolve_platform(platform)
    type_profile = resolve_type(prompt_type)
    main_prompt_max_length = _main_prompt_max_length(type_profile)
    reference_image_analysis = analyze_reference_images(
        reference_images=reference_images,
        reference_image=reference_image,
        idea=clean_idea,
        platform_profile=platform_profile,
        prompt_type_profile=type_profile,
        vision_generator=vision_generator,
        runtime_config=runtime_config,
    )

    prompt = build_prompt_studio_prompt(
        idea=clean_idea,
        prompt_type_profile=type_profile,
        platform_profile=platform_profile,
        context_notes=clean_notes,
        reference_image_analysis=reference_image_analysis,
        current_package=current_package,
        revision_instruction=revision_instruction,
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
        main_prompt=_clean_text(payload.get("main_prompt"), main_prompt_max_length),
        variants=_clean_variants(payload.get("variants"), main_prompt_max_length),
        negative_prompt=_clean_text(payload.get("negative_prompt"), MAIN_PROMPT_MAX_LENGTH),
        recommended_settings=_clean_settings(payload.get("recommended_settings")),
        notes_id=_clean_text(payload.get("notes_id"), NOTES_MAX_LENGTH),
        model_label=model_label,
        reference_image_analyzed=reference_image_analysis != "",
    )


def generate_prompt_chat_decision(
    *,
    user_message: str,
    idea: str,
    platform_label: str,
    prompt_type_label: str,
    active_version_label: str = "Versi aktif",
    current_package: Mapping[str, Any] | None = None,
    chat_messages: list[Mapping[str, Any]] | None = None,
    text_generator: Callable[[str], str] | None = None,
    runtime_config: Mapping[str, Any] | None = None,
) -> PromptChatDecision:
    clean_message = (user_message or "").strip()
    if not clean_message:
        raise ValueError("Pesan wajib diisi.")
    if len(clean_message) > PROMPT_CHAT_MESSAGE_MAX_LENGTH:
        clean_message = clean_message[:PROMPT_CHAT_MESSAGE_MAX_LENGTH]

    prompt = build_prompt_studio_chat_prompt(
        user_message=clean_message,
        idea=idea,
        platform_label=platform_label,
        prompt_type_label=prompt_type_label,
        active_version_label=active_version_label,
        current_package=current_package,
        chat_messages=chat_messages,
        runtime_config=runtime_config,
    )

    generator = text_generator or (
        lambda body: _default_text_generator(body, runtime_config=runtime_config)
    )
    raw = generator(prompt)
    model_label = _extract_model_label(raw)
    payload = _parse_prompt_chat_json(raw)

    intent = str(payload.get("intent") or "answer").strip().lower()
    if intent not in PROMPT_CHAT_INTENTS:
        intent = "answer"

    assistant_message = _clean_text(
        payload.get("assistant_message"),
        PROMPT_CHAT_ASSISTANT_MAX_LENGTH,
    )
    revision_instruction = _clean_text(
        payload.get("revision_instruction"),
        PROMPT_CHAT_MESSAGE_MAX_LENGTH,
    )

    if intent == "revise" and revision_instruction == "":
        revision_instruction = clean_message

    if assistant_message == "":
        if intent == "clarify":
            assistant_message = "Maksudnya ingin saya cek bagian apa dari prompt ini?"
        elif intent == "revise":
            assistant_message = "Saya akan buat versi baru berdasarkan arahan itu."
        else:
            assistant_message = "Bisa. Saya bantu bahas prompt ini tanpa mengubah panel hasil dulu."

    if intent != "revise":
        revision_instruction = ""

    return PromptChatDecision(
        intent=intent,
        assistant_message=assistant_message,
        revision_instruction=revision_instruction,
        model_label=model_label,
    )


def analyze_reference_image(
    *,
    reference_image: Mapping[str, Any] | None,
    idea: str,
    platform_profile: Mapping[str, Any],
    prompt_type_profile: Mapping[str, Any],
    reference_label: str = "Gambar referensi",
    vision_generator: Callable[[str, Mapping[str, str]], str] | None = None,
    runtime_config: Mapping[str, Any] | None = None,
) -> str:
    if not reference_image:
        return ""

    normalized = _normalize_reference_image(reference_image)
    prompt = build_reference_image_analysis_prompt(
        idea=idea,
        platform_profile=platform_profile,
        prompt_type_profile=prompt_type_profile,
        reference_label=reference_label,
        runtime_config=runtime_config,
    )

    generator = vision_generator or (
        lambda body, image: _default_vision_generator(
            body,
            image,
            runtime_config=runtime_config,
        )
    )
    raw = generator(prompt, normalized)
    text = _strip_model_label(raw).strip()
    if text.startswith("[ISTA_AI_ERROR]"):
        raise ValueError("Gagal menganalisis gambar referensi.")

    clean = _clean_text(text, REFERENCE_IMAGE_ANALYSIS_MAX_LENGTH)
    if clean == "":
        raise ValueError("Gagal menganalisis gambar referensi.")

    return clean


def analyze_reference_images(
    *,
    reference_images: list[Mapping[str, Any]] | None = None,
    reference_image: Mapping[str, Any] | None = None,
    idea: str,
    platform_profile: Mapping[str, Any],
    prompt_type_profile: Mapping[str, Any],
    vision_generator: Callable[[str, Mapping[str, str]], str] | None = None,
    runtime_config: Mapping[str, Any] | None = None,
) -> str:
    images = list(reference_images or [])
    if not images and reference_image:
        images = [reference_image]

    if not images:
        return ""
    if len(images) > REFERENCE_IMAGE_MAX_COUNT:
        raise ValueError("Gambar referensi maksimal 5 file.")

    analyses: list[str] = []
    for index, image in enumerate(images):
        label = str(image.get("label") or f"Gambar {index + 1}").strip() or f"Gambar {index + 1}"
        analysis = analyze_reference_image(
            reference_image=image,
            idea=idea,
            platform_profile=platform_profile,
            prompt_type_profile=prompt_type_profile,
            reference_label=label,
            vision_generator=vision_generator,
            runtime_config=runtime_config,
        )
        analyses.append(f"{label}:\n{analysis}")

    return "\n\n".join(analyses)


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


def _default_vision_generator(
    prompt: str,
    reference_image: Mapping[str, str],
    runtime_config: Mapping[str, Any] | None = None,
) -> str:
    from app.services.llm_streaming import stream_with_cascade

    models = _prompt_studio_vision_models(runtime_config)
    data_url = f"data:{reference_image['mime_type']};base64,{reference_image['data_base64']}"
    messages = [
        {
            "role": "user",
            "content": [
                {"type": "text", "text": prompt},
                {"type": "image_url", "image_url": {"url": data_url}},
            ],
        }
    ]

    chunks: list[str] = []
    for chunk in stream_with_cascade(
        messages,  # type: ignore[arg-type]
        model_list=models,
    ):
        chunks.append(chunk)

    return "".join(chunks)


def _prompt_studio_vision_models(runtime_config: Mapping[str, Any] | None = None) -> list[dict[str, Any]]:
    if isinstance(runtime_config, Mapping):
        models = runtime_config.get("prompt_studio_vision_models")
        if isinstance(models, list):
            safe_models = []
            for model in models[:8]:
                if not isinstance(model, Mapping):
                    continue
                provider = str(model.get("provider") or "").strip()
                model_name = str(model.get("model_name") or "").strip()
                api_key_env = str(model.get("api_key_env") or "").strip()
                if provider and model_name and api_key_env:
                    safe = dict(model)
                    safe["provider"] = provider
                    safe["model_name"] = model_name
                    safe["api_key_env"] = api_key_env
                    safe_models.append(safe)
            if safe_models:
                return safe_models

    return get_prompt_studio_vision_models()


def _normalize_reference_image(reference_image: Mapping[str, Any]) -> dict[str, str]:
    mime_type = str(reference_image.get("mime_type") or "").strip().lower()
    if mime_type not in REFERENCE_IMAGE_ALLOWED_MIME_TYPES:
        raise ValueError("Format gambar referensi tidak didukung.")

    data_base64 = str(reference_image.get("data_base64") or "").strip()
    if data_base64 == "":
        raise ValueError("Data gambar referensi kosong.")
    if len(data_base64) > REFERENCE_IMAGE_BASE64_MAX_LENGTH:
        raise ValueError("Ukuran gambar referensi terlalu besar.")

    try:
        base64.b64decode(data_base64, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise ValueError("Data gambar referensi tidak valid.") from exc

    return {"mime_type": mime_type, "data_base64": data_base64}


def _main_prompt_max_length(prompt_type_profile: Mapping[str, Any] | None = None) -> int:
    key = str((prompt_type_profile or {}).get("key") or "").strip().lower()
    if key == "presentation":
        return PRESENTATION_MAIN_PROMPT_MAX_LENGTH
    return DEFAULT_MAIN_PROMPT_MAX_LENGTH


def _format_current_package(
    current_package: Mapping[str, Any] | None,
    main_prompt_max_length: int = DEFAULT_MAIN_PROMPT_MAX_LENGTH,
) -> str:
    if not isinstance(current_package, Mapping) or not current_package:
        return "-"

    normalized = {
        "main_prompt": _clean_text(current_package.get("main_prompt"), main_prompt_max_length),
        "variants": _clean_variants(current_package.get("variants"), main_prompt_max_length),
        "negative_prompt": _clean_text(current_package.get("negative_prompt"), MAIN_PROMPT_MAX_LENGTH),
        "recommended_settings": _clean_settings(current_package.get("recommended_settings")),
        "notes_id": _clean_text(current_package.get("notes_id"), NOTES_MAX_LENGTH),
    }

    try:
        return json.dumps(normalized, ensure_ascii=False, indent=2)
    except (TypeError, ValueError):
        return "-"


def _format_prompt_chat_history(messages: list[Mapping[str, Any]]) -> str:
    lines: list[str] = []
    for message in messages[-PROMPT_CHAT_HISTORY_MAX_MESSAGES:]:
        role = str(message.get("role") or "").strip().lower()
        if role not in {"user", "assistant"}:
            continue
        content = _clean_text(message.get("content"), 900)
        if not content:
            continue
        label = "User" if role == "user" else "Assistant"
        lines.append(f"{label}: {content}")

    return "\n".join(lines) if lines else "-"


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

    if not _clean_text(parsed.get("main_prompt"), 1):
        raise ValueError("Hasil paket prompt tidak memuat prompt utama.")

    return parsed


def _parse_prompt_chat_json(raw: str) -> dict[str, Any]:
    text = _strip_model_label(raw).strip()
    if not text:
        raise ValueError("AI tidak menghasilkan respons chat Prompy.")

    fenced = re.match(r"^```(?:json)?\s*(?P<body>.+?)\s*```$", text, flags=re.IGNORECASE | re.DOTALL)
    if fenced:
        text = fenced.group("body").strip()

    candidate = _extract_first_json_object(text)
    if candidate is None:
        raise ValueError("Respons chat Prompy tidak valid.")

    try:
        parsed = json.loads(candidate)
    except json.JSONDecodeError as exc:
        raise ValueError("Respons chat Prompy tidak valid.") from exc

    if not isinstance(parsed, dict):
        raise ValueError("Respons chat Prompy tidak valid.")

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


def _clean_variants(value: Any, max_length: int = DEFAULT_MAIN_PROMPT_MAX_LENGTH) -> list[str]:
    if not isinstance(value, list):
        return []
    variants: list[str] = []
    for item in value:
        clean = _clean_text(item, max_length)
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
