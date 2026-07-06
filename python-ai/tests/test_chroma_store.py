from unittest.mock import MagicMock, patch

import pytest

from app.services import chroma_store


@pytest.fixture(autouse=True)
def _clear_chroma_cache():
    chroma_store.clear_chroma_store_cache()
    yield
    chroma_store.clear_chroma_store_cache()


@patch("app.services.chroma_store.Chroma")
def test_get_chroma_store_caches_by_collection_and_embedding(mock_chroma):
    embedding_a = MagicMock(model="text-embedding-3-small")
    embedding_b = MagicMock(model="text-embedding-3-large")
    mock_chroma.side_effect = [MagicMock(name="store-a"), MagicMock(name="store-b")]

    first = chroma_store.get_chroma_store(
        "documents_collection",
        embedding_function=embedding_a,
        persist_directory="/tmp/chroma",
    )
    second = chroma_store.get_chroma_store(
        "documents_collection",
        embedding_function=embedding_a,
        persist_directory="/tmp/chroma",
    )
    third = chroma_store.get_chroma_store(
        "documents_collection",
        embedding_function=embedding_b,
        persist_directory="/tmp/chroma",
    )

    assert first is second
    assert third is not first
    assert mock_chroma.call_count == 2


@patch("app.services.chroma_store.Chroma")
def test_get_chroma_store_without_embedding(mock_chroma):
    mock_chroma.return_value = MagicMock(name="store")

    first = chroma_store.get_chroma_store("parents_collection", persist_directory="/tmp/chroma")
    second = chroma_store.get_chroma_store("parents_collection", persist_directory="/tmp/chroma")

    assert first is second
    mock_chroma.assert_called_once_with(
        collection_name="parents_collection",
        persist_directory="/tmp/chroma",
    )
