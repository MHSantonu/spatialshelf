"""Spike 1.3: let muse-spark-1.3 pick SAM 3.1's noun prompts.

Pipeline: upright 1280px shelf frame -> muse-spark-1.3 (json_schema,
1-4 noun phrases) -> one SAM 3.1 image request per phrase in parallel,
plus one baseline "product package" request -> per-phrase stats ->
merge boxes across phrases (box IoU > 0.6 keeps higher confidence) ->
merged box overlay + summary JSON.

Run from the repo root inside the spikes venv, e.g.::

    spikes\\.venv\\Scripts\\python.exe spikes\\pick_phrases.py
"""

import argparse
import asyncio
import base64
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import cv2

from common import get_client
from meta_sam_parser import SegmentationBoxRecord
from sam_frames import fetch_events, parse_events
from sam_video import color_for

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_FRAME = (
    "spikes/out/VID_20261027_181256_1280_product_package_frames/frame_61.jpg"
)
PROMPT_PATH = ROOT / "prompts" / "pick_phrases.md"
SCHEMA_PATH = ROOT / "prompts" / "pick_phrases_schema.json"
CHAT_MODEL = "muse-spark-1.3"
MIN_CONFIDENCE = 0.5
IOU_THRESHOLD = 0.6


def resolve(path_str: str) -> Path:
    path = Path(path_str)
    return path if path.is_absolute() else ROOT / path


def pick_phrases(client, system: str, schema: dict, jpg_path: Path) -> tuple:
    """One muse-spark-1.3 call over the shelf frame; return phrases + raw."""
    data_url = "data:image/jpeg;base64," + base64.b64encode(
        jpg_path.read_bytes()
    ).decode("ascii")
    response = client.chat.completions.create(
        model=CHAT_MODEL,
        messages=[
            {"role": "system", "content": system},
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": "What kinds of packaged products are on this shelf?",
                    },
                    {"type": "image_url", "image_url": {"url": data_url}},
                ],
            },
        ],
        response_format={
            "type": "json_schema",
            "json_schema": {"name": "pick_phrases", "schema": schema},
        },
    )
    phrases = json.loads(response.choices[0].message.content)["phrases"]
    phrases = [p.strip() for p in phrases if p.strip()][:4]
    if not phrases:
        raise RuntimeError("muse-spark-1.3 returned no phrases")
    return phrases, response.model_dump(mode="json")


async def run_all(client, jpg_path: Path, phrases: list) -> list:
    """One SAM image request per phrase, concurrently."""

    async def one(phrase: str):
        try:
            events, seconds = await asyncio.to_thread(
                fetch_events, client, jpg_path, phrase
            )
        except Exception as error:
            raise RuntimeError(f"SAM request for {phrase!r} failed") from error
        result = await parse_events(events)
        return phrase, result, seconds

    return list(await asyncio.gather(*(one(p) for p in phrases)))


def collect_boxes(result) -> list:
    """Box detections at >= 0.5 as (object_id, box, confidence) tuples."""
    boxes = []
    for record in result.records:
        if not isinstance(record, SegmentationBoxRecord):
            continue
        confidence = record.confidence if record.confidence is not None else 0.0
        if confidence < MIN_CONFIDENCE:
            continue
        boxes.append(
            {
                "object_id": record.object_id,
                "box": (
                    int(record.left),
                    int(record.top),
                    int(record.right),
                    int(record.bottom),
                ),
                "confidence": confidence,
            }
        )
    return boxes


def box_iou(first: tuple, second: tuple) -> float:
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


def merge_boxes(all_boxes: list) -> tuple:
    """Greedy dedup: highest confidence wins any IoU > 0.6 cluster."""
    ordered = sorted(all_boxes, key=lambda b: b["confidence"], reverse=True)
    kept: list = []
    for candidate in ordered:
        if all(
            box_iou(candidate["box"], survivor["box"]) <= IOU_THRESHOLD
            for survivor in kept
        ):
            kept.append(candidate)
    return kept, len(all_boxes) - len(kept)


def draw_merged(jpg_path: Path, png_path: Path, kept: list) -> None:
    """Box + label overlay of the merged detections onto the frame."""
    img = cv2.imread(str(jpg_path), cv2.IMREAD_COLOR)
    if img is None:
        raise RuntimeError(f"Could not read {jpg_path}")
    for index, winner in enumerate(kept, start=1):
        left, top, right, bottom = winner["box"]
        colour = color_for(f"{winner['phrase']}:{winner['object_id']}")
        cv2.rectangle(img, (left, top), (right, bottom), colour, 2)
        label = f"{index}:{winner['confidence']:.2f} {winner['phrase']}"
        org = (left, top - 6 if top >= 18 else top + 16)
        cv2.putText(img, label, org, cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 0, 0), 3)
        cv2.putText(
            img, label, org, cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 1
        )
    cv2.imwrite(str(png_path), img)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Spike 1.3: muse-spark-1.3 picks SAM phrases"
    )
    parser.add_argument("--frame", default=DEFAULT_FRAME)
    parser.add_argument("--baseline", default="product package")
    parser.add_argument("--out-dir", default="spikes/out/VID_20261027_181256_phrases")
    args = parser.parse_args()

    jpg_path = resolve(args.frame)
    out_dir = resolve(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    system = PROMPT_PATH.read_text(encoding="utf-8").strip()
    schema = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))

    client = get_client()
    phrases, chat_raw = pick_phrases(client, system, schema, jpg_path)
    print(f"spark phrases: {phrases}")

    requests = list(phrases)
    if args.baseline not in requests:
        requests.append(args.baseline)
    results = asyncio.run(run_all(client, jpg_path, requests))

    stats = []
    all_boxes: list = []
    for phrase, result, seconds in results:
        boxes = collect_boxes(result)
        for entry in boxes:
            entry["phrase"] = phrase
        all_boxes.extend(boxes)
        ids = {entry["object_id"] for entry in boxes}
        confs = [entry["confidence"] for entry in boxes]
        mean = sum(confs) / len(confs) if confs else 0.0
        over = sum(1 for entry in boxes if entry["confidence"] >= 0.7)
        stats.append(
            {
                "phrase": phrase,
                "instances": len(ids),
                "mean_confidence": round(mean, 3),
                "n_ge_07": over,
                "seconds": round(seconds, 1),
            }
        )
        print(
            f"phrase {phrase!r}: instances={len(ids)} "
            f"mean_confidence={mean:.3f} n_ge_0.7={over} seconds={seconds:.1f}"
        )

    kept, dropped = merge_boxes(all_boxes)
    print(
        f"merged: {len(kept)} boxes "
        f"(dropped {dropped} duplicates at IoU>{IOU_THRESHOLD})"
    )

    draw_merged(jpg_path, out_dir / "merged_overlay.png", kept)
    (out_dir / "merged.json").write_text(
        json.dumps(
            {
                "frame": str(jpg_path),
                "spark_phrases": phrases,
                "baseline": args.baseline,
                "chat_raw": chat_raw,
                "per_phrase": stats,
                "merged_total": len(kept),
                "merged_dropped": dropped,
                "merged_boxes": [
                    {
                        "phrase": w["phrase"],
                        "object_id": w["object_id"],
                        "box": list(w["box"]),
                        "confidence": w["confidence"],
                    }
                    for w in kept
                ],
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    print(f"saved: {out_dir / 'merged_overlay.png'}")


if __name__ == "__main__":
    main()
