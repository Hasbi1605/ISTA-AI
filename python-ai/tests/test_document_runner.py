import os
import subprocess
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app.document_runner import (
    DEFAULT_DOCUMENT_PROCESS_SUBPROCESS_TIMEOUT,
    _parse_result_payload,
    run_document_process,
)


def test_parse_result_payload_uses_last_valid_json_line():
    payload = _parse_result_payload(
        "log line\n"
        "{\"ignored\": true}\n"
        "{\"success\": true, \"message\": \"done\"}\n"
    )

    assert payload == {"success": True, "message": "done"}


def test_run_document_process_returns_success_payload(monkeypatch):
    captured = {}

    def fake_run(*args, **kwargs):
        captured["timeout"] = kwargs.get("timeout")
        return subprocess.CompletedProcess(
            args=args[0],
            returncode=0,
            stdout="info\n{\"success\": true, \"message\": \"processed\", \"chunk_count\": 7, \"embedding_provider\": \"text-embedding\"}\n",
            stderr="",
        )

    monkeypatch.setattr("app.document_runner.subprocess.run", fake_run)

    success, message, metrics = run_document_process("/tmp/doc.pdf", "doc.pdf", "42")

    assert success is True
    assert message == "processed"
    assert metrics == {"chunk_count": 7, "embedding_provider": "text-embedding"}
    assert captured["timeout"] == DEFAULT_DOCUMENT_PROCESS_SUBPROCESS_TIMEOUT


def test_run_document_process_honors_timeout_env_override(monkeypatch):
    captured = {}

    def fake_run(*args, **kwargs):
        captured["timeout"] = kwargs.get("timeout")
        return subprocess.CompletedProcess(
            args=args[0],
            returncode=0,
            stdout="{\"success\": true, \"message\": \"processed\"}\n",
            stderr="",
        )

    monkeypatch.setenv("DOCUMENT_PROCESS_SUBPROCESS_TIMEOUT", "1200")
    monkeypatch.setattr("app.document_runner.subprocess.run", fake_run)

    success, message, metrics = run_document_process("/tmp/doc.pdf", "doc.pdf", "42")

    assert success is True
    assert message == "processed"
    assert metrics == {}
    assert captured["timeout"] == 1200


def test_run_document_process_falls_back_to_stderr_when_payload_missing(monkeypatch):
    def fake_run(*args, **kwargs):
        return subprocess.CompletedProcess(
            args=args[0],
            returncode=1,
            stdout="not-json\n",
            stderr="traceback detail",
        )

    monkeypatch.setattr("app.document_runner.subprocess.run", fake_run)

    success, message, metrics = run_document_process("/tmp/doc.pdf", "doc.pdf", "42")

    assert success is False
    assert "traceback detail" in message
    assert metrics == {}
