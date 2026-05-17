import os
import sys
from urllib.parse import quote

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))


@pytest.mark.parametrize(
    ("model", "expected_marker"),
    [
        (
            {
                "label": "GPT-4.1 (Primary)",
                "provider": "litellm",
                "model_name": "openai/gpt-4.1",
                "api_key_env": "GITHUB_TOKEN",
            },
            "[MODEL:GPT-4.1 (Primary)]\n",
        ),
        (
            {
                "provider": "litellm",
                "model_name": "openai/gpt-4.1",
                "api_key_env": "GITHUB_TOKEN",
            },
            "[MODEL:litellm:openai/gpt-4.1]\n",
        ),
        (
            {
                "label": "Amazon Nova Micro (Bedrock)",
                "provider": "bedrock_converse",
                "model_name": "amazon.nova-micro-v1:0",
                "api_key_env": "AWS_BEARER_TOKEN_BEDROCK",
            },
            "[MODEL:Amazon Nova Micro (Bedrock)]\n",
        ),
    ],
)
def test_stream_with_cascade_prefers_configured_model_label(monkeypatch, model, expected_marker):
    from app.services import llm_streaming

    def fake_run_model(_model, _messages):
        if False:
            yield ""

    monkeypatch.setattr(llm_streaming, "_run_model", fake_run_model)

    output = list(
        llm_streaming.stream_with_cascade(
            messages=[{"role": "user", "content": "Halo"}],
            model_list=[model],
        )
    )

    assert output[0] == expected_marker


def test_stream_with_cascade_emits_error_sentinel_when_no_models_available():
    from app.services import llm_streaming

    output = list(
        llm_streaming.stream_with_cascade(
            messages=[{"role": "user", "content": "Halo"}],
            model_list=[],
        )
    )

    assert output == [llm_streaming.ERROR_SENTINEL + llm_streaming.AI_UNAVAILABLE_MESSAGE]


def test_stream_with_cascade_emits_error_sentinel_when_all_models_fail(monkeypatch):
    from app.services import llm_streaming

    def fake_run_model(_model, _messages):
        raise RuntimeError("provider unavailable")

    monkeypatch.setattr(llm_streaming, "_run_model", fake_run_model)

    output = list(
        llm_streaming.stream_with_cascade(
            messages=[{"role": "user", "content": "Halo"}],
            model_list=[
                {
                    "label": "Primary",
                    "provider": "litellm",
                    "model_name": "primary-model",
                    "api_key_env": "PRIMARY_TOKEN",
                },
                {
                    "label": "Fallback",
                    "provider": "litellm",
                    "model_name": "fallback-model",
                    "api_key_env": "FALLBACK_TOKEN",
                },
            ],
        )
    )

    assert output == [llm_streaming.ERROR_SENTINEL + llm_streaming.AI_UNAVAILABLE_MESSAGE]


def test_run_model_bedrock_converse_uses_bearer_token_and_parses_text(monkeypatch):
    from app.services import llm_streaming

    captured = {}

    class FakeResponse:
        def raise_for_status(self):
            return None

        def json(self):
            return {
                "output": {
                    "message": {
                        "content": [
                            {"text": "Halo dari Bedrock"},
                        ],
                    },
                },
            }

    def fake_post(url, **kwargs):
        captured["url"] = url
        captured.update(kwargs)
        return FakeResponse()

    monkeypatch.setattr(llm_streaming, "get_env", lambda name, default=None: {
        "AWS_BEARER_TOKEN_BEDROCK": "test-bedrock-token",
        "AWS_BEDROCK_REGION": default,
    }.get(name, default))
    monkeypatch.setattr(llm_streaming.requests, "post", fake_post)

    model = {
        "provider": "bedrock_converse",
        "model_name": "amazon.nova-micro-v1:0",
        "api_key_env": "AWS_BEARER_TOKEN_BEDROCK",
        "region": "us-east-1",
        "max_tokens": 7,
        "temperature": 0.1,
        "timeout": 12,
    }
    messages = [
        {"role": "system", "content": "Jawab singkat."},
        {"role": "user", "content": "Halo"},
    ]

    output = list(llm_streaming._run_model(model, messages))

    assert output == ["Halo dari Bedrock"]
    assert captured["url"] == (
        "https://bedrock-runtime.us-east-1.amazonaws.com/model/"
        f"{quote('amazon.nova-micro-v1:0', safe='')}/converse"
    )
    assert captured["headers"]["Authorization"] == "Bearer test-bedrock-token"
    assert captured["headers"]["Content-Type"] == "application/json"
    assert captured["timeout"] == 12
    assert captured["json"] == {
        "messages": [
            {
                "role": "user",
                "content": [{"text": "Halo"}],
            },
        ],
        "system": [{"text": "Jawab singkat."}],
        "inferenceConfig": {
            "maxTokens": 7,
            "temperature": 0.1,
        },
    }
