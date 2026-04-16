import os
import json
import requests
import litellm
from typing import List, Dict, Generator
from app.services.rag_service import get_rag_context_for_prompt

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
    
    return os.getenv("DEFAULT_SYSTEM_PROMPT", "Anda adalah ISTA AI, asisten virtual istana pintar. Jawablah dengan sopan dan membantu.")


def _stream_gemini_native(model_name: str, api_key: str, messages: List[Dict[str, str]]) -> Generator[str, None, None]:
    """Stream response from Google AI Studio REST API directly."""
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model_name}:streamGenerateContent?alt=sse&key={api_key}"

    contents = []
    system_instruction = None
    for msg in messages:
        if msg["role"] == "system":
            system_instruction = msg["content"]
        else:
            role = "user" if msg["role"] == "user" else "model"
            contents.append({"role": role, "parts": [{"text": msg["content"]}]})

    body = {"contents": contents}
    if system_instruction:
        body["systemInstruction"] = {"parts": [{"text": system_instruction}]}

    response = requests.post(url, json=body, stream=True, timeout=30)
    response.raise_for_status()

    for line in response.iter_lines():
        if line:
            decoded = line.decode("utf-8")
            if decoded.startswith("data: "):
                try:
                    data = json.loads(decoded[6:])
                    text = data.get("candidates", [{}])[0].get("content", {}).get("parts", [{}])[0].get("text", "")
                    if text:
                        yield text
                except (json.JSONDecodeError, IndexError, KeyError):
                    continue


def get_llm_stream(
    messages: List[Dict[str, str]],
    force_web_search: bool = False,
    allow_auto_realtime_web: bool = True,
    documents_active: bool = False,
    explicit_web_request: bool = False,
) -> Generator[str, None, None]:
    """
    Generator that yields chunks of text from the best available LLM.
    Fallback sequence: GPT-5 Chat -> GPT-4o -> Gemini -> Groq.
    
    Includes policy-aware LangSearch integration.
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
    
    search_context = ""
    if query:
        try:
            search_context = get_rag_context_for_prompt(
                query,
                force_web_search=force_web_search,
                allow_auto_realtime_web=allow_auto_realtime_web,
                documents_active=documents_active,
                explicit_web_request=explicit_web_request,
            )
        except Exception as e:
            print(f"[Warning] Search/RAG context failed: {e}")
    
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
                "- Jika sumber web tersedia, utamakan data faktual dari sumber tersebut untuk bagian yang bersifat real-time.\n"
                "- Jika ada bagian 'FAKTA TERSTRUKTUR' dengan skor pengadilan, sebutkan skor tersebut secara eksplisit.\n"
                "- Jawab secara ringkas, jelas, dan hindari istilah teknis internal sistem."
            )
        
        if system_prompt_base:
            enhanced_system = search_context + system_prompt_base + assertive_instruction
        else:
            enhanced_system = search_context + default_system_prompt + assertive_instruction
    else:
        enhanced_system = system_prompt_base if system_prompt_base else default_system_prompt
    
    enhanced_messages = []
    has_system_message = any(msg["role"] == "system" for msg in messages)
    
    if not has_system_message:
        enhanced_messages.append({"role": "system", "content": enhanced_system})
    
    for msg in messages:
        if msg["role"] == "system":
            enhanced_messages.append({"role": "system", "content": enhanced_system})
        else:
            enhanced_messages.append(msg)
    
    model_list = _get_chat_models_fallback()
    
    for model in model_list:
        api_key = os.getenv(model["api_key_env"])
        if not api_key:
            continue

        try:
            if model["provider"] == "gemini_native":
                gen = _stream_gemini_native(model["model_name"], api_key, enhanced_messages)
            else:
                kwargs = {
                    "model": model["model_name"],
                    "messages": enhanced_messages,
                    "api_key": api_key,
                    "stream": True,
                    "timeout": 30,
                    "num_retries": 0,
                }
                if "base_url" in model:
                    kwargs["api_base"] = model["base_url"]
                gen = litellm.completion(**kwargs)

            yield f"[MODEL:{model['label']}]\n"

            if model["provider"] == "gemini_native":
                for chunk in gen:
                    yield chunk
            else:
                for chunk in gen:
                    content = chunk.choices[0].delta.content
                    if content:
                        yield content

            return

        except Exception as e:
            print(f"[Fallback] {model['label']} gagal: {e}")
            continue

    yield "❌ Maaf, semua layanan AI sedang tidak tersedia. Silakan coba lagi nanti."


def get_llm_stream_with_sources(messages: List[Dict[str, str]], sources: List[Dict]) -> Generator[str, None, None]:
    """
    Generator that yields chunks of text from the best available LLM.
    Includes source metadata at the end of the stream.
    Used for RAG mode where system message already contains the RAG prompt.
    """
    enhanced_messages = messages
    
    model_list = _get_chat_models_fallback()
    
    for model in model_list:
        api_key = os.getenv(model["api_key_env"])
        if not api_key:
            continue

        try:
            if model["provider"] == "gemini_native":
                gen = _stream_gemini_native(model["model_name"], api_key, enhanced_messages)
            else:
                kwargs = {
                    "model": model["model_name"],
                    "messages": enhanced_messages,
                    "api_key": api_key,
                    "stream": True,
                    "timeout": 30,
                    "num_retries": 0,
                }
                if "base_url" in model:
                    kwargs["api_base"] = model["base_url"]
                gen = litellm.completion(**kwargs)

            yield f"[MODEL:{model['label']}]\n"

            if model["provider"] == "gemini_native":
                for chunk in gen:
                    yield chunk
            else:
                for chunk in gen:
                    content = chunk.choices[0].delta.content
                    if content:
                        yield content

            if sources:
                sources_json = json.dumps(sources, ensure_ascii=False, separators=(',', ':'))
                yield f"\n\n[SOURCES:{sources_json}]"
            
            return

        except Exception as e:
            print(f"[Fallback] {model['label']} gagal: {e}")
            continue

    yield "❌ Maaf, semua layanan AI sedang tidak tersedia. Silakan coba lagi nanti."
