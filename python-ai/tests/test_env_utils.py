import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))


def test_normalize_env_value_strips_matching_quotes_and_whitespace():
    from app.env_utils import normalize_env_value

    assert normalize_env_value('  "quoted-value"  ') == "quoted-value"
    assert normalize_env_value("  'quoted-value'  ") == "quoted-value"


def test_get_env_int_parses_quoted_numbers(monkeypatch):
    from app.env_utils import get_env_int

    monkeypatch.setenv("TEST_TIMEOUT", ' "15" ')

    assert get_env_int("TEST_TIMEOUT", 5) == 15


def test_get_env_bool_parses_quoted_boolean_strings(monkeypatch):
    from app.env_utils import get_env_bool

    monkeypatch.setenv("TEST_FLAG", " 'true' ")

    assert get_env_bool("TEST_FLAG", False) is True


def test_llm_manager_strips_quotes_from_default_system_prompt_env(monkeypatch):
    import app.llm_manager as manager

    monkeypatch.setattr(manager, "CONFIG_AVAILABLE", False)
    monkeypatch.setenv("DEFAULT_SYSTEM_PROMPT", '  "Prompt override dari env"  ')

    assert manager._get_default_system_prompt_fallback() == "Prompt override dari env"


def test_internal_service_token_rejects_missing_or_default_secret(monkeypatch):
    from fastapi import HTTPException
    from app.api_shared import get_internal_service_token, verify_token

    monkeypatch.delenv("AI_SERVICE_TOKEN", raising=False)

    assert get_internal_service_token() is None

    try:
        verify_token("Bearer anything")
    except HTTPException as exc:
        assert exc.status_code == 503
    else:
        raise AssertionError("verify_token should fail closed when token is missing")

    monkeypatch.setenv("AI_SERVICE_TOKEN", "your_internal_api_secret")

    assert get_internal_service_token() is None

    monkeypatch.setenv("AI_SERVICE_TOKEN", "change_me_internal_api_secret")

    assert get_internal_service_token() is None

    monkeypatch.setenv("AI_SERVICE_TOKEN", "CHANGE_ME")

    assert get_internal_service_token() is None


def test_internal_service_token_accepts_configured_secret(monkeypatch):
    from app.api_shared import get_internal_service_token, verify_token

    monkeypatch.setenv("AI_SERVICE_TOKEN", ' "safe-secret" ')

    assert get_internal_service_token() == "safe-secret"
    assert verify_token("Bearer safe-secret") is None

def test_verify_token_uses_constant_time_comparison(monkeypatch):
    import app.api_shared as shared

    calls = []

    def fake_compare_digest(provided, expected):
        calls.append((provided, expected))
        return True

    monkeypatch.setenv("AI_SERVICE_TOKEN", "safe-secret")
    monkeypatch.setattr(shared.hmac, "compare_digest", fake_compare_digest)

    assert shared.verify_token("Bearer provided-secret") is None
    assert calls == [("Bearer provided-secret", "Bearer safe-secret")]
