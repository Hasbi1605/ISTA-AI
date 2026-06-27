from typing import Any

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from app.api_shared import verify_token
from app.config_loader import get_prompt_studio_platforms, get_prompt_studio_types
from app.services.prompt_generation import (
    CONTEXT_NOTES_MAX_LENGTH,
    IDEA_MAX_LENGTH,
    PROMPT_CHAT_MESSAGE_MAX_LENGTH,
    REFERENCE_IMAGE_ALLOWED_MIME_TYPES,
    REFERENCE_IMAGE_BASE64_MAX_LENGTH,
    REFERENCE_IMAGE_MAX_COUNT,
    generate_prompt_chat_decision,
    generate_prompt_package,
)

router = APIRouter(prefix="/api/prompts", tags=["Prompts"])


def _choices(profiles: list[dict[str, Any]]) -> list[dict[str, str]]:
    return [
        {"key": str(profile.get("key", "")), "label": str(profile.get("label", ""))}
        for profile in profiles
        if profile.get("key")
    ]


class GeneratePromptRequest(BaseModel):
    idea: str = Field(..., min_length=1, max_length=IDEA_MAX_LENGTH)
    platform: str = Field("generic", min_length=1, max_length=60)
    prompt_type: str = Field("image", min_length=1, max_length=60)
    context_notes: str | None = Field(None, max_length=CONTEXT_NOTES_MAX_LENGTH)
    reference_image: dict[str, Any] | None = None
    reference_images: list[dict[str, Any]] | None = None
    current_package: dict[str, Any] | None = None
    revision_instruction: str | None = Field(None, max_length=8000)
    runtime_config: dict[str, Any] | None = None

    def normalized_reference_images(self) -> list[dict[str, str]]:
        images = self.reference_images or ([] if self.reference_image is None else [self.reference_image])
        if not images:
            return []
        if len(images) > REFERENCE_IMAGE_MAX_COUNT:
            raise ValueError("Gambar referensi maksimal 5 file.")

        normalized = []
        for index, image in enumerate(images):
            mime_type = str(image.get("mime_type") or "").strip().lower()
            data_base64 = str(image.get("data_base64") or "").strip()
            if mime_type not in REFERENCE_IMAGE_ALLOWED_MIME_TYPES:
                raise ValueError("Format gambar referensi tidak didukung.")
            if data_base64 == "" or len(data_base64) > REFERENCE_IMAGE_BASE64_MAX_LENGTH:
                raise ValueError("Data gambar referensi tidak valid.")

            normalized.append({
                "label": str(image.get("label") or f"Gambar {index + 1}").strip() or f"Gambar {index + 1}",
                "mime_type": mime_type,
                "data_base64": data_base64,
            })

        return normalized


class PromptChatRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=PROMPT_CHAT_MESSAGE_MAX_LENGTH)
    idea: str = Field("", max_length=IDEA_MAX_LENGTH)
    platform_label: str = Field("Universal", max_length=120)
    prompt_type_label: str = Field("Gambar", max_length=120)
    active_version_label: str = Field("Versi aktif", max_length=80)
    current_package: dict[str, Any] | None = None
    chat_messages: list[dict[str, Any]] = Field(default_factory=list, max_length=24)
    runtime_config: dict[str, Any] | None = None


@router.get("/profiles", dependencies=[Depends(verify_token)])
def list_prompt_profiles():
    return {
        "platforms": _choices(get_prompt_studio_platforms()),
        "types": _choices(get_prompt_studio_types()),
    }


@router.post("/generate", dependencies=[Depends(verify_token)])
def generate_prompt(request: GeneratePromptRequest):
    try:
        package = generate_prompt_package(
            idea=request.idea,
            platform=request.platform,
            prompt_type=request.prompt_type,
            context_notes=request.context_notes or "",
            reference_images=request.normalized_reference_images(),
            current_package=request.current_package,
            revision_instruction=request.revision_instruction or "",
            runtime_config=request.runtime_config,
        )
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    return package.to_dict()


@router.post("/chat", dependencies=[Depends(verify_token)])
def prompt_chat(request: PromptChatRequest):
    try:
        decision = generate_prompt_chat_decision(
            user_message=request.message,
            idea=request.idea,
            platform_label=request.platform_label,
            prompt_type_label=request.prompt_type_label,
            active_version_label=request.active_version_label,
            current_package=request.current_package,
            chat_messages=request.chat_messages,
            runtime_config=request.runtime_config,
        )
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    return decision.to_dict()
