import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app.chat_api import app as chat_app
from app.documents_api import app as documents_app


def _paths(app):
    return {route.path for route in app.routes}


def test_chat_app_only_exposes_chat_routes():
    paths = _paths(chat_app)

    assert "/api/chat" in paths
    assert "/api/health" in paths
    assert "/api/documents/process" not in paths
    assert "/api/documents/summarize" not in paths


def test_document_app_only_exposes_document_routes():
    paths = _paths(documents_app)

    assert "/api/health" in paths
    assert "/api/documents/process" in paths
    assert "/api/documents/summarize" in paths
    assert "/api/documents/extract-content" in paths
    assert "/api/documents/extract-tables" in paths
    assert "/api/documents/export" in paths
    assert "/api/memos/generate-body" in paths
    assert "/api/prompts/generate" in paths
    assert "/api/prompts/chat" in paths
    assert "/api/prompts/profiles" in paths
    assert "/api/chat" not in paths


def test_chat_app_does_not_expose_prompt_routes():
    paths = _paths(chat_app)

    assert "/api/prompts/generate" not in paths
    assert "/api/prompts/chat" not in paths
