"""Spike 1.4: read 8 SAM crops with muse-spark-1.3 (spec Contract 1).

Pipeline: parse frame_144_raw.txt with meta_sam_parser -> pick the 8
largest confident non-edge instances -> mask-on-white crops ->
one muse-spark-1.3 chat completion (8 crops, json_schema) -> table.

Run from the spikes/ dir inside its venv, e.g.::

    .venv\\Scripts\\Activate.ps1
    python read_crops.py
"""

import base64
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import cv2
import numpy as np

from common import get_client
from meta_sam_parser import (
    CompletedOutcome,
    SegmentationBoxRecord,
    SegmentationMaskRecord,
    decode_mask_to_raster,
    image_segmentation_format,
)

ROOT = Path(__file__).resolve().parent.parent
FRAME_DIR = ROOT / "spikes" / "out" / "shelf_bottles_bottle_frames"
CROP_DIR = ROOT / "spikes" / "out" / "crops"
MIN_CONFIDENCE = 0.7
CROP_COUNT = 8
PAD = 8


def load_result(raw_path: Path):
    """Parse saved SAM image-mode output text with meta_sam_parser."""
    parser = image_segmentation_format().create_parser()
    parser.push(raw_path.read_text(encoding="utf-8"))
    return parser.finish(CompletedOutcome()).result


def pick_slots(result, frame_w: int, frame_h: int) -> list:
    """8 largest instances with confidence >= 0.7, boxes off the edge."""
    slots = {}
    for record in result.records:
        if not isinstance(record, (SegmentationBoxRecord, SegmentationMaskRecord)):
            continue
        slot = slots.setdefault(record.object_id, {"box": None, "mask": None})
        if isinstance(record, SegmentationBoxRecord):
            slot["box"] = record
        else:
            slot["mask"] = record
    candidates = []
    for object_id, slot in slots.items():
        box, mask_rec = slot["box"], slot["mask"]
        if box is None or mask_rec is None:
            continue
        confidence = (
            box.confidence
            if box.confidence is not None
            else (mask_rec.confidence or 0.0)
        )
        if confidence < MIN_CONFIDENCE:
            continue
        left, top, right, bottom = (
            int(box.left),
            int(box.top),
            int(box.right),
            int(box.bottom),
        )
        if left <= 0 or top <= 0 or right >= frame_w or bottom >= frame_h:
            continue
        area = (right - left) * (bottom - top)
        candidates.append((area, confidence, object_id, slot))
    candidates.sort(key=lambda item: item[0], reverse=True)
    if len(candidates) < CROP_COUNT:
        raise RuntimeError(
            f"Only {len(candidates)} instances qualify, need {CROP_COUNT}"
        )
    return candidates[:CROP_COUNT]


def make_crop(img, slot: dict, crop_path: Path) -> None:
    """Crop the padded box, painting pixels outside the mask white."""
    frame_h, frame_w = img.shape[:2]
    box = slot["box"]
    left = max(0, int(box.left) - PAD)
    top = max(0, int(box.top) - PAD)
    right = min(frame_w, int(box.right) + PAD)
    bottom = min(frame_h, int(box.bottom) + PAD)
    mask = slot["mask"].mask
    raster = (
        np.frombuffer(decode_mask_to_raster(mask), dtype=np.uint8)
        .reshape(mask.height, mask.width)
        .astype(bool)
    )
    if (mask.width, mask.height) == (frame_w, frame_h):
        ox, oy = 0, 0
    elif (mask.width, mask.height) == (
        int(box.right) - int(box.left),
        int(box.bottom) - int(box.top),
    ):
        ox, oy = int(box.left), int(box.top)
    else:  # unexpected raster size: fit to the box
        raster = cv2.resize(
            raster.astype(np.uint8),
            (int(box.right) - int(box.left), int(box.bottom) - int(box.top)),
            interpolation=cv2.INTER_NEAREST,
        ).astype(bool)
        ox, oy = int(box.left), int(box.top)
    full = np.zeros((frame_h, frame_w), dtype=bool)
    rh, rw = raster.shape
    full[oy : oy + rh, ox : ox + rw] |= raster
    crop = img[top:bottom, left:right].copy()
    crop[~full[top:bottom, left:right]] = (255, 255, 255)
    cv2.imwrite(str(crop_path), crop)


def format_size(item: dict) -> str:
    value, unit = item.get("size_value"), item.get("size_unit", "unknown")
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return f"? {unit}"
    return f"{value:g} {unit}"


def main() -> None:
    system = (ROOT / "prompts" / "read_crops_system.txt").read_text(encoding="utf-8")
    schema = json.loads(
        (ROOT / "prompts" / "read_crops_schema.json").read_text(encoding="utf-8")
    )
    img = cv2.imread(str(FRAME_DIR / "frame_144.jpg"), cv2.IMREAD_COLOR)
    if img is None:
        raise RuntimeError(f"Could not read {FRAME_DIR / 'frame_144.jpg'}")
    frame_h, frame_w = img.shape[:2]

    result = load_result(FRAME_DIR / "frame_144_raw.txt")
    candidates = pick_slots(result, frame_w, frame_h)

    CROP_DIR.mkdir(parents=True, exist_ok=True)
    content = []
    crops = []
    for index, (_, confidence, _, slot) in enumerate(candidates, start=1):
        crop_path = CROP_DIR / f"crop_{index}.jpg"
        make_crop(img, slot, crop_path)
        data_url = "data:image/jpeg;base64," + base64.b64encode(
            crop_path.read_bytes()
        ).decode("ascii")
        content.append({"type": "text", "text": f"Image {index}"})
        content.append({"type": "image_url", "image_url": {"url": data_url}})
        crops.append((confidence, crop_path))

    client = get_client()
    response = client.chat.completions.create(
        model="muse-spark-1.3",
        messages=[
            {"role": "system", "content": system},
            {"role": "user", "content": content},
        ],
        response_format={
            "type": "json_schema",
            "json_schema": {"name": "read_crops", "schema": schema},
        },
    )
    (CROP_DIR / "response.json").write_text(
        json.dumps(response.model_dump(mode="json"), indent=2), encoding="utf-8"
    )

    items = json.loads(response.choices[0].message.content)["items"]
    by_index = {item["image_index"]: item for item in items}
    header = (
        f"{'idx':<4} {'name':<30} {'brand':<18} {'size':<12} "
        f"{'category':<15} {'mrp':<15} {'conf':<6} crop"
    )
    print(header)
    print("-" * len(header))
    for index in range(1, CROP_COUNT + 1):
        item = by_index[index]
        _, crop_path = crops[index - 1]
        print(
            f"{index:<4} {item['name'][:30]:<30} {item['brand'][:18]:<18} "
            f"{format_size(item)[:12]:<12} {item['category'][:15]:<15} "
            f"{item['mrp_text'][:15]:<15} {item['confidence']:<6.2f} {crop_path}"
        )


if __name__ == "__main__":
    main()
