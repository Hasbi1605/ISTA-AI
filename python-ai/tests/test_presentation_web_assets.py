"""Tests untuk licensed web asset enrichment presentasi (#227).

Menjamin: default tetap no-internet/local, whitelist provider + kebijakan
lisensi ditegakkan, metadata wajib (source/license/attribution) divalidasi,
aset yang lolos ter-cache ke storage privat dengan metadata yang dapat
diaudit/diretrieve, kegagalan provider/jaringan/lisensi fallback ke lokal, dan
jejak audit terbentuk.
"""

import os
import socket
import sys
from datetime import datetime, timezone

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app.services.presentation_assets import ASSET_MODE
from app.services.presentation_web_assets import (
    ASSET_MODE_LICENSED,
    ASSET_MODE_LOCAL,
    DEFAULT_ASSET_MODE,
    LicensedAssetCandidate,
    LicensedWebAssetService,
    license_is_allowed,
    normalize_asset_mode,
    normalize_license,
    provider_choices,
)

PNG_BYTES = b"\x89PNG\r\n\x1a\n" + b"0" * 64


def _fixed_clock():
    return datetime(2026, 6, 20, 12, 0, 0, tzinfo=timezone.utc)


def _valid_candidate(**overrides) -> LicensedAssetCandidate:
    base = dict(
        provider="openverse",
        source_url="https://api.openverse.org/v1/images/abc.jpg",
        license="CC0",
        attribution="Foto oleh Penulis (CC0), via Openverse",
        title="Ilustrasi",
        creator="Penulis",
        license_url="https://creativecommons.org/publicdomain/zero/1.0/",
    )
    base.update(overrides)
    return LicensedAssetCandidate(**base)


# ── Default & mode ───────────────────────────────────────────────────────────
def test_default_mode_matches_local_baseline():
    assert DEFAULT_ASSET_MODE == ASSET_MODE_LOCAL == ASSET_MODE
    assert ASSET_MODE == "local_assets_only"


@pytest.mark.parametrize(
    "value,expected",
    [
        (None, ASSET_MODE_LOCAL),
        ("", ASSET_MODE_LOCAL),
        ("garbage", ASSET_MODE_LOCAL),
        ("Licensed-Web-Assets", ASSET_MODE_LICENSED),
        ("licensed web assets", ASSET_MODE_LICENSED),
        ("local_assets_only", ASSET_MODE_LOCAL),
    ],
)
def test_normalize_asset_mode(value, expected):
    assert normalize_asset_mode(value) == expected


def test_service_disabled_without_fetcher_even_in_licensed_mode():
    service = LicensedWebAssetService(mode=ASSET_MODE_LICENSED)
    assert service.enabled is False


def test_default_service_does_not_open_network(monkeypatch, tmp_path):
    """Default (mode lokal, tanpa fetcher) tidak boleh membuka socket."""

    def _blocked(*args, **kwargs):
        raise AssertionError("Enrichment default tidak boleh akses jaringan.")

    monkeypatch.setattr(socket, "socket", _blocked)
    monkeypatch.setattr(socket, "create_connection", _blocked)

    service = LicensedWebAssetService(cache_dir=tmp_path)
    assert service.enrich(_valid_candidate()) is None
    # Audit mencatat fallback, bukan crash.
    assert service.audit.entries[-1].status == "fallback"


# ── Kebijakan lisensi ────────────────────────────────────────────────────────
@pytest.mark.parametrize("lic", ["CC0", "cc-by", "CC BY-SA 4.0", "Public Domain", "PDM"])
def test_allowed_licenses(lic):
    assert license_is_allowed(lic) is True


@pytest.mark.parametrize(
    "lic", ["", None, "CC-BY-NC", "CC-BY-ND", "All Rights Reserved", "editorial", "unknown"]
)
def test_disallowed_licenses(lic):
    assert license_is_allowed(lic) is False


def test_normalize_license_aliases():
    assert normalize_license("CC0 1.0") == "cc0"
    assert normalize_license("CC-BY-4.0") == "cc-by"
    assert normalize_license("public domain") == "public-domain"


# ── Validasi ─────────────────────────────────────────────────────────────────
def _service_with_fetcher(tmp_path, content=PNG_BYTES):
    def fetcher(url):
        return content

    return LicensedWebAssetService(
        mode=ASSET_MODE_LICENSED, fetcher=fetcher, cache_dir=tmp_path, clock=_fixed_clock
    )


def test_valid_candidate_passes_validation(tmp_path):
    service = _service_with_fetcher(tmp_path)
    ok, reason = service.validate(_valid_candidate())
    assert ok is True
    assert reason == "ok"


@pytest.mark.parametrize(
    "overrides,reason",
    [
        ({"provider": "google_images"}, "provider_not_whitelisted"),
        ({"source_url": "http://api.openverse.org/x.jpg"}, "source_url_not_https"),
        ({"source_url": "https://evil.example.com/x.jpg"}, "source_host_not_trusted"),
        ({"attribution": "  "}, "attribution_missing"),
        ({"license": "CC-BY-NC"}, "license_not_allowed"),
        ({"license": "All Rights Reserved"}, "license_not_allowed"),
    ],
)
def test_invalid_candidates_rejected(tmp_path, overrides, reason):
    service = _service_with_fetcher(tmp_path)
    ok, got = service.validate(_valid_candidate(**overrides))
    assert ok is False
    assert got == reason


# ── Enrichment + cache + metadata ────────────────────────────────────────────
def test_enrich_caches_asset_and_returns_metadata(tmp_path):
    service = _service_with_fetcher(tmp_path)
    metadata = service.enrich(_valid_candidate())

    assert metadata is not None
    assert metadata.provider == "openverse"
    assert metadata.license_normalized == "cc0"
    assert metadata.attribution
    assert metadata.accessed_at == "2026-06-20T12:00:00+00:00"

    # Cache file benar-benar ditulis di storage privat (tmp).
    assert os.path.isfile(metadata.cache_path)
    assert str(tmp_path) in metadata.cache_path
    with open(metadata.cache_path, "rb") as fh:
        assert fh.read() == PNG_BYTES

    # Metadata dapat diserialisasi (retrievable/auditable).
    as_dict = metadata.to_dict()
    assert as_dict["source_url"] == _valid_candidate().source_url
    assert as_dict["cache_path"] == metadata.cache_path


def test_enrich_records_used_audit_entry(tmp_path):
    service = _service_with_fetcher(tmp_path)
    service.enrich(_valid_candidate())
    used = [e for e in service.audit.entries if e.status == "used"]
    assert len(used) == 1
    assert used[0].provider == "openverse"
    assert used[0].cache_path


def test_rejected_candidate_records_audit_and_no_cache(tmp_path):
    service = _service_with_fetcher(tmp_path)
    assert service.enrich(_valid_candidate(license="CC-BY-NC")) is None
    rejected = [e for e in service.audit.entries if e.status == "rejected"]
    assert rejected and rejected[0].reason == "license_not_allowed"
    # Tidak ada file yang ditulis untuk kandidat ditolak.
    assert not any(tmp_path.rglob("*.bin"))


def test_provider_or_network_failure_falls_back_to_local(tmp_path):
    def failing_fetcher(url):
        raise ConnectionError("network down")

    service = LicensedWebAssetService(
        mode=ASSET_MODE_LICENSED, fetcher=failing_fetcher, cache_dir=tmp_path
    )
    assert service.enrich(_valid_candidate()) is None
    fb = [e for e in service.audit.entries if e.status == "fallback"]
    assert fb and fb[0].reason == "provider_or_network_error"


def test_empty_asset_falls_back(tmp_path):
    service = _service_with_fetcher(tmp_path, content=b"")
    assert service.enrich(_valid_candidate()) is None
    assert service.audit.entries[-1].status == "fallback"


def test_oversized_asset_rejected(tmp_path):
    big = b"0" * (LicensedWebAssetService.MAX_ASSET_BYTES + 1)
    service = _service_with_fetcher(tmp_path, content=big)
    assert service.enrich(_valid_candidate()) is None
    assert service.audit.entries[-1].reason == "asset_too_large"


def test_enrich_many_returns_only_valid(tmp_path):
    service = _service_with_fetcher(tmp_path)
    results = service.enrich_many(
        [
            _valid_candidate(),
            _valid_candidate(provider="google_images"),  # rejected
            _valid_candidate(source_url="https://upload.wikimedia.org/x.jpg", provider="wikimedia_commons"),
        ]
    )
    assert len(results) == 2
    assert {r.provider for r in results} == {"openverse", "wikimedia_commons"}


def test_local_mode_never_enriches_even_with_candidates(tmp_path):
    def fetcher(url):
        return PNG_BYTES

    service = LicensedWebAssetService(
        mode=ASSET_MODE_LOCAL, fetcher=fetcher, cache_dir=tmp_path
    )
    assert service.enrich(_valid_candidate()) is None
    assert service.audit.entries[-1].reason == "mode_local_assets_only"
    assert not any(tmp_path.rglob("*.bin"))


def test_provider_choices_are_whitelisted_only():
    keys = {p["key"] for p in provider_choices()}
    assert keys == {"openverse", "wikimedia_commons"}
    assert "google" not in " ".join(keys)
