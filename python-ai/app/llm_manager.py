import logging
from typing import Any, Dict, Generator, List

from app.env_utils import get_env
from app.services.llm_streaming import (
    build_enhanced_messages,
    compose_enhanced_system_prompt,
    extract_web_sources,
    get_chat_models_fallback as _shared_get_chat_models_fallback,
    get_default_system_prompt_fallback as _shared_get_default_system_prompt_fallback,
    is_context_too_large as _shared_is_context_too_large,
    is_rate_limit as _shared_is_rate_limit,
    stream_with_cascade as _shared_stream_with_cascade,
)

logger = logging.getLogger(__name__)

try:
    from app.config_loader import (
        DEFAULT_PROMPTS,
        get_chat_models,
        get_system_prompt,
        get_assertive_instruction,
    )
    CONFIG_AVAILABLE = True
except ImportError:
    CONFIG_AVAILABLE = False
    # Keep this inline fallback text synchronized with
    # app.config_loader.DEFAULT_PROMPTS["system"]["default"].
    DEFAULT_PROMPTS = {
        "system": {
            "default": (
                "Anda adalah ISTA AI, asisten kerja internal untuk pegawai "
                "Istana Kepresidenan Yogyakarta.\n\n"
                "GAYA RESPONS:\n"
                "- Gunakan Bahasa Indonesia yang baku, luwes, dan nyaman dibaca.\n"
                "- Bersikap ramah, serius, fokus, dan tenang.\n"
                "- Jawab inti persoalan terlebih dahulu. Tambahkan detail hanya bila membantu.\n"
                "- Gunakan struktur seperlunya. Jangan memaksa daftar poin jika bentuk naratif lebih nyaman.\n"
                "- Hindari emoji, jargon model, pembuka repetitif, pujian berlebihan, dan nada menggurui.\n"
                "- Tetap terdengar profesional tanpa menjadi kaku atau birokratis.\n\n"
                "ATURAN KERJA:\n"
                "- Jika informasi belum cukup, katakan dengan jujur apa yang belum diketahui.\n"
                "- Jika perlu klarifikasi, ajukan pertanyaan sesingkat mungkin.\n"
                "- Jika bisa membantu, beri langkah lanjut yang konkret.\n"
                "- Jangan menyebut proses internal sistem, nama model, atau istilah teknis internal kecuali diminta."
            )
        }
    }


def get_context_for_query(*args, **kwargs):
    from app.services.rag_policy import get_context_for_query as _get_context_for_query

    return _get_context_for_query(*args, **kwargs)


def _is_context_too_large(error: Exception) -> bool:
    return _shared_is_context_too_large(error)


def _is_rate_limit(error: Exception) -> bool:
    return _shared_is_rate_limit(error)


def _get_chat_models_fallback():
    get_chat_models_fn = get_chat_models if CONFIG_AVAILABLE else (lambda: [])
    return _shared_get_chat_models_fallback(CONFIG_AVAILABLE, get_chat_models_fn)


def _get_default_system_prompt_fallback():
    env_prompt = get_env("DEFAULT_SYSTEM_PROMPT", "") or ""
    get_system_prompt_fn = get_system_prompt if CONFIG_AVAILABLE else (lambda: "")
    return _shared_get_default_system_prompt_fallback(
        CONFIG_AVAILABLE,
        get_system_prompt_fn,
        env_prompt,
        DEFAULT_PROMPTS["system"]["default"],
        logger,
    )


def _runtime_models(runtime_config: Dict[str, Any] | None) -> List[Dict]:
    if not isinstance(runtime_config, dict):
        return []

    models = runtime_config.get("chat_models")
    if not isinstance(models, list):
        return []

    safe_models: List[Dict] = []
    for model in models[:3]:
        if not isinstance(model, dict):
            continue

        provider = str(model.get("provider") or "").strip()
        model_name = str(model.get("model_name") or "").strip()
        api_key_env = str(model.get("api_key_env") or "").strip()
        if provider == "" or model_name == "" or api_key_env == "":
            continue

        safe_model = dict(model)
        safe_model["provider"] = provider
        safe_model["model_name"] = model_name
        safe_model["api_key_env"] = api_key_env
        safe_models.append(safe_model)

    return safe_models


def _runtime_system_prompt(runtime_config: Dict[str, Any] | None) -> str:
    if not isinstance(runtime_config, dict):
        return ""

    prompt = runtime_config.get("system_prompt")
    return prompt.strip() if isinstance(prompt, str) else ""


def _messages_with_runtime_prompt(
    messages: List[Dict[str, str]],
    runtime_config: Dict[str, Any] | None,
) -> List[Dict[str, str]]:
    runtime_prompt = _runtime_system_prompt(runtime_config)
    if runtime_prompt == "":
        return messages

    enhanced: List[Dict[str, str]] = []
    injected = False
    for message in messages:
        if message.get("role") == "system" and not injected:
            existing = message.get("content") or ""
            enhanced.append(
                {
                    "role": "system",
                    "content": f"{runtime_prompt}\n\n{existing}".strip(),
                }
            )
            injected = True
            continue

        enhanced.append(message)

    if not injected:
        enhanced.insert(0, {"role": "system", "content": runtime_prompt})

    return enhanced


def _stream_with_cascade(
    messages: List[Dict[str, str]],
    sources: List[Dict] | None = None,
    runtime_config: Dict[str, Any] | None = None,
) -> Generator[str, None, None]:
    model_list = _runtime_models(runtime_config) or _get_chat_models_fallback()
    yield from _shared_stream_with_cascade(
        messages,
        model_list=model_list,
        sources=sources,
        logger=logger,
    )


def get_llm_stream(
    messages: List[Dict[str, str]],
    force_web_search: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
    explicit_web_request: bool = False,
    request_id: str | None = None,
    runtime_config: Dict[str, Any] | None = None,
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

    default_system_prompt = (
        _runtime_system_prompt(runtime_config) or _get_default_system_prompt_fallback()
    )

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
                request_id=request_id,
            )
            search_context = context_data.get("search_context", "")
            web_sources = extract_web_sources(context_data)
        except Exception as e:
            logger.warning("⚠️  Web search/RAG context gagal: %s", e)

    if search_context:
        if CONFIG_AVAILABLE:
            try:
                assertive_instruction = get_assertive_instruction()
            except Exception:
                assertive_instruction = ""
        else:
            assertive_instruction = (
                "\n\nInstruksi tambahan:\n"
                "- Gunakan informasi web terbaru di atas hanya jika relevan dengan pertanyaan user.\n"
                "- Jika sumber web tersedia, utamakan data faktual dari sumber tersebut.\n"
                "- Jawab secara ringkas, jelas, dan hindari istilah teknis internal sistem."
            )
    else:
        assertive_instruction = ""

    enhanced_system = compose_enhanced_system_prompt(
        search_context=search_context,
        system_prompt_base=system_prompt_base,
        default_system_prompt=default_system_prompt,
        assertive_instruction=assertive_instruction,
    )

    enhanced_messages = build_enhanced_messages(messages, enhanced_system)

    yield from _stream_with_cascade(
        enhanced_messages,
        sources=web_sources or None,
        runtime_config=runtime_config,
    )


def get_llm_stream_with_sources(
    messages: List[Dict[str, str]],
    sources: List[Dict],
    runtime_config: Dict[str, Any] | None = None,
) -> Generator[str, None, None]:
    """
    Generator untuk RAG mode — system message sudah berisi RAG prompt.
    Sources metadata dikirim di akhir stream.
    Cascade fallback aktif termasuk untuk error 413 (konteks terlalu besar).
    """
    yield from _stream_with_cascade(
        _messages_with_runtime_prompt(messages, runtime_config),
        sources=sources,
        runtime_config=runtime_config,
    )
