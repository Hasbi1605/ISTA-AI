import json
import os
import subprocess
import sys
from typing import Any, Dict, Tuple

from app.env_utils import get_env_int

DEFAULT_DOCUMENT_PROCESS_SUBPROCESS_TIMEOUT = 900


def _parse_result_payload(stdout: str) -> Dict[str, Any]:
    lines = [line.strip() for line in stdout.splitlines() if line.strip()]
    for line in reversed(lines):
        try:
            payload = json.loads(line)
        except json.JSONDecodeError:
            continue

        if isinstance(payload, dict) and "success" in payload and "message" in payload:
            return payload

    raise ValueError("Subprocess did not return a valid JSON payload.")


def run_document_process(file_path: str, filename: str, user_id: str, document_id: str = "") -> Tuple[bool, str]:
    timeout_seconds = get_env_int("DOCUMENT_PROCESS_SUBPROCESS_TIMEOUT", DEFAULT_DOCUMENT_PROCESS_SUBPROCESS_TIMEOUT)
    app_dir = os.path.dirname(os.path.dirname(__file__))

    completed = subprocess.run(
        [
            sys.executable,
            "-m",
            "app.document_tasks",
            "process",
            file_path,
            filename,
            user_id,
            document_id,
        ],
        cwd=app_dir,
        capture_output=True,
        text=True,
        timeout=timeout_seconds,
        check=False,
    )

    try:
        payload = _parse_result_payload(completed.stdout)
        return bool(payload["success"]), str(payload["message"])
    except Exception:
        stderr_excerpt = (completed.stderr or "").strip()[-1500:]
        stdout_excerpt = (completed.stdout or "").strip()[-1500:]
        detail = stderr_excerpt or stdout_excerpt or f"Subprocess exit code {completed.returncode}"
        return False, f"Pemrosesan dokumen gagal: {detail}"
