"""Product crops cut from full-res keyframes (spike variant B).

Ported from spikes/read_crops.py (scale_box, make_crop_b): each 1280 px
detection box is scaled to keyframes_full, padded 25% per side, clamped
to the frame, and upscaled 2x with Lanczos while its shorter side is
under 300 px. Unlike variant A, nothing is painted white. Saved crops
are capped at 1024 px long side (JPEG q90).

Edge-truncated detections are skipped when same_spot.json pairs them
with a clean view from another keyframe; rows come from the largest
vertical gaps in box-centre y (max 8).

All paths are relative to SHARED_STORAGE_PATH unless absolute.
"""

from __future__ import annotations

import json
import statistics
from pathlib import Path

import cv2

from services.embed import BATCH_SIZE, embed_batch
from services.positions import compute_positions

PAD_FRACTION = 0.25
UPSCALE_MIN_SIDE = 300
MAX_LONG_SIDE = 1024
JPEG_QUALITY = 90
MAX_ROWS = 8


def scale_box(box: list | tuple, sx: float, sy: float) -> tuple[int, int, int, int]:
    """Detection box (1280 px space) scaled into the full-res keyframe."""
    left, top, right, bottom = box
    return (
        int(left * sx),
        int(top * sy),
        int(right * sx),
        int(bottom * sy),
    )


def touches_edge(box: list | tuple, width: int, height: int) -> bool:
    """True when the box touches the frame edge (likely truncated)."""
    left, top, right, bottom = box
    return left <= 0 or top <= 0 or right >= width or bottom >= height


def make_crop(img, box: tuple[int, int, int, int]):
    """Variant B crop: 25% padding, real background, 2x if small.

    Returns the crop array capped at 1024 px long side.
    """
    frame_h, frame_w = img.shape[:2]
    left, top, right, bottom = box
    box_w, box_h = right - left, bottom - top
    pad_x, pad_y = int(PAD_FRACTION * box_w), int(PAD_FRACTION * box_h)
    left = max(0, left - pad_x)
    top = max(0, top - pad_y)
    right = min(frame_w, right + pad_x)
    bottom = min(frame_h, bottom + pad_y)
    crop = img[top:bottom, left:right].copy()
    h, w = crop.shape[:2]
    if min(h, w) < UPSCALE_MIN_SIDE:
        crop = cv2.resize(
            crop, (w * 2, h * 2), interpolation=cv2.INTER_LANCZOS4
        )
        h, w = crop.shape[:2]
    long_side = max(h, w)
    if long_side > MAX_LONG_SIDE:
        scale = MAX_LONG_SIDE / long_side
        crop = cv2.resize(
            crop,
            (round(w * scale), round(h * scale)),
            interpolation=cv2.INTER_AREA,
        )
    return crop


def select_detections(
    detections: list[dict], sizes: dict[int, tuple[int, int]], pairs: list[dict]
) -> tuple[list[dict], list[str]]:
    """Drop edge-truncated detections that have a clean paired view.

    A truncated detection is skipped only when same_spot pairs it with a
    non-truncated one; when every view is truncated the views are kept so
    the item is not lost entirely.
    """
    truncated = {
        det["id"]
        for det in detections
        if touches_edge(det["box"], *sizes[det["keyframe"]])
    }
    partners: dict[str, set[str]] = {}
    for pair in pairs:
        partners.setdefault(pair["a"], set()).add(pair["b"])
        partners.setdefault(pair["b"], set()).add(pair["a"])
    kept, skipped = [], []
    for det in detections:
        if det["id"] in truncated and any(
            other not in truncated for other in partners.get(det["id"], ())
        ):
            skipped.append(det["id"])
        else:
            kept.append(det)
    return kept, skipped


def assign_shelf_positions(items: list[dict]) -> list[dict]:
    """1-based shelf_row from the largest cy gaps (max 8 rows) plus the
    shelf_x rank within each row; returns items sorted by row, order."""
    if not items:
        return []
    by_y = sorted(items, key=lambda d: d["cy"])
    threshold = 0.5 * statistics.median(d["h"] for d in by_y)
    gaps = [
        (by_y[i + 1]["cy"] - by_y[i]["cy"], i)
        for i in range(len(by_y) - 1)
    ]
    large = sorted(
        (gap for gap in gaps if gap[0] > threshold),
        key=lambda gap: gap[0],
        reverse=True,
    )[: MAX_ROWS - 1]
    cuts = set(index for _, index in large)
    row = 0
    for position, det in enumerate(by_y):
        if position == 0 or position - 1 in cuts:
            row += 1
        det["shelf_row"] = row
    rows: dict[int, list[dict]] = {}
    for det in by_y:
        rows.setdefault(det["shelf_row"], []).append(det)
    for dets in rows.values():
        dets.sort(key=lambda d: (d["shelf_x"], d["id"]))
        for order, det in enumerate(dets, start=1):
            det["shelf_order"] = order
    return sorted(items, key=lambda d: (d["shelf_row"], d["shelf_order"]))


def _ensure_positions(root: Path, scan_id: str) -> tuple[dict, dict]:
    """positions.json + same_spot.json, computing them when absent."""
    scan_dir = root / "scans" / scan_id
    positions_path = scan_dir / "positions.json"
    same_spot_path = scan_dir / "same_spot.json"
    if not (positions_path.is_file() and same_spot_path.is_file()):
        compute_positions(root, scan_id)
    return (
        json.loads(positions_path.read_text(encoding="utf-8")),
        json.loads(same_spot_path.read_text(encoding="utf-8")),
    )


def cut_crops(shared_root: Path, scan_id: str) -> dict:
    """Cut one crop per kept detection; return rows in shelf order."""
    root = Path(shared_root).resolve()
    scan_dir = root / "scans" / scan_id
    if not scan_dir.is_dir():
        raise FileNotFoundError(f"Scan not found: {scan_id}")
    detections_path = scan_dir / "detections.json"
    if not detections_path.is_file():
        raise FileNotFoundError(
            f"No detections for scan {scan_id} (run /scan/segment first)"
        )
    detections = json.loads(detections_path.read_text(encoding="utf-8"))[
        "detections"
    ]
    positions, same_spot = _ensure_positions(root, scan_id)
    sizes = {kf["index"]: (kf["width"], kf["height"]) for kf in positions["keyframes"]}
    located = {det["id"]: det for det in positions["detections"]}

    kept, skipped = select_detections(
        detections, sizes, same_spot.get("pairs", [])
    )
    by_kf: dict[int, list[dict]] = {}
    for det in kept:
        if det["id"] not in located:
            raise ValueError(
                f"Detection {det['id']} has no entry in positions.json"
            )
        by_kf.setdefault(det["keyframe"], []).append(det)

    crops_dir = scan_dir / "crops"
    crops_dir.mkdir(parents=True, exist_ok=True)
    items = []
    for kf in sorted(by_kf):
        full_path = scan_dir / "keyframes_full" / f"kf_{kf}.jpg"
        img = cv2.imread(str(full_path), cv2.IMREAD_COLOR)
        if img is None:
            raise FileNotFoundError(f"Could not read keyframe: {full_path}")
        full_h, full_w = img.shape[:2]
        kf_w, kf_h = sizes[kf]
        for det in sorted(by_kf[kf], key=lambda d: d["id"]):
            crop = make_crop(
                img, scale_box(det["box"], full_w / kf_w, full_h / kf_h)
            )
            crop_path = crops_dir / f"{det['id']}.jpg"
            cv2.imwrite(
                str(crop_path), crop, [int(cv2.IMWRITE_JPEG_QUALITY), JPEG_QUALITY]
            )
            crop_h, crop_w = crop.shape[:2]
            spot = located[det["id"]]
            items.append(
                {
                    "id": det["id"],
                    "keyframe": kf,
                    "crop_path": crop_path.relative_to(root).as_posix(),
                    "crop_width": crop_w,
                    "crop_height": crop_h,
                    "box": [int(v) for v in det["box"]],
                    "confidence": det["confidence"],
                    "phrase": det["phrase"],
                    "cy": spot["cy"],
                    "h": spot["h"],
                    "shelf_x": spot["shelf_x"],
                }
            )

    ordered = assign_shelf_positions(items)
    for det in ordered:
        del det["cy"]
        del det["h"]
        del det["shelf_x"]
    for start in range(0, len(ordered), BATCH_SIZE):
        chunk = ordered[start : start + BATCH_SIZE]
        vectors = embed_batch([root / det["crop_path"] for det in chunk])
        for det, vector in zip(chunk, vectors):
            det["embedding"] = vector
    return {
        "scan_id": scan_id,
        "crops": ordered,
        "total_crops": len(ordered),
        "skipped_edge": len(skipped),
        "rows": max((d["shelf_row"] for d in ordered), default=0),
    }
