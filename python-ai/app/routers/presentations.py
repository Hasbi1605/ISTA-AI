from typing import Any

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field

from app.api_shared import verify_token
from app.services.presentation_render import (
    render_presentation,
    template_choices,
)
from app.services.presentation_web_assets import (
    ASSET_MODES,
    DEFAULT_ASSET_MODE,
    LicensedAssetCandidate,
    LicensedWebAssetService,
    normalize_asset_mode,
    provider_choices,
)

router = APIRouter(prefix="/api/presentations", tags=["Presentations"])

PPTX_MEDIA_TYPE = (
    "application/vnd.openxmlformats-officedocument.presentationml.presentation"
)


class PresentationSlideInput(BaseModel):
    title: str = Field("", max_length=300)
    bullets: list[str] = Field(default_factory=list)
    layout: str | None = Field(None, max_length=40)


class LicensedAssetCandidateInput(BaseModel):
    provider: str = Field(..., max_length=60)
    source_url: str = Field(..., max_length=2000)
    license: str = Field(..., max_length=120)
    attribution: str = Field(..., max_length=400)
    title: str | None = Field(None, max_length=300)
    creator: str | None = Field(None, max_length=200)
    license_url: str | None = Field(None, max_length=2000)


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
    # Mode aset: default `local_assets_only` (no-internet). Mode
    # `licensed_web_assets` bersifat opsional & eksplisit; tanpa fetcher
    # jaringan tidak ada efek (fallback ke aset lokal).
    asset_mode: str | None = Field(None, max_length=40)
    licensed_assets: list[LicensedAssetCandidateInput] = Field(default_factory=list)
    # Passthrough konfigurasi tambahan dari Laravel (tidak dipakai renderer MVP).
    configuration: dict[str, Any] | None = None


@router.get("/templates", dependencies=[Depends(verify_token)])
def list_presentation_templates():
    return {
        "templates": template_choices(),
        "asset_modes": list(ASSET_MODES),
        "default_asset_mode": DEFAULT_ASSET_MODE,
        "licensed_providers": provider_choices(),
    }


@router.post("/generate", dependencies=[Depends(verify_token)])
def generate_presentation(request: GeneratePresentationRequest):
    outline = [
        {"title": slide.title, "bullets": slide.bullets, "layout": slide.layout}
        for slide in request.outline
    ]

    # Pengayaan aset web berlisensi (opsional). Default `local_assets_only`:
    # tanpa fetcher jaringan, service tidak membuka koneksi dan semua kandidat
    # fallback ke aset lokal. Renderer di bawah tetap deterministik/no-internet.
    asset_mode = normalize_asset_mode(request.asset_mode)
    asset_service = LicensedWebAssetService(mode=asset_mode)
    enriched_assets = asset_service.enrich_many(
        [
            LicensedAssetCandidate(
                provider=item.provider,
                source_url=item.source_url,
                license=item.license,
                attribution=item.attribution,
                title=item.title or "",
                creator=item.creator or "",
                license_url=item.license_url or "",
            )
            for item in request.licensed_assets
        ]
    )

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
        # Mode aset efektif + jumlah aset eksternal yang lolos validasi/cache.
        # Privacy-safe (tanpa url/isi): cukup untuk audit lintas-layanan.
        "X-Presentation-Asset-Mode": asset_mode,
        "X-Presentation-Licensed-Assets": str(len(enriched_assets)),
        "X-Content-Type-Options": "nosniff",
        "Cache-Control": "no-store",
    }

    return Response(content=render.content, media_type=PPTX_MEDIA_TYPE, headers=headers)
