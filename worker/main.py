"""SpatialShelf scan worker.

Pixel work lives here: video prep, SAM 3.1 calls, mask crops, embeddings.
The Laravel app owns everything users touch; models never write to the DB.

Config comes from the repo-root .env (MODEL_API_KEY, SHARED_STORAGE_PATH).
Every endpoint takes paths relative to SHARED_STORAGE_PATH.
"""

import os
import shutil
import threading
from contextlib import asynccontextmanager
from pathlib import Path

from dotenv import load_dotenv
from fastapi import FastAPI

ROOT = Path(__file__).resolve().parent.parent
load_dotenv(ROOT / ".env")

SHARED_STORAGE_PATH = Path(os.getenv("SHARED_STORAGE_PATH", str(ROOT / "storage-shared")))

_state = {"dinov2_loaded": False}
_lock = threading.Lock()


def _check_ffmpeg() -> None:
    if shutil.which("ffmpeg") is None:
        raise RuntimeError(
            "ffmpeg was not found on PATH. Install ffmpeg and restart the worker "
            "(https://ffmpeg.org/download.html)."
        )


def _load_dinov2() -> None:
    # Lazy-load DINOv2 here on first use so /health still answers when the
    # model is missing or slow. Runs once, guarded by a lock.
    with _lock:
        if _state["dinov2_loaded"]:
            return
        # TODO: load facebook/dinov2-small via transformers + torch (CPU).
        _state["dinov2_loaded"] = True


def ensure_dinov2() -> bool:
    _load_dinov2()
    return _state["dinov2_loaded"]


@asynccontextmanager
async def lifespan(app: FastAPI):
    _check_ffmpeg()
    # DINOv2 stays unloaded here on purpose: it loads lazily on first use
    # so /health answers even before the first embedding request.
    yield


app = FastAPI(title="SpatialShelf scan worker", lifespan=lifespan)


@app.get("/health")
def health():
    return {"ok": True, "dinov2_loaded": _state["dinov2_loaded"]}
