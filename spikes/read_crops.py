"""Spike 1.4/1.4b: read SAM crops with muse-spark-1.3 (spec Contract 1).

Pipeline: parse frame_144_raw.txt with meta_sam_parser -> pick the 8
largest confident non-edge instances -> mask-on-white crops (A) and/or
padded context crops (B) -> one muse-spark-1.3 chat completion per
variant (8 crops, json_schema) -> table.

Run from the spikes/ dir inside its venv, e.g.::

    .venv\\Scripts\\Activate.ps1
    python read_crops.py --variant both

4K mode: SAM boxes from a downscaled frame, crops cut from the full-res
frame::

    python read_crops.py --variant b --frame-dir <sam frames dir> \\
        --frame frame_61.jpg --source <full-res frame> --out-dir <out>
"""

import argparse
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
CROP_B_DIR = ROOT / "spikes" / "out" / "crops_b"
MIN_CONFIDENCE = 0.7
CROP_COUNT = 8
PAD = 8


def load_result(raw_path: Path):
    """Parse saved SAM image-mode output text with meta_sam_parser."""
    parser = image_segmentation_format().create_parser()
    parser.push(raw_path.read_text(encoding="utf-8"))
    return parser.finish(CompletedOutcome()).result


def pick_slots(result, frame_w: int, frame_h: int, count: int) -> list:
    """N largest instances with confidence >= 0.7, boxes off the edge."""
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
    if len(candidates) < count:
        raise RuntimeError(
            f"Only {len(candidates)} instances qualify, need {count}"
        )
    return candidates[:count]


def full_frame_mask(img, slot: dict):
    """Boolean full-frame mask for one instance's mask record."""
    frame_h, frame_w = img.shape[:2]
    box = slot["box"]
    mask = slot["mask"].mask
    raster = (
        np.frombuffer(decode_mask_to_raster(mask), dtype=np.uint8)
        .reshape(mask.height, mask.width)
        .astype(bool)
    )
    box_w, box_h = int(box.right) - int(box.left), int(box.bottom) - int(box.top)
    if (mask.width, mask.height) == (frame_w, frame_h):
        ox, oy = 0, 0
    elif (mask.width, mask.height) == (box_w, box_h):
        ox, oy = int(box.left), int(box.top)
    else:  # unexpected raster size: fit to the box
        raster = cv2.resize(
            raster.astype(np.uint8),
            (box_w, box_h),
            interpolation=cv2.INTER_NEAREST,
        ).astype(bool)
        ox, oy = int(box.left), int(box.top)
    full = np.zeros((frame_h, frame_w), dtype=bool)
    rh, rw = raster.shape
    full[oy : oy + rh, ox : ox + rw] |= raster
    return full


def make_crop_a(img, slot: dict, crop_path: Path) -> tuple:
    """Variant A: tight box + 8 px padding, outside-mask painted white."""
    box = slot["box"]
    frame_h, frame_w = img.shape[:2]
    left = max(0, int(box.left) - PAD)
    top = max(0, int(box.top) - PAD)
    right = min(frame_w, int(box.right) + PAD)
    bottom = min(frame_h, int(box.bottom) + PAD)
    full = full_frame_mask(img, slot)
    crop = img[top:bottom, left:right].copy()
    crop[~full[top:bottom, left:right]] = (255, 255, 255)
    cv2.imwrite(str(crop_path), crop)
    return (right - left, bottom - top)


def scale_box(slot: dict, sx: float, sy: float) -> tuple:
    """SAM box (small-frame pixels) scaled into the crop source frame."""
    box = slot["box"]
    return (
        int(box.left * sx),
        int(box.top * sy),
        int(box.right * sx),
        int(box.bottom * sy),
    )


def make_crop_b(img, box: tuple, crop_path: Path) -> tuple:
    """Variant B: 25% padding each side, real background, 2x if small."""
    frame_h, frame_w = img.shape[:2]
    left, top, right, bottom = box
    box_w, box_h = right - left, bottom - top
    pad_x, pad_y = int(0.25 * box_w), int(0.25 * box_h)
    left = max(0, left - pad_x)
    top = max(0, top - pad_y)
    right = min(frame_w, right + pad_x)
    bottom = min(frame_h, bottom + pad_y)
    crop = img[top:bottom, left:right].copy()
    h, w = crop.shape[:2]
    if min(h, w) < 300:
        crop = cv2.resize(
            crop, (w * 2, h * 2), interpolation=cv2.INTER_LANCZOS4
        )
    cv2.imwrite(str(crop_path), crop)
    h, w = crop.shape[:2]
    return (w, h)


def format_size(item: dict) -> str:
    value, unit = item.get("size_value"), item.get("size_unit", "unknown")
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return f"? {unit}"
    return f"{value:g} {unit}"


def read_variant(client, system: str, schema: dict, crop_paths: list, out_dir: Path):
    """One muse-spark-1.3 call over numbered crops; return items by index."""
    content = []
    for index, crop_path in enumerate(crop_paths, start=1):
        data_url = "data:image/jpeg;base64," + base64.b64encode(
            crop_path.read_bytes()
        ).decode("ascii")
        content.append({"type": "text", "text": f"Image {index}"})
        content.append({"type": "image_url", "image_url": {"url": data_url}})
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
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / "response.json").write_text(
        json.dumps(response.model_dump(mode="json"), indent=2), encoding="utf-8"
    )
    items = json.loads(response.choices[0].message.content)["items"]
    return {item["image_index"]: item for item in items}


def print_single(items: dict, sizes: list, paths: list) -> None:
    header = (
        f"{'idx':<4} {'px':<11} {'name':<30} {'brand':<18} {'size':<12} "
        f"{'category':<17} {'mrp':<15} {'conf':<6} crop"
    )
    print(header)
    print("-" * len(header))
    for index in range(1, len(paths) + 1):
        item = items[index]
        w, h = sizes[index - 1]
        print(
            f"{index:<4} {w}x{h:<8} {item['name'][:30]:<30} "
            f"{item['brand'][:18]:<18} {format_size(item)[:12]:<12} "
            f"{item['category'][:17]:<17} {item['mrp_text'][:15]:<15} "
            f"{item['confidence']:<6.2f} {paths[index - 1]}"
        )


def print_compare(a_items: dict, b_items: dict, a_sizes: list, b_sizes: list) -> None:
    header = (
        f"{'idx':<4} {'A px':<11} {'A name':<28} {'A size':<12} {'A conf':<7} "
        f"{'B px':<11} {'B name':<28} {'B size':<12} {'B conf':<7}"
    )
    print(header)
    print("-" * len(header))
    for index in range(1, len(a_sizes) + 1):
        a, b = a_items[index], b_items[index]
        aw, ah = a_sizes[index - 1]
        bw, bh = b_sizes[index - 1]
        print(
            f"{index:<4} {aw}x{ah:<8} {a['name'][:28]:<28} "
            f"{format_size(a)[:12]:<12} {a['confidence']:<7.2f} "
            f"{bw}x{bh:<8} {b['name'][:28]:<28} "
            f"{format_size(b)[:12]:<12} {b['confidence']:<7.2f}"
        )


def resolve(path_str: str) -> Path:
    path = Path(path_str)
    return path if path.is_absolute() else ROOT / path


def main() -> None:
    parser = argparse.ArgumentParser(description="Spike 1.4/1.4b: read SAM crops")
    parser.add_argument("--variant", choices=["a", "b", "both"], default="b")
    parser.add_argument(
        "--frame-dir", default="spikes/out/shelf_bottles_bottle_frames"
    )
    parser.add_argument("--frame", default="frame_144.jpg")
    parser.add_argument(
        "--source",
        default=None,
        help="Full-res image to cut variant-B crops from; SAM boxes are "
        "scaled up. Defaults to --frame.",
    )
    parser.add_argument("--count", type=int, default=CROP_COUNT)
    parser.add_argument(
        "--out-dir",
        default=None,
        help="Output dir for variant-B crops and response.json. "
        "Defaults to spikes/out/crops_b.",
    )
    args = parser.parse_args()

    system = (ROOT / "prompts" / "read_crops_system.txt").read_text(encoding="utf-8")
    schema = json.loads(
        (ROOT / "prompts" / "read_crops_schema.json").read_text(encoding="utf-8")
    )
    frame_dir = resolve(args.frame_dir)
    small_path = frame_dir / args.frame
    img = cv2.imread(str(small_path), cv2.IMREAD_COLOR)
    if img is None:
        raise RuntimeError(f"Could not read {small_path}")

    small_h, small_w = img.shape[:2]
    result = load_result(frame_dir / (Path(args.frame).stem + "_raw.txt"))
    candidates = pick_slots(result, small_w, small_h, args.count)
    slots = [slot for _, _, _, slot in candidates]

    src_img = img
    sx = sy = 1.0
    if args.source is not None:
        src_path = resolve(args.source)
        src_img = cv2.imread(str(src_path), cv2.IMREAD_COLOR)
        if src_img is None:
            raise RuntimeError(f"Could not read {src_path}")
        src_h, src_w = src_img.shape[:2]
        sx, sy = src_w / small_w, src_h / small_h
        print(
            f"crop source: {src_path} ({src_w}x{src_h}, "
            f"scale {sx:.4f}x{sy:.4f})"
        )
    b_dir = resolve(args.out_dir) if args.out_dir else CROP_B_DIR

    client = get_client()
    a_items = b_items = None
    a_sizes: list = []
    b_sizes: list = []
    a_paths: list = []
    b_paths: list = []
    if args.variant in ("a", "both"):
        CROP_DIR.mkdir(parents=True, exist_ok=True)
        for index, slot in enumerate(slots, start=1):
            path = CROP_DIR / f"crop_{index}.jpg"
            a_sizes.append(make_crop_a(img, slot, path))
            a_paths.append(path)
        a_items = read_variant(
            client, system, schema, a_paths, CROP_DIR
        )
    if args.variant in ("b", "both"):
        b_dir.mkdir(parents=True, exist_ok=True)
        for index, slot in enumerate(slots, start=1):
            path = b_dir / f"crop_{index}.jpg"
            b_sizes.append(make_crop_b(src_img, scale_box(slot, sx, sy), path))
            b_paths.append(path)
        b_items = read_variant(
            client, system, schema, b_paths, b_dir
        )

    if args.variant == "both":
        print_compare(a_items, b_items, a_sizes, b_sizes)
    elif args.variant == "a":
        print_single(a_items, a_sizes, a_paths)
    else:
        print_single(b_items, b_sizes, b_paths)


if __name__ == "__main__":
    main()
