import json
import logging
import requests
import warnings
from typing import Callable, Dict, Generator, List
from urllib.parse import quote

import litellm

from app.env_utils import get_env

logger = logging.getLogger(__name__)
ERROR_SENTINEL = "[ISTA_AI_ERROR]"
NATIVE_TEXT_PROVIDERS = {"gemini_native", "bedrock_converse"}
AI_UNAVAILABLE_MESSAGE = "❌ Maaf, semua layanan AI sedang tidak tersedia. Silakan coba lagi nanti."

# Suppress verbose litellm output.
litellm.set_verbose = False
litellm.suppress_debug_info = True

# litellm menggunakan httpx.AsyncClient secara internal untuk streaming.
# Ketika kita mengkonsumsi stream dalam sync generator, aclose() tidak bisa
# di-await dengan benar -> warning asyncio "Task was destroyed but pending".
# Ini hanya cosmetic warning, tidak mempengaruhi fungsionalitas.
warnings.filterwarnings(
    "ignore",
    message="coroutine 'AsyncClient.aclose' was never awaited",
    category=RuntimeWarning,
)
# Suppress asyncio logger noise dari masalah yang sama.
logging.getLogger("asyncio").setLevel(logging.CRITICAL)


def is_context_too_large(error: Exception) -> bool:
    """Detect 413 / request-body-too-large errors from any provider."""
    msg = str(error).lower()
    return any(
        k in msg
        for k in [
            "413",
            "tokens_limit_reached",
            "request body too large",
            "max size",
            "context_length_exceeded",
            "too large",
        ]
    )


def is_rate_limit(error: Exception) -> bool:
    """Detect 429 / quota-exhausted errors from any provider."""
    msg = str(error).lower()
    return any(
        k in msg
        for k in [
            "429",
            "rate limit",
            "resource_exhausted",
            "quota",
            "too many requests",
            "503",
        ]
    )


def get_chat_models_fallback(
    config_available: bool,
    get_chat_models_fn: Callable[[], List[Dict]],
) -> List[Dict]:
    """Get chat models from config (source of truth)."""
    if config_available:
        models = get_chat_models_fn()
        if models:
            return models
    return []


def get_default_system_prompt_fallback(
    config_available: bool,
    get_system_prompt_fn: Callable[[], str],
    env_prompt: str,
    default_prompt: str,
    logger: logging.Logger | None = None,
) -> str:
    """Get system prompt from config, env override, or shared default prompt."""
    if config_available:
        try:
            prompt = get_system_prompt_fn()
            if prompt:
                return prompt
        except Exception as exc:
            if logger is not None:
                logger.warning("⚠️  Gagal memuat system prompt dari config: %s", exc)

    if env_prompt:
        return env_prompt

    return default_prompt


def extract_web_sources(context_data: Dict | None) -> list[Dict]:
    web_sources: list[Dict] = []
    raw_results = context_data.get("search_results", []) if isinstance(context_data, dict) else []

    for result in raw_results:
        url = (result.get("url") or "").strip()
        title = (result.get("title") or "").strip()
        snippet = (result.get("snippet") or result.get("description") or "").strip()
        if url:
            web_sources.append({
                "type": "web",
                "title": title or url,
                "url": url,
                "snippet": snippet[:160] if snippet else "",
            })

    return web_sources


def compose_enhanced_system_prompt(
    search_context: str,
    system_prompt_base: str | None,
    default_system_prompt: str,
    assertive_instruction: str,
) -> str:
    if search_context:
        base = system_prompt_base if system_prompt_base else default_system_prompt
        return f"{search_context.rstrip()}\n\n{base}\n\n{assertive_instruction.strip()}".strip()

    return system_prompt_base if system_prompt_base else default_system_prompt


def build_enhanced_messages(messages: List[Dict[str, str]], enhanced_system: str) -> List[Dict[str, str]]:
    enhanced_messages: List[Dict[str, str]] = []
    has_system_message = any(msg["role"] == "system" for msg in messages)

    if not has_system_message:
        enhanced_messages.append({"role": "system", "content": enhanced_system})

    for msg in messages:
        if msg["role"] == "system":
            enhanced_messages.append({"role": "system", "content": enhanced_system})
        else:
            enhanced_messages.append(msg)

    return enhanced_messages


def get_model_display_label(model: Dict) -> str:
    label = model.get("label")
    if isinstance(label, str):
        label = label.strip()
    if label:
        return label

    provider = (model.get("provider") or "unknown").strip()
    model_name = (model.get("model_name") or "unknown").strip()
    return f"{provider}:{model_name}"


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

    system_text = ""
    user_messages = []
    for msg in messages:
        if msg["role"] == "system":
            system_text = msg["content"]
        else:
            role = "user" if msg["role"] == "user" else "model"
            user_messages.append({"role": role, "parts": [{"text": msg["content"]}]})

    gemini_system_token_limit = 7000
    try:
        import tiktoken as _tiktoken

        _enc = _tiktoken.get_encoding("cl100k_base")
        system_tokens = len(_enc.encode(system_text)) if system_text else 0
    except Exception:
        system_tokens = len(system_text.split()) if system_text else 0

    contents = []
    body_system_instruction = None

    if system_text and system_tokens > gemini_system_token_limit:
        logger.warning(
            "⚠️  Gemini: systemInstruction terlalu besar (%d token > %d limit) -> konteks RAG dipindahkan ke user message pertama",
            system_tokens,
            gemini_system_token_limit,
        )
        if user_messages:
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


def _build_bedrock_converse_payload(messages: List[Dict[str, str]], model: Dict) -> Dict:
    bedrock_messages = []
    system_blocks = []

    for msg in messages:
        content = (msg.get("content") or "").strip()
        if not content:
            continue

        role = msg.get("role", "user")
        if role == "system":
            system_blocks.append({"text": content})
            continue

        bedrock_role = "assistant" if role == "assistant" else "user"
        bedrock_messages.append({
            "role": bedrock_role,
            "content": [{"text": content}],
        })

    if not bedrock_messages:
        bedrock_messages.append({
            "role": "user",
            "content": [{"text": "Halo"}],
        })

    inference_config: Dict = {
        "maxTokens": int(model.get("max_tokens", 512)),
    }
    if "temperature" in model:
        inference_config["temperature"] = float(model["temperature"])
    if "top_p" in model:
        inference_config["topP"] = float(model["top_p"])
    if "stop_sequences" in model:
        inference_config["stopSequences"] = model["stop_sequences"]

    payload: Dict = {
        "messages": bedrock_messages,
        "inferenceConfig": inference_config,
    }
    if system_blocks:
        payload["system"] = system_blocks

    return payload


def _stream_bedrock_converse(model: Dict, api_key: str, messages: List[Dict[str, str]]) -> Generator[str, None, None]:
    """Call Amazon Bedrock Converse API with a bearer API key."""
    region = model.get("region") or get_env("AWS_BEDROCK_REGION", "us-east-1") or "us-east-1"
    model_id = model["model_name"]
    url = f"https://bedrock-runtime.{region}.amazonaws.com/model/{quote(model_id, safe='')}/converse"
    payload = _build_bedrock_converse_payload(messages, model)

    response = requests.post(
        url,
        json=payload,
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        timeout=int(model.get("timeout", 60)),
    )
    response.raise_for_status()

    data = response.json()
    content_blocks = (
        data.get("output", {})
        .get("message", {})
        .get("content", [])
    )
    for block in content_blocks:
        text = block.get("text", "")
        if text:
            yield text


def _run_model(model: dict, messages: List[Dict[str, str]]) -> Generator:
    """Create a streaming generator for the given model config."""
    api_key = get_env(model["api_key_env"])
    if not api_key:
        raise ValueError(f"API key env '{model['api_key_env']}' tidak ditemukan")

    if model["provider"] == "gemini_native":
        return _stream_gemini_native(model["model_name"], api_key, messages)
    if model["provider"] == "bedrock_converse":
        return _stream_bedrock_converse(model, api_key, messages)

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


def stream_with_cascade(
    messages: List[Dict[str, str]],
    model_list: List[Dict],
    sources: List[Dict] | None = None,
    logger: logging.Logger | None = None,
) -> Generator[str, None, None]:
    active_logger = logger or logging.getLogger(__name__)
    total = len(model_list)

    if total == 0:
        active_logger.error("❌ Tidak ada model chat yang tersedia.")
        yield ERROR_SENTINEL + AI_UNAVAILABLE_MESSAGE
        return

    for idx, model in enumerate(model_list, start=1):
        label = get_model_display_label(model)

        try:
            gen = _run_model(model, messages)
            active_logger.info("🤖 [%d/%d] Menggunakan: %s", idx, total, label)
            yield f"[MODEL:{label}]\n"

            if model["provider"] in NATIVE_TEXT_PROVIDERS:
                for chunk in gen:
                    yield chunk
            else:
                for chunk in gen:
                    content = chunk.choices[0].delta.content
                    if content:
                        yield content

            if sources:
                sources_json = json.dumps(sources, ensure_ascii=False, separators=(",", ":"))
                yield f"\n\n[SOURCES:{sources_json}]"

            active_logger.info("✅ Respons selesai dari: %s", label)
            return

        except Exception as exc:
            if is_context_too_large(exc):
                active_logger.warning(
                    "⚠️  [%d/%d] %s -> konteks terlalu besar (413), cascade ke model berikutnya...",
                    idx,
                    total,
                    label,
                )
            elif is_rate_limit(exc):
                active_logger.warning(
                    "⚠️  [%d/%d] %s -> rate limit (429), cascade ke model berikutnya...",
                    idx,
                    total,
                    label,
                )
            else:
                active_logger.warning(
                    "⚠️  [%d/%d] %s -> error: %s",
                    idx,
                    total,
                    label,
                    str(exc)[:120],
                )
            continue

    active_logger.error("❌ Semua %d model gagal. Tidak ada respons yang bisa dikirim.", total)
    yield ERROR_SENTINEL + AI_UNAVAILABLE_MESSAGE
