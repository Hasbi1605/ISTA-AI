import os
import json
import requests
import litellm
from typing import List, Dict, Generator

# Suppress verbose litellm output
litellm.set_verbose = False

# Model definitions (updated April 2026)
MODEL_LIST = [
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
    {
        "label": "GPT-4.1 Mini (GitHub)",
        "provider": "litellm",
        "model_name": "openai/gpt-4.1-mini",
        "api_key_env": "GITHUB_TOKEN",
        "base_url": "https://models.inference.ai.azure.com",
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


def get_llm_stream(messages: List[Dict[str, str]]) -> Generator[str, None, None]:
    """
    Generator that yields chunks of text from the best available LLM.
    Fallback sequence: Gemini -> Groq -> GitHub Models.
    """
    for model in MODEL_LIST:
        api_key = os.getenv(model["api_key_env"])
        if not api_key:
            continue

        try:
            if model["provider"] == "gemini_native":
                # Use direct REST API for Gemini
                gen = _stream_gemini_native(model["model_name"], api_key, messages)
            else:
                # Use litellm for other providers
                kwargs = {
                    "model": model["model_name"],
                    "messages": messages,
                    "api_key": api_key,
                    "stream": True,
                    "timeout": 30,
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
