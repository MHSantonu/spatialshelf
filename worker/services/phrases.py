"""SAM phrase picker: muse-spark-1.3 names the products on a shelf frame.

Ported from spikes/pick_phrases.py. The system prompt and JSON schema
load from prompts/ at runtime; the raw request and response are saved
to the scan folder for debugging. Any failure returns FALLBACK_PHRASES.

All paths are relative to SHARED_STORAGE_PATH unless absolute.
"""

from __future__ import annotations

import base64
import json
import os
import re
from pathlib import Path

from dotenv import load_dotenv
from openai import OpenAI

ROOT = Path(__file__).resolve().parent.parent.parent
load_dotenv(ROOT / ".env")

PROMPT_PATH = ROOT / "prompts" / "pick_phrases.md"
SCHEMA_PATH = ROOT / "prompts" / "schemas" / "pick_phrases.json"
CHAT_MODEL = "muse-spark-1.3"
USER_MESSAGE = "What kinds of packaged products are on this shelf?"
FALLBACK_PHRASES = ["product package", "bottle"]
BRAND_WORD_RE = re.compile(r"\b[A-Z][A-Za-z]*\b")


def sanitize_phrases(phrases: list[str]) -> list[str]:
    """Strip capitalised brand-like words, keeping the generic type.

    "Horlicks jar" -> "jar". A phrase with nothing generic left is
    dropped; survivors dedupe case-insensitively, order kept.
    """
    clean: list[str] = []
    seen: set[str] = set()
    for phrase in phrases:
        generic = re.sub(r"\s+", " ", BRAND_WORD_RE.sub("", phrase)).strip()
        if generic and generic.lower() not in seen:
            seen.add(generic.lower())
            clean.append(generic)
    return clean


def _shared_root() -> Path:
    return Path(os.getenv("SHARED_STORAGE_PATH", str(ROOT / "storage-shared")))


def _resolve(path: str | Path) -> Path:
    candidate = Path(path)
    return candidate if candidate.is_absolute() else _shared_root() / candidate


def _get_client() -> OpenAI:
    api_key = os.getenv("MODEL_API_KEY")
    if not api_key:
        raise RuntimeError("MODEL_API_KEY is missing in the root .env")
    base_url = os.getenv("META_BASE_URL", "https://api.meta.ai/v1")
    return OpenAI(api_key=api_key, base_url=base_url)


def _write_json(path: Path, payload: dict) -> None:
    """Best-effort save; a failed write must never break the fallback."""
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    except OSError:
        pass


def pick_phrases(
    keyframe_path: str | Path, scan_dir: str | Path | None = None
) -> list[str]:
    """Name the product kinds on a keyframe; fall back on any error."""
    try:
        keyframe = _resolve(keyframe_path)
        scan = (
            _resolve(scan_dir)
            if scan_dir is not None
            else keyframe.parent.parent  # scans/<scan_id>/keyframes/kf_N.jpg
        )
        system = PROMPT_PATH.read_text(encoding="utf-8").strip()
        schema = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
        data_url = "data:image/jpeg;base64," + base64.b64encode(
            keyframe.read_bytes()
        ).decode("ascii")
        request_payload = {
            "model": CHAT_MODEL,
            "messages": [
                {"role": "system", "content": system},
                {
                    "role": "user",
                    "content": [
                        {"type": "text", "text": USER_MESSAGE},
                        {"type": "image_url", "image_url": {"url": data_url}},
                    ],
                },
            ],
            "response_format": {
                "type": "json_schema",
                "json_schema": {"name": "pick_phrases", "schema": schema},
            },
        }
        _write_json(scan / "phrases_request.json", request_payload)
        response = _get_client().chat.completions.create(**request_payload)
        _write_json(
            scan / "phrases_response.json",
            response.model_dump(mode="json"),
        )
        phrases = json.loads(response.choices[0].message.content)["phrases"]
        phrases = [p.strip() for p in phrases if p.strip()][:4]
        if not phrases:
            raise RuntimeError("muse-spark-1.3 returned no phrases")
        sanitized = sanitize_phrases(phrases)
        if sanitized != phrases:
            print(f"[phrases] brand words rejected: {phrases} -> {sanitized}")
        if not sanitized:
            raise RuntimeError("all phrases rejected as brand names")
        return sanitized
    except Exception as error:
        try:
            scan = (
                _resolve(scan_dir)
                if scan_dir is not None
                else _resolve(keyframe_path).parent.parent
            )
        except Exception:
            return list(FALLBACK_PHRASES)
        _write_json(
            scan / "phrases_response.json",
            {
                "error": f"{type(error).__name__}: {error}",
                "fallback_phrases": FALLBACK_PHRASES,
            },
        )
        return list(FALLBACK_PHRASES)
