"""End-to-end scan driver: sample video -> prepare -> segment -> crops.

Copies a sample video into SHARED_STORAGE_PATH/uploads, calls the three
scan endpoints in order, and builds a contact sheet of all crops.
Label-drawing style ported from spikes/sam_frames.py.

Run with the worker venv (worker running on localhost:8080), e.g.::

    worker\\.venv\\Scripts\\python.exe worker/scripts/run_scan.py
    worker\\.venv\\Scripts\\python.exe worker/scripts/run_scan.py --video samples/shelf_boxes.mp4 --phrases bottle,jar
"""

from __future__ import annotations

import argparse
import datetime
import json
import shutil
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

import cv2
import numpy as np

ROOT = Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(ROOT / "worker"))

from dotenv import load_dotenv  # noqa: E402

load_dotenv(ROOT / ".env")

import os  # noqa: E402

SHARED = Path(
    os.getenv("SHARED_STORAGE_PATH", str(ROOT / "storage-shared"))
).resolve()

CELL = 224
COLS = 6
LABEL_LINES = 2
REQUEST_TIMEOUT = 600


def post(base_url: str, path: str, payload: dict) -> dict:
    """POST JSON, return the decoded body or exit with the error."""
    request = urllib.request.Request(
        base_url + path,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=REQUEST_TIMEOUT) as reply:
            return json.loads(reply.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        body = error.read().decode("utf-8", "replace")
        print(f"{path} -> HTTP {error.code}: {body}")
        raise SystemExit(1)


def timed(label: str, func, *args):
    started = time.perf_counter()
    result = func(*args)
    elapsed = time.perf_counter() - started
    print(f"{label}: {elapsed:.1f}s")
    return result, elapsed


def contact_sheet(crops: list[dict], out_path: Path) -> None:
    """Grid of crop thumbnails labelled #seq id + row/order."""
    rows = max(1, (len(crops) + COLS - 1) // COLS)
    line_h = 22
    label_h = line_h * LABEL_LINES + 8
    sheet = np.full(
        (rows * (CELL + label_h), COLS * CELL, 3), 255, dtype="uint8"
    )
    for seq, crop in enumerate(crops, start=1):
        img = cv2.imread(str(SHARED / crop["crop_path"]), cv2.IMREAD_COLOR)
        if img is None:
            raise RuntimeError(f"Could not read {crop['crop_path']}")
        scale = CELL / max(img.shape[:2])
        thumb = cv2.resize(
            img,
            (round(img.shape[1] * scale), round(img.shape[0] * scale)),
            interpolation=cv2.INTER_AREA,
        )
        th, tw = thumb.shape[:2]
        top = ((seq - 1) // COLS) * (CELL + label_h)
        left = ((seq - 1) % COLS) * CELL
        oy, ox = (CELL - th) // 2, (CELL - tw) // 2
        sheet[top + oy : top + oy + th, left + ox : left + ox + tw] = thumb
        base = top + CELL
        cv2.putText(
            sheet, f"#{seq} {crop['id']}", (left + 6, base + 20),
            cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 0, 0), 1,
        )
        cv2.putText(
            sheet,
            f"row {crop['shelf_row']} ord {crop['shelf_order']}",
            (left + 6, base + 42),
            cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 0, 0), 1,
        )
    out_path.parent.mkdir(parents=True, exist_ok=True)
    cv2.imwrite(str(out_path), sheet, [int(cv2.IMWRITE_JPEG_QUALITY), 90])


def main() -> None:
    parser = argparse.ArgumentParser(description="End-to-end scan driver")
    parser.add_argument("--video", default="samples/shelf_bottles.mp4")
    parser.add_argument(
        "--phrases", default=None, help="Comma list; omit for auto pick_phrases"
    )
    parser.add_argument("--scan-id", default=None)
    parser.add_argument("--base-url", default="http://localhost:8080")
    args = parser.parse_args()

    video = Path(args.video)
    video = video if video.is_absolute() else ROOT / video
    if not video.is_file():
        print(f"Video not found: {video}")
        raise SystemExit(1)
    scan_id = args.scan_id or (
        f"{video.stem}_{datetime.datetime.now():%Y%m%d_%H%M%S}"
    )
    phrases = (
        [p.strip() for p in args.phrases.split(",") if p.strip()]
        if args.phrases
        else None
    )

    uploads = SHARED / "uploads"
    uploads.mkdir(parents=True, exist_ok=True)
    dest = uploads / f"{scan_id}{video.suffix}"
    shutil.copy(video, dest)
    video_path = dest.relative_to(SHARED).as_posix()
    print(f"upload: {video_path}")

    total = 0.0
    prepared, elapsed = timed(
        "prepare", post, args.base_url, "/scan/prepare",
        {"scan_id": scan_id, "video_path": video_path},
    )
    total += elapsed
    print(f"  clip {prepared['clip_width']}x{prepared['clip_height']} "
          f"{prepared['frame_count']}f {prepared['duration']}s, "
          f"{len(prepared['keyframes'])} keyframes")

    segment_body: dict = {"scan_id": scan_id}
    if phrases:
        segment_body["phrases"] = phrases
    segmented, elapsed = timed(
        "segment", post, args.base_url, "/scan/segment", segment_body
    )
    total += elapsed
    print(f"  phrases {segmented['phrases']} ({segmented['phrase_source']}), "
          f"{segmented['total_detections']} detections, "
          f"overlay {segmented['overlay']['status']}")

    cropped, elapsed = timed(
        "crops", post, args.base_url, "/scan/crops", {"scan_id": scan_id}
    )
    total += elapsed
    print(f"  {cropped['total_crops']} crops, {cropped['rows']} rows, "
          f"{cropped['skipped_edge']} edge-skipped")

    sheet_rel = f"scans/{scan_id}/contact_sheet.jpg"
    started = time.perf_counter()
    contact_sheet(cropped["crops"], SHARED / sheet_rel)
    elapsed = time.perf_counter() - started
    total += elapsed
    print(f"contact sheet: {elapsed:.1f}s")
    print(f"contact sheet: {sheet_rel}")
    print(f"total: {total:.1f}s")


if __name__ == "__main__":
    main()
