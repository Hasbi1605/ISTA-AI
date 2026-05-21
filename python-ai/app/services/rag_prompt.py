from typing import Dict, List, Tuple

from app.config_loader import get_rag_prompt
from app.runtime_config import render_prompt_template, runtime_prompt


def build_rag_prompt(
    question: str,
    chunks: List[Dict],
    include_sources: bool = True,
    web_context: str = "",
    runtime_config: Dict | None = None,
) -> Tuple[str, List[Dict]]:
    if not chunks:
        return question, []

    context_parts = []
    sources = []

    for chunk in chunks:
        filename = chunk.get("filename", "Dokumen Tidak Diketahui")
        context_parts.append(f"--- Referensi dari Dokumen: {filename} ---")
        context_parts.append(chunk.get("content", ""))
        context_parts.append("")

        if include_sources:
            sources.append({
                "filename": chunk.get("filename", "unknown"),
                "chunk_index": chunk.get("chunk_index", 0),
                "relevance_score": chunk.get("score", 0),
            })

    context_str = "\n".join(context_parts)
    web_section = ""
    if web_context.strip():
        web_section = f"""
KONTEKS WEB TERBARU:
{web_context}
"""

    rag_prompt = render_prompt_template(
        runtime_prompt(runtime_config, "rag", "document"),
        get_rag_prompt(),
        context_str=context_str or "",
        web_section=web_section or "",
        question=question or "",
    )

    return rag_prompt, sources
