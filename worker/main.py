"""SpatialShelf scan worker.

Pixel work lives here: video prep, SAM 3.1 calls, mask crops, embeddings.
The Laravel app owns everything users touch; models never write to the DB.

Config comes from the repo-root .env (MODEL_API_KEY, SHARED_STORAGE_PATH).
Every endpoint takes paths relative to SHARED_STORAGE_PATH.
"""

import os
import re
import shutil
import threading
from contextlib import asynccontextmanager
from pathlib import Path

from dotenv import load_dotenv
from fastapi import FastAPI, HTTPException
from PIL import UnidentifiedImageError
from pydantic import BaseModel, Field

from services.crops import cut_crops
from services.embed import EMBED_DIM, embed, ensure_model
from services.sam import SamError, close_client as close_sam_client, segment_scan
from services.video import VideoError, prepare_scan

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
    # Loads once, guarded by a lock; called at startup so the first
    # embedding request never pays the download + init cost.
    with _lock:
        if _state["dinov2_loaded"]:
            return
        ensure_model()
        _state["dinov2_loaded"] = True


def ensure_dinov2() -> bool:
    _load_dinov2()
    return _state["dinov2_loaded"]


@asynccontextmanager
async def lifespan(app: FastAPI):
    _check_ffmpeg()
    ensure_dinov2()
    yield
    await close_sam_client()


app = FastAPI(title="SpatialShelf scan worker", lifespan=lifespan)


@app.get("/health")
def health():
    return {"ok": True, "dinov2_loaded": _state["dinov2_loaded"]}


SCAN_ID_RE = re.compile(r"[A-Za-z0-9_-]{1,64}")


class PrepareRequest(BaseModel):
    scan_id: str
    video_path: str = Field(min_length=1)


class PrepareResponse(BaseModel):
    scan_id: str
    clip_path: str
    clip_width: int
    clip_height: int
    full_width: int
    full_height: int
    frame_count: int
    duration: float
    fps: int
    rotation: int
    source_frame_count: int
    source_frame_indices: list[int]
    dropped_views: int
    candidate_count: int
    keyframes: list[str]
    keyframes_full: list[str]


@app.post("/scan/prepare", response_model=PrepareResponse)
def scan_prepare(body: PrepareRequest):
    if not SCAN_ID_RE.fullmatch(body.scan_id):
        raise HTTPException(
            status_code=400,
            detail="scan_id may only contain letters, numbers, dash and "
            "underscore (max 64 chars).",
        )
    try:
        return prepare_scan(SHARED_STORAGE_PATH, body.scan_id, body.video_path)
    except FileNotFoundError as error:
        raise HTTPException(status_code=404, detail=str(error))
    except VideoError as error:
        raise HTTPException(status_code=422, detail=str(error))
    except ValueError as error:
        raise HTTPException(status_code=400, detail=str(error))


class SegmentRequest(BaseModel):
    scan_id: str
    phrases: list[str] | None = None


class OverlayResult(BaseModel):
    phrase: str
    status: str
    path: str | None = None
    seconds: float | None = None
    error: str | None = None


class SegmentPairError(BaseModel):
    keyframe: int
    phrase: str
    error: str


class SegmentTimings(BaseModel):
    total_seconds: float
    pick_phrases_seconds: float | None = None
    image_seconds: float
    overlay_seconds: float | None = None


class RetryInfo(BaseModel):
    reason: str
    candidate_count: int
    kept_count: int
    retry_seconds: float
    total_after: int


class SegmentResponse(BaseModel):
    scan_id: str
    phrases: list[str]
    phrase_source: str
    keyframe_indices: list[int]
    counts_per_keyframe: dict[int, int]
    total_detections: int
    detections_path: str
    errors: list[SegmentPairError] = []
    overlay: OverlayResult
    retried: bool
    retry: RetryInfo | None = None
    timings: SegmentTimings


@app.post("/scan/segment", response_model=SegmentResponse)
async def scan_segment(body: SegmentRequest):
    if not SCAN_ID_RE.fullmatch(body.scan_id):
        raise HTTPException(
            status_code=400,
            detail="scan_id may only contain letters, numbers, dash and "
            "underscore (max 64 chars).",
        )
    try:
        return await segment_scan(SHARED_STORAGE_PATH, body.scan_id, body.phrases)
    except FileNotFoundError as error:
        raise HTTPException(status_code=404, detail=str(error))
    except SamError as error:
        raise HTTPException(status_code=502, detail=str(error))


class CropsRequest(BaseModel):
    scan_id: str


class CropItem(BaseModel):
    id: str
    keyframe: int
    crop_path: str
    crop_width: int
    crop_height: int
    box: list[int]
    confidence: float
    phrase: str
    shelf_row: int
    shelf_order: int
    embedding: list[float]


class CropsResponse(BaseModel):
    scan_id: str
    crops: list[CropItem]
    total_crops: int
    skipped_edge: int
    rows: int


@app.post("/scan/crops", response_model=CropsResponse)
def scan_crops(body: CropsRequest):
    if not SCAN_ID_RE.fullmatch(body.scan_id):
        raise HTTPException(
            status_code=400,
            detail="scan_id may only contain letters, numbers, dash and "
            "underscore (max 64 chars).",
        )
    try:
        return cut_crops(SHARED_STORAGE_PATH, body.scan_id)
    except FileNotFoundError as error:
        raise HTTPException(status_code=404, detail=str(error))
    except ValueError as error:
        raise HTTPException(status_code=400, detail=str(error))


class EmbedRequest(BaseModel):
    image_path: str = Field(min_length=1)


class EmbedResponse(BaseModel):
    image_path: str
    embedding: list[float]
    dim: int


@app.post("/embed", response_model=EmbedResponse)
def post_embed(body: EmbedRequest):
    root = SHARED_STORAGE_PATH.resolve()
    src = (root / body.image_path).resolve()
    if src != root and root not in src.parents:
        raise HTTPException(
            status_code=400,
            detail="image_path must stay inside SHARED_STORAGE_PATH",
        )
    if not src.is_file():
        raise HTTPException(
            status_code=404, detail=f"Image not found: {body.image_path}"
        )
    try:
        vector = embed(src)
    except (UnidentifiedImageError, OSError) as error:
        raise HTTPException(
            status_code=422, detail=f"Not a readable image: {body.image_path}"
        )
    return {"image_path": body.image_path, "embedding": vector, "dim": EMBED_DIM}
