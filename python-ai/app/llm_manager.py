import os
import json
import logging
import warnings
import requests
import litellm
from typing import List, Dict, Generator
from app.services.rag_service import get_rag_context_for_prompt, get_context_for_query

logger = logging.getLogger(__name__)

try:
    from app.config_loader import (
        get_chat_models,
        get_system_prompt,
        get_assertive_instruction,
    )
    CONFIG_AVAILABLE = True
except ImportError:
    CONFIG_AVAILABLE = False

# Suppress verbose litellm output
litellm.set_verbose = False
litellm.suppress_debug_info = True

# litellm menggunakan httpx.AsyncClient secara internal untuk streaming.
# Ketika kita mengkonsumsi stream dalam sync generator, aclose() tidak bisa
# di-await dengan benar → warning asyncio "Task was destroyed but pending".
# Ini hanya cosmetic warning, tidak mempengaruhi fungsionalitas.
warnings.filterwarnings(
    "ignore",
    message="coroutine 'AsyncClient.aclose' was never awaited",
    category=RuntimeWarning,
)
# Suppress asyncio logger noise dari masalah yang sama
logging.getLogger("asyncio").setLevel(logging.CRITICAL)


# ─── Error Classification Helpers ─────────────────────────────────────────────

def _is_context_too_large(error: Exception) -> bool:
    """Detect 413 / request-body-too-large errors from any provider."""
    msg = str(error).lower()
    return any(k in msg for k in [
        "413", "tokens_limit_reached", "request body too large",
        "max size", "context_length_exceeded", "too large",
    ])

def _is_rate_limit(error: Exception) -> bool:
    """Detect 429 / quota-exhausted errors from any provider."""
    msg = str(error).lower()
    return any(k in msg for k in [
        "429", "rate limit", "resource_exhausted", "quota",
        "too many requests", "503",
    ])


# ─── Model & Prompt Helpers ────────────────────────────────────────────────────

def _get_chat_models_fallback():
    """Get chat models from config (source of truth)."""
    if CONFIG_AVAILABLE:
        models = get_chat_models()
        if models:
            return models
    return []


def _get_default_system_prompt_fallback():
    """Get system prompt - tries config first, falls back to env."""
    if CONFIG_AVAILABLE:
        prompt = get_system_prompt()
        if prompt:
            return prompt
    return os.getenv(
        "DEFAULT_SYSTEM_PROMPT",
        "Anda adalah ISTA AI, asisten virtual istana pintar. Jawablah dengan sopan dan membantu."
    )


# ─── Gemini Native Streaming ───────────────────────────────────────────────────

def _stream_gemini_native(model_name: str, api_key: str, messages: List[Dict[str, str]]) -> Generator[str, None, None]:
    """
    Stream response from Google AI Studio REST API directly.

    Catatan: Gemini systemInstruction punya batas efektif ~8K token.
    Jika konteks RAG (system message) melebihi 7000 token, konten dipindahkan
    ke dalam user message pertama agar tidak di-drop diam-diam oleh API.
    """
    url = (
        f"https://generativelanguage.googleapis.com/v1beta/models/"
        f"{model_name}:streamGenerateContent?alt=sse&key={api_key}"
    )

    # Pisahkan system message dan user/assistant messages
    system_text = ""
    user_messages = []
    for msg in messages:
        if msg["role"] == "system":
            system_text = msg["content"]
        else:
            role = "user" if msg["role"] == "user" else "model"
            user_messages.append({"role": role, "parts": [{"text": msg["content"]}]})

    # Cek apakah system message aman untuk systemInstruction (<= 7000 token)
    GEMINI_SYSTEM_TOKEN_LIMIT = 7000
    try:
        import tiktoken as _tiktoken
        _enc = _tiktoken.get_encoding("cl100k_base")
        system_tokens = len(_enc.encode(system_text)) if system_text else 0
    except Exception:
        system_tokens = len(system_text.split()) if system_text else 0

    contents = []
    body_system_instruction = None

    if system_text and system_tokens > GEMINI_SYSTEM_TOKEN_LIMIT:
        # Konteks RAG terlalu besar untuk systemInstruction → injeksi ke user message pertama
        logger.warning(
            "⚠️  Gemini: systemInstruction terlalu besar (%d token > %d limit) "
            "→ konteks RAG dipindahkan ke user message pertama",
            system_tokens, GEMINI_SYSTEM_TOKEN_LIMIT,
        )
        # Semua konten (termasuk teks sistem) masuk ke contents[]
        if user_messages:
            # Sisipkan instruksi sistem sebagai prefix pesan user pertama
            first_user = user_messages[0]
            first_user["parts"] = [{"text": system_text + "\n\n---\n\n" + first_user["parts"][0]["text"]}]
            contents = user_messages
        else:
            contents = [{"role": "user", "parts": [{"text": system_text}]}]
    else:
        contents = user_messages
        if system_text:
            body_system_instruction = {"parts": [{"text": system_text}]}

    body = {"contents": contents}
    if body_system_instruction:
        body["systemInstruction"] = body_system_instruction

    response = requests.post(url, json=body, stream=True, timeout=30)
    response.raise_for_status()

    for line in response.iter_lines():
        if line:
            decoded = line.decode("utf-8")
            if decoded.startswith("data: "):
                try:
                    data = json.loads(decoded[6:])
                    text = (
                        data.get("candidates", [{}])[0]
                        .get("content", {})
                        .get("parts", [{}])[0]
                        .get("text", "")
                    )
                    if text:
                        yield text
                except (json.JSONDecodeError, IndexError, KeyError):
                    continue


# ─── Core LLM Streaming with Cascade Fallback ─────────────────────────────────

def _run_model(model: dict, messages: List[Dict[str, str]]) -> Generator:
    """Create a streaming generator for the given model config."""
    api_key = os.getenv(model["api_key_env"])
    if not api_key:
        raise ValueError(f"API key env '{model['api_key_env']}' tidak ditemukan")

    if model["provider"] == "gemini_native":
        return _stream_gemini_native(model["model_name"], api_key, messages)

    kwargs = {
        "model": model["model_name"],
        "messages": messages,
        "api_key": api_key,
        "stream": True,
        "timeout": 30,
        "num_retries": 0,
    }
    if "base_url" in model:
        kwargs["api_base"] = model["base_url"]
    return litellm.completion(**kwargs)


def _stream_with_cascade(
    messages: List[Dict[str, str]],
    sources: List[Dict] = None,
) -> Generator[str, None, None]:
    """
    Core cascade engine: iterate model list, yield tokens, handle fallback.

    Cascade rules:
    - 413 / context too large  → skip to next model (larger context window)
    - 429 / rate limit         → skip to next model
    - Other errors             → skip to next model
    All errors are logged clearly so the terminal is readable.
    """
    model_list = _get_chat_models_fallback()
    total = len(model_list)

    for idx, model in enumerate(model_list, start=1):
        label = model["label"]
        try:
            gen = _run_model(model, messages)
            logger.info("🤖 [%d/%d] Menggunakan: %s", idx, total, label)
            yield f"[MODEL:{label}]\n"

            if model["provider"] == "gemini_native":
                for chunk in gen:
                    yield chunk
            else:
                for chunk in gen:
                    content = chunk.choices[0].delta.content
                    if content:
                        yield content

            # Append sources kalau ada
            if sources:
                sources_json = json.dumps(sources, ensure_ascii=False, separators=(',', ':'))
                yield f"\n\n[SOURCES:{sources_json}]"

            logger.info("✅ Respons selesai dari: %s", label)
            return

        except Exception as e:
            if _is_context_too_large(e):
                logger.warning(
                    "⚠️  [%d/%d] %s → konteks terlalu besar (413), cascade ke model berikutnya...",
                    idx, total, label,
                )
            elif _is_rate_limit(e):
                logger.warning(
                    "⚠️  [%d/%d] %s → rate limit (429), cascade ke model berikutnya...",
                    idx, total, label,
                )
            else:
                logger.warning(
                    "⚠️  [%d/%d] %s → error: %s",
                    idx, total, label, str(e)[:120],
                )
            continue

    logger.error("❌ Semua %d model gagal. Tidak ada respons yang bisa dikirim.", total)
    yield "❌ Maaf, semua layanan AI sedang tidak tersedia. Silakan coba lagi nanti."


# ─── Public API ───────────────────────────────────────────────────────────────

def get_llm_stream(
    messages: List[Dict[str, str]],
    force_web_search: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
    explicit_web_request: bool = False,
) -> Generator[str, None, None]:
    """
    Generator yang yield token dari LLM terbaik yang tersedia.
    Fallback otomatis jika model gagal (rate limit, context terlalu besar, dll).
    Termasuk integrasi LangSearch untuk web search.
    """
    query = None
    system_prompt_base = None

    for msg in reversed(messages):
        if msg["role"] == "user":
            query = msg["content"]
            break
        elif msg["role"] == "system" and system_prompt_base is None:
            system_prompt_base = msg["content"]

    default_system_prompt = _get_default_system_prompt_fallback()

    # Cari konteks dari web search (jika diaktifkan)
    search_context = ""
    web_sources: list = []
    if query:
        try:
            context_data = get_context_for_query(
                query,
                force_web_search=force_web_search,
                allow_auto_realtime_web=allow_auto_realtime_web,
                documents_active=documents_active,
                explicit_web_request=explicit_web_request,
            )
            search_context = context_data.get("search_context", "")

            # Ekstrak web search results sebagai sources (untuk ditampilkan sebagai link)
            raw_results = context_data.get("search_results", [])
            if raw_results:
                for r in raw_results:
                    url   = (r.get("url") or "").strip()
                    title = (r.get("title") or "").strip()
                    snippet = (r.get("snippet") or r.get("description") or "").strip()
                    if url:  # hanya sertakan jika ada URL valid
                        web_sources.append({
                            "type":     "web",
                            "title":    title or url,
                            "url":      url,
                            "snippet":  snippet[:160] if snippet else "",
                        })
        except Exception as e:
            logger.warning("⚠️  Web search/RAG context gagal: %s", e)

    # Bangun system prompt
    if search_context:
        assertive_instruction = ""
        if CONFIG_AVAILABLE:
            try:
                assertive_instruction = get_assertive_instruction()
            except Exception:
                pass
        else:
            assertive_instruction = (
                "\n\nInstruksi tambahan:\n"
                "- Gunakan informasi web terbaru di atas hanya jika relevan dengan pertanyaan user.\n"
                "- Jika sumber web tersedia, utamakan data faktual dari sumber tersebut.\n"
                "- Jawab secara ringkas, jelas, dan hindari istilah teknis internal sistem."
            )

        base = system_prompt_base if system_prompt_base else default_system_prompt
        enhanced_system = search_context + base + assertive_instruction
    else:
        enhanced_system = system_prompt_base if system_prompt_base else default_system_prompt

    # Susun pesan final
    enhanced_messages = []
    has_system_message = any(msg["role"] == "system" for msg in messages)
    if not has_system_message:
        enhanced_messages.append({"role": "system", "content": enhanced_system})
    for msg in messages:
        if msg["role"] == "system":
            enhanced_messages.append({"role": "system", "content": enhanced_system})
        else:
            enhanced_messages.append(msg)

    yield from _stream_with_cascade(enhanced_messages, sources=web_sources or None)


def get_llm_stream_with_sources(
    messages: List[Dict[str, str]],
    sources: List[Dict],
) -> Generator[str, None, None]:
    """
    Generator untuk RAG mode — system message sudah berisi RAG prompt.
    Sources metadata dikirim di akhir stream.
    Cascade fallback aktif termasuk untuk error 413 (konteks terlalu besar).
    """
    yield from _stream_with_cascade(messages, sources=sources)
