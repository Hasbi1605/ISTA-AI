#!/usr/bin/env python3
import json
import re
import statistics
import subprocess
import time
import random
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any


API_URL = "https://models.github.ai/inference/chat/completions"


def get_token() -> str:
    proc = subprocess.run(
        ["gh", "auth", "token"], capture_output=True, text=True, check=False
    )
    token = proc.stdout.strip()
    if not token:
        raise RuntimeError("Token GitHub CLI tidak tersedia. Jalankan `gh auth login`.")
    return token


def build_haystack(seed: str, needle: str) -> str:
    chunks = []
    for i in range(1, 65):
        chunks.append(
            f"Blok {i:02d} | proyek {seed} | catatan operasional rutin | nilai checksum {i * 37} | status sinkron."
        )
    chunks.insert(41, f"Catatan kritikal internal: {needle}")
    return "\n".join(chunks)


@dataclass
class PromptCase:
    key: str
    category: str
    prompt: str
    max_out: int


def prompt_suite() -> list[PromptCase]:
    needle_1 = "KODE_AKSES=ALPHA-7391-ZETA"
    needle_2 = "NILAI_X=271 dan NILAI_Y=154"

    p_reason_1 = (
        "Selesaikan tepat, lalu jawab ANGKA SAJA tanpa teks lain.\n"
        "Hitung nilai: ((27*14) - (18*9) + (7^2)) / 5 + 13"
    )
    p_reason_2 = (
        "Jawab ANGKA SAJA tanpa teks lain.\n"
        "Berapa banyak susunan berbeda huruf pada kata STATISTIK?"
    )

    p_json = (
        "Ekstrak informasi dari kalimat berikut dan kembalikan HANYA JSON valid satu baris.\n"
        "Kalimat: 'Sejak pagi API pembayaran sering timeout, pelanggan enterprise mulai komplain, mohon prioritas hari ini.'\n"
        "Skema wajib:\n"
        "{\"sentiment\":\"positive|neutral|negative\",\"urgency\":0-3,\"entities\":[string],\"action\":string}\n"
        "Aturan: entities harus huruf kecil, tanpa duplikasi."
    )

    p_bugfix = (
        "Perbaiki bug fungsi Python berikut. Kembalikan hanya kode fungsi final, tanpa penjelasan.\n"
        "def fib(n):\n"
        "    if n <= 1:\n"
        "        return 1\n"
        "    a, b = 0, 1\n"
        "    for _ in range(n):\n"
        "        a = b\n"
        "        b = a + b\n"
        "    return b\n"
        "Target perilaku benar: fib(0)=0, fib(1)=1, fib(7)=13"
    )

    p_rag_1 = (
        "Baca konteks panjang berikut. Jawab persis nilai kode akses, tanpa kata tambahan.\n\n"
        f"{build_haystack('atlas', needle_1)}"
    )
    p_rag_2 = (
        "Dari konteks, temukan NILAI_X dan NILAI_Y lalu jawab jumlahnya sebagai ANGKA SAJA.\n\n"
        f"{build_haystack('orion', needle_2)}"
    )

    p_id = (
        "Tulis jawaban dalam Bahasa Indonesia formal, tepat 2 kalimat, total maksimal 36 kata. "
        "Jangan gunakan kata 'namun'. Topik: rekomendasi mitigasi saat API rate limit sering muncul."
    )

    return [
        PromptCase("reason_math", "reasoning", p_reason_1, 80),
        PromptCase("reason_combinatoric", "reasoning", p_reason_2, 80),
        PromptCase("json_strict", "coding_structured", p_json, 220),
        PromptCase("bugfix_mini", "coding_structured", p_bugfix, 260),
        PromptCase("rag_needle", "rag_long_context", p_rag_1, 80),
        PromptCase("rag_compute", "rag_long_context", p_rag_2, 80),
        PromptCase("id_instruction", "instruction_id", p_id, 120),
    ]


def uses_max_completion_tokens(model: str) -> bool:
    return model.startswith("openai/o") or model.startswith("openai/gpt-5")


def call_model(token: str, model: str, prompt: str, max_out: int) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "model": model,
        "messages": [{"role": "user", "content": prompt}],
    }
    if not (model.startswith("openai/o") or model.startswith("openai/gpt-5")):
        payload["temperature"] = 0.2
    if uses_max_completion_tokens(model):
        payload["max_completion_tokens"] = max_out
    else:
        payload["max_tokens"] = max_out

    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        API_URL,
        data=body,
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        },
        method="POST",
    )

    started = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            latency = (time.perf_counter() - started) * 1000
            data = json.loads(raw)
            text = ""
            try:
                text = data["choices"][0]["message"]["content"] or ""
            except Exception:
                text = ""
            return {
                "success": True,
                "status": resp.status,
                "latency_ms": round(latency, 2),
                "response_text": text,
                "usage": data.get("usage"),
                "error": None,
                "raw": data,
            }
    except urllib.error.HTTPError as e:
        latency = (time.perf_counter() - started) * 1000
        err_body = e.read().decode("utf-8", errors="replace")
        # Adaptive retry for unsupported temperature on some models.
        if e.code == 400 and "temperature" in err_body and "unsupported" in err_body.lower():
            payload.pop("temperature", None)
            retry_body = json.dumps(payload).encode("utf-8")
            retry_req = urllib.request.Request(
                API_URL,
                data=retry_body,
                headers={
                    "Authorization": f"Bearer {token}",
                    "Content-Type": "application/json",
                },
                method="POST",
            )
            retry_started = time.perf_counter()
            try:
                with urllib.request.urlopen(retry_req, timeout=120) as resp:
                    raw = resp.read().decode("utf-8", errors="replace")
                    retry_latency = (time.perf_counter() - retry_started) * 1000
                    data = json.loads(raw)
                    text = ""
                    try:
                        text = data["choices"][0]["message"]["content"] or ""
                    except Exception:
                        text = ""
                    return {
                        "success": True,
                        "status": resp.status,
                        "latency_ms": round(retry_latency, 2),
                        "response_text": text,
                        "usage": data.get("usage"),
                        "error": None,
                        "raw": data,
                    }
            except Exception as re_err:
                return {
                    "success": False,
                    "status": getattr(re_err, "code", None),
                    "latency_ms": round((time.perf_counter() - retry_started) * 1000, 2),
                    "response_text": "",
                    "usage": None,
                    "error": str(re_err),
                    "raw": None,
                }
        return {
            "success": False,
            "status": e.code,
            "latency_ms": round(latency, 2),
            "response_text": "",
            "usage": None,
            "error": err_body,
            "raw": None,
        }
    except Exception as e:
        latency = (time.perf_counter() - started) * 1000
        return {
            "success": False,
            "status": None,
            "latency_ms": round(latency, 2),
            "response_text": "",
            "usage": None,
            "error": str(e),
            "raw": None,
        }


def score_case(case_key: str, answer: str) -> tuple[int, str]:
    txt = (answer or "").strip()
    if not txt:
        return 1, "Jawaban kosong"

    if case_key == "reason_math":
        if txt == "66":
            return 5, "Tepat"
        if "66" in txt:
            return 4, "Nilai benar, format kurang ketat"
        return 2, "Nilai tidak tepat"

    if case_key == "reason_combinatoric":
        if txt == "15120":
            return 5, "Tepat"
        if "15120" in txt:
            return 4, "Nilai benar, format kurang ketat"
        return 2, "Nilai tidak tepat"

    if case_key == "json_strict":
        try:
            obj = json.loads(txt)
        except Exception:
            return 1, "Bukan JSON valid"
        req = {"sentiment", "urgency", "entities", "action"}
        if set(obj.keys()) != req:
            return 2, "Skema tidak persis"
        if obj["sentiment"] not in {"positive", "neutral", "negative"}:
            return 2, "Nilai sentiment tidak valid"
        if not isinstance(obj["urgency"], int) or not (0 <= obj["urgency"] <= 3):
            return 2, "Urgency tidak valid"
        if not isinstance(obj["entities"], list):
            return 2, "Entities bukan list"
        entities = obj["entities"]
        if len(set(entities)) != len(entities):
            return 3, "Entities duplikat"
        if any((not isinstance(x, str)) or (x != x.lower()) for x in entities):
            return 3, "Entities tidak lowercase"
        if obj["sentiment"] == "negative" and obj["urgency"] >= 2:
            return 5, "Skema dan isi sesuai"
        return 4, "Skema benar, inferensi sebagian"

    if case_key == "bugfix_mini":
        has_sig = "def fib" in txt
        has_base0 = re.search(r"if\s+n\s*<=\s*0\s*:\s*return\s+0", txt) is not None
        has_base1 = re.search(r"if\s+n\s*==\s*1\s*:\s*return\s+1", txt) is not None
        has_loop = "for _ in range" in txt
        has_update = re.search(r"a\s*,\s*b\s*=\s*b\s*,\s*a\s*\+\s*b", txt) is not None
        has_return_a_or_b = ("return a" in txt) or ("return b" in txt)
        hits = sum([has_sig, has_base0, has_base1, has_loop, has_update, has_return_a_or_b])
        if hits >= 6:
            return 5, "Bugfix tepat"
        if hits >= 4:
            return 3, "Perbaikan parsial"
        return 2, "Bugfix lemah"

    if case_key == "rag_needle":
        if txt == "ALPHA-7391-ZETA" or txt == "KODE_AKSES=ALPHA-7391-ZETA":
            return 5, "Needle ditemukan tepat"
        if "ALPHA-7391-ZETA" in txt:
            return 4, "Needle benar, format kurang ketat"
        return 2, "Needle salah"

    if case_key == "rag_compute":
        if txt == "425":
            return 5, "Kalkulasi tepat"
        if "425" in txt:
            return 4, "Angka benar, format kurang ketat"
        return 2, "Kalkulasi salah"

    if case_key == "id_instruction":
        lower = txt.lower()
        sentence_count = len([x for x in re.split(r"[.!?]+", txt) if x.strip()])
        word_count = len(re.findall(r"\S+", txt))
        has_namun = "namun" in lower
        if sentence_count == 2 and word_count <= 36 and not has_namun:
            return 5, "Instruksi diikuti"
        if sentence_count == 2 and not has_namun:
            return 4, "Instruksi sebagian besar diikuti"
        return 2, "Gagal mengikuti instruksi"

    return 3, "Tidak ada rubric"


def benchmark_model(token: str, model: str, cases: list[PromptCase]) -> dict[str, Any]:
    results = []
    for case in cases:
        res = None
        for attempt in range(1, 4):
            res = call_model(token, model, case.prompt, case.max_out)
            if res["success"]:
                break
            if res["status"] == 429 and attempt < 3:
                sleep_s = (1.2 * attempt) + random.uniform(0.2, 0.8)
                time.sleep(sleep_s)
                continue
            break
        assert res is not None
        score = None
        note = ""
        if res["success"]:
            score, note = score_case(case.key, res["response_text"])
        results.append(
            {
                "case": case.key,
                "category": case.category,
                "success": res["success"],
                "status": res["status"],
                "latency_ms": res["latency_ms"],
                "usage": res["usage"],
                "quality_score_1_5": score,
                "quality_note": note,
                "answer": res["response_text"],
                "error": res["error"],
            }
        )

    burst_prompt = "Balas tepat: OK"
    burst = []
    for i in range(6):
        res = None
        for attempt in range(1, 3):
            res = call_model(token, model, burst_prompt, 16)
            if res["success"]:
                break
            if res["status"] == 429 and attempt < 2:
                time.sleep(0.6 + random.uniform(0.1, 0.4))
                continue
            break
        assert res is not None
        burst.append(
            {
                "success": res["success"],
                "status": res["status"],
                "latency_ms": res["latency_ms"],
                "error": res["error"],
            }
        )
        if i < 5:
            time.sleep(0.35)

    return {
        "model": model,
        "cases": results,
        "burst": burst,
    }


def aggregate(model_result: dict[str, Any]) -> dict[str, Any]:
    cases = model_result["cases"]
    burst = model_result["burst"]

    success_cases = [c for c in cases if c["success"]]
    latencies = [c["latency_ms"] for c in success_cases]
    scores = [c["quality_score_1_5"] for c in success_cases if c["quality_score_1_5"]]
    prompt_success_rate = (len(success_cases) / len(cases) * 100.0) if cases else 0.0
    burst_success = [b for b in burst if b["success"]]
    burst_success_rate = (len(burst_success) / len(burst) * 100.0) if burst else 0.0

    status_hist: dict[str, int] = {}
    for b in burst:
        key = str(b["status"])
        status_hist[key] = status_hist.get(key, 0) + 1

    usage_prompt_tokens = []
    usage_completion_tokens = []
    usage_total_tokens = []
    for c in success_cases:
        u = c.get("usage") or {}
        if isinstance(u, dict):
            p = u.get("prompt_tokens")
            q = u.get("completion_tokens")
            t = u.get("total_tokens")
            if isinstance(p, (int, float)):
                usage_prompt_tokens.append(p)
            if isinstance(q, (int, float)):
                usage_completion_tokens.append(q)
            if isinstance(t, (int, float)):
                usage_total_tokens.append(t)

    return {
        "model": model_result["model"],
        "prompt_success_rate": round(prompt_success_rate, 2),
        "median_latency_ms": round(statistics.median(latencies), 2) if latencies else None,
        "avg_quality_score": round(sum(scores) / len(scores), 2) if scores else None,
        "burst_success_rate": round(burst_success_rate, 2),
        "burst_status_hist": status_hist,
        "usage_prompt_tokens_median": round(statistics.median(usage_prompt_tokens), 2)
        if usage_prompt_tokens
        else None,
        "usage_completion_tokens_median": round(
            statistics.median(usage_completion_tokens), 2
        )
        if usage_completion_tokens
        else None,
        "usage_total_tokens_median": round(statistics.median(usage_total_tokens), 2)
        if usage_total_tokens
        else None,
    }


def pick_reasoning_model(token: str) -> str:
    probe = call_model(token, "deepseek/deepseek-r1-0528", "Balas: OK", 16)
    if probe["success"]:
        return "deepseek/deepseek-r1-0528"
    return "deepseek/deepseek-r1"


def main() -> None:
    token = get_token()
    cases = prompt_suite()

    reasoning_deepseek = pick_reasoning_model(token)
    models = [
        "openai/o3",
        reasoning_deepseek,
        "openai/gpt-5",
        "openai/gpt-5-chat",
        "openai/gpt-4o",
        "openai/gpt-4.1",
    ]

    all_results = {
        "meta": {
            "api_url": API_URL,
            "run_at_utc": datetime.now(timezone.utc).isoformat(),
            "models": models,
            "cases": [c.__dict__ for c in cases],
            "burst_requests_per_model": 6,
        },
        "results": [],
        "aggregates": [],
    }

    for model in models:
        result = benchmark_model(token, model, cases)
        all_results["results"].append(result)
        all_results["aggregates"].append(aggregate(result))
        print(
            f"[{model}] selesai | prompt success {all_results['aggregates'][-1]['prompt_success_rate']}% | burst success {all_results['aggregates'][-1]['burst_success_rate']}%"
        )

    out_path = "test/benchmark_results_github_models.json"
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(all_results, f, ensure_ascii=False, indent=2)
    print(f"Hasil tersimpan di: {out_path}")


if __name__ == "__main__":
    main()
