import base64
from typing import Any

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field

from app.api_shared import verify_token
from app.services.memo_generation import generate_memo_docx

router = APIRouter(prefix="/api/memos", tags=["Memos"])

MEMO_CONTEXT_MAX_LENGTH = 20000


class GenerateMemoRequest(BaseModel):
    memo_type: str = Field(..., min_length=1, max_length=60)
    title: str = Field(..., min_length=1, max_length=160)
    context: str = Field(..., min_length=1, max_length=MEMO_CONTEXT_MAX_LENGTH)
    configuration: dict[str, str] | None = None
    runtime_config: dict[str, Any] | None = None


@router.post("/generate-body", dependencies=[Depends(verify_token)])
def generate_memo_body(request: GenerateMemoRequest):
    try:
        draft = generate_memo_docx(
            memo_type=request.memo_type,
            title=request.title,
            context=request.context,
            configuration=request.configuration,
            runtime_config=request.runtime_config,
        )
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    searchable_text = base64.urlsafe_b64encode(
        draft.searchable_text[:2000].encode("utf-8")
    ).decode("ascii")

    return Response(
        content=draft.content,
        media_type="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        headers={
            "Content-Disposition": f'attachment; filename="{draft.filename}"',
            "X-Memo-Searchable-Text-B64": searchable_text,
            "X-Memo-Page-Size": draft.page_size,
            "X-Content-Type-Options": "nosniff",
            "Cache-Control": "no-store",
        },
    )
