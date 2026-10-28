"""Pan-ordered shelf positions from keyframe alignment.

Neighbouring keyframes show the same physical items, so detections are
not merged here by position alone. Instead each detection gets a
pan-corrected shelf_x plus normalised centre/size, and same_spot.json
lists cross-keyframe pairs Laravel combines with embeddings (Phase 4).

Shift is accumulated over dense clip.mp4 frames (10 fps, 0.1 s apart)
and sampled at each keyframe's time: keyframes sit ~1.5 s apart, past
the ~1/3-frame motion where direct keyframe-to-keyframe correlation
still locks (verified on samples/shelf_bottles.mp4). Only the
horizontal component corrects positions; dy is recorded for diagnostics.

No spike covers this; written fresh against OpenCV phase correlation.

All paths are relative to SHARED_STORAGE_PATH unless absolute.
"""

from __future__ import annotations

import json
import math
import os
from collections.abc import Iterable, Iterator
from pathlib import Path

import cv2
import numpy as np

ROOT = Path(__file__).resolve().parent.parent.parent

SAME_SPOT_DX = 0.4  # |Δshelf_x| < 0.4 x mean box width
SAME_SPOT_DY = 0.5  # |Δcentre_y| < 0.5 x mean box height


def _shared_root() -> Path:
    return Path(os.getenv("SHARED_STORAGE_PATH", str(ROOT / "storage-shared")))


def _resolve(path: str | Path) -> Path:
    candidate = Path(path)
    return candidate if candidate.is_absolute() else _shared_root() / candidate


def _kf_index(path: Path) -> int | None:
    """Keyframe index from kf_<i>.jpg, else None."""
    stem = path.stem
    if not stem.startswith("kf_"):
        return None
    try:
        return int(stem.split("_", 1)[1])
    except ValueError:
        return None


def estimate_shift(gray_a: np.ndarray, gray_b: np.ndarray) -> tuple[float, float, float]:
    """Scene-content motion from frame A to B in pixels (+x = right).

    Returns (dx, dy, response). Non-finite output (blank frames) degrades
    to zero shift with zero response rather than killing the scan.
    """
    if gray_a.shape != gray_b.shape:
        raise ValueError(
            f"Frame shapes differ: {gray_a.shape} vs {gray_b.shape}"
        )
    height, width = gray_a.shape[:2]
    window = cv2.createHanningWindow((width, height), cv2.CV_32F)
    (dx, dy), response = cv2.phaseCorrelate(
        np.asarray(gray_a, dtype=np.float32),
        np.asarray(gray_b, dtype=np.float32),
        window,
    )
    if not all(np.isfinite([dx, dy, response])):
        return 0.0, 0.0, 0.0
    return float(dx), float(dy), float(response)


def accumulate_offsets(
    frames: Iterable[np.ndarray],
) -> tuple[list[float], list[dict], tuple[int, int]]:
    """Per-frame accumulated pan offsets in frame-width fractions.

    offsets[0] is 0; each step subtracts the measured content motion so a
    fixed item's shelf_x = offset + centre_x stays constant. Consumes the
    frames in one pass. Returns (offsets, per-step edges, (width, height)).
    """
    offsets = [0.0]
    edges: list[dict] = []
    prev: np.ndarray | None = None
    size: tuple[int, int] | None = None
    for frame in frames:
        gray = (
            cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            if frame.ndim == 3
            else frame
        )
        if prev is None:
            prev = gray
            size = (gray.shape[1], gray.shape[0])
            continue
        dx, dy, response = estimate_shift(prev, gray)
        offsets.append(offsets[-1] - dx / prev.shape[1])
        step = len(offsets) - 2
        edges.append(
            {
                "from": step,
                "to": step + 1,
                "dx_px": round(dx, 3),
                "dy_px": round(dy, 3),
                "response": round(response, 4),
            }
        )
        prev = gray
    if size is None:
        raise ValueError("No frames to align")
    return offsets, edges, size


def read_clip_frames(clip_path: Path) -> Iterator[np.ndarray]:
    """Decode a clip to grayscale frames in order, one at a time."""
    capture = cv2.VideoCapture(str(clip_path))
    if not capture.isOpened():
        raise FileNotFoundError(f"Could not open clip: {clip_path}")
    try:
        while True:
            ok, frame = capture.read()
            if not ok:
                break
            yield cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    finally:
        capture.release()


def keyframe_fractions(count: int) -> list[float]:
    """Keyframe times as duration fractions, mirroring /scan/prepare's
    5%..95% spread (exact to under one source frame)."""
    if count <= 1:
        return [0.5] * count
    return [0.05 + i * 0.90 / (count - 1) for i in range(count)]


def sample_offsets(
    clip_offsets: list[float], fractions: list[float]
) -> list[float]:
    """Linear interpolation of accumulated offsets at duration fractions."""
    count = len(clip_offsets)
    sampled = []
    for fraction in fractions:
        position = min(max(fraction, 0.0), 1.0) * count
        position = min(max(position, 0.0), count - 1)
        low = math.floor(position)
        high = min(low + 1, count - 1)
        blend = position - low
        sampled.append(
            clip_offsets[low] * (1 - blend) + clip_offsets[high] * blend
        )
    return sampled


def position_detections(
    detections: list[dict], sizes: dict[int, tuple[int, int]], offsets: dict[int, float]
) -> list[dict]:
    """Normalised centres plus shelf_x for each detection."""
    positioned = []
    for det in detections:
        kf = det["keyframe"]
        if kf not in sizes or kf not in offsets:
            raise ValueError(f"Detection {det.get('id')} has unknown keyframe {kf}")
        width, height = sizes[kf]
        left, top, right, bottom = det["box"]
        cx = (left + right) / 2 / width
        cy = (top + bottom) / 2 / height
        positioned.append(
            {
                "id": det["id"],
                "keyframe": kf,
                "phrase": det.get("phrase"),
                "cx": round(cx, 6),
                "cy": round(cy, 6),
                "w": round((right - left) / width, 6),
                "h": round((bottom - top) / height, 6),
                "shelf_x": round(offsets[kf] + cx, 6),
            }
        )
    return positioned


def find_same_spot(positioned: list[dict], kf_order: list[int]) -> list[dict]:
    """Candidate same-item pairs across adjacent keyframes only."""
    by_kf: dict[int, list[dict]] = {}
    for det in positioned:
        by_kf.setdefault(det["keyframe"], []).append(det)
    for dets in by_kf.values():
        dets.sort(key=lambda d: d["id"])
    pairs = []
    for first, second in zip(kf_order, kf_order[1:]):
        for a in by_kf.get(first, []):
            for b in by_kf.get(second, []):
                dx = abs(a["shelf_x"] - b["shelf_x"])
                dy = abs(a["cy"] - b["cy"])
                if dx < SAME_SPOT_DX * (a["w"] + b["w"]) / 2 and dy < SAME_SPOT_DY * (
                    a["h"] + b["h"]
                ) / 2:
                    pairs.append(
                        {
                            "a": a["id"],
                            "b": b["id"],
                            "dx": round(dx, 4),
                            "dy": round(dy, 4),
                        }
                    )
    pairs.sort(key=lambda p: (p["a"], p["b"]))
    return pairs


def compute_positions(shared_root: Path, scan_id: str) -> dict:
    """Write positions.json and same_spot.json for one segmented scan."""
    root = Path(shared_root).resolve()
    scan_dir = root / "scans" / scan_id
    if not scan_dir.is_dir():
        raise FileNotFoundError(f"Scan not found: {scan_id}")
    detections_path = scan_dir / "detections.json"
    if not detections_path.is_file():
        raise FileNotFoundError(
            f"No detections for scan {scan_id} (run /scan/segment first)"
        )
    kf_paths = sorted((scan_dir / "keyframes").glob("kf_*.jpg"))
    if not kf_paths:
        raise FileNotFoundError(
            f"No keyframes for scan {scan_id} (run /scan/prepare first)"
        )
    clip_path = scan_dir / "clip.mp4"
    if not clip_path.is_file():
        raise FileNotFoundError(
            f"No clip.mp4 for scan {scan_id} (run /scan/prepare first)"
        )

    indexed = []
    for order, path in enumerate(kf_paths):
        index = _kf_index(path)
        indexed.append((index if index is not None else order, path))
    indexed.sort(key=lambda item: item[0])
    kf_order = [index for index, _ in indexed]

    clip_offsets, step_edges, clip_size = accumulate_offsets(
        read_clip_frames(clip_path)
    )
    fractions = keyframe_fractions(len(indexed))
    sampled = sample_offsets(clip_offsets, fractions)
    offsets = dict(zip(kf_order, sampled))

    width, _ = clip_size
    edges = []
    positions = [
        min(max(f, 0.0), 1.0) * len(clip_offsets) for f in fractions
    ]
    for pos, (frm, to) in enumerate(zip(kf_order, kf_order[1:])):
        first = max(0, math.floor(positions[pos]))
        last = min(len(step_edges) - 1, math.ceil(positions[pos + 1]) - 1)
        steps = step_edges[first : last + 1] if last >= first else []
        edges.append(
            {
                "from": frm,
                "to": to,
                "clip_from": first,
                "clip_to": last + 1,
                "dx_px": round(-(offsets[to] - offsets[frm]) * width, 3),
                "min_response": (
                    round(min(s["response"] for s in steps), 4)
                    if steps
                    else None
                ),
            }
        )

    sizes: dict[int, tuple[int, int]] = {}
    kf_entries = []
    for (index, path), fraction in zip(indexed, fractions):
        gray = cv2.imread(str(path), cv2.IMREAD_GRAYSCALE)
        if gray is None:
            raise FileNotFoundError(f"Could not read keyframe: {path}")
        height, width = gray.shape[:2]
        if (width, height) != clip_size:
            raise ValueError(
                f"Keyframe {path.name} is {width}x{height} "
                f"but clip.mp4 is {clip_size[0]}x{clip_size[1]}"
            )
        sizes[index] = (width, height)
        kf_entries.append(
            {
                "index": index,
                "path": path.relative_to(root).as_posix(),
                "width": width,
                "height": height,
                "fraction": round(fraction, 4),
                "offset": round(offsets[index], 6),
            }
        )

    detections = json.loads(detections_path.read_text(encoding="utf-8"))[
        "detections"
    ]
    positioned = position_detections(detections, sizes, offsets)
    pairs = find_same_spot(positioned, kf_order)

    positions_path = scan_dir / "positions.json"
    positions_path.write_text(
        json.dumps(
            {
                "scan_id": scan_id,
                "keyframes": kf_entries,
                "edges": edges,
                "clip_frames": len(clip_offsets),
                "total_detections": len(positioned),
                "detections": positioned,
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    same_spot_path = scan_dir / "same_spot.json"
    same_spot_path.write_text(
        json.dumps(
            {"scan_id": scan_id, "total_pairs": len(pairs), "pairs": pairs},
            indent=2,
        ),
        encoding="utf-8",
    )

    def rel(path: Path) -> str:
        return path.relative_to(root).as_posix()

    return {
        "scan_id": scan_id,
        "keyframes": kf_order,
        "offsets": [round(offsets[i], 6) for i in kf_order],
        "detection_count": len(positioned),
        "pair_count": len(pairs),
        "positions_path": rel(positions_path),
        "same_spot_path": rel(same_spot_path),
    }
