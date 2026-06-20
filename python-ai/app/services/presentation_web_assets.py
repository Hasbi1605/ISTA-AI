"""Licensed web asset enrichment for the presentation workflow (#227).

Pengayaan aset web bersifat **opsional**. Default tetap `local_assets_only`
(lihat `presentation_assets.py`) sehingga renderer presentasi aman dipakai tanpa
jaringan. Mode `licensed_web_assets` hanya aktif bila dipilih eksplisit DAN
sebuah fetcher jaringan disuntikkan; tanpa fetcher, modul ini tidak pernah
membuka koneksi dan otomatis fallback ke aset lokal.

Jaminan yang ditegakkan modul ini:

* **Provider whitelist + license policy** — hanya provider terdaftar dan lisensi
  yang diizinkan (CC0 / Public Domain / CC-BY / CC-BY-SA) yang lolos.
* **Metadata wajib** — kandidat tanpa `source_url`, `license`, atau `attribution`
  yang valid ditolak.
* **Cache privat** — aset yang lolos disalin ke storage lokal/privat sebelum
  dipakai; path cache disimpan di metadata.
* **Fallback** — kegagalan provider/jaringan/validasi lisensi selalu fallback ke
  aset lokal (mengembalikan ``None``), tidak pernah memutus generate.
* **Audit trail** — setiap percobaan pemakaian aset eksternal dicatat (provider,
  url, lisensi, atribusi, status, alasan, cache path, accessed_at).

Privasi: modul ini TIDAK pernah mencatat isi dokumen, prompt, token, atau secret.
Audit hanya berisi metadata aset publik yang sudah berlisensi.
"""

from __future__ import annotations

import hashlib
import logging
import os
import re
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable, Optional
from urllib.parse import urlparse

from app.env_utils import get_env

logger = logging.getLogger(__name__)

# ── Mode aset ──────────────────────────────────────────────────────────────
# Selaras dengan presentation_assets.ASSET_MODE (default no-internet).
ASSET_MODE_LOCAL = "local_assets_only"
ASSET_MODE_LICENSED = "licensed_web_assets"
ASSET_MODES = (ASSET_MODE_LOCAL, ASSET_MODE_LICENSED)
DEFAULT_ASSET_MODE = ASSET_MODE_LOCAL


def normalize_asset_mode(value: Optional[str]) -> str:
    """Normalisasi nilai asset_mode; nilai tak dikenal -> default lokal."""
    candidate = (value or "").strip().lower().replace("-", "_").replace(" ", "_")
    return candidate if candidate in ASSET_MODES else DEFAULT_ASSET_MODE


# ── Kebijakan lisensi ────────────────────────────────────────────────────────
# Hanya lisensi yang jelas mengizinkan reuse. Dinormalisasi (lihat
# ``normalize_license``) sebelum dicocokkan.
ALLOWED_LICENSES: frozenset[str] = frozenset(
    {
        "cc0",
        "public-domain",
        "pdm",  # Public Domain Mark
        "cc-by",
        "cc-by-sa",
    }
)

_LICENSE_ALIASES: dict[str, str] = {
    "cc0-1.0": "cc0",
    "cc0 1.0": "cc0",
    "creative-commons-zero": "cc0",
    "publicdomain": "public-domain",
    "public domain": "public-domain",
    "public-domain-mark": "pdm",
    "cc-by-4.0": "cc-by",
    "cc-by-3.0": "cc-by",
    "cc-by-2.0": "cc-by",
    "cc-by-sa-4.0": "cc-by-sa",
    "cc-by-sa-3.0": "cc-by-sa",
    "by": "cc-by",
    "by-sa": "cc-by-sa",
}

# Lisensi yang secara eksplisit dilarang walau provider whitelisted (mis. NC/ND
# atau "all rights reserved"). Hanya untuk pesan audit yang jelas.
_LICENSE_BLOCK_HINTS = ("nc", "nd", "all-rights-reserved", "rights-reserved", "editorial")


def normalize_license(value: Optional[str]) -> str:
    candidate = re.sub(r"\s+", "-", (value or "").strip().lower())
    candidate = candidate.strip("-")
    return _LICENSE_ALIASES.get(candidate, candidate)


def license_is_allowed(value: Optional[str]) -> bool:
    normalized = normalize_license(value)
    if not normalized:
        return False
    if any(hint in normalized for hint in _LICENSE_BLOCK_HINTS):
        return False
    return normalized in ALLOWED_LICENSES


# ── Provider whitelist ───────────────────────────────────────────────────────
@dataclass(frozen=True)
class LicensedProvider:
    """Provider aset berlisensi yang diizinkan.

    ``hosts`` adalah daftar host (suffix-match) sumber aset yang dipercaya.
    ``allowed_licenses`` mempersempit kebijakan global per provider.
    """

    key: str
    label: str
    hosts: tuple[str, ...]
    allowed_licenses: frozenset[str]
    attribution_required: bool = True


# Whitelist konservatif: hanya repositori aset dengan metadata lisensi jelas.
# TIDAK memuat Google Images, situs berita, media sosial, atau scraping acak
# (out of scope #227). Tidak ada foto orang/pejabat atau logo institusi pihak
# ketiga — kebijakan tersebut ditegakkan secara organisasi, bukan otomatis di
# sini, namun whitelist sengaja dibatasi ke sumber ikon/ilustrasi generik.
LICENSED_PROVIDERS: dict[str, LicensedProvider] = {
    "openverse": LicensedProvider(
        key="openverse",
        label="Openverse (CC search)",
        hosts=("openverse.org", "api.openverse.org", "openverse-api.org"),
        allowed_licenses=frozenset({"cc0", "public-domain", "pdm", "cc-by", "cc-by-sa"}),
    ),
    "wikimedia_commons": LicensedProvider(
        key="wikimedia_commons",
        label="Wikimedia Commons",
        hosts=("commons.wikimedia.org", "upload.wikimedia.org"),
        allowed_licenses=frozenset({"cc0", "public-domain", "pdm", "cc-by", "cc-by-sa"}),
    ),
}


def provider_choices() -> list[dict[str, str]]:
    """Daftar provider whitelisted (untuk kontrak/QA)."""
    return [{"key": p.key, "label": p.label} for p in LICENSED_PROVIDERS.values()]


def _host_matches(host: str, allowed: tuple[str, ...]) -> bool:
    host = host.lower()
    return any(host == h or host.endswith("." + h) for h in allowed)


# ── Model data ───────────────────────────────────────────────────────────────
@dataclass(frozen=True)
class LicensedAssetCandidate:
    """Kandidat aset eksternal beserta metadata lisensinya."""

    provider: str
    source_url: str
    license: str
    attribution: str
    title: str = ""
    creator: str = ""
    license_url: str = ""


@dataclass(frozen=True)
class LicensedAssetMetadata:
    """Metadata aset yang lolos & ter-cache (dapat diaudit/diretrieve)."""

    provider: str
    source_url: str
    license: str
    license_normalized: str
    attribution: str
    cache_path: str
    accessed_at: str
    title: str = ""
    creator: str = ""
    license_url: str = ""
    sha256: str = ""

    def to_dict(self) -> dict[str, str]:
        return {
            "provider": self.provider,
            "source_url": self.source_url,
            "license": self.license,
            "license_normalized": self.license_normalized,
            "attribution": self.attribution,
            "cache_path": self.cache_path,
            "accessed_at": self.accessed_at,
            "title": self.title,
            "creator": self.creator,
            "license_url": self.license_url,
            "sha256": self.sha256,
        }


@dataclass
class AssetAuditEntry:
    """Satu baris audit pemakaian (atau penolakan) aset eksternal."""

    provider: str
    source_url: str
    status: str  # "used" | "rejected" | "fallback"
    reason: str
    license: str = ""
    attribution: str = ""
    cache_path: str = ""
    accessed_at: str = ""

    def to_dict(self) -> dict[str, str]:
        return {
            "provider": self.provider,
            "source_url": self.source_url,
            "status": self.status,
            "reason": self.reason,
            "license": self.license,
            "attribution": self.attribution,
            "cache_path": self.cache_path,
            "accessed_at": self.accessed_at,
        }


class AssetAuditTrail:
    """Penampung jejak audit aset eksternal (in-memory, dapat diserialisasi)."""

    def __init__(self) -> None:
        self._entries: list[AssetAuditEntry] = []

    def record(self, entry: AssetAuditEntry) -> None:
        self._entries.append(entry)

    @property
    def entries(self) -> list[AssetAuditEntry]:
        return list(self._entries)

    def to_list(self) -> list[dict[str, str]]:
        return [entry.to_dict() for entry in self._entries]


# Fetcher disuntikkan dari luar: (url) -> bytes. Default None = tanpa jaringan.
AssetFetcher = Callable[[str], bytes]


def _utc_now_iso(clock: Optional[Callable[[], datetime]]) -> str:
    now = clock() if clock is not None else datetime.now(timezone.utc)
    if now.tzinfo is None:
        now = now.replace(tzinfo=timezone.utc)
    return now.astimezone(timezone.utc).isoformat()


def default_cache_dir() -> Path:
    """Direktori cache privat untuk aset eksternal.

    Default ke ``PRESENTATION_ASSET_CACHE_DIR`` bila diset, jika tidak ke
    ``<repo>/storage/presentation_assets`` (privat, bukan public web root).
    """
    configured = get_env("PRESENTATION_ASSET_CACHE_DIR")
    if configured:
        return Path(configured)
    base = Path(__file__).resolve().parents[2]  # python-ai/
    return base / "storage" / "presentation_assets"


class LicensedWebAssetService:
    """Validasi + cache + audit aset web berlisensi (opsional).

    Aman secara default: tanpa ``fetcher`` (dan/atau mode bukan
    ``licensed_web_assets``) tidak ada koneksi jaringan dan ``enrich`` selalu
    mengembalikan ``None`` (renderer memakai aset lokal).
    """

    MAX_ASSET_BYTES = 8 * 1024 * 1024  # 8 MB guard

    def __init__(
        self,
        *,
        mode: str = DEFAULT_ASSET_MODE,
        fetcher: Optional[AssetFetcher] = None,
        cache_dir: Optional[Path] = None,
        clock: Optional[Callable[[], datetime]] = None,
        audit: Optional[AssetAuditTrail] = None,
    ) -> None:
        self.mode = normalize_asset_mode(mode)
        self._fetcher = fetcher
        self._cache_dir = Path(cache_dir) if cache_dir is not None else default_cache_dir()
        self._clock = clock
        self.audit = audit if audit is not None else AssetAuditTrail()

    @property
    def enabled(self) -> bool:
        """True hanya bila mode lisensi aktif DAN fetcher tersedia."""
        return self.mode == ASSET_MODE_LICENSED and self._fetcher is not None

    # ── Validasi ────────────────────────────────────────────────────────────
    def validate(self, candidate: LicensedAssetCandidate) -> tuple[bool, str]:
        """Validasi metadata kandidat terhadap whitelist & kebijakan lisensi.

        Mengembalikan ``(ok, reason)``; ``reason`` aman untuk audit (tanpa
        konten dokumen/secret).
        """
        provider = LICENSED_PROVIDERS.get((candidate.provider or "").strip().lower())
        if provider is None:
            return False, "provider_not_whitelisted"

        url = (candidate.source_url or "").strip()
        parsed = urlparse(url)
        if parsed.scheme != "https" or not parsed.netloc:
            return False, "source_url_not_https"
        if not _host_matches(parsed.netloc, provider.hosts):
            return False, "source_host_not_trusted"

        if not (candidate.attribution or "").strip() and provider.attribution_required:
            return False, "attribution_missing"

        if not license_is_allowed(candidate.license):
            return False, "license_not_allowed"
        if normalize_license(candidate.license) not in provider.allowed_licenses:
            return False, "license_not_allowed_for_provider"

        return True, "ok"

    # ── Enrichment ────────────────────────────────────────────────────────────
    def enrich(self, candidate: LicensedAssetCandidate) -> Optional[LicensedAssetMetadata]:
        """Coba validasi + cache satu aset. Selalu fallback (``None``) saat gagal.

        Tidak pernah melempar exception ke pemanggil: kegagalan apa pun dicatat
        di audit dan menghasilkan fallback ke aset lokal.
        """
        provider_key = (candidate.provider or "").strip().lower()
        source_url = (candidate.source_url or "").strip()

        if self.mode != ASSET_MODE_LICENSED:
            self._audit(provider_key, source_url, "fallback", "mode_local_assets_only")
            return None
        if self._fetcher is None:
            # Tanpa fetcher: tidak ada jaringan -> fallback lokal (default aman).
            self._audit(provider_key, source_url, "fallback", "no_fetcher_no_network")
            return None

        ok, reason = self.validate(candidate)
        if not ok:
            self._audit(provider_key, source_url, "rejected", reason,
                        license_value=candidate.license, attribution=candidate.attribution)
            return None

        try:
            content = self._fetcher(source_url)
        except Exception:  # noqa: BLE001 — kegagalan jaringan/provider apa pun -> fallback
            # Sengaja tidak melog exception detail (bisa memuat URL/secret di trace).
            self._audit(provider_key, source_url, "fallback", "provider_or_network_error",
                        license_value=candidate.license, attribution=candidate.attribution)
            return None

        if not isinstance(content, (bytes, bytearray)) or len(content) == 0:
            self._audit(provider_key, source_url, "fallback", "empty_asset",
                        license_value=candidate.license, attribution=candidate.attribution)
            return None
        if len(content) > self.MAX_ASSET_BYTES:
            self._audit(provider_key, source_url, "rejected", "asset_too_large",
                        license_value=candidate.license, attribution=candidate.attribution)
            return None

        accessed_at = _utc_now_iso(self._clock)
        try:
            cache_path, digest = self._cache_asset(provider_key, source_url, bytes(content))
        except OSError:
            self._audit(provider_key, source_url, "fallback", "cache_write_failed",
                        license_value=candidate.license, attribution=candidate.attribution)
            return None

        metadata = LicensedAssetMetadata(
            provider=provider_key,
            source_url=source_url,
            license=candidate.license,
            license_normalized=normalize_license(candidate.license),
            attribution=candidate.attribution.strip(),
            cache_path=str(cache_path),
            accessed_at=accessed_at,
            title=(candidate.title or "").strip(),
            creator=(candidate.creator or "").strip(),
            license_url=(candidate.license_url or "").strip(),
            sha256=digest,
        )
        self._audit(
            provider_key, source_url, "used", "ok",
            license_value=candidate.license, attribution=candidate.attribution,
            cache_path=str(cache_path), accessed_at=accessed_at,
        )
        return metadata

    def enrich_many(
        self, candidates: list[LicensedAssetCandidate]
    ) -> list[LicensedAssetMetadata]:
        """Validasi + cache banyak kandidat; hanya yang lolos dikembalikan."""
        results: list[LicensedAssetMetadata] = []
        for candidate in candidates:
            metadata = self.enrich(candidate)
            if metadata is not None:
                results.append(metadata)
        return results

    # ── Internal ──────────────────────────────────────────────────────────────
    def _cache_asset(self, provider_key: str, source_url: str, content: bytes) -> tuple[Path, str]:
        digest = hashlib.sha256(content).hexdigest()
        # Nama file deterministik dari hash url+isi; tidak membocorkan isi dokumen.
        url_hash = hashlib.sha256(source_url.encode("utf-8")).hexdigest()[:16]
        safe_provider = re.sub(r"[^a-z0-9_]+", "", provider_key) or "provider"
        directory = self._cache_dir / safe_provider
        directory.mkdir(parents=True, exist_ok=True)
        cache_path = directory / f"{url_hash}-{digest[:16]}.bin"
        # Tulis hanya bila belum ada (cache hit hemat I/O & idempoten).
        if not cache_path.exists():
            cache_path.write_bytes(content)
        return cache_path, digest

    def _audit(
        self,
        provider: str,
        source_url: str,
        status: str,
        reason: str,
        *,
        license_value: str = "",
        attribution: str = "",
        cache_path: str = "",
        accessed_at: str = "",
    ) -> None:
        self.audit.record(
            AssetAuditEntry(
                provider=provider,
                source_url=source_url,
                status=status,
                reason=reason,
                license=license_value,
                attribution=attribution,
                cache_path=cache_path,
                accessed_at=accessed_at,
            )
        )
        # Log privacy-safe: hanya status + alasan + provider, tanpa url penuh.
        logger.info(
            "presentation_asset_audit",
            extra={
                "provider": provider,
                "status": status,
                "reason": reason,
                "has_url": bool(source_url),
            },
        )
