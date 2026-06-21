from typing import Any

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from app.api_shared import verify_token
from app.config_loader import get_prompt_studio_platforms, get_prompt_studio_types
from app.services.prompt_generation import (
    CONTEXT_NOTES_MAX_LENGTH,
    IDEA_MAX_LENGTH,
    REFERENCE_IMAGE_ALLOWED_MIME_TYPES,
    REFERENCE_IMAGE_BASE64_MAX_LENGTH,
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
    runtime_config: dict[str, Any] | None = None

    def normalized_reference_image(self) -> dict[str, str] | None:
        if self.reference_image is None:
            return None

        mime_type = str(self.reference_image.get("mime_type") or "").strip().lower()
        data_base64 = str(self.reference_image.get("data_base64") or "").strip()
        if mime_type not in REFERENCE_IMAGE_ALLOWED_MIME_TYPES:
            raise ValueError("Format gambar referensi tidak didukung.")
        if data_base64 == "" or len(data_base64) > REFERENCE_IMAGE_BASE64_MAX_LENGTH:
            raise ValueError("Data gambar referensi tidak valid.")

        return {"mime_type": mime_type, "data_base64": data_base64}


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
            reference_image=request.normalized_reference_image(),
            runtime_config=request.runtime_config,
        )
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    return package.to_dict()
