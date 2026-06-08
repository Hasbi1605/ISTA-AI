from pathlib import Path

import yaml


ROOT_DIR = Path(__file__).resolve().parents[2]


def test_production_python_services_do_not_publish_chroma_or_ai_ports():
    compose = yaml.safe_load((ROOT_DIR / "docker-compose.production.yml").read_text())
    services = compose["services"]

    for service_name in ("python-ai", "python-ai-docs"):
        service = services[service_name]
        assert not service.get("ports"), f"{service_name} must stay internal-only"

    chroma_service = services.get("chroma") or services.get("chromadb")
    if chroma_service is not None:
        assert not chroma_service.get("ports"), "Chroma must not expose an unauthenticated HTTP port"
