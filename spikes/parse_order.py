"""Spike 1.5: parse a customer order with muse-spark-1.3 (spec Contract 2).

Input is one handwritten-list photo or one voice note::

    .venv\\Scripts\\Activate.ps1
    pip install -r requirements.txt
    python parse_order.py --image ..\\samples\\list\\list_1.jpg
    python parse_order.py --audio ..\\samples\\voice\\voice_1.m4a

Audio is converted with ffmpeg to mono 16-bit PCM WAV at 24 kHz, then
transcribed with muse-voice-transcribe-1.0 (multipart ``request`` +
``audio`` parts). The transcript -- or the list photo, EXIF rotation
fixed -- goes to muse-spark-1.3 with the catalog table first in the
system prompt. Prices are computed here in Python, never by the model.
Raw model responses are saved under spikes/out/orders/.
"""

import argparse
import base64
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import requests

from common import get_client

ROOT = Path(__file__).resolve().parent.parent
CATALOG_PATH = ROOT / "spikes" / "catalog.json"
SYSTEM_PATH = ROOT / "prompts" / "parse_order_system.txt"
SCHEMA_PATH = ROOT / "prompts" / "parse_order_schema.json"
OUT_DIR = ROOT / "spikes" / "out" / "orders"

ASR_URL = "https://api.meta.ai/v1/asr/transcribe"
ASR_MODEL = "muse-voice-transcribe-1.0"
CHAT_MODEL = "muse-spark-1.3"


def load_catalog() -> list:
    return json.loads(CATALOG_PATH.read_text(encoding="utf-8"))


def catalog_table(catalog: list) -> str:
    """Compact catalog table, placed first in the system prompt."""
    rows = ["id | name | size | aliases | in_stock"]
    for item in catalog:
        in_stock = "yes" if item.get("stock", 0) > 0 else "no"
        rows.append(
            f"{item['id']} | {item['name']} | {item['size']} | - | {in_stock}"
        )
    return "\n".join(rows)


def build_system(catalog: list) -> str:
    instructions = SYSTEM_PATH.read_text(encoding="utf-8").strip()
    return catalog_table(catalog) + "\n\n" + instructions


def build_schema(catalog: list) -> dict:
    """Schema template plus a live enum of catalog ids (spec Contract 2)."""
    schema = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
    ids = [item["id"] for item in catalog]
    # The template holds a "<live ids>" placeholder; replace it with the
    # shop's real catalog ids so the model can only pick those or "none".
    line_schema = schema["properties"]["lines"]["items"]["properties"]
    line_schema["product_id"]["enum"] = ids + ["none"]
    return schema


def convert_to_wav(src: Path) -> Path:
    """ffmpeg any-format audio to mono 16-bit PCM WAV at 24 kHz."""
    if shutil.which("ffmpeg") is None:
        raise RuntimeError("ffmpeg is not on PATH (needed for --audio)")
    fd, tmp = tempfile.mkstemp(prefix="order_", suffix=".wav")
    os.close(fd)
    wav_path = Path(tmp)
    subprocess.run(
        [
            "ffmpeg", "-y", "-i", str(src),
            "-ac", "1", "-ar", "24000", "-c:a", "pcm_s16le",
            "-map_metadata", "-1", str(wav_path),
        ],
        check=True,
        capture_output=True,
    )
    return wav_path


def transcribe(wav_path: Path, catalog: list) -> tuple:
    """POST multipart (request JSON + audio WAV) to the ASR endpoint."""
    api_key = os.getenv("MODEL_API_KEY")
    if not api_key:
        raise RuntimeError("MODEL_API_KEY is missing in the root .env")
    request_json = {
        "model": ASR_MODEL,
        "mode": "PUSH_TO_TALK",
        "audioEncoding": "WAV",
        "languageBias": ["English", "Bengali"],
        "keywords": [item["name"] for item in catalog],
    }
    with wav_path.open("rb") as handle:
        response = requests.post(
            ASR_URL,
            headers={"Authorization": f"Bearer {api_key}"},
            files={
                "request": (None, json.dumps(request_json), "application/json"),
                "audio": (wav_path.name, handle, "audio/wav"),
            },
            timeout=120,
        )
    response.raise_for_status()
    raw = response.json()
    transcript = raw.get("transcript")
    if not transcript:
        turns = raw.get("turns") or []
        transcript = " ".join(
            turn.get("transcript", "") for turn in turns
        ).strip()
    return transcript, raw


def load_image_data_url(path: Path) -> str:
    """JPEG bytes with EXIF rotation applied, as a data URL."""
    try:
        from PIL import Image, ImageOps
    except ImportError:
        raise RuntimeError(
            "Pillow is required for --image: pip install -r requirements.txt"
        )
    with Image.open(path) as img:
        fixed = ImageOps.exif_transpose(img).convert("RGB")
        buffer = io.BytesIO()
        fixed.save(buffer, format="JPEG", quality=92)
    return "data:image/jpeg;base64," + base64.b64encode(buffer.getvalue()).decode(
        "ascii"
    )


def parse_order(client, system: str, schema: dict, user_content: list) -> tuple:
    response = client.chat.completions.create(
        model=CHAT_MODEL,
        messages=[
            {"role": "system", "content": system},
            {"role": "user", "content": user_content},
        ],
        response_format={
            "type": "json_schema",
            "json_schema": {"name": "parse_order", "schema": schema},
        },
    )
    parsed = json.loads(response.choices[0].message.content)
    return parsed, response.model_dump(mode="json")


def fmt_qty(qty) -> str:
    if isinstance(qty, float) and qty.is_integer():
        return str(int(qty))
    return str(qty)


def print_invoice(input_name: str, parsed: dict, catalog: list) -> None:
    by_id = {item["id"]: item for item in catalog}
    total = 0
    matched_count = 0
    not_found: list = []
    ambiguous: list = []
    print(f"INVOICE -- {input_name}")
    print(f"{'requested':<30} {'product':<26} {'qty':<6} {'unit':<8} {'line':<8} status")
    print("-" * 96)
    for line in parsed.get("lines", []):
        requested = line.get("requested_text", "")
        qty = line.get("qty") or 1
        status = line.get("status", "")
        product_id = line.get("product_id", "none")
        product = by_id.get(product_id)
        if status == "matched" and product is not None:
            unit = product["price"]
            line_total = qty * unit
            if isinstance(line_total, float) and line_total.is_integer():
                line_total = int(line_total)
            total += line_total
            matched_count += 1
            print(
                f"{requested[:30]:<30} {product['name'][:26]:<26} "
                f"{fmt_qty(qty):<6} {unit:<8} {line_total:<8} {status}"
            )
        else:
            print(
                f"{requested[:30]:<30} {'-':<26} "
                f"{fmt_qty(qty):<6} {'-':<8} {'-':<8} {status}"
            )
            if status == "ambiguous":
                ambiguous.append(line)
            else:
                not_found.append(line)
    print("-" * 96)
    print(f"TOTAL: \u09f3{total} ({matched_count} matched lines)")
    if not_found:
        print("NOT FOUND:")
        for line in not_found:
            print(f"  - {line.get('requested_text', '')}")
    if ambiguous:
        print("AMBIGUOUS:")
        for line in ambiguous:
            options = ", ".join(
                by_id[pid]["name"] if pid in by_id else pid
                for pid in line.get("alternative_ids", [])
            )
            print(f"  - {line.get('requested_text', '')} (options: {options})")


def main() -> None:
    if hasattr(sys.stdout, "reconfigure"):  # Windows console is cp1252, not UTF-8
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    parser = argparse.ArgumentParser(description="Spike 1.5: parse a customer order")
    parser.add_argument("--image", type=Path, default=None)
    parser.add_argument("--audio", type=Path, default=None)
    args = parser.parse_args()
    if bool(args.image) == bool(args.audio):
        parser.error("pass exactly one of --image or --audio")

    catalog = load_catalog()
    system = build_system(catalog)
    schema = build_schema(catalog)
    client = get_client()

    src: Path = args.image or args.audio
    transcript = None
    asr_raw = None
    if args.audio is not None:
        wav_path = convert_to_wav(args.audio)
        try:
            transcript, asr_raw = transcribe(wav_path, catalog)
        finally:
            wav_path.unlink(missing_ok=True)
        print(f"TRANSCRIPT: {transcript}")
        user_content = [{"type": "text", "text": f"Customer order:\n{transcript}"}]
    else:
        data_url = load_image_data_url(args.image)
        user_content = [
            {
                "type": "text",
                "text": "Read the customer's handwritten order in this photo "
                "and match each item to the catalog.",
            },
            {"type": "image_url", "image_url": {"url": data_url}},
        ]

    parsed, chat_raw = parse_order(client, system, schema, user_content)

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    (OUT_DIR / f"{src.stem}.json").write_text(
        json.dumps(
            {
                "input": str(src),
                "transcript": transcript,
                "asr_raw": asr_raw,
                "chat_raw": chat_raw,
                "parsed": parsed,
            },
            indent=2,
        ),
        encoding="utf-8",
    )

    print_invoice(src.name, parsed, catalog)


if __name__ == "__main__":
    main()
