import os
import json
import requests
import litellm
from typing import List, Dict, Generator
from app.services.rag_service import get_rag_context_for_prompt

# Suppress verbose litellm output
litellm.set_verbose = False

# Model definitions (updated April 2026)
MODEL_LIST = [
    {
        "label": "GPT-5 Chat (Primary)",
        "provider": "litellm",
        "model_name": "openai/gpt-5-chat",
        "api_key_env": "GITHUB_TOKEN",
        "base_url": "https://models.inference.ai.azure.com",
    },
    {
        "label": "GPT-5 Chat (Backup Node)",
        "provider": "litellm",
        "model_name": "openai/gpt-5-chat",
        "api_key_env": "GITHUB_TOKEN_2",
        "base_url": "https://models.inference.ai.azure.com",
    },
    {
        "label": "GPT-4o (Primary)",
        "provider": "litellm",
        "model_name": "openai/gpt-4o",
        "api_key_env": "GITHUB_TOKEN",
        "base_url": "https://models.inference.ai.azure.com",
    },
    {
        "label": "GPT-4o (Backup Node)",
        "provider": "litellm",
        "model_name": "openai/gpt-4o",
        "api_key_env": "GITHUB_TOKEN_2",
        "base_url": "https://models.inference.ai.azure.com",
    },
    {
        "label": "Gemini 3 Flash",
        "provider": "gemini_native",
        "model_name": "gemini-3-flash-preview",
        "api_key_env": "GEMINI_API_KEY",
    },
    {
        "label": "Llama 3.3 70B (Groq)",
        "provider": "litellm",
        "model_name": "groq/llama-3.3-70b-versatile",
        "api_key_env": "GROQ_API_KEY",
    },
]


def _stream_gemini_native(model_name: str, api_key: str, messages: List[Dict[str, str]]) -> Generator[str, None, None]:
    """Stream response from Google AI Studio REST API directly."""
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model_name}:streamGenerateContent?alt=sse&key={api_key}"

    # Convert OpenAI-style messages to Gemini format
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


def get_llm_stream(messages: List[Dict[str, str]], force_web_search: bool = False) -> Generator[str, None, None]:
    """
    Generator that yields chunks of text from the best available LLM.
    Fallback sequence: Gemini -> Groq -> GitHub Models.
    
    Includes LangSearch integration for real-time information.
    """
    # Extract query and system prompt from messages
    # Get LAST user message (most recent query)
    query = None
    system_prompt_base = None
    
    for msg in reversed(messages):
        if msg["role"] == "user":
            query = msg["content"]
            break
        elif msg["role"] == "system" and system_prompt_base is None:
            system_prompt_base = msg["content"]
    
    # Get default system prompt from env config
    default_system_prompt = os.getenv("DEFAULT_SYSTEM_PROMPT", "Anda adalah ISTA AI, asisten virtual istana pintar. Jawablah dengan sopan dan membantu.")
    
    # Get search + RAG context if we have a query
    search_context = ""
    if query:
        try:
            search_context = get_rag_context_for_prompt(query, force_web_search=force_web_search)
        except Exception as e:
            print(f"[Warning] Search/RAG context failed: {e}")
    
    # Build enhanced system prompt with search results
    if search_context:
        assertive_instruction = (
            "\n\n🔴 INSTRUKSI WAJIB 🔴\n"
            "PRIORITASKAN informasi dari web search results di atas untuk menjawab pertanyaan user.\n"
            "Web search results berisi data REAL-TIME yang lebih akurat daripada pengetahuan internal Anda.\n"
            "Jika ada konflik antara search results dan pengetahuan internal, SELALU gunakan search results.\n"
            "Pengetahuan internal Anda mungkin outdated (terakhir update 2024)."
        )
        if system_prompt_base:
            enhanced_system = search_context + system_prompt_base + assertive_instruction
        else:
            enhanced_system = search_context + default_system_prompt + assertive_instruction
    else:
        enhanced_system = system_prompt_base if system_prompt_base else default_system_prompt
    
    # Rebuild messages with enhanced system prompt
    enhanced_messages = []
    has_system_message = any(msg["role"] == "system" for msg in messages)
    
    # If no system message exists, prepend one
    if not has_system_message:
        enhanced_messages.append({"role": "system", "content": enhanced_system})
    
    # Process existing messages
    for msg in messages:
        if msg["role"] == "system":
            enhanced_messages.append({"role": "system", "content": enhanced_system})
        else:
            enhanced_messages.append(msg)
    
    for model in MODEL_LIST:
        api_key = os.getenv(model["api_key_env"])
        if not api_key:
            continue

        try:
            if model["provider"] == "gemini_native":
                # Use direct REST API for Gemini
                gen = _stream_gemini_native(model["model_name"], api_key, enhanced_messages)
            else:
                # Use litellm for other providers
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

            # Yield model identifier
            yield f"[MODEL:{model['label']}]\n"

            if model["provider"] == "gemini_native":
                for chunk in gen:
                    yield chunk
            else:
                for chunk in gen:
                    content = chunk.choices[0].delta.content
                    if content:
                        yield content

            # Success — stop trying other models
            return

        except Exception as e:
            print(f"[Fallback] {model['label']} gagal: {e}")
            continue

    # All models failed
    yield "❌ Maaf, semua layanan AI sedang tidak tersedia. Silakan coba lagi nanti."


def get_llm_stream_with_sources(messages: List[Dict[str, str]], sources: List[Dict]) -> Generator[str, None, None]:
    """
    Generator that yields chunks of text from the best available LLM.
    Includes source metadata at the end of the stream.
    Used for RAG mode where system message already contains the RAG prompt.
    """
    # Pass messages directly - RAG mode already has the system message with context
    enhanced_messages = messages
    
    for model in MODEL_LIST:
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

            # Success — add sources at the end (single line, compact JSON)
            if sources:
                sources_json = json.dumps(sources, ensure_ascii=False, separators=(',', ':'))
                yield f"\n\n[SOURCES:{sources_json}]"
            
            return

        except Exception as e:
            print(f"[Fallback] {model['label']} gagal: {e}")
            continue

    yield "❌ Maaf, semua layanan AI sedang tidak tersedia. Silakan coba lagi nanti."
