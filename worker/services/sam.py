"""SAM 3.1 segmentation: image-mode detection plus one overlay video request.

Ported from spikes/sam_frames.py (image requests, parsing, confidence
filter) and spikes/pick_phrases.py (phrase fan-out, box merge). Every
raw model output lands in scans/<scan_id>/sam/ for debugging.

All paths are relative to SHARED_STORAGE_PATH unless absolute.
"""

from __future__ import annotations

import asyncio
import base64
import json
import os
import re
import shutil
import time
from pathlib import Path

from dotenv import load_dotenv
from meta_sam_parser import (
    SegmentationBoxRecord,
    image_segmentation_format,
    parse_responses_stream,
    video_segmentation_format,
)
from openai import APIStatusError, AsyncOpenAI

from services.phrases import pick_phrases

ROOT = Path(__file__).resolve().parent.parent.parent
load_dotenv(ROOT / ".env")

SAM_MODEL = "sam-3.1"
METADATA = {"include_confidence": "true", "mask_encoding": "one_bit"}
MAX_CONCURRENCY = 8
MAX_PHRASES = 4
MIN_CONFIDENCE = 0.6
IOU_THRESHOLD = 0.6
CONTAINMENT_THRESHOLD = 0.7
RETRY_DELAY = 2.0
BASELINE_PHRASES = ["jar", "box", "packet", "bottle"]
RETRY_MIN_DETECTIONS = 10


class SamError(Exception):
    """SAM request failure. The API maps this to HTTP 502."""


_async_client: AsyncOpenAI | None = None


def _get_client() -> AsyncOpenAI:
    """Shared async client; one is enough for the worker's lifetime."""
    global _async_client
    if _async_client is None:
        api_key = os.getenv("MODEL_API_KEY")
        if not api_key:
            raise RuntimeError("MODEL_API_KEY is missing in the root .env")
        base_url = os.getenv("META_BASE_URL", "https://api.meta.ai/v1")
        _async_client = AsyncOpenAI(api_key=api_key, base_url=base_url)
    return _async_client


async def close_client() -> None:
    global _async_client
    if _async_client is not None:
        await _async_client.close()
        _async_client = None


def slug(text: str) -> str:
    """Lowercase slug for raw-output filenames."""
    return re.sub(r"[^a-z0-9]+", "_", text.lower()).strip("_") or "phrase"


def _kf_index(path: Path) -> int | None:
    """Keyframe index from kf_<i>.jpg, else None."""
    stem = path.stem
    if not stem.startswith("kf_"):
        return None
    try:
        return int(stem.split("_", 1)[1])
    except ValueError:
        return None


def _data_url(jpg_path: Path) -> str:
    return "data:image/jpeg;base64," + base64.b64encode(
        jpg_path.read_bytes()
    ).decode("ascii")


def box_iou(first: list, second: list) -> float:
    ix0, iy0 = max(first[0], second[0]), max(first[1], second[1])
    ix1, iy1 = min(first[2], second[2]), min(first[3], second[3])
    inter_w, inter_h = ix1 - ix0, iy1 - iy0
    if inter_w <= 0 or inter_h <= 0:
        return 0.0
    inter = inter_w * inter_h
    union = (
        (first[2] - first[0]) * (first[3] - first[1])
        + (second[2] - second[0]) * (second[3] - second[1])
        - inter
    )
    return inter / union if union > 0 else 0.0


def box_containment(first: list, second: list) -> float:
    """Intersection over the smaller box: 1.0 when one sits fully inside."""
    ix0, iy0 = max(first[0], second[0]), max(first[1], second[1])
    ix1, iy1 = min(first[2], second[2]), min(first[3], second[3])
    inter_w, inter_h = ix1 - ix0, iy1 - iy0
    if inter_w <= 0 or inter_h <= 0:
        return 0.0
    areas = [
        (first[2] - first[0]) * (first[3] - first[1]),
        (second[2] - second[0]) * (second[3] - second[1]),
    ]
    smallest = min(areas)
    return (inter_w * inter_h) / smallest if smallest > 0 else 0.0


def merge_detections(detections: list[dict]) -> list[dict]:
    """Greedy dedup: highest confidence wins any IoU > 0.6 or
    >= 70% containment cluster."""
    ordered = sorted(
        detections,
        key=lambda d: (-d["confidence"], d["phrase"], d["box"]),
    )
    kept: list[dict] = []
    for candidate in ordered:
        if all(
            box_iou(candidate["box"], survivor["box"]) <= IOU_THRESHOLD
            and box_containment(candidate["box"], survivor["box"])
            < CONTAINMENT_THRESHOLD
            for survivor in kept
        ):
            kept.append(candidate)
    return kept


async def _parse_stream(stream, fmt):
    """Parse a streamed Responses result with the given SAM format."""

    async def event_source():
        async for event in stream:
            yield event

    parsed = parse_responses_stream(event_source(), fmt)
    return await parsed.final_result()


async def _image_result(client: AsyncOpenAI, data_url: str, phrase: str):
    """One streamed sam-3.1 IMAGE request; return (result, seconds)."""
    started = time.perf_counter()
    stream = await client.responses.create(
        model=SAM_MODEL,
        stream=True,
        input=[
            {
                "role": "user",
                "content": [
                    {"type": "input_text", "text": phrase},
                    {"type": "input_image", "image_url": data_url},
                ],
            }
        ],
        metadata=METADATA,
    )
    result = await _parse_stream(stream, image_segmentation_format())
    return result, time.perf_counter() - started


async def _video_result(client: AsyncOpenAI, clip_path: Path, phrase: str):
    """Files API upload plus one streamed sam-3.1 video request."""
    data = await asyncio.to_thread(clip_path.read_bytes)
    uploaded = await client.files.create(
        file=("clip.mp4", data), purpose="user_data"
    )
    started = time.perf_counter()
    stream = await client.responses.create(
        model=SAM_MODEL,
        stream=True,
        input=[
            {
                "role": "user",
                "content": [
                    {"type": "input_text", "text": phrase},
                    {"type": "input_video", "file_id": uploaded.id},
                ],
            }
        ],
        metadata=METADATA,
    )
    result = await _parse_stream(stream, video_segmentation_format())
    return result, time.perf_counter() - started


async def _with_retry(call):
    """Run once; retry a single time on 429/5xx."""
    try:
        return await call()
    except APIStatusError as error:
        status = error.status_code
        if status is None or (status != 429 and status < 500):
            raise
        await asyncio.sleep(RETRY_DELAY)
        return await call()


PRODUCT_PHRASE = "product"


def largest_box(boxes: list[dict]) -> dict | None:
    """Box dict with the biggest area, or None when empty."""
    best = None
    best_area = 0
    for box in boxes:
        left, top, right, bottom = box["box"]
        area = (right - left) * (bottom - top)
        if area > best_area:
            best_area = area
            best = box
    return best


def padded_box(
    box: list, img_width: int, img_height: int, pad: float = 0.25
) -> list[int]:
    """Box grown by the pad fraction per side, clamped to the image."""
    left, top, right, bottom = box
    pad_x = int(round((right - left) * pad))
    pad_y = int(round((bottom - top) * pad))
    return [
        max(0, left - pad_x),
        max(0, top - pad_y),
        min(img_width, right + pad_x),
        min(img_height, bottom + pad_y),
    ]


async def detect_largest_box(image_path: Path) -> dict | None:
    """Biggest sam-3.1 box for "product" at confidence >= 0.6, or None.

    One-shot call with its own client, outside the scan pipeline; used
    to crop single-product order photos before embedding.
    """
    api_key = os.getenv("MODEL_API_KEY")
    if not api_key:
        raise SamError("MODEL_API_KEY is missing in the root .env")
    base_url = os.getenv("META_BASE_URL", "https://api.meta.ai/v1")
    client = AsyncOpenAI(api_key=api_key, base_url=base_url)
    try:
        data_url = await asyncio.to_thread(_data_url, image_path)
        try:
            result, _ = await _with_retry(
                lambda: _image_result(client, data_url, PRODUCT_PHRASE)
            )
        except Exception as error:
            raise SamError(
                f"sam-3.1 product detect failed: {type(error).__name__}: {error}"
            ) from error
    finally:
        await client.close()
    boxes = []
    for record in result.records:
        if not isinstance(record, SegmentationBoxRecord):
            continue
        confidence = (
            record.confidence if record.confidence is not None else 0.0
        )
        if confidence < MIN_CONFIDENCE:
            continue
        left, top = int(round(record.left)), int(round(record.top))
        right, bottom = int(round(record.right)), int(round(record.bottom))
        if right <= left or bottom <= top:
            continue
        boxes.append(
            {"box": [left, top, right, bottom], "confidence": confidence}
        )
    return largest_box(boxes)


async def _image_pair(
    client: AsyncOpenAI,
    sem: asyncio.Semaphore,
    kf_index: int,
    phrase: str,
    data_url: str,
    sam_dir: Path,
) -> dict:
    """One keyframe x phrase SAM image request; never raises."""
    stem = f"kf_{kf_index}_{slug(phrase)}"
    outcome: dict = {
        "keyframe": kf_index,
        "phrase": phrase,
        "seconds": 0.0,
        "boxes": [],
        "error": None,
    }
    try:
        async with sem:
            result, seconds = await _with_retry(
                lambda: _image_result(client, data_url, phrase)
            )
        outcome["seconds"] = round(seconds, 1)
        await asyncio.to_thread(
            (sam_dir / f"{stem}.txt").write_text,
            result.raw_output,
            "utf-8",
        )
        for record in result.records:
            if not isinstance(record, SegmentationBoxRecord):
                continue
            confidence = (
                record.confidence if record.confidence is not None else 0.0
            )
            if confidence < MIN_CONFIDENCE:
                continue
            left, top = int(round(record.left)), int(round(record.top))
            right, bottom = int(round(record.right)), int(round(record.bottom))
            if right <= left or bottom <= top:
                continue
            outcome["boxes"].append(
                {
                    "box": [left, top, right, bottom],
                    "confidence": round(confidence, 4),
                    "phrase": phrase,
                }
            )
    except Exception as error:
        outcome["error"] = f"{type(error).__name__}: {error}"
        try:
            await asyncio.to_thread(
                (sam_dir / f"{stem}.error.json").write_text,
                json.dumps(
                    {
                        "keyframe": kf_index,
                        "phrase": phrase,
                        "error": outcome["error"],
                    },
                    indent=2,
                ),
                "utf-8",
            )
        except OSError:
            pass
    return outcome


def _finish_video(
    task: asyncio.Task,
    overlay: dict,
    overlay_path: Path,
    status_path: Path,
    root: Path,
) -> None:
    """Record the overlay video request's final status on disk."""
    try:
        result, seconds = task.result()
    except asyncio.CancelledError:
        overlay["status"] = "failed"
        overlay["error"] = "cancelled"
    except Exception as error:
        overlay["status"] = "failed"
        overlay["error"] = f"{type(error).__name__}: {error}"
    else:
        try:
            overlay_path.write_text(result.raw_output, encoding="utf-8")
        except OSError as error:
            overlay["status"] = "failed"
            overlay["error"] = f"OSError: {error}"
        else:
            overlay["status"] = "done"
            overlay["path"] = overlay_path.relative_to(root).as_posix()
            overlay["seconds"] = round(seconds, 1)
    _write_status(status_path, overlay)


def _write_status(status_path: Path, overlay: dict) -> None:
    try:
        status_path.write_text(json.dumps(overlay, indent=2), encoding="utf-8")
    except OSError:
        pass


def _clean_phrases(phrases: list[str] | None) -> list[str]:
    """Strip, drop empties, dedupe case-insensitively, cap at 4."""
    seen: set[str] = set()
    clean: list[str] = []
    for phrase in phrases or []:
        text = phrase.strip()
        if text and text.lower() not in seen:
            seen.add(text.lower())
            clean.append(text)
    return clean[:MAX_PHRASES]


def with_baseline(clean: list[str]) -> list[str]:
    """Picked phrases plus any missing baseline container types."""
    seen = {phrase.lower() for phrase in clean}
    return clean + [b for b in BASELINE_PHRASES if b.lower() not in seen]


def _indexed(kf_paths: list[Path]) -> list[tuple[int, Path]]:
    """(index, path) pairs sorted by keyframe index."""
    indexed = []
    for order, path in enumerate(kf_paths):
        index = _kf_index(path)
        indexed.append((index if index is not None else order, path))
    indexed.sort(key=lambda item: item[0])
    return indexed


def _merge_counts(raw_by_kf: dict[int, list], indexed) -> dict[int, int]:
    return {
        index: len(merge_detections(raw_by_kf.get(index, [])))
        for index, _ in indexed
    }


def _assemble(indexed, raw_by_kf: dict[int, list]) -> tuple[list[dict], dict[int, int]]:
    """Merge boxes per keyframe; assign kf<i>_<n> detection ids."""
    detections = []
    counts: dict[int, int] = {}
    for index, _ in indexed:
        merged = merge_detections(raw_by_kf.get(index, []))
        counts[index] = len(merged)
        for number, det in enumerate(merged):
            detections.append(
                {
                    "id": f"kf{index}_{number}",
                    "keyframe": index,
                    "box": det["box"],
                    "confidence": det["confidence"],
                    "phrase": det["phrase"],
                }
            )
    return detections, counts


async def _retry_expansion(
    client: AsyncOpenAI,
    sem: asyncio.Semaphore,
    scan_dir: Path,
    sam_dir: Path,
    indexed,
    raw_by_kf: dict[int, list],
    first_total: int,
) -> tuple | None:
    """Expand keyframes/ to all candidates; run baseline on each.

    Pass-1 boxes remap through candidates/kept.json (deduped kf position
    to candidate index) and union with the retry boxes. Returns
    (indexed, raw_by_kf, retry_info, outcomes), or None when the scan
    has no expandable candidates. Never raises: a failed retry leaves
    the pass-1 results untouched.
    """
    try:
        cand_dir = scan_dir / "candidates"
        full_cand_dir = scan_dir / "candidates_full"
        kept_path = cand_dir / "kept.json"
        if not kept_path.is_file():
            return None
        kept = json.loads(kept_path.read_text(encoding="utf-8"))["kept"]
        cand_paths = sorted(cand_dir.glob("kf_*.jpg"))
        if len(cand_paths) <= len(indexed):
            return None
        if len(kept) != len(indexed):
            print(
                f"[sam] retry skipped: kept.json lists {len(kept)} "
                f"frames for {len(indexed)} keyframes"
            )
            return None
        full_names = (
            {p.name for p in full_cand_dir.glob("kf_*.jpg")}
            if full_cand_dir.is_dir()
            else set()
        )
        if any(p.name not in full_names for p in cand_paths):
            print("[sam] retry skipped: candidates_full is incomplete")
            return None
        started = time.perf_counter()
        kf_dir = scan_dir / "keyframes"
        full_dir = scan_dir / "keyframes_full"
        for stale in kf_dir.glob("kf_*.jpg"):
            stale.unlink()
        for stale in full_dir.glob("kf_*.jpg"):
            stale.unlink()
        for path in cand_paths:
            shutil.copy(path, kf_dir / path.name)
            shutil.copy(full_cand_dir / path.name, full_dir / path.name)
        expanded = _indexed(sorted(kf_dir.glob("kf_*.jpg")))
        remapped: dict[int, list] = {}
        for pos, (old_index, _) in enumerate(indexed):
            remapped.setdefault(kept[pos], []).extend(
                raw_by_kf.get(old_index, [])
            )
        encoded = await asyncio.gather(
            *(asyncio.to_thread(_data_url, path) for _, path in expanded)
        )
        outcomes = await asyncio.gather(
            *[
                _image_pair(client, sem, index, phrase, url, sam_dir)
                for (index, _), url in zip(expanded, encoded)
                for phrase in BASELINE_PHRASES
            ]
        )
        for outcome in outcomes:
            remapped.setdefault(outcome["keyframe"], []).extend(
                outcome["boxes"]
            )
        total_after = sum(_merge_counts(remapped, expanded).values())
        retry_info = {
            "reason": f"only {first_total} detections in pass 1",
            "candidate_count": len(expanded),
            "kept_count": len(indexed),
            "retry_seconds": round(time.perf_counter() - started, 1),
            "total_after": total_after,
        }
        print(
            f"[sam] retry: {len(indexed)} -> {len(expanded)} frames, "
            f"{first_total} -> {total_after} detections"
        )
        return expanded, remapped, retry_info, outcomes
    except Exception as error:
        print(f"[sam] retry failed, keeping pass-1 results: {error}")
        return None


async def segment_scan(
    shared_root: Path, scan_id: str, phrases: list[str] | None = None
) -> dict:
    """Detect products on every keyframe x phrase; fire overlay video too."""
    total_started = time.perf_counter()
    root = Path(shared_root).resolve()
    scan_dir = root / "scans" / scan_id
    if not scan_dir.is_dir():
        raise FileNotFoundError(
            f"Scan not found: {scan_id} (run /scan/prepare first)"
        )
    kf_paths = sorted((scan_dir / "keyframes").glob("kf_*.jpg"))
    if not kf_paths:
        raise FileNotFoundError(
            f"No keyframes for scan {scan_id} (run /scan/prepare first)"
        )
    indexed = _indexed(kf_paths)

    client = _get_client()
    sam_dir = scan_dir / "sam"
    sam_dir.mkdir(parents=True, exist_ok=True)

    clean = _clean_phrases(phrases)
    pick_seconds = None
    if clean:
        phrase_source = "given"
    else:
        phrase_source = "picked"
        middle = indexed[len(indexed) // 2][1]
        started = time.perf_counter()
        clean = await asyncio.to_thread(pick_phrases, middle, scan_dir)
        pick_seconds = round(time.perf_counter() - started, 1)
    phrases = with_baseline(clean)

    encoded = await asyncio.gather(
        *(asyncio.to_thread(_data_url, path) for _, path in indexed)
    )

    overlay_slug = slug(phrases[0])
    overlay_path = sam_dir / f"overlay_{overlay_slug}.txt"
    status_path = sam_dir / f"overlay_{overlay_slug}.status.json"
    overlay: dict = {
        "phrase": phrases[0],
        "status": "skipped",
        "path": None,
        "seconds": None,
        "error": None,
    }
    clip_path = scan_dir / "clip.mp4"
    image_started = time.perf_counter()
    video_task = None
    if clip_path.is_file():
        video_task = asyncio.create_task(
            _with_retry(lambda: _video_result(client, clip_path, phrases[0]))
        )
    sem = asyncio.Semaphore(MAX_CONCURRENCY)
    outcomes = await asyncio.gather(
        *[
            _image_pair(client, sem, index, phrase, url, sam_dir)
            for (index, _), url in zip(indexed, encoded)
            for phrase in phrases
        ]
    )
    image_seconds = round(time.perf_counter() - image_started, 1)

    if video_task is None:
        overlay["error"] = "clip.mp4 not found; run /scan/prepare first"
        _write_status(status_path, overlay)
    elif video_task.done():
        _finish_video(video_task, overlay, overlay_path, status_path, root)
    else:
        overlay["status"] = "running"

        def _later(task: asyncio.Task) -> None:
            final = {
                "phrase": phrases[0],
                "status": "running",
                "path": None,
                "seconds": None,
                "error": None,
            }
            _finish_video(task, final, overlay_path, status_path, root)
            print(f"[sam] overlay {overlay_slug}: {final['status']}")

        video_task.add_done_callback(_later)

    raw_by_kf: dict[int, list] = {}
    for outcome in outcomes:
        raw_by_kf.setdefault(outcome["keyframe"], []).extend(outcome["boxes"])
    first_total = sum(_merge_counts(raw_by_kf, indexed).values())

    retried = False
    retry_info = None
    if first_total < RETRY_MIN_DETECTIONS:
        retry = await _retry_expansion(
            client, sem, scan_dir, sam_dir, indexed, raw_by_kf, first_total
        )
        if retry is not None:
            indexed, raw_by_kf, retry_info, retry_outcomes = retry
            outcomes = outcomes + list(retry_outcomes)
            retried = True

    detections, counts = _assemble(indexed, raw_by_kf)
    total = len(detections)
    await asyncio.to_thread(
        (scan_dir / "detections.json").write_text,
        json.dumps(
            {
                "scan_id": scan_id,
                "phrases": phrases,
                "total": total,
                "retried": retried,
                "retry": retry_info,
                "detections": detections,
            },
            indent=2,
        ),
        "utf-8",
    )

    errors = [
        {
            "keyframe": outcome["keyframe"],
            "phrase": outcome["phrase"],
            "error": outcome["error"],
        }
        for outcome in outcomes
        if outcome["error"]
    ]
    if outcomes and len(errors) == len(outcomes):
        raise SamError(
            f"All {len(outcomes)} SAM image requests failed: "
            f"{errors[0]['error']}"
        )

    def rel(path: Path) -> str:
        return path.relative_to(root).as_posix()

    return {
        "scan_id": scan_id,
        "phrases": phrases,
        "picked_phrases": clean,
        "phrase_source": phrase_source,
        "keyframe_indices": [index for index, _ in indexed],
        "counts_per_keyframe": counts,
        "total_detections": total,
        "detections_path": rel(scan_dir / "detections.json"),
        "errors": errors,
        "overlay": overlay,
        "retried": retried,
        "retry": retry_info,
        "timings": {
            "total_seconds": round(time.perf_counter() - total_started, 1),
            "pick_phrases_seconds": pick_seconds,
            "image_seconds": image_seconds,
            "overlay_seconds": overlay["seconds"],
        },
    }
