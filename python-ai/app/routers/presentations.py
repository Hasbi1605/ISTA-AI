from typing import Any

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field

from app.api_shared import verify_token
from app.services.presentation_render import (
    render_presentation,
    template_choices,
)

router = APIRouter(prefix="/api/presentations", tags=["Presentations"])

PPTX_MEDIA_TYPE = (
    "application/vnd.openxmlformats-officedocument.presentationml.presentation"
)


class PresentationSlideInput(BaseModel):
    title: str = Field("", max_length=300)
    bullets: list[str] = Field(default_factory=list)
    layout: str | None = Field(None, max_length=40)


class GeneratePresentationRequest(BaseModel):
    title: str = Field(..., min_length=1, max_length=200)
    visual_template: str = Field(..., min_length=1, max_length=60)
    subtitle: str | None = Field(None, max_length=300)
    audience: str | None = Field(None, max_length=200)
    header: str | None = Field(None, max_length=200)
    footer: str | None = Field(None, max_length=200)
    presenter: str | None = Field(None, max_length=200)
    unit: str | None = Field(None, max_length=200)
    slide_count: int | None = Field(None, ge=1, le=50)
    date: str | None = Field(None, max_length=80)
    outline: list[PresentationSlideInput] = Field(default_factory=list)
    # Passthrough konfigurasi tambahan dari Laravel (tidak dipakai renderer MVP).
    configuration: dict[str, Any] | None = None


@router.get("/templates", dependencies=[Depends(verify_token)])
def list_presentation_templates():
    return {"templates": template_choices()}


@router.post("/generate", dependencies=[Depends(verify_token)])
def generate_presentation(request: GeneratePresentationRequest):
    outline = [
        {"title": slide.title, "bullets": slide.bullets, "layout": slide.layout}
        for slide in request.outline
    ]

    try:
        render = render_presentation(
            title=request.title,
            visual_template=request.visual_template,
            outline=outline,
            subtitle=request.subtitle,
            audience=request.audience,
            header=request.header,
            footer=request.footer,
            presenter=request.presenter,
            unit=request.unit,
            slide_count=request.slide_count,
            date=request.date,
        )
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    headers = {
        "Content-Disposition": f'attachment; filename="{render.filename}"',
        "X-Presentation-Template": render.template,
        "X-Presentation-Slide-Count": str(render.slide_count),
        "X-Content-Type-Options": "nosniff",
        "Cache-Control": "no-store",
    }

    return Response(content=render.content, media_type=PPTX_MEDIA_TYPE, headers=headers)
