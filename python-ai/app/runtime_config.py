from __future__ import annotations

import logging
from typing import Any, Mapping

logger = logging.getLogger(__name__)


def nested_value(config: Mapping[str, Any] | None, *path: str, default: Any = None) -> Any:
    value: Any = config
    for key in path:
        if not isinstance(value, Mapping):
            return default
        value = value.get(key)

    return default if value is None else value


def runtime_prompt(config: Mapping[str, Any] | None, *path: str) -> str:
    value = nested_value(config, "prompts", *path, default="")
    return value.strip() if isinstance(value, str) else ""


def render_prompt_template(template: str, fallback_template: str = "", **kwargs: Any) -> str:
    candidates: list[str] = []
    for candidate in (template, fallback_template):
        if isinstance(candidate, str) and candidate.strip() and candidate not in candidates:
            candidates.append(candidate)

    last_error: Exception | None = None
    for index, candidate in enumerate(candidates):
        try:
            rendered = candidate.format(**kwargs)
        except (KeyError, IndexError, ValueError) as exc:
            last_error = exc
            if index == 0 and fallback_template:
                logger.warning("Runtime prompt template invalid; using default prompt: %s", exc)
            continue

        if rendered.strip():
            return rendered

    if last_error is not None:
        logger.warning("Prompt template render failed: %s", last_error)

    return ""


def runtime_int(
    config: Mapping[str, Any] | None,
    *path: str,
    default: int,
    minimum: int | None = None,
    maximum: int | None = None,
) -> int:
    value = nested_value(config, *path, default=default)
    try:
        resolved = int(value)
    except (TypeError, ValueError):
        resolved = default

    if minimum is not None:
        resolved = max(minimum, resolved)
    if maximum is not None:
        resolved = min(maximum, resolved)

    return resolved
