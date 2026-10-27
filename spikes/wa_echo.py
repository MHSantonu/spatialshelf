"""WhatsApp echo webhook spike (Spike 1.6).

GET  /webhook  Meta verification (hub.mode / hub.verify_token / hub.challenge).
POST /webhook  Verified with X-Hub-Signature-256, logged to out/wa_log.jsonl,
               then echoed back via the WhatsApp Cloud API (Graph v21.0).

Text messages get "Echo: <text>". Image/audio messages are downloaded to
out/media/<media_id>.<ext> and answered with "Got your <type>".
Status updates are ignored. Every response is HTTP 200.

Run from the spikes/ directory:
    .\\.venv\\Scripts\\python.exe -m uvicorn wa_echo:app --port 8001
"""

import hashlib
import hmac
import json
import mimetypes
import os
from pathlib import Path

import requests
import uvicorn
from dotenv import load_dotenv
from fastapi import FastAPI, Query, Request, Response
from fastapi.responses import PlainTextResponse

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
load_dotenv(ROOT / ".env")

VERIFY_TOKEN = os.getenv("WA_VERIFY_TOKEN", "")
APP_SECRET = os.getenv("WA_APP_SECRET", "")
ACCESS_TOKEN = os.getenv("WA_ACCESS_TOKEN", "")
PHONE_NUMBER_ID = os.getenv("WA_PHONE_NUMBER_ID", "")

for _name, _value in (
    ("WA_VERIFY_TOKEN", VERIFY_TOKEN),
    ("WA_APP_SECRET", APP_SECRET),
    ("WA_ACCESS_TOKEN", ACCESS_TOKEN),
    ("WA_PHONE_NUMBER_ID", PHONE_NUMBER_ID),
):
    if not _value:
        raise RuntimeError(f"{_name} is missing in the root .env")

GRAPH_BASE = "https://graph.facebook.com/v26.0"
LOG_PATH = HERE / "out" / "wa_log.jsonl"
MEDIA_DIR = HERE / "out" / "media"

EXT_BY_MIME = {
    "image/jpeg": "jpg",
    "image/png": "png",
    "image/webp": "webp",
    "audio/ogg": "ogg",
    "audio/mpeg": "mp3",
    "audio/mp4": "m4a",
    "audio/aac": "aac",
    "audio/amr": "amr",
    "audio/wav": "wav",
}

app = FastAPI(title="wa_echo")


def _auth_headers() -> dict:
    # Bearer token is only ever sent to graph.facebook.com, never printed.
    return {"Authorization": f"Bearer {ACCESS_TOKEN}"}


def _forbidden(detail: str) -> Response:
    return Response(content=detail, status_code=403, media_type="text/plain")


@app.get("/webhook")
def verify_webhook(
    hub_mode: str = Query(default="", alias="hub.mode"),
    hub_verify_token: str = Query(default="", alias="hub.verify_token"),
    hub_challenge: str = Query(default="", alias="hub.challenge"),
):
    """Meta subscription verification: echo hub.challenge as plain text."""
    if (
        hub_mode == "subscribe"
        and hub_challenge
        and hmac.compare_digest(hub_verify_token, VERIFY_TOKEN)
    ):
        return PlainTextResponse(hub_challenge)
    return _forbidden("verification failed")


def _signature_ok(raw_body: bytes, signature: str) -> bool:
    if not signature.startswith("sha256="):
        return False
    expected = hmac.new(APP_SECRET.encode(), raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(signature[len("sha256="):], expected)


def _check(response: requests.Response) -> None:
    """Raise on Graph errors, printing Meta's error body (code + message)."""
    try:
        response.raise_for_status()
    except requests.HTTPError:
        # Error bodies hold code/message JSON, never the token.
        print(f"[wa] Graph error: {response.text}", flush=True)
        raise


def send_text(to: str, body: str) -> None:
    r = requests.post(
        f"{GRAPH_BASE}/{PHONE_NUMBER_ID}/messages",
        headers={**_auth_headers(), "Content-Type": "application/json"},
        json={
            "messaging_product": "whatsapp",
            "to": to,
            "type": "text",
            "text": {"body": body},
        },
        timeout=20,
    )
    _check(r)


def download_media(media_id: str) -> Path:
    """Resolve the media URL via Graph, download it, save under out/media/."""
    meta = requests.get(
        f"{GRAPH_BASE}/{media_id}", headers=_auth_headers(), timeout=20
    )
    _check(meta)
    info = meta.json()
    mime = info.get("mime_type", "application/octet-stream")
    ext = EXT_BY_MIME.get(mime) or (mimetypes.guess_extension(mime) or ".bin").lstrip(".")
    MEDIA_DIR.mkdir(parents=True, exist_ok=True)
    dest = MEDIA_DIR / f"{media_id}.{ext}"
    data = requests.get(info["url"], headers=_auth_headers(), timeout=60)
    _check(data)
    dest.write_bytes(data.content)
    return dest


def handle_message(msg: dict) -> None:
    sender = msg.get("from", "?")
    mtype = msg.get("type", "?")
    # Console line per message: type + sender only. Tokens are never printed.
    print(f"[wa] incoming {mtype} from {sender}", flush=True)

    if mtype == "text":
        send_text(sender, f"Echo: {msg.get('text', {}).get('body', '')}")
    elif mtype in ("image", "audio"):
        media_id = msg.get(mtype, {}).get("id", "")
        if media_id:
            dest = download_media(media_id)
            print(f"[wa] saved {mtype} to {dest}", flush=True)
        send_text(sender, f"Got your {mtype}")
    else:
        send_text(sender, f"Got your {mtype}")


@app.post("/webhook")
async def receive_webhook(request: Request):
    raw_body = await request.body()
    if not _signature_ok(raw_body, request.headers.get("x-hub-signature-256") or ""):
        return _forbidden("bad signature")

    try:
        payload = json.loads(raw_body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        payload = {"_unparseable": True}

    LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
    with LOG_PATH.open("a", encoding="utf-8") as f:
        f.write(json.dumps(payload, ensure_ascii=False) + "\n")

    try:
        if isinstance(payload, dict):
            for entry in payload.get("entry", []):
                for change in entry.get("changes", []):
                    value = change.get("value", {})
                    # Status updates (value["statuses"]) are ignored.
                    for msg in value.get("messages", []):
                        try:
                            handle_message(msg)
                        except Exception as exc:  # keep the loop + 200 alive
                            print(f"[wa] handler error: {exc!r}", flush=True)
    except Exception as exc:
        print(f"[wa] error: {exc!r}", flush=True)

    # Always 200: Meta retries anything else, and replies already went out
    # (or their failure was logged above).
    return {"status": "ok"}


if __name__ == "__main__":
    uvicorn.run("wa_echo:app", host="127.0.0.1", port=8001)
